<?php
$u = 'http://127.0.0.1:8000/predict'; // hoặc http://127.0.0.1:8000/predict nếu bạn gọi thẳng Uvicorn
$payload = ['text' => 'dm truong lon'];

$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 10,
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP=$code\nERR=$err\nBODY=$body\n";
