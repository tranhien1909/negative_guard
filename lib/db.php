<?php
require_once __DIR__ . '/config.php';


function db()
{
    static $pdo;
    if ($pdo) return $pdo;


    $driver = envv('DB_DRIVER', 'sqlite');
    if ($driver === 'sqlite') {
        $path = envv('DB_PATH', __DIR__ . '/../data/app.sqlite');
        $pdo = new PDO('sqlite:' . $path);
    } else {
        $host = envv('DB_HOST', '127.0.0.1');
        $port = envv('DB_PORT', '3306');
        $name = envv('DB_NAME', 'kltndb');
        $user = envv('DB_USER', 'root');
        $pass = envv('DB_PASS', '');
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}
