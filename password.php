<?php
require_once __DIR__ . '/../config/api.php';

api_run(function () use ($conn): void {
    api_method('POST');
    $user = api_user();
    $data = api_json();
    api_csrf($data);
    $current = $data['current_password'] ?? null;
    if (!is_string($current) || $current === '' || strlen($current) > 1024) {
        throw new ApiException('Password saat ini tidak valid.');
    }
    $new = $data['new_password'] ?? null;
    if (!is_string($new) || strlen($new) > 1024) {
        throw new ApiException('Password baru tidak valid.');
    }
    if (strlen($new) < 12) {
        throw new ApiException('Password baru minimal 12 karakter.');
    }

    $stmt = $conn->prepare('SELECT password FROM users WHERE username=? LIMIT 1');
    $stmt->bind_param('s', $user['username']);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if (!$account || !password_verify($current, $account['password'])) {
        throw new ApiException('Password saat ini tidak sesuai.', 401);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE username=?');
    $stmt->bind_param('ss', $hash, $user['username']);
    $stmt->execute();
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    api_ok(['message' => 'Password berhasil diperbarui.', 'csrf' => $_SESSION['csrf']]);
});
