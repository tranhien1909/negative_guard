<?php

declare(strict_types=1);
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';

$predictUrl = $config['ml']['predict_url'] ?? '';
if (!$predictUrl) {
    fwrite(STDERR, "predict_url is EMPTY\n");
    exit(1);
}

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
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $http < 200 || $http >= 300) {
        fwrite(STDERR, "ML_ERROR http=$http err=$err body=$body\n");
        return [null, null];
    }
    $j = json_decode($body, true);
    return [isset($j['risk_score']) ? (float)$j['risk_score'] : null, $j['label'] ?? null];
}

$rows = $pdo->query("SELECT fb_comment_id, message
                     FROM comments
                     WHERE risk_score IS NULL OR last_analysis_time IS NULL
                     LIMIT 200")->fetchAll();

$ok = 0;
$fail = 0;
foreach ($rows as $r) {
    [$risk, $label] = predict($predictUrl, (string)$r['message']);
    if ($risk !== null) {
        $pdo->prepare("UPDATE comments
                   SET risk_score=:r, label=:l, last_analysis_time=NOW()
                   WHERE fb_comment_id=:id")->execute([
            ':r' => $risk,
            ':l' => $label,
            ':id' => $r['fb_comment_id']
        ]);
        $ok++;
    } else {
        $fail++;
    }
}
echo "Updated: $ok, Failed: $fail\n";
