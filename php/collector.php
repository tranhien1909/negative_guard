<?php
// php/collector.php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Bangkok');

$pdo   = db_connect($config['db']);
$fbCfg = $config['facebook'];

if (empty($fbCfg['page_id']) || empty($fbCfg['page_access_token'])) {
    fail("Missing facebook.page_id or facebook.page_access_token in config.php");
}

$base = 'https://graph.facebook.com/v21.0';
$limit = (int)($fbCfg['limit'] ?? 25);
$since = compute_since($pdo, (int)($fbCfg['fetch_window_hours'] ?? 24));

$params = [
    'access_token' => $fbCfg['page_access_token'],
    'fields'       => 'id,from,message,created_time,permalink_url',
    'limit'        => $limit,
    'since'        => $since,
];

$url = $base . '/' . rawurlencode($fbCfg['page_id']) . '/posts?' . http_build_query($params);

$total = 0;
while ($url) {
    [$code, $data] = http_json($url);
    if ($code >= 300) fail("HTTP $code: " . json_encode($data, JSON_UNESCAPED_UNICODE));

    foreach (($data['data'] ?? []) as $p) {
        store_post($pdo, $p);
        $total++;

        // (tùy chọn) kéo comment cho mỗi post
        if (!empty($fbCfg['fetch_comments'])) {
            collect_comments($pdo, $p['id'], $fbCfg);
        }
    }
    $url = $data['paging']['next'] ?? null;
}

log_line("Fetched $total posts");
exit(0);

// ------------- helpers -------------

function compute_since(PDO $pdo, int $hours): int
{
    $max = $pdo->query("SELECT MAX(created_at) FROM posts WHERE platform='facebook'")->fetchColumn();
    if ($max) return max(0, strtotime($max) - 3600); // backoff 1h
    return time() - $hours * 3600;
}

function store_post(PDO $pdo, array $p): void
{
    $id   = $p['id'] ?? '';
    if (!$id) return;
    $author = $p['from']['name'] ?? null;
    $msg    = $p['message'] ?? '';
    $created = !empty($p['created_time']) ? date('Y-m-d H:i:s', strtotime($p['created_time'])) : null;
    $link   = $p['permalink_url'] ?? null;

    $sql = "INSERT INTO posts(platform, external_id, author_name, content, url, created_at, fetched_at, status, kind)
          VALUES ('facebook', :id, :author, :content, :url, :created_at, :fetched_at, 'normal', 'post')
          ON DUPLICATE KEY UPDATE author_name=VALUES(author_name), content=VALUES(content),
                                  url=VALUES(url), created_at=VALUES(created_at), fetched_at=VALUES(fetched_at)";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':id' => $id,
        ':author' => $author,
        ':content' => $msg,
        ':url' => $link,
        ':created_at' => $created,
        ':fetched_at' => date('Y-m-d H:i:s'),
    ]);
}

function collect_comments(PDO $pdo, string $postId, array $fbCfg): void
{
    $base = 'https://graph.facebook.com/v21.0';
    $params = [
        'access_token' => $fbCfg['page_access_token'],
        'limit' => (int)($fbCfg['comments_limit'] ?? 50),
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
    $id   = $c['id'] ?? '';
    if (!$id) return;
    $author = $c['from']['name'] ?? null;
    $msg    = $c['message'] ?? '';
    $created = !empty($c['created_time']) ? date('Y-m-d H:i:s', strtotime($c['created_time'])) : null;
    $link   = $c['permalink_url'] ?? null; // có thể null từ v20+

    $sql = "INSERT INTO posts(platform, external_id, parent_external_id, author_name, content, url, created_at, fetched_at, status, kind)
          VALUES ('facebook', :id, :parent, :author, :content, :url, :created_at, :fetched_at, 'normal', 'comment')
          ON DUPLICATE KEY UPDATE author_name=VALUES(author_name), content=VALUES(content),
                                  url=VALUES(url), created_at=VALUES(created_at), fetched_at=VALUES(fetched_at)";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':id' => $id,
        ':parent' => $postId,
        ':author' => $author,
        ':content' => $msg,
        ':url' => $link,
        ':created_at' => $created,
        ':fetched_at' => date('Y-m-d H:i:s'),
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
