<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: ../login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$sessionRole = (in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true)) ? 'admin' : 'user';

if ($sessionRole !== 'admin') {
    header('Location: index.php');
    exit();
}

header('Location: ../admin/config.php');
exit();
