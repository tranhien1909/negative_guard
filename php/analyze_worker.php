<?php

declare(strict_types=1);
$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$predictUrl = $config['ml']['predict_url'] ?? 'http://127.0.0.1:8000/predict';
if (!$predictUrl) {
    fwrite(STDERR, "ERROR: predict_url is empty\n");
    exit(1);
}

function predict(string $url, string $text): array
{
    if (!$url) return [null, null];
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
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $http >= 300) {
        fwrite(STDERR, "ML HTTP $http $err $body\n");
        return [null, null];
    }
    $j = json_decode($body, true);
    return [isset($j['risk_score']) ? (float)$j['risk_score'] : null, $j['label'] ?? null];
}

foreach ([['table' => 'posts', 'idcol' => 'fb_post_id'], ['table' => 'comments', 'idcol' => 'fb_comment_id']] as $t) {
    $table = $t['table'];
    $idcol = $t['idcol'];
    $rows = $pdo->query("
    SELECT {$idcol} AS id, message
    FROM {$table}
    WHERE last_analysis_time IS NULL
       OR last_analysis_time < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ORDER BY last_analysis_time IS NULL DESC
    LIMIT 200
  ")->fetchAll();

    foreach ($rows as $r) {
        $text = (string)($r['message'] ?? '');
        [$risk, $label] = $text !== '' ? predict($predictUrl, $text) : [null, null];
        $pdo->prepare("UPDATE {$table} SET risk_score=:r,label=:l,last_analysis_time=NOW() WHERE {$idcol}=:id")
            ->execute([':r' => $risk, ':l' => $label, ':id' => $r['id']]);
    }
}
echo "done\n";
