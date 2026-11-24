<?php
require_once __DIR__ . '/../config/api.php';

api_run(function (): void {
    api_method('POST');
    api_user();
    $data = api_json();
    api_csrf($data);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    api_ok(['message' => 'Anda telah keluar.']);
});
