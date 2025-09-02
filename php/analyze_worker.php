<?php
require __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

$predict_url = $config['ml']['predict_url'];
$token = $config['fb']['page_access_token'];
$version = $config['fb']['graph_version'];
$pageId = $config['fb']['page_id'];

// Lấy các post chưa phân tích hoặc phân tích cũ (>1h)
$stmt = $pdo->query("SELECT * FROM posts WHERE last_analysis_time IS NULL OR last_analysis_time < DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 20");
$posts = $stmt->fetchAll();

foreach ($posts as $p) {
    $text = $p['message'] ?: '';
    $payload = json_encode(['text' => $text]);

    $ch = curl_init($predict_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if (!$resp) {
        // log error
        continue;
    }
    $res = json_decode($resp, true);
    if (!$res) continue;

    $risk = floatval($res['risk_score'] ?? 0);
    $label = $res['label'] ?? '';

    // cập nhật DB
    $u = $pdo->prepare("UPDATE posts SET risk_score = :risk, label = :label, last_analysis_time = NOW() WHERE id = :id");
    $u->execute([':risk' => $risk, ':label' => $label, ':id' => $p['id']]);

    // quyết định hành động
    if ($risk >= 0.8 && $p['action_taken'] !== 'commented') {
        // comment
        $comment = "⚠️ Hệ thống phát hiện dấu hiệu rủi ro (" . $label . "). Vui lòng thận trọng — BQT sẽ xác minh. Không bấm link lạ.";
        $comment_url = "https://graph.facebook.com/{$version}/{$p['id']}/comments";
        $post_fields = http_build_query(['message' => $comment, 'access_token' => $token]);
        $ch2 = curl_init($comment_url);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        $r2 = curl_exec($ch2);
        curl_close($ch2);

        // ghi audit + cập nhật action_taken
        $ins = $pdo->prepare("INSERT INTO audit_logs (post_id, action_type, reason, payload, actor) VALUES (:pid, 'auto_comment', :reason, :payload, 'system')");
        $ins->execute([':pid' => $p['id'], ':reason' => "risk={$risk}", ':payload' => $r2]);

        $pdo->prepare("UPDATE posts SET action_taken='commented' WHERE id=:id")->execute([':id' => $p['id']]);
        echo "Commented on {$p['id']}\n";
    } else {
        echo "Post {$p['id']} risk {$risk}, no action\n";
    }
}
