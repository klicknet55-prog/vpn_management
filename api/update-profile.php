<?php
session_start();

header('Content-Type: application/json');

if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan login kembali.']);
    exit();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
    exit();
}

require_once __DIR__ . '/db.php';

$action = trim((string) ($_POST['action'] ?? ''));

try {
    $db = getDB();

    if ($action === 'update_profile') {
        $fullName    = trim((string) ($_POST['full_name'] ?? ''));
        $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));

        if ($fullName === '') {
            echo json_encode(['success' => false, 'message' => 'Nama lengkap tidak boleh kosong.']);
            exit();
        }

        $phoneDigits = '';
        if ($phoneNumber !== '') {
            $phoneDigits = preg_replace('/\D+/', '', $phoneNumber);
            if (str_starts_with($phoneDigits, '0')) {
                $phoneDigits = '62' . substr($phoneDigits, 1);
            }
            if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
                echo json_encode(['success' => false, 'message' => 'No. HP tidak valid.']);
                exit();
            }
        }

        $stmt = $db->prepare(
            'UPDATE users SET full_name = :full_name, phone_number = :phone_number, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'full_name'    => $fullName,
            'phone_number' => $phoneDigits !== '' ? $phoneDigits : null,
            'id'           => $userId,
        ]);

        // Update session
        $_SESSION['full_name'] = $fullName;

        echo json_encode(['success' => true, 'message' => 'Profil berhasil diperbarui.', 'full_name' => $fullName]);

    } elseif ($action === 'change_password') {
        $oldPassword  = (string) ($_POST['old_password'] ?? '');
        $newPassword  = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
            echo json_encode(['success' => false, 'message' => 'Semua field password wajib diisi.']);
            exit();
        }

        if (strlen($newPassword) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password baru minimal 6 karakter.']);
            exit();
        }

        if ($newPassword !== $confirmPassword) {
            echo json_encode(['success' => false, 'message' => 'Konfirmasi password tidak cocok.']);
            exit();
        }

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $hash = (string) ($stmt->fetchColumn() ?: '');

        if (!password_verify($oldPassword, $hash)) {
            echo json_encode(['success' => false, 'message' => 'Password lama tidak benar.']);
            exit();
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $upd = $db->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
        $upd->execute(['hash' => $newHash, 'id' => $userId]);

        echo json_encode(['success' => true, 'message' => 'Password berhasil diubah.']);

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action tidak valid.']);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server.']);
}
