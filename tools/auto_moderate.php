<?php
// CLI: php tools/auto_moderate.php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/fb_graph.php';
require_once __DIR__ . '/../lib/openai_client.php';

$pdo = db();

function already_done($commentId, $action)
{
    $st = db()->prepare('SELECT 1 FROM auto_actions WHERE object_id=? AND action=? LIMIT 1');
    $st->execute([$commentId, $action]);
    return (bool)$st->fetchColumn();
}
function mark_done($commentId, $action, $risk, $reason, $reply = null)
{
    $st = db()->prepare('INSERT IGNORE INTO auto_actions(object_id,object_type,action,risk,reason,response_text) VALUES (?,?,?,?,?,?)');
    $st->execute([$commentId, 'comment', $action, $risk, $reason, $reply]);
}

$winMin   = (int) envv('AUTO_SCAN_WINDOW_MINUTES', 60);
$maxBatch = (int) envv('AUTO_MAX_COMMENTS_PER_RUN', 50);
$threshold = (int) envv('AUTO_RISK_THRESHOLD', 60);
$doHide   = filter_var(envv('AUTO_ACTION_HIDE', 'true'), FILTER_VALIDATE_BOOLEAN);
$doReply  = filter_var(envv('AUTO_REPLY_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
$prefix   = envv('AUTO_REPLY_PREFIX', '[BQT]');

$since = time() - $winMin * 60;
$processed = 0;

echo "AutoModerate: window {$winMin}m, threshold {$threshold}, hide=" . ($doHide ? 'Y' : 'N') . ", reply=" . ($doReply ? 'Y' : 'N') . "\n";

try {
    $posts = fb_get_page_posts_since($since, 25);
    foreach (($posts['data'] ?? []) as $p) {
        $postId = $p['id'];
        $comments = fb_get_post_comments_since($postId, $since, 100);
        foreach (($comments['data'] ?? []) as $c) {
            if ($processed >= $maxBatch) {
                echo "Reached batch limit\n";
                break 2;
            }

            $cid   = $c['id'];
            $from  = $c['from']['id'] ?? '';
            $msg   = trim($c['message'] ?? '');
            if ($msg === '') continue;

            // bỏ qua comment của chính page
            $pageId = envv('FB_PAGE_ID');
            if ($from && $pageId && $from === $pageId) continue;

            // bỏ qua nếu đã xử lý
            if (already_done($cid, 'replied') || already_done($cid, 'hidden')) continue;

            // phân tích
            $res = analyze_text_with_schema($msg);
            $risk = (int)($res['overall_risk'] ?? 0);

            // chỉ hành động nếu vượt ngưỡng
            if ($risk < $threshold) {
                mark_done($cid, 'skipped', $risk, 'under_threshold');
                continue;
            }

            // quyết định nội dung trả lời (tuỳ nhãn bật)
            $labels = $res['labels'] ?? [];
            $template = '';
            if (!empty($labels['scam_phishing'])) {
                $template = "Cảnh báo: Có dấu hiệu mời chào/lừa đảo hoặc liên hệ ngoài nền tảng. Vui lòng cảnh giác, không cung cấp thông tin cá nhân hay chuyển tiền. Nếu có bằng chứng xác thực, vui lòng chia sẻ nguồn.";
            } elseif (!empty($labels['hate_speech'])) {
                $template = "Nhắc nhở: Xin giữ trao đổi văn minh, tránh lời lẽ xúc phạm/công kích cá nhân. Hãy tập trung vào thông tin và nguồn xác thực.";
            } elseif (!empty($labels['misinformation'])) {
                $template = "Lưu ý: Nội dung có thể chưa đủ nguồn xác thực. Vui lòng bổ sung đường dẫn đến nguồn tin cậy (cơ quan báo chí chính thống, công bố chính thức).";
            } else {
                $template = "Lưu ý: Nội dung có rủi ro gây hiểu nhầm. Vui lòng kiểm chứng và sử dụng ngôn từ phù hợp.";
            }
            $reply = $prefix . ' ' . $template;

            // hành động
            try {
                if ($doReply && !already_done($cid, 'replied')) {
                    fb_comment($cid, $reply);
                    mark_done($cid, 'replied', $risk, 'auto_reply', $reply);
                    echo "Replied to $cid (risk=$risk)\n";
                    $processed++;
                    usleep(600000); // 0.6s tránh rate limit
                }
                if ($doHide && !already_done($cid, 'hidden')) {
                    fb_hide_comment($cid, true);
                    mark_done($cid, 'hidden', $risk, 'auto_hide');
                    echo "Hidden   $cid (risk=$risk)\n";
                    $processed++;
                    usleep(600000);
                }
            } catch (Exception $eAct) {
                mark_done($cid, 'skipped', $risk, 'action_error: ' . substr($eAct->getMessage(), 0, 200));
                echo "Action error on $cid: " . $eAct->getMessage() . "\n";
            }
        }
    }
} catch (Exception $e) {
    echo "Fatal: " . $e->getMessage() . "\n";
}

echo "Done. processed=$processed\n";
