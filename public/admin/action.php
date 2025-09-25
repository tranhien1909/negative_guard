<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fb_graph.php';
require_once __DIR__ . '/../../lib/openai_client.php';
require_once __DIR__ . '/../../lib/db.php';

send_security_headers();
require_admin();
header('Content-Type: application/json; charset=utf-8');

// --- CHO PHÉP CLI ---
$isCli = (php_sapi_name() === 'cli') || defined('CLI_MODE');

// Chỉ gửi headers & bắt đăng nhập khi chạy qua web
if (!$isCli) {
    send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    require_admin();
}

// Kiểm tra CSRF: bỏ qua khi chạy CLI
if (
    !$isCli && (
        $_SERVER['REQUEST_METHOD'] !== 'POST' ||
        !csrf_verify($_POST['csrf'] ?? '')
    )
) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request/CSRF'], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ghi log auto_actions thống nhất.
 * - action: 'score' | 'replied' | 'hidden' | 'skipped' ...
 * - UPSERT: cập nhật risk=max(risk, new) và chạm created_at
 */
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
    } else { // sqlite
        $sql = 'INSERT INTO auto_actions(object_id,object_type,action,risk,reason,response_text)
                VALUES (?,?,?,?,?,?)
                ON CONFLICT(object_id, action) DO UPDATE SET
                  risk = MAX(auto_actions.risk, excluded.risk),
                  reason = excluded.reason,
                  response_text = COALESCE(excluded.response_text, auto_actions.response_text),
                  created_at = CURRENT_TIMESTAMP';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$objectId, $objectType, $action, $risk, $reason, $responseText]);
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'publish_post': {
                $msg = trim($_POST['message'] ?? '');
                if ($msg === '') throw new Exception('Thiếu nội dung');
                $res = fb_publish_post($msg);
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;
            }

        case 'comment': {
                $id  = trim($_POST['id'] ?? '');
                $msg = trim($_POST['message'] ?? '');
                if ($id === '' || $msg === '') throw new Exception('Thiếu id/message');
                echo json_encode(fb_comment($id, $msg), JSON_UNESCAPED_UNICODE);
                break;
            }

        case 'hide_comment': {
                $id = trim($_POST['id'] ?? '');
                $hide = ($_POST['hide'] ?? '1') === '1';
                if ($id === '') throw new Exception('Thiếu id');
                echo json_encode(fb_hide_comment($id, $hide), JSON_UNESCAPED_UNICODE);
                break;
            }

        case 'delete_comment': {
                $id = trim($_POST['id'] ?? '');
                if ($id === '') throw new Exception('Thiếu id');
                echo json_encode(fb_delete_comment($id), JSON_UNESCAPED_UNICODE);
                break;
            }

        case 'scan_now': {
                // Quét X phút gần nhất (mặc định 30)
                $window    = max(5, (int)($_POST['window'] ?? 30));
                $threshold = (int) envv('AUTO_RISK_THRESHOLD', 60);

                $doHide  = filter_var(envv('AUTO_ACTION_HIDE',   'true'), FILTER_VALIDATE_BOOLEAN);
                $doReply = filter_var(envv('AUTO_REPLY_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
                $prefix  = envv('AUTO_REPLY_PREFIX', '[BQT]');

                $sinceUnix = time() - $window * 60;
                $pageId    = envv('FB_PAGE_ID');

                $scanRes = ['scanned' => 0, 'high_risk' => 0, 'replied' => 0, 'hidden' => 0, 'skipped' => 0];

                // Lấy bài & bình luận
                $posts = fb_get_page_posts_since($sinceUnix, 20);
                foreach (($posts['data'] ?? []) as $p) {
                    $comments = fb_get_post_comments_since($p['id'], $sinceUnix, 50);
                    foreach (($comments['data'] ?? []) as $c) {
                        $cid  = $c['id'];
                        $from = $c['from']['id'] ?? '';
                        $msg  = trim($c['message'] ?? '');
                        if ($msg === '') continue;
                        if ($pageId && $from === $pageId) continue; // bỏ comment của chính Page

                        // nếu đã reply/hide rồi thì bỏ qua
                        $chk = db()->prepare('SELECT 1 FROM auto_actions WHERE object_id=? AND action IN ("replied","hidden") LIMIT 1');
                        $chk->execute([$cid]);
                        if ($chk->fetchColumn()) {
                            $scanRes['skipped']++;
                            continue;
                        }

                        $scanRes['scanned']++;
                        $res  = analyze_text_with_schema($msg);
                        $risk = (int)($res['overall_risk'] ?? 0);

                        // Luôn ghi điểm "score" để trang cảnh báo tổng hợp được
                        aa_upsert($cid, 'comment', 'score', $risk, 'scan_now');

                        if ($risk < $threshold) {
                            // log skipped (tuỳ chọn)
                            aa_upsert($cid, 'comment', 'skipped', $risk, 'under_threshold');
                            continue;
                        }

                        $scanRes['high_risk']++;

                        // Tạo template trả lời theo nhãn
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
                        $reply = trim($prefix . ' ' . $tpl);

                        if ($doReply) {
                            fb_comment($cid, $reply);
                            aa_upsert($cid, 'comment', 'replied', $risk, 'scan_now', $reply);
                            $scanRes['replied']++;
                            usleep(600000); // 0.6s tránh rate limit
                        }
                        if ($doHide) {
                            fb_hide_comment($cid, true);
                            aa_upsert($cid, 'comment', 'hidden', $risk, 'scan_now');
                            $scanRes['hidden']++;
                            usleep(600000);
                        }
                    }
                }
                echo json_encode($scanRes, JSON_UNESCAPED_UNICODE);
                break;
            }

        case 'analyze_post': {
                $postId = trim($_POST['id'] ?? '');
                if ($postId === '') {
                    echo json_encode(['error' => 'missing post id']);
                    break;
                }

                // Lấy post + comment
                $post = fb_api("/$postId", ['fields' => 'id,message,created_time,permalink_url']);
                $since = time() - 7 * 24 * 3600; // 7 ngày
                $comments = fb_get_post_comments_since($postId, $since, 200);

                $result = ['post' => null, 'comments' => []];

                // Phân tích bài viết
                $msg = trim($post['message'] ?? '');
                if ($msg !== '') {
                    $ar = analyze_text_with_schema($msg);
                    $risk = (int)($ar['overall_risk'] ?? 0);
                    $result['post'] = [
                        'id' => $postId,
                        'permalink_url' => $post['permalink_url'] ?? '#',
                        'risk' => $risk,
                        'analysis' => $ar
                    ];
                    // Lưu điểm
                    aa_upsert($postId, 'post', 'score', $risk, 'manual_analyze');
                }

                // Phân tích từng comment
                foreach (($comments['data'] ?? []) as $c) {
                    $m = trim($c['message'] ?? '');
                    if ($m === '') continue;
                    $ar   = analyze_text_with_schema($m);
                    $risk = (int)($ar['overall_risk'] ?? 0);

                    $result['comments'][] = [
                        'id' => $c['id'],
                        'from' => $c['from']['name'] ?? 'N/A',
                        'created_time' => $c['created_time'] ?? '',
                        'message' => $m,
                        'risk' => $risk,
                        'analysis' => $ar
                    ];
                    // Lưu điểm
                    aa_upsert($c['id'], 'comment', 'score', $risk, 'manual_analyze');
                }

                echo json_encode($result, JSON_UNESCAPED_UNICODE);
                break;
            }

        case 'scan_posts_now': {
                // Quét các BÀI VIẾT của trang trong N phút gần nhất và tự comment cảnh báo
                $windowMin = (int)($_POST['window'] ?? envv('AUTO_POST_WINDOW_MINUTES', 60));
                $threshold = (int) envv('AUTO_POST_RISK_THRESHOLD', 65);
                $enabled   = filter_var(envv('AUTO_POST_WARN_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
                $cooldown  = max(0, (int) envv('AUTO_POST_COOLDOWN_SECONDS', 35));
                $prefix    = trim(envv('AUTO_POST_REPLY_PREFIX', '[Cảnh báo]'));

                $sinceUnix = time() - max(5, $windowMin) * 60;
                $stats = ['scanned' => 0, 'warned' => 0, 'skipped' => 0, 'errors' => 0];

                // lấy post gần đây
                $posts = fb_get_page_posts_since($sinceUnix, 50); // bạn đã có hàm này
                foreach (($posts['data'] ?? []) as $p) {
                    $postId = $p['id'] ?? '';
                    if (!$postId) continue;

                    $msg = trim($p['message'] ?? '');
                    if ($msg === '') {
                        $stats['skipped']++;
                        continue;
                    }

                    // đã cảnh báo post này trước đó?
                    $chk = db()->prepare('SELECT 1 FROM auto_actions WHERE object_id=? AND object_type="post" AND action IN ("warned_post","skipped_post") LIMIT 1');
                    $chk->execute([$postId]);
                    if ($chk->fetch()) {
                        $stats['skipped']++;
                        continue;
                    }

                    $stats['scanned']++;
                    $ar = analyze_text_with_schema($msg);
                    $risk = (int)($ar['overall_risk'] ?? 0);

                    if ($risk < $threshold) {
                        $ins = db()->prepare('INSERT IGNORE INTO auto_actions(object_id,object_type,action,risk,reason) VALUES (?,?,?,?,?)');
                        $ins->execute([$postId, 'post', 'skipped_post', $risk, 'under_threshold']);
                        $stats['skipped']++;
                        continue;
                    }

                    // Chọn template theo nhãn
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

                    // log trước để tránh đập liên tục nếu bị lỗi spam
                    $ins = db()->prepare('INSERT IGNORE INTO auto_actions(object_id,object_type,action,risk,reason,response_text) VALUES (?,?,?,?,?,?)');

                    if (!$enabled) {
                        $ins->execute([$postId, 'post', 'skipped_post', $risk, 'auto_post_disabled', $reply]);
                        $stats['skipped']++;
                        continue;
                    }

                    try {
                        fb_comment($postId, $reply); // comment vào bài viết
                        $ins->execute([$postId, 'post', 'warned_post', $risk, 'scan_posts_now', $reply]);
                        $stats['warned']++;

                        // nghỉ cho lần kế tiếp để tránh spam
                        if ($cooldown > 0) sleep($cooldown);
                    } catch (Exception $e) {
                        $msgErr = $e->getMessage();

                        // nếu bị đánh dấu spam 1446036 -> chỉ log, không thử lại liên tục
                        if (strpos($msgErr, '1446036') !== false) {
                            $ins->execute([$postId, 'post', 'skipped_post', $risk, 'spam_blocked', $reply]);
                            $stats['skipped']++;
                        } else {
                            $ins->execute([$postId, 'post', 'skipped_post', $risk, 'error:' . substr($msgErr, 0, 60), $reply]);
                            $stats['errors']++;
                        }
                    }
                }

                echo json_encode($stats, JSON_UNESCAPED_UNICODE);
                break;
            }

        default:
            throw new Exception('Hành động không hợp lệ');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
