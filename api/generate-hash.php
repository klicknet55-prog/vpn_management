<?php
// Generate bcrypt hash for password "admin"
$password = 'admin';
$hash = password_hash($password, PASSWORD_BCRYPT);
echo "Bcrypt hash for 'admin': " . $hash . "\n";
?>
