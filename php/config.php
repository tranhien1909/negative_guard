<?php
// cấu hình - lưu file ngoài repo hoặc ENV trong production
return [
    'fb' => [
        'page_id' => '786334934561714',
        'page_access_token' => 'EAAQ8KobhDokBPU4xH8gJSoaPNsvSkm9odEwPjObQgFW2jokZAfXWtkoXFdZAMU2sInOfaojuN8w5ZA3bSlyYLmbgG7ZAB5hqKzvSPktpaNpLs4UgaGvOwwjc8BVmI7NdZBzJ6y4DkFQUwGIdv8xPky3TplLhHYU45LcZCfHzKrm80Fch9EJ2RB3DirsqVpBtVcBpZAQSggEIWqFfTLIilNiY7LMEn8OY4DH8SsNWwuIRxwZD',
        'graph_version' => 'v23.0'
    ],
    'facebook' => [
        'fetch_window_hours' => 720, // 30 ngày cho lần đầu
        'limit' => 25,
        'fetch_comments' => true,
        'comments_limit' => 50,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'dbname' => 'negative_guard',
        'user' => 'root',
        'pass' => ''
    ],
    'ml' => [
        'predict_url' => 'http://127.0.0.1:8000/predict'  // FastAPI
    ],
    'auth' => [
        'username' => 'admin',
        // tạo hash: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT), PHP_EOL;"
        // 'password_hash' => '$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'password' => '12345', // chỉ dùng tạm trong dev nếu chưa có hash
    ],
];
