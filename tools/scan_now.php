<?php
// tools/scan_now.php  (bản robust + debug)
// Chấm điểm comment gần đây, tự lọc theo created_time (không dùng 'since' của Graph)

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/fb_graph.php';
require_once __DIR__ . '/../lib/openai_client.php';

function aa_upsert(string $objectId, string $objectType, string $action, int $risk, string $reason = '', ?string $responseText = null): void
{
    $pdo = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO auto_actions(object_id,object_type,action,risk,reason,response_text)
                VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                  risk = GREATEST(risk, VALUES(risk)),
                  reason = VALUES(reason),
                  response_text = COALESCE(VALUES(response_text), response_text),
                  created_at = CURRENT_TIMESTAMP';
    } else {
        $sql = 'INSERT INTO auto_actions(object_id,object_type,action,risk,reason,response_text)
                VALUES (?,?,?,?,?,?)
                ON CONFLICT(object_id, action) DO UPDATE SET
                  risk = MAX(auto_actions.risk, excluded.risk),
                  reason = excluded.reason,
                  response_text = COALESCE(excluded.response_text, auto_actions.response_text),
                  created_at = CURRENT_TIMESTAMP';
    }
    $pdo->prepare($sql)->execute([$objectId, $objectType, $action, $risk, $reason, $responseText]);
}

$window    = (int)($argv[1] ?? 30);                       // phút
$DEBUG     = in_array('--debug', $argv, true) || in_array('-d', $argv, true);
$threshold = (int)envv('AUTO_RISK_THRESHOLD', 60);
$doHide    = filter_var(envv('AUTO_ACTION_HIDE', 'false'), FILTER_VALIDATE_BOOLEAN);
$doReply   = filter_var(envv('AUTO_REPLY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$prefix    = envv('AUTO_REPLY_PREFIX', '[BQT]');
$pageId    = envv('FB_PAGE_ID');

$sinceUnix = time() - $window * 60;
$out = ['scanned' => 0, 'high_risk' => 0, 'replied' => 0, 'hidden' => 0, 'skipped' => 0, 'posts' => 0];

if ($DEBUG) {
    fwrite(STDERR, "PAGE_ID={$pageId}, window={$window}m, since=" . gmdate('c', $sinceUnix) . PHP_EOL);
}

try {
    // 1) Lấy 25 post mới nhất (không lọc)
    $postsRes = fb_api("/$pageId/posts", [
        'limit'  => 25,
        'fields' => 'id,created_time,permalink_url'
    ]);
    $posts = $postsRes['data'] ?? [];
    $out['posts'] = count($posts);
    if ($DEBUG) fwrite(STDERR, "Posts fetched: " . count($posts) . PHP_EOL);
} catch (Exception $e) {
    echo json_encode(['error' => 'Graph posts: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

// 2) Với mỗi post: lấy comment (phân trang), rồi tự lọc theo created_time
foreach ($posts as $p) {
    $pid = $p['id'];
    $postTime = strtotime($p['created_time'] ?? '1970-01-01');
    if ($DEBUG) fwrite(STDERR, "Post $pid @ " . ($p['created_time'] ?? '?') . PHP_EOL);

    $after = null;
    do {
        $params = [
            'filter' => 'stream',
            'limit'  => 100,
            'fields' => 'id,from{id,name},message,created_time'
        ];
        if ($after) $params['after'] = $after;

        try {
            $commentsRes = fb_api("/$pid/comments", $params);
        } catch (Exception $e) {
            if ($DEBUG) fwrite(STDERR, "  comments error: " . $e->getMessage() . PHP_EOL);
            break;
        }

        $chunk = $commentsRes['data'] ?? [];
        if ($DEBUG) fwrite(STDERR, "  chunk comments: " . count($chunk) . PHP_EOL);

        foreach ($chunk as $c) {
            $cid  = $c['id'];
            $msg  = trim($c['message'] ?? '');
            $from = $c['from']['id'] ?? '';
            $ct   = strtotime($c['created_time'] ?? '1970-01-01');

            // lọc theo thời gian
            if ($ct < $sinceUnix) continue;
            if ($msg === '')     continue;

            $isFromPage = ($pageId && $from === $pageId);

            // nếu đã hành động thì thôi
            $chk = db()->prepare('SELECT 1 FROM auto_actions WHERE object_id=? AND action IN ("replied","hidden") LIMIT 1');
            $chk->execute([$cid]);
            if ($chk->fetchColumn()) {
                $out['skipped']++;
                continue;
            }

            $out['scanned']++;
            if ($DEBUG) fwrite(STDERR, "    + scan $cid by {$c['from']['name']} @ {$c['created_time']} :: " . mb_substr($msg, 0, 60) . PHP_EOL);

            $ar   = analyze_text_with_schema($msg);
            $risk = (int)($ar['overall_risk'] ?? 0);

            // luôn log điểm (để UI tổng hợp hiển thị)
            aa_upsert($cid, 'comment', 'score', $risk, 'cli_scan');

            if ($risk < $threshold) {
                aa_upsert($cid, 'comment', 'skipped', $risk, 'under_threshold');
                continue;
            }

            $out['high_risk']++;

            // Mẫu reply (nếu bật)
            $labels = $ar['labels'] ?? [];
            if (!empty($labels['scam_phishing'])) {
                $tpl = "Cảnh báo: Có dấu hiệu mời chào/lừa đảo. Vui lòng cảnh giác, không cung cấp thông tin cá nhân hay chuyển tiền.";
            } elseif (!empty($labels['hate_speech'])) {
                $tpl = "Nhắc nhở: Xin giữ trao đổi văn minh, tránh lời lẽ xúc phạm/công kích.";
            } elseif (!empty($labels['misinformation'])) {
                $tpl = "Lưu ý: Nội dung có thể chưa đủ nguồn xác thực. Vui lòng bổ sung đường dẫn đến nguồn tin cậy.";
            } else {
                $tpl = "Lưu ý: Nội dung có rủi ro gây hiểu nhầm. Vui lòng kiểm chứng và sử dụng ngôn từ phù hợp.";
            }
            $reply = trim($prefix . ' ' . $tpl);

            // Không reply/hide comment của chính Page
            if (!$isFromPage && $doReply) {
                fb_comment($cid, $reply);
                aa_upsert($cid, 'comment', 'replied', $risk, 'cli_scan', $reply);
                $out['replied']++;
                usleep(600000);
            }
            if (!$isFromPage && $doHide) {
                fb_hide_comment($cid, true);
                aa_upsert($cid, 'comment', 'hidden', $risk, 'cli_scan');
                $out['hidden']++;
                usleep(600000);
            }
        }

        $after = $commentsRes['paging']['cursors']['after'] ?? null;
    } while ($after);
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
