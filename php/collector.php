<?php
// file collectior.php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Bangkok');

// tách auth và options cho rõ
$auth = $config['fb'];           // page_id, page_access_token, graph_version
$opts = $config['facebook'] ?? []; // fetch_window_hours, limit, fetch_comments,...

if (empty($auth['page_id']) || empty($auth['page_access_token'])) {
    fail("Missing fb.page_id or fb.page_access_token in config.php");
}

$version = $auth['graph_version'] ?? 'v23.0';
$base = "https://graph.facebook.com/$version";

$limit = (int)($opts['limit'] ?? 25);
$since = compute_since($pdo, (int)($opts['fetch_window_hours'] ?? 24));

$params = [
    'access_token' => $auth['page_access_token'],
    'fields'       => 'id,from,message,created_time,permalink_url',
    'limit'        => $limit,
    'since'        => $since,
];

$url = $base . '/' . rawurlencode($auth['page_id']) . '/posts?' . http_build_query($params);

$total = 0;
while ($url) {
    [$code, $data] = http_json($url);
    if ($code >= 300) fail("HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE));

    foreach (($data['data'] ?? []) as $p) {
        store_post($pdo, $p);
        $total++;

        // (tùy chọn) kéo comment cho mỗi post
        if (!empty($opts['fetch_comments'])) {
            collect_comments($pdo, $p['id'], $base, $auth['page_access_token'], $opts);
        }
    }
    $url = $data['paging']['next'] ?? null;
}

log_line("Fetched $total posts");
exit(0);

// ------------- helpers -------------

function compute_since(PDO $pdo, int $hours): int
{
    $max = $pdo->query("SELECT MAX(created_time) FROM posts")->fetchColumn();
    if ($max) return max(0, strtotime($max) - 3600); // backoff 1h
    return time() - $hours * 3600;
}

function store_post(PDO $pdo, array $p): void
{
    $fbId    = $p['id'] ?? '';
    if (!$fbId) return;
    $fromId  = $p['from']['id'] ?? null;
    $message = $p['message'] ?? '';
    $created = !empty($p['created_time']) ? date('Y-m-d H:i:s', strtotime($p['created_time'])) : null;
    $link    = $p['permalink_url'] ?? null;

    $sql = "INSERT INTO posts (fb_post_id, message, created_time, permalink_url, from_id, last_seen)
            VALUES (:fbid, :message, :created_time, :plink, :from_id, NOW())
            ON DUPLICATE KEY UPDATE
              message = VALUES(message),
              created_time = COALESCE(posts.created_time, VALUES(created_time)),
              permalink_url = VALUES(permalink_url),
              from_id = VALUES(from_id),
              last_seen = NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':fbid' => $fbId,
        ':message' => $message,
        ':created_time' => $created,
        ':plink' => $link,
        ':from_id' => $fromId,
    ]);
}


function collect_comments(PDO $pdo, string $postId, string $base, string $pageToken, array $opts): void
{
    $params = [
        'access_token' => $pageToken,
        'limit' => (int)($opts['comments_limit'] ?? 50),
        'filter' => 'stream',
        'fields' => 'id,from,message,created_time,permalink_url',
    ];
    $url = $base . '/' . rawurlencode($postId) . '/comments?' . http_build_query($params);
    $n = 0;
    while ($url) {
        [$code, $data] = http_json($url);
        if ($code >= 300) {
            log_line("comments($postId) HTTP $code " . json_encode($data));
            break;
        }
        foreach (($data['data'] ?? []) as $c) {
            store_comment($pdo, $postId, $c);
            $n++;
        }
        $url = $data['paging']['next'] ?? null;
    }
    if ($n) log_line("Post $postId: stored $n comments");
}

function store_comment(PDO $pdo, string $postId, array $c): void
{
    $id      = $c['id'] ?? '';
    if (!$id) return;
    $fromId  = $c['from']['id']   ?? null;
    $message = $c['message']      ?? '';
    $created = !empty($c['created_time']) ? date('Y-m-d H:i:s', strtotime($c['created_time'])) : null;
    $link    = $c['permalink_url'] ?? null;

    $meta = json_encode([
        'permalink_url' => $link,
        'kind' => 'comment',
        'parent_id' => $postId
    ], JSON_UNESCAPED_UNICODE);

    $sql = "INSERT INTO posts (id, message, created_time, from_id, meta)
            VALUES (:id, :message, :created_time, :from_id, :meta)
            ON DUPLICATE KEY UPDATE
              message = VALUES(message),
              created_time = VALUES(created_time),
              from_id = VALUES(from_id),
              meta = VALUES(meta),
              updated_at = CURRENT_TIMESTAMP";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':id' => $id,
        ':message' => $message,
        ':created_time' => $created,
        ':from_id' => $fromId,
        ':meta' => $meta,
    ]);
}


function http_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $out = curl_exec($ch);
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
