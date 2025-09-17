<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../db.php';

$posts = $pdo->query("SELECT id, fb_post_id, message, created_time AS created_at, risk_score, label, action_taken
FROM posts
ORDER BY COALESCE(created_time, id) DESC")->fetchAll();
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
            margin: 0;
            padding: 0;
        }

        header {
            background: #5563DE;
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            margin: 0;
            font-size: 20px;
        }

        header a {
            color: white;
            text-decoration: none;
            font-weight: bold;
            background: #ff4d4d;
            padding: 6px 12px;
            border-radius: 6px;
            transition: 0.3s;
        }

        header a:hover {
            background: #cc0000;
        }

        main {
            padding: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        th,
        td {
            padding: 12px 15px;
            text-align: left;
        }

        th {
            background: #5563DE;
            color: white;
            text-transform: uppercase;
            font-size: 13px;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        tr:hover {
            background: #eef2ff;
        }

        .risk-low {
            color: #2e7d32;
            font-weight: bold;
        }

        .risk-medium {
            color: #f9a825;
            font-weight: bold;
        }

        .risk-high {
            color: #c62828;
            font-weight: bold;
        }

        select,
        input[type="text"] {
            padding: 6px 8px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            margin-right: 6px;
        }

        button {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            background: #5563DE;
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }

        button:hover {
            background: #3c47a0;
        }
    </style>
</head>

<body>
    <header>
        <h1>Negative Info Guard - Dashboard</h1>
        <a href="http://127.0.0.1:8000/">Logout</a>
    </header>

    <main>
        <h2>Danh sách bài viết</h2>
        <table>
            <tr>
                <th>Row ID</th>
                <th>FB Post ID</th> <!-- thêm cột -->
                <th>Message</th>
                <th>Created</th>
                <th>Risk</th>
                <th>Label</th>
                <th>Action Taken</th>
                <th>Thao tác</th>
            </tr>

            <?php foreach ($posts as $p):
                // cố gắng suy ra FB Post ID từ các cột hiện có
                $fbId = $p['fb_post_id']
                    ?? $p['graph_id']
                    ?? $p['post_fbid']
                    ?? $p['object_id']
                    ?? $p['external_id']
                    ?? $p['post_id']
                    ?? $p['source_post_id']
                    ?? ((!empty($p['page_id']) && !empty($p['post_id_short'])) ? ($p['page_id'] . '_' . $p['post_id_short']) : null);
            ?>
                <tr>
                    <td><?= htmlspecialchars($p['id']) ?></td> <!-- Row ID nội bộ -->
                    <td><?= $fbId ? htmlspecialchars($fbId) : '<em>chưa có</em>' ?></td> <!-- FB Post ID -->
                    <td style="max-width:400px"><?= nl2br(htmlspecialchars(substr($p['message'] ?? '', 0, 300))) ?></td>
                    <td><?= htmlspecialchars($p['created_at'] ?? '') ?></td>
                    <td>
                        <?php
                        $risk = (float)$p['risk_score'];
                        echo $risk < 0.4
                            ? "<span class='risk-low'>🟢 " . number_format($risk, 2) . "</span>"
                            : ($risk < 0.7
                                ? "<span class='risk-medium'>🟡 " . number_format($risk, 2) . "</span>"
                                : "<span class='risk-high'>🔴 " . number_format($risk, 2) . "</span>");
                        ?>
                    </td>
                    <td><?= $p['label'] ?></td>
                    <td><?= $p['action_taken'] ?></td>
                    <td>
                        <form method="post" action="approve.php" style="display:flex; gap:5px; flex-wrap:wrap;">
                            <!-- luôn gửi Row ID để approve.php tra cứu -->
                            <input type="hidden" name="row_id" value="<?= htmlspecialchars($p['id']) ?>">
                            <?php if (!empty($p['fb_post_id'])): ?>
                                <input type="hidden" name="fb_post_id" value="<?= htmlspecialchars($p['fb_post_id']) ?>">
                            <?php endif; ?>
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