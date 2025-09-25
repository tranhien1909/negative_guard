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
        if ($x !== false) $s = $x; // "đm" -> "dm", "óc chó" -> "oc cho"
    }
    $s = preg_replace('/[^a-z0-9@:\.\/\-\s]/', ' ', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

// trả true nếu regex (cho chuỗi đã chuẩn hóa và/hoặc chuỗi gốc) khớp
function _has_kw(string $norm, string $orig, string $re_norm, ?string $re_utf = null): bool
{
    if (preg_match($re_norm, $norm)) return true;
    if ($re_utf && preg_match($re_utf, $orig)) return true;
    return false;
}
// gom bằng chứng khớp regex (ưu tiên có dấu, nếu không có thì lấy không dấu)
function _collect_ev(string $norm, string $orig, string $re_norm, ?string $re_utf = null): array
{
    $m = [];
    if ($re_utf && preg_match_all($re_utf, $orig, $m) && !empty($m[0])) return array_values(array_unique($m[0]));
    $m = [];
    if (preg_match_all($re_norm, $norm, $m) && !empty($m[0])) return array_values(array_unique($m[0]));
    return [];
}

// Quét luật đơn giản: xúc phạm, lừa đảo/đa cấp, mồi tiền, link, SĐT
function _pre_screen_text($text)
{
    $t = _vi_norm($text);

    $flags = ['harassment' => false, 'scam' => false, 'money_lure' => false, 'sexual' => false, 'bribery' => false, 'link' => false, 'phone' => false];
    $ev = ['harassment' => [], 'bribery' => [], 'sexual' => [], 'link' => [], 'phone' => []];
    $reasons = [];
    $heur = 0;

    // xúc phạm
    $bad = '(dm|dmm|dit me|djt|clm|bò|cc|clgt|oc cho|oc lon|lon|ngu lon|mat day|vo hoc|do oc|do dot|do dan|ham|thang|con cho|do ban|do khung|ngu|do ec)';
    if (preg_match_all('/\b' . $bad . '\b/u', $t, $m)) {
        $flags['harassment'] = true;
        $ev['harassment'] = array_values(array_unique($m[0]));
        $cnt = count($m[0]);
        $heur += min(45, 22 + ($cnt - 1) * 9);
        $reasons[] = "Ngôn từ xúc phạm ($cnt lần).";
    }
    if (preg_match('/\b(thay|co)\s+ham\b/u', $t, $m2)) {
        $flags['harassment'] = true;
        $heur += 10;
        $ev['harassment'][] = 'thay ham';
        $reasons[] = 'Công kích giáo viên.';
    }

    // --- Bribery / "phong bì" ---
    $reBrNorm = '/(?:^|[^a-z0-9])(phong\s*bi|lot\s*tay|di\s*tien|chay\s*tien|hoi\s*lo)(?=$|[^a-z0-9])/i';
    $reBrUtf  = '/\b(phong\s*bì|lót\s*tay|đi\s*tiền|chạy\s*tiền|hối\s*lộ|ăn\s*chặn)\b/iu';
    if (_has_kw($t, $text, $reBrNorm, $reBrUtf)) {
        $flags['bribery'] = true;
        $flags['scam'] = true;
        $heur += 25;
        $ev['bribery'] = _collect_ev($t, $text, $reBrNorm, $reBrUtf);
        $reasons[] = 'Dấu hiệu hối lộ/“phong bì”.';
    }

    // --- Sexual / “mại dâm”, clip nóng ---
    $reSexNorm = '/(?:^|[^a-z0-9])(mai\s*dam|gai\s*goi|clip\s*nong|video\s*nong|xxx|sex|18\+|jav|phim\s*sex|link\s*nong|nhay\s*cam)(?=$|[^a-z0-9])/i';
    $reSexUtf  = '/\b(mại\s*dâm|gái\s*gọi|clip\s*nóng|video\s*nóng|xxx|sex|18\+|jav|phim\s*sex|link\s*nóng|nhạy\s*cảm)\b/iu';
    if (_has_kw($t, $text, $reSexNorm, $reSexUtf)) {
        $flags['sexual'] = true;
        $flags['scam'] = true;
        $heur += 35;
        $ev['sexual'] = _collect_ev($t, $text, $reSexNorm, $reSexUtf);
        $reasons[] = 'Nội dung tình dục/“mại dâm”.';
    }

    // mời chào kiếm tiền
    if (preg_match('/\b(kiem tien nhanh|0 von|thu nhap khung|viec nhe|luong cao|chi can dien thoai|da cap)\b/u', $t)) {
        $flags['scam'] = true;
        $heur += 25;
        $reasons[] = 'Mời chào kiếm tiền/đa cấp.';
    }
    if (preg_match('/\b(tien thuong|chuyen khoan truoc|coc|hoa hong)\b/u', $t)) {
        $flags['money_lure'] = true;
        $heur += 10;
    }

    // link + miền rủi ro
    if (preg_match_all('/https?:\/\/\S+/i', $text, $m)) {
        $flags['link'] = true;
        $heur += 6;
        $ev['link'] = $m[0];
        $reasons[] = 'Có liên kết ngoài.';
    }
    if (preg_match('/(bit\.ly|t\.me|telegram|zalo\.me|wa\.me|m\.me)/i', $text)) {
        $heur += 9;
        $reasons[] = 'Tên miền rủi ro cao.';
    }

    // SĐT
    if (preg_match_all('/\b0\d{9,10}\b/', $t, $m)) {
        $flags['phone'] = true;
        $heur += 4;
        $ev['phone'] = $m[0];
    }

    // biểu cảm
    $exclam = substr_count($text, '!');
    if ($exclam >= 3) $heur += 6;
    elseif ($exclam >= 1) $heur += 2;
    $letters = preg_replace('/[^A-Za-zÀ-ỹĂÂÊÔƠƯĐ]/u', '', $text);
    $uppers  = preg_replace('/[^A-ZĐÊÔƠƯ]/u', '', $text);
    $ratio = (mb_strlen($letters, 'UTF-8') ?: 1);
    $ratio = mb_strlen($uppers, 'UTF-8') / $ratio;
    if ($ratio > 0.6) $heur += 8;
    elseif ($ratio > 0.3) $heur += 4;

    return ['flags' => $flags, 'evidence' => $ev, 'reasons' => $reasons, 'heur_score' => $heur];
}


function _dedupe_warnings(array $list): array
{
    $seen = [];
    $out = [];
    foreach ($list as $w) {
        $title = mb_strtolower(trim($w['title'] ?? ''));
        $ev    = mb_strtolower(preg_replace('/\s+/', ' ', trim($w['evidence'] ?? '')));
        $key   = $title . '|' . $ev;
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $out[] = $w;
    }
    return $out;
}

// Hợp nhất kết quả LLM + luật, tính lại risk
function _apply_prescreen_and_normalize(array $parsed, array $screen): array
{
    // Ánh xạ nhãn theo cờ luật
    if ($screen['flags']['harassment']) $parsed['labels']['hate_speech'] = true;
    if ($screen['flags']['scam'] || $screen['flags']['money_lure'] || $screen['flags']['sexual'])
        $parsed['labels']['scam_phishing'] = true;

    // Chuẩn hoá trước
    $norm = _normalize_analysis($parsed);

    // >>> CHỈ DÙNG CẢNH BÁO TỪ LUẬT <<<
    $w = [];
    if ($screen['flags']['harassment']) {
        $ev = $screen['evidence']['harassment'] ?? [];
        $w[] = [
            'title' => 'Ngôn từ xúc phạm/công kích',
            'severity' => 'high',
            'evidence' => $ev ? ('Từ ngữ: ' . implode(', ', array_slice($ev, 0, 5))) : 'Từ ngữ xúc phạm',
            'suggestion' => 'Giữ văn minh, tránh công kích cá nhân.'
        ];
    }
    if ($screen['flags']['bribery']) {
        $ev = $screen['evidence']['bribery'] ?? [];
        $w[] = [
            'title' => 'Cáo buộc phong bì/hối lộ',
            'severity' => 'high',
            'evidence' => $ev ? ('Từ khoá: ' . implode(', ', array_slice($ev, 0, 5))) : '“phong bì/lót tay/đi tiền”',
            'suggestion' => 'Không cáo buộc khi thiếu chứng cứ; nếu có nguồn, hãy dẫn link chính thống.'
        ];
    }
    if ($screen['flags']['sexual']) {
        $ev = $screen['evidence']['sexual'] ?? [];
        $w[] = [
            'title' => 'Rủ rê nội dung nhạy cảm',
            'severity' => 'high',
            'evidence' => $ev ? ('Từ khoá: ' . implode(', ', array_slice($ev, 0, 5))) : 'mại dâm/clip nóng…',
            'suggestion' => 'Tránh phát tán; cảnh giác link/nhóm lạ.'
        ];
    }
    if ($screen['flags']['link']) {
        $ev = $screen['evidence']['link'] ?? [];
        $w[] = [
            'title' => 'Liên kết ngoài/miền rủi ro',
            'severity' => 'medium',
            'evidence' => $ev ? ('Link: ' . implode(', ', array_slice($ev, 0, 3))) : 'Có liên kết ngoài',
            'suggestion' => 'Không bấm link lạ; kiểm tra tên miền; báo cáo nếu nghi ngờ.'
        ];
    }
    if ($screen['flags']['phone']) {
        $ev = $screen['evidence']['phone'] ?? [];
        $w[] = [
            'title' => 'Số liên hệ công khai',
            'severity' => 'low',
            'evidence' => $ev ? ('Số: ' . implode(', ', $ev)) : 'Có số điện thoại',
            'suggestion' => 'Tránh chia sẻ thông tin cá nhân trong bình luận.'
        ];
    }
    // Ghi đè hoàn toàn warnings từ model
    $norm['warnings'] = $w;

    // TÍNH LẠI RISK (giữ công thức tổng hợp bạn đang dùng)
    $norm['_model_score'] = (isset($parsed['overall_risk']) && is_numeric($parsed['overall_risk'])) ? (int)$parsed['overall_risk'] : 0;
    $norm['overall_risk']  = _compute_composite_risk($norm, $screen, $parsed);

    // >>> MỨC SÀN THEO CỜ LUẬT <<<
    if (!empty($screen['flags']['sexual']))   $norm['overall_risk'] = max($norm['overall_risk'], 75);
    if (!empty($screen['flags']['bribery']))  $norm['overall_risk'] = max($norm['overall_risk'], 65);
    if (!empty($screen['flags']['harassment'])) $norm['overall_risk'] = max($norm['overall_risk'], 45);

    // Quyết định chia sẻ
    $shareTh = (int) envv('SAFE_SHARE_THRESHOLD', 45);
    $norm['safe_to_share'] = $norm['overall_risk'] <= $shareTh;
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
        $out['overall_risk'] = max(0, min(100, $risk));
    }

    // Nếu thiếu safe_to_share, suy ra theo ngưỡng
    if (!array_key_exists('safe_to_share', $x)) {
        $out['safe_to_share'] = $out['overall_risk'] <= 40;
    }

    return $out;
}

function _compute_composite_risk(array $norm, array $screen, ?array $origParsed): int
{
    // Trọng số có thể chỉnh qua .env
    $wModel  = (float) envv('RISK_WEIGHT_MODEL', 0.2);
    $wLabels = (float) envv('RISK_WEIGHT_LABELS', 0.5);
    $wHeur   = (float) envv('RISK_WEIGHT_HEUR', 0.3);
    $sum = max(1e-6, $wModel + $wLabels + $wHeur);
    $wModel /= $sum;
    $wLabels /= $sum;
    $wHeur /= $sum;

    $modelScore = (isset($origParsed['overall_risk']) && is_numeric($origParsed['overall_risk']))
        ? (int)$origParsed['overall_risk'] : 0;

    $L = $norm['labels'] ?? [];
    $labelScore  = 0;
    $labelScore += !empty($L['misinformation'])   ? 25 : 0;
    $labelScore += !empty($L['propaganda']) && count($L['propaganda']) ? 10 : 0;
    $labelScore += !empty($L['scam_phishing'])     ? 50 : 0; // ↑
    $labelScore += !empty($L['hate_speech'])       ? 40 : 0; // ↑
    $labelScore += !empty($L['violence_threat'])   ? 70 : 0; // ↑

    foreach (($norm['warnings'] ?? []) as $w) {
        $sev = $w['severity'] ?? 'low';
        $labelScore += ($sev === 'critical' ? 15 : ($sev === 'high' ? 10 : ($sev === 'medium' ? 6 : 3)));
    }
    $labelScore = min(100, $labelScore);

    $heurScore = (int) round(min(100, max(0, (float)($screen['heur_score'] ?? 0))));

    $score = $wModel * $modelScore + $wLabels * $labelScore + $wHeur * $heurScore;
    return (int) max(0, min(100, round($score)));
}


function analyze_text_with_schema($text)
{
    $model = envv('OPENAI_MODEL', 'llama-3.1-8b-instant');
    $pres  = _pre_screen_text($text); // ← quét luật trước

    // Nếu đã có dấu hiệu mạnh -> trả kết quả theo luật (ổn định, không gọi model)
    if ($pres['flags']['bribery'] || $pres['flags']['sexual'] || $pres['flags']['harassment']) {
        $parsed = [
            'overall_risk'  => null,
            'labels'        => [
                'misinformation'  => false,
                'propaganda'      => [],
                'scam_phishing'   => ($pres['flags']['bribery'] || $pres['flags']['sexual']),
                'hate_speech'     => $pres['flags']['harassment'],
                'violence_threat' => false,
            ],
            'flagged_terms' => [],
            'warnings'      => [],   // sẽ được xây lại bằng luật ở _apply_prescreen_and_normalize
            'safe_to_share' => null,
            'notes'         => 'rule_only'
        ];
        $norm = _apply_prescreen_and_normalize($parsed, $pres);

        // nâng sàn tuyệt đối lần nữa cho chắc
        if ($pres['flags']['sexual'])    $norm['overall_risk'] = max($norm['overall_risk'], 80);
        if ($pres['flags']['bribery'])   $norm['overall_risk'] = max($norm['overall_risk'], 70);
        if ($pres['flags']['harassment']) $norm['overall_risk'] = max($norm['overall_risk'], 50);

        return $norm;
    }

    $jsonSchema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'overall_risk' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'labels' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'misinformation'  => ['type' => 'boolean'],
                    'propaganda'      => ['type' => 'array', 'items' => ['type' => 'string']],
                    'scam_phishing'   => ['type' => 'boolean'],
                    'hate_speech'     => ['type' => 'boolean'],
                    'violence_threat' => ['type' => 'boolean'],
                ],
                'required' => ['misinformation', 'propaganda', 'scam_phishing', 'hate_speech', 'violence_threat']
            ],
            'flagged_terms' => ['type' => 'array', 'items' => ['type' => 'string']],
            'warnings' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'title'      => ['type' => 'string'],
                        'severity'   => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                        'evidence'   => ['type' => 'string'],
                        'suggestion' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'severity', 'evidence', 'suggestion']
                ]
            ],
            'safe_to_share' => ['type' => 'boolean'],
            'notes'         => ['type' => 'string'],
        ],
        'required' => ['overall_risk', 'labels', 'flagged_terms', 'warnings', 'safe_to_share', 'notes']
    ];
    $prompt =
        "Bạn là hệ thống kiểm tra rủi ro nội dung MXH.\n" .
        "Chỉ đánh giá theo các nhóm: tin giả/thiếu nguồn, mời chào/lừa đảo, xúc phạm/công kích, kích động bạo lực, nội dung nhạy cảm, liên kết rủi ro, thông tin cá nhân.\n" .
        "Không suy đoán về chính trị, tội phạm, cơ quan nhà nước… nếu văn bản KHÔNG nêu rõ. Không thêm cảnh báo không có bằng chứng trong văn bản.\n" .
        "Trả JSON đúng schema, không thêm chữ khác.\n";

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
            'temperature' => 0,
            'top_p' => 1,
            'store' => false,
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
        'temperature' => 0,
        'top_p' => 1,
        'store' => false,
    ]);
    $out2 = _extract_text_from_response($resp2);
    $parsed2 = $out2 ? _json_from_text_loose($out2) : null;

    return _apply_prescreen_and_normalize($parsed2 ?: [], $pres);
}
