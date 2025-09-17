<?php
// file cron\fetch_posts.php
require __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$graphVer = $config['fb']['graph_version'];
$pageId   = $config['fb']['page_id'];
$token    = $config['fb']['page_access_token'];

$limit  = (int)($config['facebook']['limit'] ?? 25);
$hours  = (int)($config['facebook']['fetch_window_hours'] ?? 72);
$since  = time() - $hours * 3600;

$predictUrl = $config['ml']['predict_url'] ?? null; // nếu muốn chấm điểm luôn

function fb_get($url, $params = [])
{
    $ch = curl_init($url . ($params ? ('?' . http_build_query($params)) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body, $err];
}

function call_predict($url, $text)
{
    if (!$url) return [null, null];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['text' => $text]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        $j = json_decode($body, true);
        return [$j['risk_score'] ?? null, $j['label'] ?? null];
    }
    return [null, null];
}

$params = [
    'fields'       => 'id,message,permalink_url,created_time',
    'limit'        => $limit,
    'since'        => $since,
    'access_token' => $token,
];

$endpoint = "https://graph.facebook.com/{$graphVer}/{$pageId}/posts";

do {
    [$code, $body, $err] = fb_get($endpoint, $params);
    if ($code < 200 || $code >= 300) {
        fwrite(STDERR, "Graph error: HTTP $code $err $body\n");
        break;
    }

    $json = json_decode($body, true);
    $data = $json['data'] ?? [];
    foreach ($data as $it) {
        $fbId    = $it['id']; // <-- CHÍNH LÀ FB POST ID (pageid_postid)
        $message = $it['message'] ?? null;
        $plink   = $it['permalink_url'] ?? null;
        $ctime   = !empty($it['created_time']) ? date('Y-m-d H:i:s', strtotime($it['created_time'])) : null;

        // Gọi model để có risk/label (tuỳ chọn)
        [$risk, $label] = call_predict($predictUrl, (string)$message);

        // Lưu DB (idempotent nhờ UNIQUE KEY fb_post_id)
        $stmt = $pdo->prepare("
  INSERT INTO posts (fb_post_id, message, permalink_url, created_time, last_seen, risk_score, label, action_taken)
  VALUES (:fbid, :msg, :plink, :ctime, NOW(), :risk, :label, COALESCE(action_taken, 'none'))
  ON DUPLICATE KEY UPDATE
    message       = VALUES(message),
    permalink_url = VALUES(permalink_url),
    created_time  = COALESCE(posts.created_time, VALUES(created_time)),
    last_seen     = VALUES(last_seen),
    risk_score    = COALESCE(VALUES(risk_score), posts.risk_score),
    label         = COALESCE(VALUES(label), posts.label)
");
        $stmt->execute([
            ':fbid'  => $fbId,
            ':msg'   => $message,
            ':plink' => $plink,
            ':ctime' => $ctime,
            ':risk'  => $risk,
            ':label' => $label,
        ]);

        echo "Saved: $fbId\n";
    }

    // Phân trang (nếu muốn đi tiếp nhiều trang)
    $next = $json['paging']['next'] ?? null;
    if ($next) {
        $endpoint = $next;
        $params = [];
    } // next đã là URL đầy đủ
} while (!empty($next));
