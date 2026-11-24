<?php
require_once __DIR__ . '/../config/api.php';

api_run(function () use ($conn): void {
    api_method('POST');
    $data = api_json();
    $username = api_text($data, 'username', 50);
    $password = $data['password'] ?? '';
    if (!is_string($password)) {
        throw new ApiException('Username atau password salah.', 401);
    }
    if ($password === '' || strlen($password) > 1024) {
        throw new ApiException('Username atau password salah.', 401);
    }

    $now = time();
    if (!isset($_SESSION['login_attempts']) || $now - $_SESSION['login_attempts']['since'] > 900) {
        $_SESSION['login_attempts'] = ['count' => 0, 'since' => $now];
    }
    if ($_SESSION['login_attempts']['count'] >= 10) {
        throw new ApiException('Terlalu banyak percobaan login. Coba lagi dalam 15 menit.', 429);
    }

    $stmt = $conn->prepare('SELECT username, password, role, user_id FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    if (!$account || !password_verify($password, $account['password'])) {
        $_SESSION['login_attempts']['count']++;
        throw new ApiException('Username atau password salah.', 401);
    }

    session_regenerate_id(true);
    unset($_SESSION['login_attempts']);
    $_SESSION['user'] = [
        'username' => $account['username'],
        'role' => $account['role'],
        'user_id' => $account['user_id'],
    ];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    api_ok(['user' => $_SESSION['user'], 'csrf' => $_SESSION['csrf']]);
});