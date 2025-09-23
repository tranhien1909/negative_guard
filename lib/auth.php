<?php
require_once __DIR__ . '/config.php';


session_name(envv('SESSION_NAME', 'KLTNSESSID'));
session_start();


function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}


function csrf_verify($token)
{
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token ?? '');
}


function require_admin()
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: /login.php');
        exit;
    }
}
