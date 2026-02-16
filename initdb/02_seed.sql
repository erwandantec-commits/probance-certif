INSERT INTO packages (name, pass_threshold_percent, duration_limit_minutes, selection_count, is_active)
VALUES
('Certification Package Red', 80, 120, 5, 1);

-- 5 questions de démo (package_id = 1)
INSERT INTO questions (package_id, text, option_a, option_b, option_c, option_d, correct_option) VALUES
(1, 'Probance est principalement une plateforme de…', 'CRM', 'Marketing Automation', 'ERP', 'Ticketing', 'B'),
(1, 'Une session est ACTIVE si…', 'elle est créée', 'elle est < durée max', 'elle a un score', 'elle est terminée', 'B'),
(1, 'Une mauvaise réponse vaut…', '1 point', '0 point', '0.5 point', '-1 point', 'B'),
(1, 'Le seuil de réussite par défaut est…', '50%', '70%', '80%', '90%', 'C'),
(1, 'MariaDB est un…', 'OS', 'SGBD', 'Framework JS', 'Navigateur', 'B');
