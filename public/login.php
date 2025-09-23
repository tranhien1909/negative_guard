<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
send_security_headers();


$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $err = 'CSRF token không hợp lệ';
    } else {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        $stmt = db()->prepare('SELECT id, password_hash FROM admin_users WHERE username = ?');
        $stmt->execute([$u]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($p, $row['password_hash'])) {
            $_SESSION['admin_id'] = (int)$row['id'];
            header('Location: /admin/dashboard.php');
            exit;
        }
        $err = 'Sai tài khoản hoặc mật khẩu';
    }
}
?>
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="stylesheet" href="/assets/styles.css">

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #74ABE2, #5563DE);
            height: 100vh;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .card {
            background: #fff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0px 8px 20px rgba(0, 0, 0, 0.15);
            width: 350px;
            text-align: center;
            animation: fadeIn 0.5s ease-in-out;
        }

        .card h2 {
            margin-bottom: 20px;
            color: #333;
        }

        .row {
            margin-bottom: 15px;
            text-align: left;
        }

        label {
            font-weight: 600;
            color: #555;
            display: block;
            margin-bottom: 5px;
        }

        input {
            width: 323px;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 10px;
            outline: none;
            transition: 0.3s;
            font-size: 14px;
        }

        input:focus {
            border-color: #5563DE;
            box-shadow: 0 0 5px rgba(85, 99, 222, 0.5);
        }

        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: #5563DE;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }

        button:hover {
            background: #3c47a0;
        }

        .error {
            background: #ffdddd;
            color: #c0392b;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #e74c3c;
            border-radius: 8px;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>
    <main class="card">
        <h2>Đăng nhập Admin</h2>
        <?php if ($err): ?><div class="warning critical"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
            <div class="row">
                <label>Tài khoản</label>
                <input type="text" name="username" required>
            </div>
            <div class="row">
                <label>Mật khẩu</label>
                <input type="password" name="password" style="width:100%;" required>
            </div>
            <button type="submit">Đăng nhập</button>
        </form>
    </main>
</body>

</html>