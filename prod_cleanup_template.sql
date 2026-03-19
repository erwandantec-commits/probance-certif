-- prod_cleanup_template.sql
-- MariaDB / MySQL
-- Active uniquement les blocs dont tu as besoin en décommentant les lignes DELETE concernées.

START TRANSACTION;

-- =========================================================
-- PREVIEW AVANT SUPPRESSION
-- =========================================================
SELECT 'sessions_total' AS metric, COUNT(*) AS total FROM sessions
UNION ALL
SELECT 'sessions_exam_passed', COUNT(*) FROM sessions
WHERE session_type = 'EXAM' AND status = 'TERMINATED' AND passed = 1
UNION ALL
SELECT 'questions_total', COUNT(*) FROM questions
UNION ALL
SELECT 'question_options_total', COUNT(*) FROM question_options
UNION ALL
SELECT 'certification_revocations_total', COUNT(*) FROM certification_revocations
UNION ALL
SELECT 'exam_cooldown_overrides_total', COUNT(*) FROM exam_cooldown_overrides
UNION ALL
SELECT 'packages_total', COUNT(*) FROM packages
UNION ALL
SELECT 'contacts_total', COUNT(*) FROM contacts
UNION ALL
SELECT 'users_total', COUNT(*) FROM users;

-- =========================================================
-- 1. SUPPRIMER TOUTES LES SESSIONS
-- Supprime aussi session_questions, answer_options, answers via cascade
-- =========================================================
-- DELETE FROM sessions;

-- =========================================================
-- 2. SUPPRIMER UNIQUEMENT LES CERTIFICATIONS VALIDEES
-- Une certification validée = session EXAM terminée et réussie
-- =========================================================
-- DELETE FROM sessions
-- WHERE session_type = 'EXAM'
--   AND status = 'TERMINATED'
--   AND passed = 1;

-- =========================================================
-- 3. SUPPRIMER UNIQUEMENT LES SESSIONS D'UN PACK
-- Remplacer 3 par le bon package_id
-- =========================================================
-- DELETE FROM sessions
-- WHERE package_id = 3;

-- =========================================================
-- 4. SUPPRIMER UNIQUEMENT LES SESSIONS D'UN UTILISATEUR
-- Remplacer l'email
-- =========================================================
-- DELETE s
-- FROM sessions s
-- JOIN contacts c ON c.id = s.contact_id
-- WHERE c.email = 'user@example.com';

-- =========================================================
-- 5. SUPPRIMER TOUTES LES QUESTIONS IMPORTEES
-- Supprime aussi question_options via cascade
-- Attention: les sessions existantes perdront leurs questions liées
-- =========================================================
-- DELETE FROM questions;

-- =========================================================
-- 6. SUPPRIMER UNIQUEMENT LES QUESTIONS D'UN PACK
-- Remplacer 3 par le bon package_id
-- =========================================================
-- DELETE FROM questions
-- WHERE package_id = 3;

-- =========================================================
-- 7. SUPPRIMER UNIQUEMENT UNE QUESTION
-- Remplacer 123 par le bon id
-- =========================================================
-- DELETE FROM questions
-- WHERE id = 123;

-- =========================================================
-- 8. SUPPRIMER LES REVOCATIONS DE CERTIFICATIONS
-- =========================================================
-- DELETE FROM certification_revocations;

-- =========================================================
-- 9. SUPPRIMER LES REVOCATIONS D'UN PACK
-- Remplacer 3 par le bon package_id
-- =========================================================
-- DELETE FROM certification_revocations
-- WHERE package_id = 3;

-- =========================================================
-- 10. SUPPRIMER LES REVOCATIONS D'UN UTILISATEUR
-- Remplacer l'email
-- =========================================================
-- DELETE cr
-- FROM certification_revocations cr
-- JOIN contacts c ON c.id = cr.contact_id
-- WHERE c.email = 'user@example.com';

-- =========================================================
-- 11. SUPPRIMER LES OVERRIDES DE COOLDOWN
-- =========================================================
-- DELETE FROM exam_cooldown_overrides;

-- =========================================================
-- 12. SUPPRIMER LES OVERRIDES DE COOLDOWN D'UN PACK
-- Remplacer 3 par le bon package_id
-- =========================================================
-- DELETE FROM exam_cooldown_overrides
-- WHERE package_id = 3;

-- =========================================================
-- 13. SUPPRIMER LES OVERRIDES DE COOLDOWN D'UN USER
-- Remplacer 42 par le bon user_id
-- =========================================================
-- DELETE FROM exam_cooldown_overrides
-- WHERE user_id = 42;

-- =========================================================
-- 14. RESET METIER COMPLET SANS TOUCHER AUX USERS/CONTACTS
-- =========================================================
-- DELETE FROM certification_revocations;
-- DELETE FROM exam_cooldown_overrides;
-- DELETE FROM sessions;
-- DELETE FROM questions;

-- =========================================================
-- 15. RESET D'UN PACK COMPLET
-- Remplacer 3 par le bon package_id
-- =========================================================
-- DELETE FROM certification_revocations WHERE package_id = 3;
-- DELETE FROM exam_cooldown_overrides WHERE package_id = 3;
-- DELETE FROM sessions WHERE package_id = 3;
-- DELETE FROM questions WHERE package_id = 3;

-- =========================================================
-- 16. SUPPRIMER UN PACK COMPLET
-- Attention: supprime aussi package_rules, questions, sessions,
-- revocations et overrides liés via clés étrangères
-- Remplacer 3 par le bon package_id
-- =========================================================
-- DELETE FROM packages
-- WHERE id = 3;

-- =========================================================
-- VERIFICATION APRES SUPPRESSION
-- =========================================================
SELECT 'sessions_total_after' AS metric, COUNT(*) AS total FROM sessions
UNION ALL
SELECT 'sessions_exam_passed_after', COUNT(*) FROM sessions
WHERE session_type = 'EXAM' AND status = 'TERMINATED' AND passed = 1
UNION ALL
SELECT 'questions_total_after', COUNT(*) FROM questions
UNION ALL
SELECT 'question_options_total_after', COUNT(*) FROM question_options
UNION ALL
SELECT 'certification_revocations_total_after', COUNT(*) FROM certification_revocations
UNION ALL
SELECT 'exam_cooldown_overrides_total_after', COUNT(*) FROM exam_cooldown_overrides
UNION ALL
SELECT 'packages_total_after', COUNT(*) FROM packages
UNION ALL
SELECT 'contacts_total_after', COUNT(*) FROM contacts
UNION ALL
SELECT 'users_total_after', COUNT(*) FROM users;

COMMIT;

-- En cas de doute avant validation finale, remplacer COMMIT par ROLLBACK.
