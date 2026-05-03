<?php
/**
 * Test Login Endpoint
 * Simple test to verify admin user credentials
 */

require_once __DIR__ . '/db.php';

try {
    $db = getDB();
    
    // Check admin user exists
    $stmt = $db->prepare('SELECT id, full_name, email, password_hash FROM users WHERE email = ?');
    $stmt->execute(['admin']);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo "❌ Admin user not found\n";
        exit(1);
    }
    
    echo "✅ Admin user found:\n";
    echo "  - ID: " . $user['id'] . "\n";
    echo "  - Full Name: " . $user['full_name'] . "\n";
    echo "  - Email: " . $user['email'] . "\n";
    echo "  - Password Hash: " . substr($user['password_hash'], 0, 20) . "...\n";
    
    // Test password verification
    $testPassword = 'admin';
    $isValid = password_verify($testPassword, $user['password_hash']);
    
    if ($isValid) {
        echo "✅ Password 'admin' verified successfully\n";
    } else {
        echo "❌ Password verification failed\n";
        exit(1);
    }
    
    // Check user roles
    $roleStmt = $db->prepare('
        SELECT r.code, r.name
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ?
    ');
    $roleStmt->execute([$user['id']]);
    $roles = $roleStmt->fetchAll();
    
    echo "✅ User roles:\n";
    foreach ($roles as $role) {
        echo "  - " . $role['code'] . " (" . $role['name'] . ")\n";
    }
    
    echo "\n✅ All tests passed! Database setup is correct.\n";
    echo "   Login credentials: admin / admin\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
