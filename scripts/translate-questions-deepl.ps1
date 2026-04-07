param(
    [string]$ApiKey = $env:DEEPL_API_KEY,
    [string[]]$Languages = @('en', 'es', 'jp'),
    [switch]$Overwrite,
    [switch]$DryRun,
    [int]$Limit = 0
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ApiKey)) {
    throw "DEEPL_API_KEY manquant. Passe -ApiKey ou definis la variable d'environnement DEEPL_API_KEY."
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$deeplEndpoint = if ($ApiKey -like '*:fx') { 'https://api-free.deepl.com/v2/translate' } else { 'https://api.deepl.com/v2/translate' }
$langMap = @{
    en = 'EN'
    es = 'ES'
    jp = 'JA'
}
$labels = @('A', 'B', 'C', 'D', 'E', 'F')

foreach ($lang in $Languages) {
    if (-not $langMap.ContainsKey($lang)) {
        throw "Langue non supportee: $lang (attendu: en, es, jp)."
    }
}

function Invoke-MariadbQuery {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Sql
    )

    $args = @(
        'compose', 'exec', '-T', 'db',
        'mariadb', '-N', '-B',
        '-ucertif_user', '-pcertif_pass', 'certif',
        '-e', $Sql
    )
    & docker @args
}

function Convert-HexText {
    param([string]$Value)

    if ([string]::IsNullOrEmpty($Value) -or $Value -eq 'NULL') {
        return ''
    }
    $bytes = New-Object byte[] ($Value.Length / 2)
    for ($i = 0; $i -lt $Value.Length; $i += 2) {
        $bytes[$i / 2] = [Convert]::ToByte($Value.Substring($i, 2), 16)
    }
    return [Text.Encoding]::UTF8.GetString($bytes)
}

function Convert-ToSqlUtf8Value {
    param([AllowEmptyString()][string]$Value)

    if ($null -eq $Value -or $Value -eq '') {
        return 'NULL'
    }

    $bytes = [Text.Encoding]::UTF8.GetBytes($Value)
    $hex = [BitConverter]::ToString($bytes).Replace('-', '')
    return "CONVERT(0x$hex USING utf8mb4)"
}

function Normalize-DeepLText {
    param([AllowEmptyString()][string]$Value)

    if ([string]::IsNullOrEmpty($Value)) {
        return ''
    }

    $normalized = $Value.Normalize([Text.NormalizationForm]::FormC)
    $normalized = $normalized.Replace([char]0x2018, "'")
    $normalized = $normalized.Replace([char]0x2019, "'")
    $normalized = $normalized.Replace([char]0x201C, '"')
    $normalized = $normalized.Replace([char]0x201D, '"')
    $normalized = $normalized.Replace([char]0x00A0, ' ')
    $normalized = $normalized.Replace([char]0x202F, ' ')
    $normalized = $normalized.Replace([string][char]0x2026, '...')
    $normalized = [regex]::Replace($normalized, '[\x00-\x08\x0B\x0C\x0E-\x1F]', '')
    return $normalized.Trim()
}

function Get-Questions {
    $sql = @"
SELECT
  q.id,
  q.external_id,
  HEX(COALESCE(q.text, '')) AS text_hex,
  HEX(COALESCE(q.explanation, '')) AS explanation_hex,
  DATE_FORMAT(q.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at,
  COALESCE((
    SELECT COUNT(*)
    FROM question_translations qt
    WHERE qt.question_id = q.id
      AND qt.lang = 'LANG_PLACEHOLDER'
  ), 0) AS translation_exists
FROM questions q
ORDER BY q.id
"@

    $questionMap = @{}
    foreach ($lang in $Languages) {
        $langSql = $sql.Replace('LANG_PLACEHOLDER', $lang)
        $rows = Invoke-MariadbQuery -Sql $langSql
        foreach ($row in $rows) {
            if ([string]::IsNullOrWhiteSpace($row)) {
                continue
            }
            $parts = $row -split "`t", 6
            if ($parts.Count -lt 6) {
                continue
            }
            $questionId = [int]$parts[0]
            if (-not $questionMap.ContainsKey($questionId)) {
                $questionMap[$questionId] = [ordered]@{
                    id = $questionId
                    external_id = [int]$parts[1]
                    text = Convert-HexText $parts[2]
                    explanation = Convert-HexText $parts[3]
                    updated_at = $parts[4]
                    existing_langs = @{}
                    options = @()
                }
            }
            $questionMap[$questionId].existing_langs[$lang] = ([int]$parts[5] -gt 0)
        }
    }

    $optionRows = Invoke-MariadbQuery -Sql @"
SELECT
  qo.id,
  qo.question_id,
  qo.label,
  HEX(COALESCE(qo.option_text, '')) AS option_text_hex
FROM question_options qo
ORDER BY qo.question_id, qo.label
"@
    foreach ($row in $optionRows) {
        if ([string]::IsNullOrWhiteSpace($row)) {
            continue
        }
        $parts = $row -split "`t", 4
        if ($parts.Count -lt 4) {
            continue
        }
        $questionId = [int]$parts[1]
        if (-not $questionMap.ContainsKey($questionId)) {
            continue
        }
        $questionMap[$questionId].options += [ordered]@{
            id = [int]$parts[0]
            question_id = $questionId
            label = $parts[2]
            option_text = Convert-HexText $parts[3]
        }
    }

    $questions = @($questionMap.Values | Sort-Object { $_.id })
    if ($Limit -gt 0) {
        $questions = @($questions | Select-Object -First $Limit)
    }
    return $questions
}

function Invoke-DeepLTranslationBatch {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Texts,
        [Parameter(Mandatory = $true)]
        [string]$TargetLang
    )

    if ($Texts.Count -eq 0) {
        return @()
    }

    $sanitizedTexts = @($Texts | ForEach-Object { Normalize-DeepLText $_ })

    $body = @{
        text = $sanitizedTexts
        source_lang = 'FR'
        target_lang = $TargetLang
        preserve_formatting = $true
    } | ConvertTo-Json -Depth 4

    $response = $null
    $lastError = $null
    $bodyBytes = [Text.Encoding]::UTF8.GetBytes($body)
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            $request = [System.Net.HttpWebRequest]::Create($deeplEndpoint)
            $request.Method = 'POST'
            $request.ContentType = 'application/json; charset=utf-8'
            $request.Accept = 'application/json'
            $request.Headers['Authorization'] = "DeepL-Auth-Key $ApiKey"
            $request.ContentLength = $bodyBytes.Length

            $requestStream = $request.GetRequestStream()
            try {
                $requestStream.Write($bodyBytes, 0, $bodyBytes.Length)
            } finally {
                $requestStream.Dispose()
            }

            $httpResponse = $request.GetResponse()
            try {
                $responseStream = $httpResponse.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($responseStream, [Text.Encoding]::UTF8)
                $responseJson = $reader.ReadToEnd()
                $response = $responseJson | ConvertFrom-Json
            } finally {
                if ($reader) { $reader.Dispose() }
                if ($responseStream) { $responseStream.Dispose() }
                if ($httpResponse) { $httpResponse.Dispose() }
            }
            break
        } catch {
            $lastError = $_
            if ($attempt -lt 3) {
                Start-Sleep -Seconds (2 * $attempt)
            }
        }
    }

    if ($null -eq $response) {
        if ($null -ne $lastError -and $lastError.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($lastError.Exception.Response.GetResponseStream(), [Text.Encoding]::UTF8)
            $responseBody = $reader.ReadToEnd()
            throw "DeepL API error: $responseBody"
        }
        throw $lastError
    }

    return @($response.translations)
}

function Translate-QuestionPayload {
    param(
        [Parameter(Mandatory = $true)]
        [hashtable]$Question,
        [Parameter(Mandatory = $true)]
        [string]$Lang
    )

    $segments = @()
    $segments += [ordered]@{ kind = 'question_text'; value = [string]$Question.text }
    $segments += [ordered]@{ kind = 'explanation'; value = [string]$Question.explanation }
    foreach ($option in $Question.options) {
        $segments += [ordered]@{
            kind = 'option'
            label = [string]$option.label
            option_id = [int]$option.id
            value = [string]$option.option_text
        }
    }

    $nonEmptySegments = @($segments | Where-Object { -not [string]::IsNullOrWhiteSpace($_.value) })
    if ($nonEmptySegments.Count -eq 0) {
        return [ordered]@{
            question_text = ''
            explanation = ''
            options = @{}
        }
    }

    $translated = Invoke-DeepLTranslationBatch -Texts @($nonEmptySegments | ForEach-Object { $_.value }) -TargetLang $langMap[$Lang]
    if ($translated.Count -ne $nonEmptySegments.Count) {
        throw "DeepL a retourne $($translated.Count) segments au lieu de $($nonEmptySegments.Count)."
    }

    $result = [ordered]@{
        question_text = ''
        explanation = ''
        options = @{}
    }

    for ($i = 0; $i -lt $nonEmptySegments.Count; $i++) {
        $segment = $nonEmptySegments[$i]
        $translatedText = ''
        if ($null -ne $translated[$i] -and $null -ne $translated[$i].text) {
            $translatedText = [string]$translated[$i].text
        }
        if ($segment.kind -eq 'question_text') {
            $result.question_text = $translatedText
        } elseif ($segment.kind -eq 'explanation') {
            $result.explanation = $translatedText
        } elseif ($segment.kind -eq 'option') {
            $result.options[$segment.option_id] = $translatedText
        }
    }

    return $result
}

function Save-QuestionTranslation {
    param(
        [Parameter(Mandatory = $true)]
        [hashtable]$Question,
        [Parameter(Mandatory = $true)]
        [string]$Lang,
        [Parameter(Mandatory = $true)]
        [hashtable]$Translation
    )

    $questionTextSql = Convert-ToSqlUtf8Value $Translation.question_text
    $explanationSql = Convert-ToSqlUtf8Value $Translation.explanation
    $updatedAtSql = if ([string]::IsNullOrWhiteSpace($Question.updated_at)) { 'NULL' } else { "'" + $Question.updated_at.Replace("'", "''") + "'" }

    $statements = @()
    $statements += @"
INSERT INTO question_translations(question_id, lang, question_text, explanation, source_updated_at, created_at, updated_at)
VALUES ($($Question.id), '$Lang', $questionTextSql, $explanationSql, $updatedAtSql, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  question_text = VALUES(question_text),
  explanation = VALUES(explanation),
  source_updated_at = VALUES(source_updated_at),
  updated_at = NOW();
"@

    foreach ($option in $Question.options) {
        if (-not $Translation.options.ContainsKey($option.id)) {
            continue
        }
        $optionTextSql = Convert-ToSqlUtf8Value ([string]$Translation.options[$option.id])
        $statements += @"
INSERT INTO question_option_translations(option_id, lang, option_text, created_at, updated_at)
VALUES ($($option.id), '$Lang', $optionTextSql, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  option_text = VALUES(option_text),
  updated_at = NOW();
"@
    }

    $sql = "START TRANSACTION;`n" + ($statements -join "`n") + "`nCOMMIT;"
    Invoke-MariadbQuery -Sql $sql | Out-Null
}

$questions = @(Get-Questions)
if ($questions.Count -eq 0) {
    Write-Output "Aucune question a traduire."
    exit 0
}

$targets = foreach ($question in $questions) {
    foreach ($lang in $Languages) {
        $exists = $false
        if ($question.existing_langs.Contains($lang)) {
            $exists = [bool]$question.existing_langs[$lang]
        }
        if ($Overwrite -or -not $exists) {
            [ordered]@{
                question = $question
                lang = $lang
                exists = $exists
            }
        }
    }
}

$targets = @($targets)
if ($targets.Count -eq 0) {
    Write-Output "Toutes les traductions demandees existent deja."
    exit 0
}

Write-Output ("Questions chargees: {0}" -f $questions.Count)
Write-Output ("Traductions a generer: {0}" -f $targets.Count)
Write-Output ("Endpoint DeepL: {0}" -f $deeplEndpoint)

$done = 0
foreach ($target in $targets) {
    $question = $target.question
    $lang = [string]$target.lang
    $done++
    Write-Output ("[{0}/{1}] Traduction question #{2} (external_id={3}) vers {4}" -f $done, $targets.Count, $question.id, $question.external_id, $lang)
    $translation = Translate-QuestionPayload -Question $question -Lang $lang
    if (-not $DryRun) {
        Save-QuestionTranslation -Question $question -Lang $lang -Translation $translation
    }
}

if ($DryRun) {
    Write-Output "Dry run termine. Aucune traduction n'a ete enregistree."
} else {
    Write-Output "Traductions DeepL terminees."
}
