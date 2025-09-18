<?php

declare(strict_types=1);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$predictUrl = $config['ml']['predict_url'] ?? '';

function predict(string $url, string $text): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $http >= 300) return [null, null];
    $j = json_decode($body, true);
    return [isset($j['risk_score']) ? (float)$j['risk_score'] : null, $j['label'] ?? null];
}

foreach (['posts' => ['id' => 'fb_post_id'], 'comments' => ['id' => 'fb_comment_id']] as $table => $meta) {
    $idCol = $meta['id'];
    $rows = $pdo->query("SELECT {$idCol} AS id, message FROM {$table}
                       WHERE last_analysis_time IS NULL OR last_analysis_time < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                       ORDER BY last_analysis_time IS NULL DESC LIMIT 50")->fetchAll();
    foreach ($rows as $r) {
        $text = (string)($r['message'] ?? '');
        if ($text === '') {
            $pdo->prepare("UPDATE {$table} SET last_analysis_time=NOW() WHERE {$idCol}=:id")->execute([':id' => $r['id']]);
            continue;
        }
        [$risk, $label] = predict($predictUrl, $text);
        $pdo->prepare("UPDATE {$table} SET risk_score=:r,label=:l,last_analysis_time=NOW() WHERE {$idCol}=:id")
            ->execute([':r' => $risk, ':l' => $label, ':id' => $r['id']]);
    }
}
echo "done\n";
