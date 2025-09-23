<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fb_graph.php';
send_security_headers();
require_admin();

$minRisk = (int)($_GET['min_risk'] ?? envv('AUTO_RISK_THRESHOLD', 60));
$window  = (int)($_GET['window'] ?? 1440); // phút (mặc định 24h)
$limit   = (int)($_GET['limit'] ?? 100);

$sinceSql = date('Y-m-d H:i:s', time() - $window * 60);

// Lấy các hành động auto (trong khung thời gian) có risk >= ngưỡng
$st = db()->prepare("
  SELECT object_id, MAX(risk) AS risk,
         SUM(action='replied')>0 AS replied,
         SUM(action='hidden')>0  AS hidden,
         MAX(created_at) AS last_time
  FROM auto_actions
  WHERE object_type='comment' AND created_at >= ? AND risk >= ?
  GROUP BY object_id
  ORDER BY last_time DESC
  LIMIT {$limit}
");
$st->execute([$sinceSql, $minRisk]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Lấy chi tiết comment từ Graph
$items = [];
foreach ($rows as $r) {
    try {
        $c = fb_get_comment($r['object_id']);
        $items[] = ['meta' => $r, 'c' => $c];
    } catch (Exception $e) {
        $items[] = ['meta' => $r, 'c' => ['id' => $r['object_id'], 'message' => '(Không lấy được từ Graph)', 'permalink_url' => '#']];
    }
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
        body {
            font-family: system-ui, Segoe UI, Roboto, Arial, sans-serif;
            margin: 0;
            background: #ffffffff;
            color: while
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            background: #9ca2d9ff;
            border-bottom: 1px solid #20274a
        }

        h1 {
            font-size: 20px;
            margin: 0
        }

        nav a {
            color: #9cc1ff;
            text-decoration: none
        }

        main {
            max-width: 900px;
            margin: 24px auto;
            padding: 0 16px
        }

        form textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #2c3566;
            background: white;
            color: black;
        }

        button {
            margin-top: 12px;
            padding: 10px 16px;
            border-radius: 12px;
            border: 0;
            background: #3759ff;
            color: #fff;
            cursor: pointer
        }

        .checkbox {
            display: block;
            margin-top: 8px;
            color: #a5b4fc
        }

        #result {
            margin-top: 24px
        }

        .warning {
            border: 1px solid #374151;
            border-left: 6px solid #64748b;
            border-radius: 10px;
            padding: 12px;
            margin: 10px 0;
            background: #fefefeff
        }

        .warning.high {
            border-left-color: #f59e0b
        }

        .warning.critical {
            border-left-color: #ef4444
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #1f2a5a;
            color: #9cc1ff;
            margin-right: 6px
        }

        #risk {
            font-weight: 600;
            margin-bottom: 8px
        }
    </style>
    <style>
        .toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            margin: 16px 0;
        }

        .risk-high {
            background: #f44336;
            color: #fff;
        }

        .risk-med {
            background: #ff9800;
            color: #000;
        }

        .risk-low {
            background: #8bc34a;
            color: #000;
        }

        .card {
            background: #0f1320;
            border: 1px solid #223;
            border-radius: 12px;
            padding: 12px;
            margin: 12px 0;
        }

        .row {
            display: flex;
            gap: 12px;
            justify-content: space-between;
            align-items: center;
        }

        .msg {
            white-space: pre-wrap;
            margin: 8px 0;
            color: #dfe6ff;
        }

        .muted {
            opacity: .7
        }

        .actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .actions input[type=text] {
            width: 360px;
            max-width: 60vw;
        }

        .small {
            font-size: 12px;
        }
    </style>
</head>

<body>
    <header style="display:flex;gap:12px;align-items:center;justify-content:space-between;padding:16px 24px;background:#0f1530;border-bottom:1px solid #20274a">
        <div><strong style="color: red;">Admin • Comment cảnh báo cao</strong></div>
        <nav>
            <a class="badge" href="/admin/dashboard.php">Bảng điều khiển</a> ·
            <a class="badge" href="/admin/moderation.php"><b style="color: yellow;">Cảnh báo cao</b></a> ·
            <a class="badge" href="/logout.php">Đăng xuất</a>
        </nav>
    </header>
    <main>
        <div class="row">
            <form class="toolbar" method="get" style="width: 100%;">
                <label>Ngưỡng rủi ro
                    <input type="number" name="min_risk" value="<?= htmlspecialchars($minRisk) ?>" min="0" max="100" style="width:72px; border: 1px solid;border-radius: 5px;">
                </label>
                <label>Trong vòng (phút)
                    <input type="number" name="window" value="<?= htmlspecialchars($window) ?>" min="5" style="width:88px; border: 1px solid;border-radius: 5px;">
                </label>
                <label>Giới hạn
                    <input type="number" name="limit" value="<?= htmlspecialchars($limit) ?>" min="10" style="width:88px; border: 1px solid;border-radius: 5px;">
                </label>
                <button type="submit">Lọc</button>

                <form id="scanNowForm" method="post" action="/admin/action.php" style="margin-left:auto">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="scan_now">
                    <input type="hidden" name="window" value="30">
                    <button type="submit">🔄 Quét ngay (30 phút gần nhất)</button>
                </form>
            </form>
        </div>

        <?php if (!$items): ?>
            <p class="muted text-center">Không có comment nào vượt ngưỡng trong khung thời gian đã chọn.</p>
        <?php endif; ?>

        <?php foreach ($items as $it):
            $c    = $it['c'];
            $meta = $it['meta'];
            $risk = (int)$meta['risk'];
            $riskClass = $risk >= 80 ? 'risk-high' : ($risk >= 60 ? 'risk-med' : 'risk-low');
            $replyText = "[BQT] Vui lòng trao đổi văn minh, cung cấp nguồn xác thực. Xin cảm ơn!";
        ?>
            <div class="card">
                <div class="row">
                    <div>
                        <a href="<?= htmlspecialchars($c['permalink_url'] ?? '#') ?>" target="_blank">Mở Facebook</a>
                        <div class="small muted">ID: <?= htmlspecialchars($c['id']) ?> • Lần quét: <?= htmlspecialchars($meta['last_time']) ?></div>
                    </div>
                    <div class="badge <?= $riskClass ?>">Rủi ro: <?= $risk ?>/100</div>
                </div>

                <div class="small muted" style="margin-top:6px">
                    Tác giả: <?= htmlspecialchars($c['from']['name'] ?? 'N/A') ?> •
                    Lúc: <?= htmlspecialchars($c['created_time'] ?? '') ?> •
                    Trạng thái: <?= !empty($meta['hidden']) ? 'ĐÃ ẨN' : (($c['is_hidden'] ?? false) ? 'ĐÃ ẨN' : 'ĐANG HIỂN THỊ') ?> •
                    Đã trả lời: <?= $meta['replied'] ? 'Có' : 'Chưa' ?>
                </div>

                <div class="msg"><?= htmlspecialchars($c['message'] ?? '(trống)') ?></div>

                <div class="actions">
                    <form method="post" action="/admin/action.php" onsubmit="return doReply(event)">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="comment">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                        <input type="text" name="message" placeholder="Phản hồi cảnh báo..." value="<?= htmlspecialchars($replyText) ?>">
                        <button type="submit">Trả lời</button>
                    </form>

                    <form method="post" action="/admin/action.php" onsubmit="return doHide(event, true)">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="hide_comment">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                        <input type="hidden" name="hide" value="1">
                        <button type="submit">Ẩn</button>
                    </form>

                    <form method="post" action="/admin/action.php" onsubmit="return doHide(event, false)">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="hide_comment">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                        <input type="hidden" name="hide" value="0">
                        <button type="submit">Hiện</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </main>

    <script>
        async function doReply(e) {
            e.preventDefault();
            const res = await fetch(e.target.action, {
                method: 'POST',
                body: new FormData(e.target)
            });
            const data = await res.json();
            if (data.error) alert('Lỗi: ' + data.error);
            else alert('Đã trả lời!');
            return false;
        }
        async function doHide(e, hide) {
            e.preventDefault();
            const fd = new FormData(e.target);
            const res = await fetch(e.target.action, {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.error) alert('Lỗi: ' + data.error);
            else alert(hide ? 'Đã ẩn' : 'Đã hiện');
            return false;
        }
    </script>
</body>

</html>