param(
    [string[]]$Languages = @('es', 'jp')
)

$ErrorActionPreference = 'Stop'

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

function Looks-LikeMojibake {
    param(
        [AllowEmptyString()][string]$Value,
        [string]$Lang
    )

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return $false
    }

    $markerC3 = [string][char]0x00C3
    $markerC2 = [string][char]0x00C2
    if ($Value.Contains($markerC3) -or $Value.Contains($markerC2)) {
        return $true
    }
    if ([regex]::IsMatch($Value, "[\u0080-\u009F]")) {
        return $true
    }
    return $false
}

function Repair-Mojibake {
    param(
        [AllowEmptyString()][string]$Value,
        [string]$Lang
    )

    if (-not (Looks-LikeMojibake -Value $Value -Lang $Lang)) {
        return $Value
    }

    $latin1 = [Text.Encoding]::GetEncoding(28591)
    $fixed = $Value
    for ($i = 0; $i -lt 2; $i++) {
        $bytes = $latin1.GetBytes($fixed)
        $candidate = [Text.Encoding]::UTF8.GetString($bytes)
        if ($candidate -eq $fixed) {
            break
        }
        $fixed = $candidate
        if (-not (Looks-LikeMojibake -Value $fixed -Lang $Lang)) {
            break
        }
    }
    return $fixed
}

$questionFixes = 0
$optionFixes = 0

foreach ($lang in $Languages) {
    Write-Output "Reparation des traductions question pour $lang..."
    $questionRows = Invoke-MariadbQuery -Sql @"
SELECT id, HEX(COALESCE(question_text, '')), HEX(COALESCE(explanation, ''))
FROM question_translations
WHERE lang = '$lang'
"@
    foreach ($row in $questionRows) {
        if ([string]::IsNullOrWhiteSpace($row)) {
            continue
        }
        $parts = $row -split "`t", 3
        if ($parts.Count -lt 3) {
            continue
        }
        $id = [int]$parts[0]
        $questionText = Convert-HexText $parts[1]
        $explanation = Convert-HexText $parts[2]
        $fixedQuestionText = Repair-Mojibake -Value $questionText -Lang $lang
        $fixedExplanation = Repair-Mojibake -Value $explanation -Lang $lang
        if ($fixedQuestionText -ne $questionText -or $fixedExplanation -ne $explanation) {
            $questionSql = Convert-ToSqlUtf8Value $fixedQuestionText
            $explanationSql = Convert-ToSqlUtf8Value $fixedExplanation
            Invoke-MariadbQuery -Sql "UPDATE question_translations SET question_text = $questionSql, explanation = $explanationSql, updated_at = NOW() WHERE id = $id;" | Out-Null
            $questionFixes++
        }
    }

    Write-Output "Reparation des options pour $lang..."
    $optionRows = Invoke-MariadbQuery -Sql @"
SELECT id, HEX(COALESCE(option_text, ''))
FROM question_option_translations
WHERE lang = '$lang'
"@
    foreach ($row in $optionRows) {
        if ([string]::IsNullOrWhiteSpace($row)) {
            continue
        }
        $parts = $row -split "`t", 2
        if ($parts.Count -lt 2) {
            continue
        }
        $id = [int]$parts[0]
        $optionText = Convert-HexText $parts[1]
        $fixedOptionText = Repair-Mojibake -Value $optionText -Lang $lang
        if ($fixedOptionText -ne $optionText) {
            $optionSql = Convert-ToSqlUtf8Value $fixedOptionText
            Invoke-MariadbQuery -Sql "UPDATE question_option_translations SET option_text = $optionSql, updated_at = NOW() WHERE id = $id;" | Out-Null
            $optionFixes++
        }
    }
}

Write-Output "Reparation terminee."
Write-Output "Questions corrigees: $questionFixes"
Write-Output "Options corrigees: $optionFixes"
