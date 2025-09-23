<?php
// tools/create_admin.php — chạy từ CLI: php tools/create_admin.php admin yourpassword
require_once __DIR__ . '/../lib/db.php';
[$script, $user, $pass] = $argv + [null, null, null];
if (!$user || !$pass) die("Usage: php tools/create_admin.php <username> <password>\n");
$hash = password_hash($pass, PASSWORD_DEFAULT);
$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/../data/schema.sql'));
$stmt = $pdo->prepare('INSERT INTO admin_users(username, password_hash) VALUES(?,?)');
$stmt->execute([$user, $hash]);
echo "OK\n";
