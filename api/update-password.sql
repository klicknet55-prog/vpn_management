UPDATE users SET password_hash = '$2y$10$Nkrftu97670PgZvzzAkhmOx6NuPY9e0sZhIMrvL4FvZLTSJrCXrWW' WHERE email='admin';
SELECT email, password_hash FROM users WHERE email='admin';
