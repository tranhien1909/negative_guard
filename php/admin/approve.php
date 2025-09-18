<?php

declare(strict_types=1);

require __DIR__ . '/_auth.php';
$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/db.php';

$action    = $_POST['action'] ?? '';
$reason    = trim((string)($_POST['reason'] ?? ''));
$fbPostId  = trim((string)($_POST['fb_post_id'] ?? ''));

if (!in_array($action, ['comment', 'post'], true)) {
    http_response_code(400);
    exit('Invalid action');
}
if ($reason === '') {
    http_response_code(400);
    exit('Reason is required');
}

$token   = $config['fb']['page_access_token'] ?? '';
$version = $config['fb']['graph_version'] ?? 'v21.0';
$pageId  = $config['fb']['page_id'] ?? '';

/* HTTP helpers */
function fb_post(string $endpoint, array $params): array
{
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$http, $body, $err];
}

/* logging */
$logDir = dirname(__DIR__) . '/../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0777, true);

if ($action === 'comment') {
    if ($fbPostId === '') {
        http_response_code(400);
        exit('Missing fb_post_id');
    }
    if ($token === '') {
        http_response_code(400);
        exit('Missing FB token');
    }

    $endpoint = "https://graph.facebook.com/{$version}/{$fbPostId}/comments";
    $comment  = "⚠️ [BQT] Đính chính: " . $reason;

    [$http, $body, $err] = fb_post($endpoint, ['message' => $comment, 'access_token' => $token]);

    $pdo->prepare("INSERT INTO audit_logs (post_id, action_type, reason, payload, actor, http_code, error)
                 VALUES (:pid,'manual_comment',:reason,:payload,'admin',:http,:err)")
        ->execute([':pid' => $fbPostId, ':reason' => $reason, ':payload' => (string)$body, ':http' => $http, ':err' => $err]);

    if ($http >= 200 && $http < 300) {
        $pdo->prepare("UPDATE posts SET action_taken='commented' WHERE fb_post_id=:id")
            ->execute([':id' => $fbPostId]);
        header('Location: index.php');
        exit;
    }
    http_response_code(502);
    echo "Facebook API error ($http): $err $body";
    exit;
}

if ($action === 'post') {
    if ($pageId === '' || $token === '') {
        http_response_code(400);
        exit('Missing page_id or token');
    }

    $endpoint = "https://graph.facebook.com/{$version}/{$pageId}/feed";
    $message  = "📢 Đính chính từ BQT: " . $reason;

    [$http, $body, $err] = fb_post($endpoint, ['message' => $message, 'access_token' => $token]);

    $pdo->prepare("INSERT INTO audit_logs (post_id, action_type, reason, payload, actor, http_code, error)
                 VALUES (:pid,'manual_post',:reason,:payload,'admin',:http,:err)")
        ->execute([':pid' => ($fbPostId ?: 'manual_feed_post'), ':reason' => $reason, ':payload' => (string)$body, ':http' => $http, ':err' => $err]);

    if ($http >= 200 && $http < 300) {
        header('Location: index.php');
        exit;
    }
    http_response_code(502);
    echo "Facebook API error ($http): $err $body";
    exit;
}
