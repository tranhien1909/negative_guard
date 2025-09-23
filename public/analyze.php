<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/openai_client.php';
require_once __DIR__ . '/../lib/db.php';
send_security_headers();
header('Content-Type: application/json; charset=utf-8');


try {
    if (rate_limited()) throw new Exception('Vượt quá giới hạn, thử lại sau ít phút.');
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    $text = trim($json['text'] ?? '');
    $save = !empty($json['saveLog']);
    $maxLen = (int) envv('MAX_TEXT_LEN', 5000);
    if (!$text) throw new Exception('Thiếu nội dung');
    if (mb_strlen($text) > $maxLen) throw new Exception('Nội dung quá dài');


    // 1) Moderation (lọc thô, miễn phí)
    // $mod = moderate_text($text);
    $mod = null;

    $base = getenv('OPENAI_BASE_URL') ?: '';
    if (stripos($base, 'api.groq.com') === false) {
        try {
            $mod = moderate_text($text);
        } catch (Exception $e) {
            $mod = ['error' => $e->getMessage()];
        }
    }

    // 2) Phân tích chi tiết (Structured Outputs)
    $analysis = analyze_text_with_schema($text);


    // 3) Lưu log (nếu chọn)
    if ($save) {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT INTO analysis_logs(ip, input_text, output_json, risk_score) VALUES(?,?,?,?)');
        $stmt->execute([
            $_SERVER['REMOTE_ADDR'] ?? '',
            $text,
            json_encode(['moderation' => $mod, 'analysis' => $analysis], JSON_UNESCAPED_UNICODE),
            (int) ($analysis['overall_risk'] ?? 0)
        ]);
    }


    echo json_encode($analysis, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
