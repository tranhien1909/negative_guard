<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../db.php';

$sql = "
SELECT fb_post_id   AS fb_id,
       message,
       created_time,
       last_seen,
       risk_score,
       label,
       action_taken,
       'post' AS kind
  FROM posts
UNION ALL
SELECT fb_comment_id AS fb_id,
       message,
       created_time,
       last_seen,
       risk_score,
       label,
       NULL AS action_taken,
       'comment' AS kind
  FROM comments
ORDER BY COALESCE(created_time, last_seen) DESC
";
$items = $pdo->query($sql)->fetchAll();
?>
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <title>Admin - Negative Info Guard</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #f4f6f9;
            margin: 0
        }

        header {
            background: #5563DE;
            color: #fff;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center
        }

        header h1 {
            margin: 0;
            font-size: 20px
        }

        header a {
            color: #fff;
            text-decoration: none;
            font-weight: bold;
            background: #ff4d4d;
            padding: 6px 12px;
            border-radius: 6px;
            transition: .3s
        }

        header a:hover {
            background: #cc0000
        }

        main {
            padding: 20px
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .1)
        }

        th,
        td {
            padding: 12px 15px;
            text-align: left
        }

        th {
            background: #5563DE;
            color: #fff;
            text-transform: uppercase;
            font-size: 13px
        }

        tr:nth-child(even) {
            background: #f9f9f9
        }

        tr:hover {
            background: #eef2ff
        }

        .risk-low {
            color: #2e7d32;
            font-weight: bold
        }

        .risk-medium {
            color: #f9a825;
            font-weight: bold
        }

        .risk-high {
            color: #c62828;
            font-weight: bold
        }

        select,
        input[type="text"] {
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            margin-right: 6px
        }

        button {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            background: #5563DE;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
            transition: .3s
        }

        button:hover {
            background: #3c47a0
        }
    </style>
</head>

<body>
    <header>
        <h1>Negative Info Guard - Dashboard</h1>
        <a href="http://127.0.0.1:8000">Logout</a>
    </header>

    <main>
        <h2>Danh sách bài viết & bình luận</h2>
        <table>
            <tr>
                <th>TYPE</th>
                <th>FB ID</th>
                <th>Message</th>
                <th>Created</th>
                <th>Risk</th>
                <th>Label</th>
                <th>Action</th>
                <th>Thao tác</th>
            </tr>

            <?php foreach ($items as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['kind']) ?></td>
                    <td><?= htmlspecialchars($p['fb_id']) ?></td>
                    <td style="max-width:500px"><?= nl2br(htmlspecialchars(mb_strimwidth((string)($p['message'] ?? ''), 0, 400, '…', 'UTF-8'))) ?></td>
                    <td><?= htmlspecialchars($p['created_time'] ?? $p['last_seen'] ?? '') ?></td>
                    <td>
                        <?php
                        $risk = is_null($p['risk_score']) ? null : (float)$p['risk_score'];
                        if ($risk === null) {
                            echo "<em>n/a</em>";
                        } else {
                            echo $risk < 0.4
                                ? "<span class='risk-low'>🟢 " . number_format($risk, 2) . "</span>"
                                : ($risk < 0.7
                                    ? "<span class='risk-medium'>🟡 " . number_format($risk, 2) . "</span>"
                                    : "<span class='risk-high'>🔴 " . number_format($risk, 2) . "</span>");
                        }
                        ?>
                    </td>
                    <td><?= htmlspecialchars((string)($p['label'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($p['action_taken'] ?? '')) ?></td>
                    <td>
                        <!-- chỉ giữ lại thao tác cho admin; auto chấm điểm đã có -->
                        <form method="post" action="approve.php" style="display:flex; gap:5px; flex-wrap:wrap;">
                            <input type="hidden" name="fb_post_id" value="<?= htmlspecialchars($p['fb_id']) ?>">
                            <select name="action" required>
                                <option value="comment">Comment now</option>
                                <option value="post">Post clarification</option>
                            </select>
                            <input type="text" name="reason" placeholder="reason" required>
                            <button type="submit">Thực hiện</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>

        </table>
    </main>
</body>

</html>