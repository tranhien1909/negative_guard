<?php
// tools/scan_posts.php
// Chạy: php tools/scan_posts.php 60
define('CLI_MODE', true);
$_SERVER['REQUEST_METHOD'] = 'POST';

// POST “ảo” để gọi đúng case trong action.php
$_POST = [
    'action' => 'scan_posts_now',
    'window' => isset($argv[1]) ? (int)$argv[1] : 60,
];

// nhúng thẳng action.php (đã cho phép CLI ở bước 1)
require __DIR__ . '/../public/admin/action.php';

// đảm bảo xuống dòng khi in ra CLI (phòng khi action.php không xuống dòng)
if (!headers_sent()) {
    echo PHP_EOL;
}
