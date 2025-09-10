<?php
// cấu hình - lưu file ngoài repo hoặc ENV trong production
return [
    'fb' => [
        'page_id' => '786334934561714',
        'page_access_token' => 'EAAQ8KobhDokBPQl5SuNYQSI7aoxQLftvLWTlpIUA2ZCc5VE5DUOexVZC5jke3ylRtPaO4cBNrIdeagZAZCr3y8o3awW4bmhXNyebtNHktVYZCgK2gss5OUaAcWvplxyDlZBlWggcdMKCauis5C9AFe4Oe3ZASArQqxkSZC84v5a9WUZB6lAYEkTRwkJzBFUBifInxetKm', // đặt trong ENV trung thực
        'graph_version' => 'v17.0'
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
    ]
];
