<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/fb_graph.php';

send_security_headers();
require_admin();

$pdo = db();

$minRisk = max(0, (int)($_GET['min_risk'] ?? 60));
$window  = (isset($_GET['window']) ? (int)$_GET['window'] : 1440); // phút; 0 = bỏ lọc thời gian
$limit   = max(1, (int)($_GET['limit']  ?? 100));

$params = [];
$where  = "object_type='comment'
           AND action IN ('score','scored_comment','replied','hidden')
           AND risk >= ?";

$params[] = $minRisk;

if ($window > 0) {
    $since = date('Y-m-d H:i:s', time() - $window * 60);
    $where .= " AND created_at >= ?";
    $params[] = $since;
}

$sql = "
  SELECT object_id,
         MAX(risk)       AS risk,
         MAX(created_at) AS last_seen
  FROM auto_actions
  WHERE $where
  GROUP BY object_id
  ORDER BY risk DESC, last_seen DESC
  LIMIT $limit
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function risk_level($r)
{
    if ($r >= 80) return ['critical', 'Nguy cấp'];
    if ($r >= 60) return ['high', 'Cao'];
    if ($r >= 40) return ['medium', 'Trung bình'];
    return ['low', 'Thấp'];
}
?>
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <title>Admin • Cảnh báo cao</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/styles.css">
    <style>
        /* ====== Bổ sung style nhẹ cho trang này ====== */
        .page {
            max-width: 1100px;
            margin: 32px auto;
            padding: 0 16px;
        }

        .breadcrumb {
            display: flex;
            gap: 8px;
            align-items: center;
            color: #9aa4b2;
            font-size: 14px;
        }

        .h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 8px 0 16px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
            border: 1px solid #1f2a44;
            padding: 14px;
            border-radius: 14px;
            margin: 12px 0 22px;
        }

        .filters label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 13px;
            color: #94a3b8;
        }

        .filters input[type=number] {
            width: 140px;
            padding: 10px 12px;
            border: 1px solid #22314f;
            background: #0b1220;
            color: #e5e7eb;
            border-radius: 10px;
            outline: none;
        }

        .btn {
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #22314f;
            background: #0b1220;
            color: #e5e7eb;
            cursor: pointer;
        }

        .btn:hover {
            background: #111a2f;
        }

        .btn-primary {
            background: linear-gradient(90deg, #2d6bfe, #5e4bfa);
            border: none;
        }

        .btn-primary[disabled] {
            opacity: .7;
            cursor: not-allowed;
        }

        .cards {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .card {
            border: 1px solid #1f2a44;
            background: #0b1220;
            border-radius: 16px;
            padding: 14px;
        }

        .card__top {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }

        .meta {
            display: flex;
            gap: 10px;
            align-items: center;
            color: #9aa4b2;
            font-size: 14px;
        }

        .badge {
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 600;
        }

        .badge.low {
            background: #16361f;
            color: #7ee787;
        }

        .badge.medium {
            background: #382f1a;
            color: #ffd277;
        }

        .badge.high {
            background: #3a1d20;
            color: #ff9ca5;
        }

        .badge.critical {
            background: #3b1114;
            color: #ff5c6e;
        }

        .msg {
            color: #e5e7eb;
            white-space: pre-wrap;
            margin-top: 8px;
        }

        .actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-link {
            background: #0f172a;
            border: 1px solid #24324f;
        }

        .small {
            font-size: 12px;
            color: #94a3b8;
        }

        .kbd {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            background: #111827;
            border: 1px solid #374151;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 12px;
            color: #cbd5e1;
        }

        .empty {
            text-align: center;
            color: #94a3b8;
            padding: 32px 8px;
            border: 1px dashed #24324f;
            border-radius: 14px;
            background: #0b1220;
        }
    </style>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <script src="/assets/moderation.js" defer></script>
</head>

<body>
    <header style="display:flex;gap:12px;align-items:center;justify-content:space-between;padding:16px 24px;background:#0f1530;border-bottom:1px solid #20274a">
        <div><strong style="color: red;">Cảnh báo cao</strong></div>
        <nav>
            <a class="badge" href="/admin/dashboard.php">Bảng điều khiển</a>
            <a class="badge" href="./logout.php">Đăng xuất</a>
        </nav>
    </header>

    <main class="page">
        <div class="breadcrumb">
            <span>Admin</span> › <span>Cảnh báo cao</span>
        </div>
        <div class="h1">Comment có cảnh báo cao</div>

        <form class="filters" method="get" id="filterForm">
            <label>Ngưỡng rủi ro
                <input type="number" name="min_risk" min="0" max="100" value="<?= htmlspecialchars($minRisk) ?>">
            </label>
            <label>Trong vòng (phút) — (0 để bỏ lọc thời gian)
                <input type="number" name="window" min="0" value="<?= htmlspecialchars($window) ?>">
            </label>
            <label>Giới hạn
                <input type="number" name="limit" min="1" max="500" value="<?= htmlspecialchars($limit) ?>">
            </label>

            <button class="btn" type="submit">Lọc</button>

            <button class="btn btn-primary" id="scanBtn" type="button" title="Quét comment gần đây và chấm điểm tự động">
                <span id="scanText">Quét lấy bài viết (30 phút gần nhất)</span>
            </button>
            <button id="scanPostsBtn" class="btn btn-primary">
                Comment tự động bài viết rủi ro (60 phút gần nhất)
            </button>
            <span id="scanPostsText" class="muted"></span>
        </form>

        <?php if (!$rows): ?>
            <div class="empty">
                Không có comment nào vượt ngưỡng trong khung thời gian đã chọn.
                <div class="small" style="margin-top:6px">
                    Gợi ý: hạ ngưỡng rủi ro, tăng “Trong vòng (phút)” lên <b>1440</b> (1 ngày) hoặc bấm “Quét ngay”.
                </div>
            </div>
        <?php else: ?>
            <div class="cards">
                <?php foreach ($rows as $r):
                    $cid = $r['object_id'];
                    try {
                        $c = fb_api("/$cid", ['fields' => 'id,from{name,id},message,permalink_url,created_time']);
                    } catch (Exception $e) {
                        $c = ['id' => $cid, 'from' => ['name' => 'N/A'], 'message' => '(Không lấy được nội dung từ Graph)', 'permalink_url' => '#', 'created_time' => $r['last_seen']];
                    }
                    $risk = (int)$r['risk'];
                    [$lvlClass, $lvlText] = risk_level($risk);
                ?>
                    <article class="card" data-id="<?= htmlspecialchars($cid) ?>">
                        <div class="card__top">
                            <div class="meta">
                                <span class="badge <?= $lvlClass ?>">Rủi ro: <?= $risk ?>/100 · <?= $lvlText ?></span>
                                <span class="small">Cập nhật: <?= htmlspecialchars($r['last_seen']) ?></span>
                            </div>
                            <?php if (!empty($c['permalink_url'])): ?>
                                <a class="btn btn-link" target="_blank" href="<?= htmlspecialchars($c['permalink_url']) ?>">Mở Facebook</a>
                            <?php endif; ?>
                        </div>

                        <div class="meta" style="margin-top:6px">
                            <strong><?= htmlspecialchars($c['from']['name'] ?? 'N/A') ?></strong>
                            <span class="small">• <?= htmlspecialchars($c['created_time'] ?? '') ?></span>
                        </div>

                        <div class="msg" data-collapsed="1" style="max-height:4.5em; overflow:hidden;">
                            <?= htmlspecialchars($c['message'] ?? '') ?>
                        </div>

                        <div class="actions">
                            <button class="btn" type="button" data-reply>Trả lời</button>
                            <button class="btn" type="button" data-hide>Ẩn</button>
                            <button class="btn" type="button" data-unhide>Hiện</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

</body>

</html>