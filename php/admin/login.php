<?php

declare(strict_types=1);
session_start();

$config = require __DIR__ . '/../config.php';
$user = $config['auth']['username'] ?? 'admin';
$passHash = $config['auth']['password_hash'] ?? null;   // ưu tiên hash
$passPlain = $config['auth']['password'] ?? null;       // fallback (dev)

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $ok = false;

    if ($u === $user) {
        if ($passHash) $ok = password_verify($p, $passHash);
        elseif ($passPlain !== null) $ok = hash_equals($passPlain, $p);
    }

    if ($ok) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $u;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Sai tài khoản hoặc mật khẩu.';
    }
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Admin Login</title>
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
    <div class="card">
        <h2>Đăng nhập Admin</h2>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="row">
                <label>Tên đăng nhập</label>
                <input type="text" name="username" required>
            </div>
            <div class="row">
                <label>Mật khẩu</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit">Đăng nhập</button>
        </form>
    </div>
</body>

</html>