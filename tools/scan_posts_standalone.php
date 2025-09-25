<?php
// tools/scan_posts_standalone.php
// Chạy: php -d display_errors=1 tools/scan_posts_standalone.php 60
//  -> 60 = số phút gần nhất cần quét (mặc định 60)

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/fb_graph.php';
require_once __DIR__ . '/../lib/openai_client.php';

$windowMin = isset($argv[1]) ? (int)$argv[1] : (int)envv('AUTO_POST_WINDOW_MINUTES', 60);
$threshold = (int)envv('AUTO_POST_RISK_THRESHOLD', 65);
$enabled   = filter_var(envv('AUTO_POST_WARN_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
$cooldown  = max(0, (int)envv('AUTO_POST_COOLDOWN_SECONDS', 35));
$prefix    = trim(envv('AUTO_POST_REPLY_PREFIX', '[Cảnh báo]'));

$sinceUnix = time() - max(5, $windowMin) * 60;
$stats = ['scanned' => 0, 'warned' => 0, 'skipped' => 0, 'errors' => 0, 'posts' => 0];

echo "[SCAN] window={$windowMin}min threshold={$threshold} enabled=" . ($enabled ? 'true' : 'false') . " cooldown={$cooldown}s\n";

// test token nhanh
try {
    $who = fb_api('/me', ['fields' => 'id,name']);
    echo "[TOKEN] /me => {$who['name']} ({$who['id']})\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Token check failed: " . $e->getMessage() . "\n");
}

try {
    $posts = fb_get_page_posts_since($sinceUnix, 50);
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] fb_get_page_posts_since: " . $e->getMessage() . "\n");
    $posts = [];
}
$items = $posts['data'] ?? [];
$stats['posts'] = count($items);
echo "[INFO] Posts fetched: {$stats['posts']}\n";

$pdo = db();
$ins = $pdo->prepare('INSERT IGNORE INTO auto_actions(object_id,object_type,action,risk,reason,response_text) VALUES (?,?,?,?,?,?)');

foreach ($items as $p) {
    $postId = $p['id'] ?? '';
    $msg = trim($p['message'] ?? '');

    if (!$postId || $msg === '') {
        $stats['skipped']++;
        continue;
    }

    // đã xử lý post này chưa?
    $chk = $pdo->prepare('SELECT 1 FROM auto_actions WHERE object_id=? AND object_type="post" AND action IN ("warned_post","skipped_post") LIMIT 1');
    $chk->execute([$postId]);
    if ($chk->fetch()) {
        $stats['skipped']++;
        continue;
    }

    $stats['scanned']++;
    echo "  - Analyze post {$postId} ... ";

    try {
        $ar   = analyze_text_with_schema($msg);
        $risk = (int)($ar['overall_risk'] ?? 0);
        echo "risk={$risk}\n";

        if ($risk < $threshold) {
            $ins->execute([$postId, 'post', 'skipped_post', $risk, 'under_threshold', '']);
            $stats['skipped']++;
            continue;
        }

        // chọn template
        $labels = $ar['labels'] ?? [];
        $tplScam = [
            "Nội dung có dấu hiệu mời chào/lừa đảo. Vui lòng cảnh giác, không cung cấp thông tin cá nhân hay chuyển tiền.",
            "Cảnh báo an toàn: Không chuyển tiền/đưa mã OTP cho bất kỳ ai.",
            "Lưu ý: Tránh click liên kết lạ, không chia sẻ thông tin nhạy cảm."
        ];
        $tplHate = [
            "Xin giữ trao đổi văn minh, tránh lời lẽ xúc phạm/công kích.",
            "Vui lòng tôn trọng người khác và sử dụng ngôn từ phù hợp.",
            "Nhắc nhở: Nội dung công kích/miệt thị có thể bị hạn chế hiển thị."
        ];
        $tplMisinfo = [
            "Nội dung có thể thiếu nguồn xác thực. Vui lòng bổ sung đường dẫn đáng tin cậy.",
            "Đề nghị kiểm chứng thông tin từ nguồn chính thống trước khi chia sẻ.",
            "Lưu ý: Hãy kiểm tra nguồn và ngày phát hành thông tin."
        ];
        $tplGeneric = [
            "Nội dung có rủi ro gây hiểu nhầm. Vui lòng kiểm chứng và sử dụng ngôn từ phù hợp.",
            "Bài viết có rủi ro cao theo hệ thống đánh giá. Vui lòng cân nhắc khi chia sẻ.",
            "Nhắc nhở an toàn: Kiểm chứng thông tin, tránh chia sẻ nội dung gây hiểu nhầm."
        ];

        if (!empty($labels['scam_phishing']))      $body = $tplScam[array_rand($tplScam)];
        elseif (!empty($labels['hate_speech']))     $body = $tplHate[array_rand($tplHate)];
        elseif (!empty($labels['misinformation']))  $body = $tplMisinfo[array_rand($tplMisinfo)];
        else                                        $body = $tplGeneric[array_rand($tplGeneric)];

        $reply = trim($prefix . ' ' . $body);

        if (!$enabled) {
            echo "    (disabled) would reply: {$reply}\n";
            $ins->execute([$postId, 'post', 'skipped_post', $risk, 'auto_post_disabled', $reply]);
            $stats['skipped']++;
            continue;
        }

        try {
            fb_comment($postId, $reply);
            $ins->execute([$postId, 'post', 'warned_post', $risk, 'standalone_scan', $reply]);
            $stats['warned']++;
            echo "    replied OK\n";
            if ($cooldown > 0) {
                sleep($cooldown);
            }
        } catch (Throwable $e) {
            $msgErr = $e->getMessage();
            echo "    reply ERROR: {$msgErr}\n";
            // spam 1446036
            if (strpos($msgErr, '1446036') !== false) {
                $ins->execute([$postId, 'post', 'skipped_post', $risk, 'spam_blocked', $reply]);
                $stats['skipped']++;
            } else {
                $ins->execute([$postId, 'post', 'skipped_post', $risk, 'error:' . substr($msgErr, 0, 60), $reply]);
                $stats['errors']++;
            }
        }
    } catch (Throwable $e) {
        echo "ERROR analyze: " . $e->getMessage() . "\n";
        $ins->execute([$postId, 'post', 'skipped_post', 0, 'analyze_error', '']);
        $stats['errors']++;
    }
}

echo "\nRESULT:\n" . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
