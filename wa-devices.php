<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit();
}

$sessionRoles = $_SESSION['roles'] ?? [];
$isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);

header('Location: ' . ($isAdmin ? 'admin/wa-devices.php' : 'user/wa-devices.php'));
exit();