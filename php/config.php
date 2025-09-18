<?php

declare(strict_types=1);

// autoload nằm ở GỐC dự án (vì bạn chạy composer ở đó)
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = dirname(__DIR__);
if (file_exists($root . '/.env')) {
    $dotenv = Dotenv::createImmutable($root);
    $dotenv->load();
}

$config = [
    'app' => [
        'timezone' => $_ENV['APP_TIMEZONE'] ?? 'Asia/Bangkok',
    ],
    'fb' => [
        'page_id'          => $_ENV['FB_PAGE_ID'] ?? '',
        'page_access_token' => $_ENV['FB_PAGE_ACCESS_TOKEN'] ?? '',
        'graph_version'    => $_ENV['FB_GRAPH_VERSION'] ?? 'v21.0',
    ],
    'facebook' => [
        'fetch_window_hours' => (int)($_ENV['FB_FETCH_WINDOW_HOURS'] ?? 720),
        'limit'              => (int)($_ENV['FB_LIMIT'] ?? 25),
        'fetch_comments'     => filter_var($_ENV['FB_FETCH_COMMENTS'] ?? '1', FILTER_VALIDATE_BOOL),
        'comments_limit'     => (int)($_ENV['FB_COMMENTS_LIMIT'] ?? 50),
    ],
    'db' => [
        'host'    => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'dbname'  => $_ENV['DB_NAME'] ?? 'negative_guard',
        'user'    => $_ENV['DB_USER'] ?? 'root',
        'pass'    => $_ENV['DB_PASS'] ?? '',
        'charset' => 'utf8mb4',
    ],
    'ml' => [
        'predict_url' => $_ENV['ML_PREDICT_URL'] ?? 'http://127.0.0.1:8000/predict',
    ],
    'auth' => [
        'username'      => $_ENV['ADMIN_USER'] ?? 'admin',
        'password'      => $_ENV['ADMIN_PASS'] ?? null,          // chỉ dùng cho dev
        'password_hash' => $_ENV['ADMIN_PASS_HASH'] ?? null,     // khuyên dùng
    ],
];


// đặt timezone toàn cục
date_default_timezone_set($config['app']['timezone']);

return $config;
