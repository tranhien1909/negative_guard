<?php
// php/collector.php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Bangkok');

$auth  = $config['fb'] ?? [];
$opts  = $config['facebook'] ?? [];
$mlUrl = $config['ml']['predict_url'] ?? '';
$doInlinePredict = (($_ENV['PREDICT_INLINE'] ?? '0') === '1');

if (empty($auth['page_id']) || empty($auth['page_access_token'])) {
    fail("Missing fb.page_id or fb.page_access_token in config.php");
}
$graphVersion = $auth['graph_version'] ?? 'v21.0';
$base         = 'https://graph.facebook.com/' . $graphVersion;
$pageId       = $auth['page_id'];
$token        = $auth['page_access_token'];

$limit = (int)($opts['limit'] ?? 25);
$since = compute_since($pdo, (int)($opts['fetch_window_hours'] ?? 24));

$params = [
    'access_token' => $token,
    'fields'       => 'id,from,message,created_time,permalink_url',
    'limit'        => $limit,
    'since'        => $since,
];

$url = $base . '/' . rawurlencode($pageId) . '/posts?' . http_build_query($params);

$totalPosts = 0;
while ($url) {
    [$code, $data] = http_json($url);
    if ($code >= 300) fail("HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE));

    foreach (($data['data'] ?? []) as $p) {
        $fbId    = $p['id'] ?? '';
        if (!$fbId) continue;
        $fromId  = $p['from']['id'] ?? null;
        $message = $p['message'] ?? '';
        $created = !empty($p['created_time']) ? date('Y-m-d H:i:s', strtotime($p['created_time'])) : null;
        $plink   = $p['permalink_url'] ?? null;

        store_post($pdo, $fbId, $fromId, $message, $plink, $created);

        // CHẤM ĐIỂM INLINE (nếu bật)
        if ($doInlinePredict && $mlUrl && $message !== '') {
            [$risk, $label] = call_predict($mlUrl, $message);
            if ($risk !== null) {
                update_post_score($pdo, $fbId, $risk, $label);
            }
        }

        // KÉO COMMENTS (nếu bật)
        if (!empty($opts['fetch_comments'])) {
            collect_comments($pdo, $fbId, $base, $token, (int)($opts['comments_limit'] ?? 50), $mlUrl, $doInlinePredict);
        }

        $totalPosts++;
    }
    $url = $data['paging']['next'] ?? null;
}

log_line("Fetched $totalPosts posts");
exit(0);

/* ================= helpers ================= */

function compute_since(PDO $pdo, int $hours): int
{
    $postTs = safe_max_ts($pdo, 'posts');
    $cmtTs  = safe_max_ts($pdo, 'comments');
    $latest = max($postTs ?? 0, $cmtTs ?? 0);
    return $latest > 0 ? max(0, $latest - 3600) : (time() - $hours * 3600);
}
function safe_max_ts(PDO $pdo, string $table): ?int
{
    try {
        $v = $pdo->query("SELECT MAX(created_time) FROM `{$table}`")->fetchColumn();
        return $v ? strtotime($v) : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function store_post(PDO $pdo, string $fbId, ?string $fromId, string $message, ?string $plink, ?string $created): void
{
    $sql = "INSERT INTO posts (fb_post_id, from_id, message, permalink_url, created_time, last_seen)
            VALUES (:id, :from_id, :message, :plink, :created, NOW())
            ON DUPLICATE KEY UPDATE
              from_id       = VALUES(from_id),
              message       = VALUES(message),
              permalink_url = VALUES(permalink_url),
              created_time  = COALESCE(posts.created_time, VALUES(created_time)),
              last_seen     = NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $fbId, ':from_id' => $fromId, ':message' => $message, ':plink' => $plink, ':created' => $created]);
}

function update_post_score(PDO $pdo, string $fbId, float $risk, ?string $label): void
{
    $pdo->prepare("UPDATE posts SET risk_score=:r, label=:l, last_analysis_time=NOW() WHERE fb_post_id=:id")
        ->execute([':r' => $risk, ':l' => $label, ':id' => $fbId]);
}

function collect_comments(PDO $pdo, string $postId, string $base, string $pageToken, int $limit, string $mlUrl, bool $doInlinePredict): void
{
    $params = [
        'access_token' => $pageToken,
        'limit'        => $limit,
        'filter'       => 'stream',
        'fields'       => 'id,from,message,created_time,permalink_url',
    ];
    $url = $base . '/' . rawurlencode($postId) . '/comments?' . http_build_query($params);
    $n = 0;
    while ($url) {
        [$code, $data] = http_json($url);
        if ($code >= 300) {
            log_line("comments($postId) HTTP $code " . json_encode($data, JSON_UNESCAPED_UNICODE));
            break;
        }

        foreach (($data['data'] ?? []) as $c) {
            $cid     = $c['id'] ?? '';
            if (!$cid) continue;
            $fromId  = $c['from']['id'] ?? null;
            $message = $c['message'] ?? '';
            $created = !empty($c['created_time']) ? date('Y-m-d H:i:s', strtotime($c['created_time'])) : null;
            $plink   = $c['permalink_url'] ?? null;

            store_comment($pdo, $cid, $postId, $fromId, $message, $plink, $created);

            if ($doInlinePredict && $mlUrl && $message !== '') {
                [$risk, $label] = call_predict($mlUrl, $message);
                if ($risk !== null) update_comment_score($pdo, $cid, $risk, $label);
            }
            $n++;
        }
        $url = $data['paging']['next'] ?? null;
    }
    if ($n) log_line("Post $postId: stored $n comments");
}


function store_comment(PDO $pdo, string $commentId, string $parentPostId, ?string $fromId, string $message, ?string $plink, ?string $created): void
{
    $sql = "INSERT INTO comments (fb_comment_id, parent_fb_post_id, from_id, message, permalink_url, created_time, last_seen)
            VALUES (:id, :parent, :from_id, :message, :plink, :created, NOW())
            ON DUPLICATE KEY UPDATE
              parent_fb_post_id = VALUES(parent_fb_post_id),
              from_id           = VALUES(from_id),
              message           = VALUES(message),
              permalink_url     = VALUES(permalink_url),
              created_time      = COALESCE(comments.created_time, VALUES(created_time)),
              last_seen         = NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $commentId, ':parent' => $parentPostId, ':from_id' => $fromId, ':message' => $message, ':plink' => $plink, ':created' => $created]);
}

function update_comment_score(PDO $pdo, string $cid, float $risk, ?string $label): void
{
    $pdo->prepare("UPDATE comments SET risk_score=:r, label=:l, last_analysis_time=NOW() WHERE fb_comment_id=:id")
        ->execute([':r' => $risk, ':l' => $label, ':id' => $cid]);
}

function call_predict(string $url, string $text): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 300) return [null, null];
    $j = json_decode($body, true);
    return [isset($j['risk_score']) ? (float)$j['risk_score'] : null, $j['label'] ?? null];
}

function http_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $out  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($out === false) {
        $err = curl_error($ch);
        curl_close($ch);
        log_line("cURL error: $err");
        return [599, ['error' => ['message' => $err]]];
    }
    curl_close($ch);
    $j = json_decode($out, true);
    return [$code, $j ?: []];
}
function log_line(string $s): void
{
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    file_put_contents($dir . '/collector.log', '[' . date('c') . "] $s\n", FILE_APPEND);
}
function fail(string $msg): void
{
    log_line("FATAL: $msg");
    fwrite(STDERR, $msg . "\n");
    exit(1);
}
