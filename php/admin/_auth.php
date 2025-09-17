<?php

declare(strict_types=1);
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: /php/admin/login.php');
    exit;
}
