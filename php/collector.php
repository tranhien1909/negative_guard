<?php
require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

$token = $config['fb']['page_access_token'];
$pageId = $config['fb']['page_id'];
$version = $config['fb']['graph_version'];

// Lấy posts (đơn giản)
$url = "https://graph.facebook.com/{$version}/{$pageId}/posts?fields=id,message,created_time,from&limit=25&access_token={$token}";
$resp = @file_get_contents($url);
if ($resp === false) {
    echo "Failed fetch\n";
    exit;
}
$data = json_decode($resp, true);
if (!isset($data['data'])) {
    echo "No data\n";
    exit;
}

$insert = $pdo->prepare("INSERT INTO posts (id, message, created_time, from_id) VALUES (:id,:message,:created_time,:from_id)
  ON DUPLICATE KEY UPDATE message = VALUES(message), updated_at = CURRENT_TIMESTAMP");

foreach ($data['data'] as $post) {
    $id = $post['id'];
    $msg = $post['message'] ?? '';
    $ct = date('Y-m-d H:i:s', strtotime($post['created_time']));
    $from_id = $post['from']['id'] ?? null;

    $insert->execute([
        ':id' => $id,
        ':message' => $msg,
        ':created_time' => $ct,
        ':from_id' => $from_id
    ]);
    echo "Saved post {$id}\n";
}
