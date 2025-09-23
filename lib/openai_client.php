<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/** Rate limit theo IP/phút (MySQL/SQLite) */
function rate_limited()
{
    $limit  = (int) envv('RATE_LIMIT_PER_MIN', 30);
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $minute = date('YmdHi');
    $pdo    = db();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // Tăng bộ đếm
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO rate_limits (ip, minute_window, count) VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE count = count + 1';
    } else { // sqlite
        $sql = 'INSERT INTO rate_limits (ip, minute_window, count) VALUES (?,?,1)
            ON CONFLICT(ip, minute_window) DO UPDATE SET count = count + 1';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ip, $minute]);

    $stmt = $pdo->prepare('SELECT count FROM rate_limits WHERE ip = ? AND minute_window = ?');
    $stmt->execute([$ip, $minute]);
    $count = (int) $stmt->fetchColumn();
    return $count > $limit;
}

/** Gọi OpenAI (cURL wrapper) */
function openai_post($path, $payload, $is_json = true, $retries = 3)
{
    $base = trim(envv('OPENAI_BASE_URL', 'https://api.openai.com/v1'));
    if ((str_starts_with($base, '"') && str_ends_with($base, '"')) ||
        (str_starts_with($base, "'") && str_ends_with($base, "'"))
    ) {
        $base = substr($base, 1, -1);
    }
    $base = rtrim($base, '/');
    $url  = preg_match('#/v\\d+$#', $base) ? $base . $path : $base . '/v1' . $path;

    $headers = ['Authorization: Bearer ' . envv('OPENAI_API_KEY')];
    if ($is_json) $headers[] = 'Content-Type: application/json';

    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $is_json ? json_encode($payload) : $payload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true, // để đọc Retry-After
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) throw new Exception('cURL error: ' . curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $hdrs = substr($raw, 0, $hs);
        $body = substr($raw, $hs);
        curl_close($ch);

        // Retry trên 429 và 5xx
        if ($code == 429 || ($code >= 500 && $code < 600)) {
            if ($attempt >= $retries) throw new Exception("OpenAI HTTP $code: $body");
            $retryAfter = 0.0;
            if (preg_match('/Retry-After:\\s*(\\d+(?:\\.\\d+)?)/i', $hdrs, $m)) $retryAfter = (float)$m[1];
            $sleep = max($retryAfter, pow(2, $attempt) * 0.5) + mt_rand(0, 100) / 1000.0; // backoff + jitter
            usleep((int)($sleep * 1e6));
            continue;
        }

        if ($code >= 400) throw new Exception("OpenAI HTTP $code: $body");
        return json_decode($body, true);
    }
    throw new Exception('Unexpected retry loop');
}

/** Moderation API */
function moderate_text($text)
{
    $model = envv('OPENAI_MODERATION_MODEL', 'omni-moderation-latest');
    return openai_post('/moderations', ['model' => $model, 'input' => $text]);
}

/** Lấy text ra từ cấu trúc trả về của Responses API */
function _extract_text_from_response(array $resp): ?string
{
    // Cấu trúc mới: output[] -> content[] -> { type: "output_text", text: "..." }
    if (!empty($resp['output']) && is_array($resp['output'])) {
        foreach ($resp['output'] as $msg) {
            if (!empty($msg['content']) && is_array($msg['content'])) {
                foreach ($msg['content'] as $c) {
                    if (isset($c['text'])) return $c['text'];       // ưu tiên
                }
            }
        }
    }
    // Fallback kiểu cũ (nếu có)
    if (isset($resp['choices'][0]['message']['content'])) {
        return $resp['choices'][0]['message']['content'];
    }
    return null;
}

// Chuẩn hoá chuỗi tiếng Việt về dạng dễ regex (hạ chữ, bỏ dấu cơ bản)
function _vi_norm($s)
{
    $s = mb_strtolower($s, 'UTF-8');
    if (function_exists('iconv')) {
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($x !== false) $s = $x;
    }
    $s = preg_replace('/[^a-z0-9@:\.\/\-\s]/', ' ', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

// Quét luật đơn giản: xúc phạm, lừa đảo/đa cấp, mồi tiền, link, SĐT
function _pre_screen_text($text)
{
    $t = _vi_norm($text);
    $flags = ['harassment' => false, 'scam' => false, 'money_lure' => false, 'link' => false, 'phone' => false];
    $reasons = [];

    if (preg_match('/\b(an chan|an bot|lua dao|da cap|tham nhung|trom|gian lan)\b/u', $t)) {
        $flags['scam'] = true;
        $reasons[] = 'Từ khoá cáo buộc/lừa đảo: ăn chặn/đa cấp…';
    }
    if (
        preg_match('/\b(ngu|do ec|do dot|mat day|vo hoc|doi tieu|do ban|tieng chui)\b/u', $t) ||
        preg_match('/\b(do|thang)\s+(cho|dien|ranh|khung)\b/u', $t)
    ) {
        $flags['harassment'] = true;
        $reasons[] = 'Lời lẽ xúc phạm/công kích cá nhân.';
    }
    if (preg_match('/\b(kiem tien nhanh|0 von|thu nhap khung|viec nhe|việc nhẹ|luong cao|chuyen khoan truoc)\b/u', $t)) {
        $flags['money_lure'] = true;
        $reasons[] = 'Mồi chào kiếm tiền không thực tế.';
    }
    if (preg_match('/https?:\/\/\S+/i', $text) || preg_match('/\b(m\.me|zalo\.me|t\.me|telegram\.me)\b/i', $text)) {
        $flags['link'] = true;
        $reasons[] = 'Có đường link mời gọi/ngoài nền tảng.';
    }
    if (preg_match('/\b0\d{9,10}\b/', $t)) {
        $flags['phone'] = true;
        $reasons[] = 'Có số điện thoại liên hệ.';
    }

    // điểm cộng thô
    $boost = 0;
    if ($flags['harassment']) $boost += 35;
    if ($flags['scam'])       $boost += 40;
    if ($flags['money_lure']) $boost += 15;
    if ($flags['link'])       $boost += 5;
    if ($flags['phone'])      $boost += 5;

    return ['flags' => $flags, 'reasons' => $reasons, 'boost' => $boost];
}

// Hợp nhất kết quả LLM + luật, tính lại risk
function _apply_prescreen_and_normalize(array $parsed, array $screen): array
{
    // ép bật các cờ nếu luật bắt được
    if ($screen['flags']['harassment']) $parsed['labels']['hate_speech'] = true;
    if ($screen['flags']['scam'] || $screen['flags']['money_lure']) $parsed['labels']['scam_phishing'] = true;

    // thêm warnings từ luật
    foreach ($screen['reasons'] as $r) {
        $parsed['warnings'][] = [
            'title' => 'Dấu hiệu rủi ro qua luật',
            'severity' => ($screen['flags']['scam'] || $screen['flags']['harassment']) ? 'high' : 'medium',
            'evidence' => $r,
            'suggestion' => 'Kiểm chứng nguồn; tránh lan truyền; cảnh giác trước lời mời chào/đường link.'
        ];
    }
    if ($screen['flags']['link'])   $parsed['flagged_terms'][] = 'link';
    if ($screen['flags']['phone'])  $parsed['flagged_terms'][] = 'số_điện_thoại';

    // Chuẩn hoá kiểu/trường & ước tính lại điểm
    $norm = _normalize_analysis($parsed);
    // cộng thêm boost thô
    $norm['overall_risk'] = min(100, $norm['overall_risk'] + $screen['boost']);
    // an toàn chia sẻ theo ngưỡng
    $norm['safe_to_share'] = ($norm['overall_risk'] <= 40);
    return $norm;
}

// Tách JSON nếu model trả kèm chữ/code fence
function _json_from_text_loose(?string $s): ?array
{
    if (!$s) return null;
    $s = trim($s);
    // nếu đã là JSON thuần
    $j = json_decode($s, true);
    if (is_array($j)) return $j;
    // cắt từ { ... } ngoài cùng
    $p = strpos($s, '{');
    $q = strrpos($s, '}');
    if ($p !== false && $q !== false && $q > $p) {
        $sub = substr($s, $p, $q - $p + 1);
        $j = json_decode($sub, true);
        if (is_array($j)) return $j;
    }
    return null;
}

// Chuẩn hoá kết quả để luôn có đủ trường & kiểu
function _normalize_analysis(array $x): array
{
    $out = [
        'overall_risk' => 0,
        'labels' => [
            'misinformation' => false,
            'propaganda' => [],
            'scam_phishing' => false,
            'hate_speech' => false,
            'violence_threat' => false,
        ],
        'flagged_terms' => [],
        'warnings' => [],
        'safe_to_share' => true,
        'notes' => ''
    ];

    // map risk
    foreach (['overall_risk', 'overallRisk', 'risk', 'score'] as $k) {
        if (isset($x[$k]) && is_numeric($x[$k])) {
            $out['overall_risk'] = max(0, min(100, (int)round($x[$k])));
            break;
        }
    }

    // labels
    if (!empty($x['labels']) && is_array($x['labels'])) {
        $L = $x['labels'];
        foreach (['misinformation', 'scam_phishing', 'hate_speech', 'violence_threat'] as $k) {
            if (isset($L[$k])) $out['labels'][$k] = (bool)$L[$k];
        }
        // propaganda có thể là bool/array
        if (array_key_exists('propaganda', $L)) {
            $out['labels']['propaganda'] = is_array($L['propaganda']) ? array_values($L['propaganda']) : ((bool)$L['propaganda'] ? ['generic'] : []);
        }
    }

    // flagged_terms
    if (!empty($x['flagged_terms']) && is_array($x['flagged_terms'])) {
        $out['flagged_terms'] = array_values($x['flagged_terms']);
    }

    // warnings
    if (!empty($x['warnings']) && is_array($x['warnings'])) {
        $ws = [];
        foreach ($x['warnings'] as $w) {
            if (!is_array($w)) continue;
            $ws[] = [
                'title' => (string)($w['title'] ?? ''),
                'severity' => in_array($w['severity'] ?? '', ['low', 'medium', 'high', 'critical'], true) ? $w['severity'] : 'low',
                'evidence' => (string)($w['evidence'] ?? ''),
                'suggestion' => (string)($w['suggestion'] ?? ''),
            ];
        }
        $out['warnings'] = $ws;
    }

    // safe_to_share
    if (array_key_exists('safe_to_share', $x)) $out['safe_to_share'] = (bool)$x['safe_to_share'];

    // notes
    if (!empty($x['notes'])) $out['notes'] = (string)$x['notes'];

    // Nếu vẫn chưa có điểm, ước tính thô từ labels & warnings
    if (!isset($x['overall_risk']) || !is_numeric($x['overall_risk'])) {
        $risk = 0;
        $risk += $out['labels']['misinformation']  ? 30 : 0;
        $risk += count($out['labels']['propaganda']) > 0 ? 20 : 0;
        $risk += $out['labels']['scam_phishing']   ? 40 : 0;
        $risk += $out['labels']['hate_speech']     ? 35 : 0;
        $risk += $out['labels']['violence_threat'] ? 50 : 0;
        $risk += min(5, count($out['warnings'])) * 5;
        $out['overall_risk'] = max(5, min(100, $risk));
    }

    // Nếu thiếu safe_to_share, suy ra theo ngưỡng
    if (!array_key_exists('safe_to_share', $x)) {
        $out['safe_to_share'] = $out['overall_risk'] <= 40;
    }

    return $out;
}

function analyze_text_with_schema($text)
{
    $model = envv('OPENAI_MODEL', 'llama-3.1-8b-instant');
    $pres  = _pre_screen_text($text); // ← quét luật trước

    $jsonSchema = [ /* schema của bạn – đã có additionalProperties:false & required đầy đủ */];

    $prompt =
        "Bạn là hệ thống kiểm tra an toàn thông tin & định hướng đúng trên MXH.\n" .
        "Dấu hiệu tiền xử lý (heuristic) phát hiện được: " . json_encode($pres['flags']) . ".\n" .
        "Khi các dấu hiệu trên xuất hiện, hãy cân nhắc bật nhãn phù hợp và nêu bằng chứng cụ thể.\n" .
        "Phân tích dựa trên bằng chứng; nếu thiếu, ghi 'không đủ dữ liệu'.\n";

    // 1) Structured Outputs
    try {
        $resp = openai_post('/responses', [
            'model' => $model,
            'input' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $text],
            ],
            'text' => [
                'format' => [
                    'type'   => 'json_schema',
                    'name'   => 'RiskAnalysis',
                    'schema' => $jsonSchema
                ]
            ],
        ]);
        $out = _extract_text_from_response($resp);
        $parsed = $out ? _json_from_text_loose($out) : null;
        if ($parsed) return _apply_prescreen_and_normalize($parsed, $pres);
    } catch (Exception $e) { /* fallback */
    }

    // 2) Fallback: ép JSON bằng prompt thường
    $schemaForPrompt = json_encode($jsonSchema, JSON_UNESCAPED_UNICODE);
    $resp2 = openai_post('/responses', [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' =>
                "Chỉ trả về MỘT đối tượng JSON, không chữ thừa.\n" .
                    "Tuân thủ hoàn toàn schema sau:\n$schemaForPrompt\n" .
                    "Gợi ý tiền xử lý: " . json_encode($pres['flags'])
            ],
            ['role' => 'user', 'content' => $text],
        ],
        'text' => ['format' => ['type' => 'text']],
    ]);
    $out2 = _extract_text_from_response($resp2);
    $parsed2 = $out2 ? _json_from_text_loose($out2) : null;

    return _apply_prescreen_and_normalize($parsed2 ?: [], $pres);
}
