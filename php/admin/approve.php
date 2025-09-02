<?php
require __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$post_id = $_POST['post_id'] ?? '';
$action = $_POST['action'] ?? '';
$reason = $_POST['reason'] ?? '';

if (!$post_id) {
    header('Location: index.php');
    exit;
}

if ($action == 'comment') {
    $comment = "⚠️ [BQT] Đính chính: " . $reason;
    $url = "https://graph.facebook.com/{$config['fb']['graph_version']}/{$post_id}/comments";
    $data = http_build_query(['message' => $comment, 'access_token' => $config['fb']['page_access_token']]);
    $r = file_get_contents($url . '?' . $data); // đơn giản
    $pdo->prepare("INSERT INTO audit_logs (post_id, action_type, reason, payload, actor) VALUES (:pid,'manual_comment',:reason,:payload,'admin')")->execute([':pid' => $post_id, ':reason' => $reason, ':payload' => $r]);
    $pdo->prepare("UPDATE posts SET action_taken='commented' WHERE id=:id")->execute([':id' => $post_id]);
}

if ($action == 'post') {
    $message = "📢 Đính chính từ BQT: " . $reason;
    $url = "https://graph.facebook.com/{$config['fb']['graph_version']}/{$config['fb']['page_id']}/feed";
    $data = http_build_query(['message' => $message, 'access_token' => $config['fb']['page_access_token']]);
    $r = file_get_contents($url . '?' . $data);
    $pdo->prepare("INSERT INTO audit_logs (post_id, action_type, reason, payload, actor) VALUES (:pid,'manual_post',:reason,:payload,'admin')")->execute([':pid' => $post_id, ':reason' => $reason, ':payload' => $r]);
    $pdo->prepare("UPDATE posts SET action_taken='posted' WHERE id=:id")->execute([':id' => $post_id]);
}

header('Location: index.php');
