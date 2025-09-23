<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fb_graph.php';
send_security_headers();
require_admin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request/CSRF']);
    exit;
}

$action = $_POST['action'] ?? '';
try {
    switch ($action) {
        case 'publish_post':
            $msg = trim($_POST['message'] ?? '');
            if (!$msg) throw new Exception('Thiếu nội dung');
            $res = fb_publish_post($msg);
            echo json_encode($res);
            break;

        case 'comment':
            $id  = trim($_POST['id'] ?? '');
            $msg = trim($_POST['message'] ?? '');
            if (!$id || !$msg) throw new Exception('Thiếu id/message');
            echo json_encode(fb_comment($id, $msg));
            break;

        case 'hide_comment':
            $id = trim($_POST['id'] ?? '');
            $hide = ($_POST['hide'] ?? '1') === '1';
            if (!$id) throw new Exception('Thiếu id');
            echo json_encode(fb_hide_comment($id, $hide));
            break;

        case 'delete_comment':
            $id = trim($_POST['id'] ?? '');
            if (!$id) throw new Exception('Thiếu id');
            echo json_encode(fb_delete_comment($id));
            break;

        case 'scan_now':
            // quét nhanh trong X phút (mặc định 30)
            $window = max(5, (int)($_POST['window'] ?? 30));
            $threshold = (int) envv('AUTO_RISK_THRESHOLD', 60);
            $doHide   = filter_var(envv('AUTO_ACTION_HIDE', 'true'), FILTER_VALIDATE_BOOLEAN);
            $doReply  = filter_var(envv('AUTO_REPLY_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
            $prefix   = envv('AUTO_REPLY_PREFIX', '[BQT]');
            $sinceUnix = time() - $window * 60;

            // helper local
            $scanRes = ['scanned' => 0, 'high_risk' => 0, 'replied' => 0, 'hidden' => 0, 'skipped' => 0];
            $pageId = envv('FB_PAGE_ID');

            // lấy post & comment gần đây
            $posts = fb_get_page_posts_since($sinceUnix, 20);
            foreach (($posts['data'] ?? []) as $p) {
                $comments = fb_get_post_comments_since($p['id'], $sinceUnix, 50);
                foreach (($comments['data'] ?? []) as $c) {
                    $cid = $c['id'];
                    $from = $c['from']['id'] ?? '';
                    $msg = trim($c['message'] ?? '');
                    if (!$msg) continue;
                    if ($pageId && $from === $pageId) continue; // bỏ comment của chính Page

                    // đã xử lý?
                    $chk = db()->prepare('SELECT action FROM auto_actions WHERE object_id=? AND action IN ("replied","hidden") LIMIT 1');
                    $chk->execute([$cid]);
                    if ($chk->fetch()) {
                        $scanRes['skipped']++;
                        continue;
                    }

                    $scanRes['scanned']++;
                    $res = analyze_text_with_schema($msg);
                    $risk = (int)($res['overall_risk'] ?? 0);

                    if ($risk < $threshold) {
                        $ins = db()->prepare('INSERT IGNORE INTO auto_actions(object_id,object_type,action,risk,reason) VALUES (?,?,?,?,?)');
                        $ins->execute([$cid, 'comment', 'skipped', $risk, 'under_threshold']);
                        continue;
                    }
                    $scanRes['high_risk']++;

                    $labels = $res['labels'] ?? [];
                    if (!empty($labels['scam_phishing'])) {
                        $tpl = "Cảnh báo: Có dấu hiệu mời chào/lừa đảo. Vui lòng cảnh giác, không cung cấp thông tin cá nhân hay chuyển tiền.";
                    } elseif (!empty($labels['hate_speech'])) {
                        $tpl = "Nhắc nhở: Xin giữ trao đổi văn minh, tránh lời lẽ xúc phạm/công kích.";
                    } elseif (!empty($labels['misinformation'])) {
                        $tpl = "Lưu ý: Nội dung có thể chưa đủ nguồn xác thực. Vui lòng bổ sung đường dẫn đến nguồn tin cậy.";
                    } else {
                        $tpl = "Lưu ý: Nội dung có rủi ro gây hiểu nhầm. Vui lòng kiểm chứng và sử dụng ngôn từ phù hợp.";
                    }
                    $reply = $prefix . ' ' . $tpl;

                    if ($doReply) {
                        fb_comment($cid, $reply);
                        $ins = db()->prepare('INSERT IGNORE INTO auto_actions(object_id,object_type,action,risk,reason,response_text) VALUES (?,?,?,?,?,?)');
                        $ins->execute([$cid, 'comment', 'replied', $risk, 'scan_now', $reply]);
                        $scanRes['replied']++;
                        usleep(600000);
                    }
                    if ($doHide) {
                        fb_hide_comment($cid, true);
                        $ins = db()->prepare('INSERT IGNORE INTO auto_actions(object_id,object_type,action,risk,reason) VALUES (?,?,?,?,?)');
                        $ins->execute([$cid, 'comment', 'hidden', $risk, 'scan_now']);
                        $scanRes['hidden']++;
                        usleep(600000);
                    }
                }
            }
            echo json_encode($scanRes);
            break;

        default:
            throw new Exception('Hành động không hợp lệ');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
