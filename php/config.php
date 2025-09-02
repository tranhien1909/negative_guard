<?php
// cấu hình - lưu file ngoài repo hoặc ENV trong production
return [
    'fb' => [
        'page_id' => '61580021559510',
        'page_access_token' => 'EAAgpCgQDlW4BPcYUFz3jC8eusyN48r3c9Jfi0ZCQEi6Wnp0cfpAlXFh7FrV6bGtd74tlTFcLNZAl3ZBe9VOuAEXBoMwQTpL0oxhOJaituEOBScXLUDZA1yZAE1HR5PoDgHU4zWlkvmHIcQfc0lJIiYfPW5haMVHYPWXMgOacx8FmAihH9k1yUG7M8sW81aWvLtwZDZD', // đặt trong ENV trung thực
        'graph_version' => 'v17.0'
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
