<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../db.php';
$config = require __DIR__ . '/../config.php';

$graphVer = $config['fb']['graph_version'];
$pageId   = $config['fb']['page_id'];
$token    = $config['fb']['page_access_token'];

$row_id     = $_POST['row_id']     ?? '';
$action     = $_POST['action']     ?? '';
$reason     = trim($_POST['reason'] ?? '');
$fb_post_id = $_POST['fb_post_id'] ?? ''; // có cũng tốt, không có cũng OK

if (!$row_id || !in_array($action, ['comment', 'post'], true)) {
    http_response_code(400);
    exit('Bad request: missing row_id or invalid action');
}
if ($reason === '') {
    http_response_code(400);
    exit('Reason is required');
}

/* ------------------- HTTP helpers (Graph API) ------------------- */
function fb_get($endpoint, $params)
{
    $ch = curl_init($endpoint . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body, $err];
}
function fb_post($endpoint, $params)
{
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 25,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body, $err];
}

/* --------------- Extractors: tìm fb_post_id từ DB/URL --------------- */
function extract_fb_post_id_from_row(array $row): ?string
{
    // 1) các cột có thể chứa FB object id sẵn
    $candidates = ['fb_post_id', 'graph_id', 'post_fbid', 'object_id', 'external_id', 'post_id', 'source_post_id'];
    foreach ($candidates as $c) {
        if (isset($row[$c]) && $row[$c]) return $row[$c];
    }
    // 2) nếu có page_id + post_id_short -> ghép {page_id}_{post_id_short}
    if (!empty($row['page_id']) && !empty($row['post_id_short'])) {
        return $row['page_id'] . '_' . $row['post_id_short'];
    }
    return null;
}

function extract_post_short_id_from_url(?string $url): ?string
{
    if (!$url) return null;
    // /posts/{id}
    if (preg_match('~/posts/(\d+)~', $url, $m)) return $m[1];
    // permalink.php?story_fbid={id}&id={pageid}
    if (preg_match('~story_fbid=(\d+)~', $url, $m)) return $m[1];
    // /videos/{id}
    if (preg_match('~/videos/(\d+)~', $url, $m)) return $m[1];
    // photo.php?fbid={id}
    if (preg_match('~[?&]fbid=(\d+)~', $url, $m)) return $m[1];
    return null;
}

/* --------- Fallback: gọi Graph để tìm id theo created_at/message --------- */
function search_post_id_from_graph(PDO $pdo, array $row, string $pageId, string $graphVer, string $token): ?string
{
    // Lấy mốc thời gian từ DB (nếu có)
    $created = $row['created_at'] ?? $row['created_time'] ?? null;
    $since = $until = null;
    if ($created) {
        $t = strtotime($created);
        if ($t) {
            $since = $t - 3 * 24 * 3600;
            $until = $t + 3 * 24 * 3600;
        } // +/- 3 ngày
    }

    $params = ['fields' => 'id,permalink_url,message,created_time', 'limit' => 100, 'access_token' => $token];
    if ($since) $params['since'] = $since;
    if ($until) $params['until'] = $until;

    [$code, $body, $err] = fb_get("https://graph.facebook.com/{$graphVer}/{$pageId}/posts", $params);
    if ($code < 200 || $code >= 300) return null;

    $json = json_decode($body, true);
    if (!isset($json['data']) || !is_array($json['data'])) return null;

    $msg  = isset($row['message']) ? trim($row['message']) : null;
    $url  = $row['permalink_url'] ?? $row['link'] ?? null;

    // Ưu tiên khớp permalink_url
    if ($url) {
        foreach ($json['data'] as $item) {
            if (!empty($item['permalink_url']) && strpos($url, $item['permalink_url']) !== false) {
                return $item['id']; // dạng {pageid}_{postid}
            }
        }
    }
    // Khớp theo message (so khớp thô)
    if ($msg) {
        $norm = function ($s) {
            return preg_replace('~\s+~', ' ', mb_strtolower(trim($s)));
        };
        $msg_norm = $norm($msg);
        foreach ($json['data'] as $item) {
            if (!empty($item['message'])) {
                $m2 = $norm($item['message']);
                if ($m2 === $msg_norm || (strlen($msg_norm) > 15 && strpos($m2, substr($msg_norm, 0, 15)) !== false)) {
                    return $item['id'];
                }
            }
        }
    }
    // Không tìm được
    return null;
}

/* ------------------------ Main flow ------------------------ */
try {
    // Lấy toàn bộ dòng theo row_id (tránh lỗi unknown column)
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $row_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit('Post not found (row_id=' . htmlspecialchars($row_id) . ')');
    }

    // 0) đã có fb_post_id trong form?
    $resolved = $fb_post_id ?: null;

    // 1) thử lấy từ các cột sẵn có
    if (!$resolved) $resolved = extract_fb_post_id_from_row($row);

    // 2) thử parse từ permalink/link trong DB
    if (!$resolved) {
        $permalink = $row['permalink_url'] ?? $row['link'] ?? null;
        $short = extract_post_short_id_from_url($permalink);
        if ($short) $resolved = $pageId . '_' . $short;
    }

    // 3) fallback: gọi Graph để tìm theo created_at/message, rồi lưu lại DB
    if (!$resolved && $action === 'comment') {
        $resolved = search_post_id_from_graph($pdo, $row, $pageId, $graphVer, $token);
        if ($resolved) {
            // lưu lại để lần sau đỡ tìm
            $col = 'fb_post_id';
            // thêm cột nếu chưa có? Ở đây chỉ thử update, nếu cột không tồn tại sẽ bỏ qua bằng try/catch
            try {
                $pdo->prepare("UPDATE posts SET fb_post_id = :fbid WHERE id = :id")->execute([':fbid' => $resolved, ':id' => $row_id]);
            } catch (\Throwable $e) {
                // ignore nếu bảng chưa có cột fb_post_id
            }
        }
    }

    if ($action === 'comment') {
        if (!$resolved) {
            http_response_code(400);
            exit('Không tìm được Facebook Post ID từ DB/URL/Graph. Hãy kiểm tra lại dữ liệu bài viết.');
        }
        $endpoint = "https://graph.facebook.com/{$graphVer}/{$resolved}/comments";
        $comment  = "⚠️ [BQT] Đính chính: " . $reason;

        [$code, $body, $err] = fb_post($endpoint, [
            'message' => $comment,
            'access_token' => $token,
        ]);

        $pdo->prepare(
            "INSERT INTO audit_logs (post_id, action_type, reason, payload, actor, http_code, error)
       VALUES (:pid,'manual_comment',:reason,:payload,'admin',:code,:err)"
        )->execute([
            ':pid'     => $resolved,
            ':reason'  => $reason,
            ':payload' => $body,
            ':code'    => $code,
            ':err'     => $err,
        ]);

        if ($code < 200 || $code >= 300) {
            http_response_code(502);
            echo "Facebook API error (comment): HTTP {$code} | {$err} | {$body}";
            exit;
        }
        $pdo->prepare("UPDATE posts SET action_taken='commented' WHERE id=:id")->execute([':id' => $row_id]);
    }

    if ($action === 'post') {
        $endpoint = "https://graph.facebook.com/{$graphVer}/{$pageId}/feed";
        $message  = "📢 Đính chính từ BQT: " . $reason;

        [$code, $body, $err] = fb_post($endpoint, [
            'message' => $message,
            'access_token' => $token,
        ]);

        $pdo->prepare(
            "INSERT INTO audit_logs (post_id, action_type, reason, payload, actor, http_code, error)
       VALUES (:pid,'manual_post',:reason,:payload,'admin',:code,:err)"
        )->execute([
            ':pid'     => $row_id, // log theo id nội bộ
            ':reason'  => $reason,
            ':payload' => $body,
            ':code'    => $code,
            ':err'     => $err,
        ]);

        if ($code < 200 || $code >= 300) {
            http_response_code(502);
            echo "Facebook API error (post): HTTP {$code} | {$err} | {$body}";
            exit;
        }
        $pdo->prepare("UPDATE posts SET action_taken='posted' WHERE id=:id")->execute([':id' => $row_id]);
    }

    header('Location: index.php');
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo "Unexpected error: " . $e->getMessage();
    exit;
}
