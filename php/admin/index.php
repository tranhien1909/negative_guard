<?php
require __DIR__ . '/../db.php';

$posts = $pdo->query("SELECT * FROM posts ORDER BY created_time DESC LIMIT 100")->fetchAll();
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Admin - Negative Info Guard</title>
</head>

<body>
    <h1>Dashboard</h1>
    <table border="1" cellpadding="6">
        <tr>
            <th>Post ID</th>
            <th>Message</th>
            <th>Created</th>
            <th>Risk</th>
            <th>Label</th>
            <th>Action</th>
        </tr>
        <?php foreach ($posts as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['id']) ?></td>
                <td style="max-width:400px"><?= nl2br(htmlspecialchars(substr($p['message'], 0, 300))) ?></td>
                <td><?= $p['created_time'] ?></td>
                <td><?= $p['risk_score'] ?></td>
                <td><?= $p['label'] ?></td>
                <td><?= $p['action_taken'] ?></td>
                <td>
                    <form method="post" action="approve.php">
                        <input type="hidden" name="post_id" value="<?= htmlspecialchars($p['id']) ?>">
                        <select name="action">
                            <option value="none">--</option>
                            <option value="comment">Comment now</option>
                            <option value="post">Post clarification</option>
                        </select>
                        <input type="text" name="reason" placeholder="reason">
                        <button type="submit">Do</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>

</html>