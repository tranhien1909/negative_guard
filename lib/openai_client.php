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
    // 1) Đồng bộ nhãn từ heuristic → labels
    if ($screen['flags']['harassment']) $parsed['labels']['hate_speech']   = true;
    if ($screen['flags']['scam'] || $screen['flags']['money_lure'] || $screen['flags']['sexual'])
        $parsed['labels']['scam_phishing'] = true;

    // 2) Chuẩn hoá đầu tiên (đảm bảo đủ trường)
    $norm = _normalize_analysis($parsed);

    // 3) Bắt đầu gộp cảnh báo theo tiêu đề (title tiếng Việt)
    //    bucket[title] = ['title'=>..., 'severity'=>..., 'evidence'=>[], 'suggestion'=>...]
    $bucket = [];

    // Helper gộp
    $mergeWarn = function ($title, $severity, $evidence = '', $suggestion = '') use (&$bucket) {
        $title = trim($title);
        $severity = in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'low';
        if (!isset($bucket[$title])) {
            $bucket[$title] = [
                'title' => $title,
                'severity' => $severity,
                'evidence' => [],
                'suggestion' => $suggestion ?: 'Vui lòng giữ văn minh, kiểm chứng nguồn; cảnh giác mời chào/đường link.'
            ];
        } else {
            // giữ severity cao nhất
            $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
            if ($rank[$severity] > $rank[$bucket[$title]['severity']]) {
                $bucket[$title]['severity'] = $severity;
            }
        }
        if ($evidence) $bucket[$title]['evidence'][] = $evidence;
    };

    // 3a) Gộp cảnh báo từ LLM (nếu có)
    foreach ($norm['warnings'] as $w) {
        $t = mb_strtolower($w['title'] ?? '');
        // Suy ra tag theo title tiếng Anh nếu có
        if (strpos($t, 'hate') !== false)           $title = _vn_title_for('hate_speech');
        elseif (strpos($t, 'scam') !== false || strpos($t, 'phishing') !== false)
            $title = _vn_title_for('scam_phishing');
        elseif (strpos($t, 'misinfo') !== false)    $title = _vn_title_for('misinformation');
        elseif (strpos($t, 'violence') !== false)   $title = _vn_title_for('violence_threat');
        elseif (strpos($t, 'propaganda') !== false) $title = _vn_title_for('propaganda');
        else                                        $title = _vn_title_for('other');

        $mergeWarn($title, $w['severity'] ?? 'low', trim($w['evidence'] ?? ''), trim($w['suggestion'] ?? ''));
    }

    // 3b) Gộp cảnh báo từ heuristic (rõ ràng, có tiêu đề Việt hoá)
    if ($screen['flags']['harassment']) {
        foreach ($screen['reasons'] as $r) {
            if (mb_stripos($r, 'xúc phạm') !== false) {
                $mergeWarn(_vn_title_for('hate_speech'), 'high', $r);
            }
        }
    }
    if ($screen['flags']['scam']) {
        foreach ($screen['reasons'] as $r) {
            if (mb_stripos($r, 'đa cấp') !== false || mb_stripos($r, 'lừa') !== false || mb_stripos($r, 'mời chào') !== false) {
                $mergeWarn(_vn_title_for('scam_phishing'), 'high', $r);
            }
        }
    }
    if ($screen['flags']['sexual']) {
        foreach ($screen['reasons'] as $r) {
            if (mb_stripos($r, 'tình dục') !== false || mb_stripos($r, 'mại dâm') !== false || mb_stripos($r, 'clip nóng') !== false) {
                $mergeWarn(_vn_title_for('scam_phishing'), 'medium', $r); // đẩy qua scam vì rủi ro link/câu kéo
            }
        }
    }
    if ($screen['flags']['link']) {
        $mergeWarn('Liên kết ngoài', 'medium', 'Có liên kết ngoài/URL.');
    }
    if ($screen['flags']['phone']) {
        $mergeWarn('Số điện thoại', 'low', 'Có số điện thoại.');
    }

    // 4) Chuyển bucket → warnings (evidence gộp)
    $warnings = [];
    foreach ($bucket as $title => $w) {
        $warnings[] = [
            'title'      => $title,
            'severity'   => $w['severity'],
            'evidence'   => _join_evidence($w['evidence']),
            'suggestion' => $w['suggestion'],
        ];
    }
    // sắp xếp: critical > high > medium > low
    usort($warnings, function ($a, $b) {
        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        return $rank[$b['severity']] <=> $rank[$a['severity']];
    });
    $norm['warnings'] = $warnings;

    // 5) Tính lại risk gộp (giữ nguyên công thức + heuristic)
    $norm['_model_score'] = (isset($parsed['overall_risk']) && is_numeric($parsed['overall_risk'])) ? (int)$parsed['overall_risk'] : 0;
    $norm['overall_risk'] = _compute_composite_risk($norm, $screen, $parsed);

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

// Việt hoá tiêu đề theo nhãn/flag
function _vn_title_for($tag)
{
    switch ($tag) {
        case 'hate_speech':
            return 'Ngôn từ xúc phạm/công kích';
        case 'scam_phishing':
            return 'Mời chào/lừa đảo/phishing';
        case 'misinformation':
            return 'Thông tin thiếu kiểm chứng';
        case 'violence_threat':
            return 'Kích động/đe doạ bạo lực';
        case 'propaganda':
            return 'Tuyên truyền/phiến diện';
        default:
            return 'Dấu hiệu rủi ro';
    }
}

// Nối bằng chứng thành danh sách gọn gàng
function _join_evidence(array $items)
{
    $items = array_values(array_unique(array_filter(array_map('trim', $items))));
    if (!$items) return '';
    // mỗi dòng một dấu •
    return "• " . implode("\n• ", $items);
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

    $jsonSchema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'scores' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'misinformation'  => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    'propaganda'      => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    'scam_phishing'   => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    'hate_speech'     => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    'violence_threat' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                ],
                'required' => ['misinformation', 'propaganda', 'scam_phishing', 'hate_speech', 'violence_threat']
            ],
            'severity_hint' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'evidence_spans' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'misinformation'  => ['type' => 'array', 'items' => ['type' => 'string']],
                    'propaganda'      => ['type' => 'array', 'items' => ['type' => 'string']],
                    'scam_phishing'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'hate_speech'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'violence_threat' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['misinformation', 'propaganda', 'scam_phishing', 'hate_speech', 'violence_threat']
            ],
            'rewrite_suggestion' => ['type' => 'string'],
        ],
        'required' => ['scores', 'severity_hint', 'evidence_spans', 'rewrite_suggestion']
    ];

    // tiền xử lý heuristics sẵn có của bạn
    $pres  = _pre_screen_text($text);

    $rubric = <<<TXT
Bạn là bộ chấm điểm rủi ro nội dung MXH (tiếng Việt).
Chấm điểm 0–100 cho từng hạng mục: misinfo, propaganda, scam/phishing, hate speech, violence/threat.
Quy tắc:
- 0–20: hầu như không có dấu hiệu; 21–40: có dấu hiệu nhẹ/ẩn; 41–60: đáng chú ý; 61–80: cao; 81–100: rất cao/rõ rệt.
- Chỉ dùng bằng chứng trong văn bản. Nếu thiếu dữ liệu, cho điểm thấp.
- Ánh xạ gợi ý từ tiền xử lý (heuristic) sau vào điểm, nhưng vẫn ưu tiên bằng chứng:
  FLAGS: %s
- Trả về JSON đúng schema; KHÔNG thêm chữ thừa.
TXT;

    $prompt = sprintf($rubric, json_encode($pres['flags'], JSON_UNESCAPED_UNICODE));

    // Gọi Responses API dạng structured (json_schema)
    $resp = openai_post('/responses', [
        'model' => $model,
        'input' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user',   'content' => $text],
        ],
        'temperature' => (float) envv('LLM_TEMPERATURE', 0.2), // giảm dao động
        'top_p'       => (float) envv('LLM_TOP_P', 0.3),
        'text' => [
            'format' => [
                'type'   => 'json_schema',
                'name'   => 'RiskScoring',
                'schema' => $jsonSchema
            ]
        ],
    ]);

    $out = _extract_text_from_response($resp);
    $parsed = $out ? _json_from_text_loose($out) : null;
    if (!$parsed) $parsed = ['scores' => ['misinformation' => 0, 'propaganda' => 0, 'scam_phishing' => 0, 'hate_speech' => 0, 'violence_threat' => 0], 'severity_hint' => 0, 'evidence_spans' => ['misinformation' => [], 'propaganda' => [], 'scam_phishing' => [], 'hate_speech' => [], 'violence_threat' => []], 'rewrite_suggestion' => ''];

    // Hợp nhất với heuristics + chuẩn hoá
    $norm = _post_calibrate_scores($parsed, $pres);
    return $norm;
}


function _post_calibrate_scores(array $modelOut, array $pres): array
{
    // 1) lấy điểm từng nhãn
    $S = $modelOut['scores'] ?? [];
    $mis = max(0, min(100, (float)($S['misinformation']  ?? 0)));
    $pro = max(0, min(100, (float)($S['propaganda']      ?? 0)));
    $scm = max(0, min(100, (float)($S['scam_phishing']   ?? 0)));
    $hat = max(0, min(100, (float)($S['hate_speech']     ?? 0)));
    $vio = max(0, min(100, (float)($S['violence_threat'] ?? 0)));

    // 2) “đẩy nhẹ” theo heuristic flags (±5–15 tuỳ flag)
    $boost = 0;
    $flags = $pres['flags'] ?? [];
    if (!empty($flags['scam'])) {
        $scm += 12;
        $boost += 4;
    }
    if (!empty($flags['money_lure'])) {
        $scm += 8;
        $boost += 2;
    }
    if (!empty($flags['harassment'])) {
        $hat += 10;
        $boost += 3;
    }
    if (!empty($flags['sexual'])) {
        $hat += 6;
        $boost += 1;
    }
    if (!empty($flags['link'])) {
        $mis += 4;
        $boost += 1;
    }

    $mis = max(0, min(100, $mis));
    $pro = max(0, min(100, $pro));
    $scm = max(0, min(100, $scm));
    $hat = max(0, min(100, $hat));
    $vio = max(0, min(100, $vio));

    // 3) công thức tổng hợp LIÊN TỤC (không bậc thang)
    //   - nhấn mạnh max rủi ro + top2 + severity_hint + heur_score chuẩn hoá
    $sev = max(0, min(100, (float)($modelOut['severity_hint'] ?? 0)));
    $arr = [$mis, $pro, $scm, $hat, $vio];
    rsort($arr); // giảm dần
    $max1 = $arr[0];
    $max2 = $arr[1] ?? 0;
    $avgTop2 = ($max1 + $max2) / 2;

    $heur  = max(0, min(100, (float)($pres['heur_score'] ?? 0)));
    $heurN = min(100, 20 + 0.8 * $heur); // ép về 20..100 để có “độ nền”

    // Trọng số có thể tinh chỉnh qua .env
    $w1 = (float) envv('RISK_W_MAX',    0.45);
    $w2 = (float) envv('RISK_W_TOP2',   0.25);
    $w3 = (float) envv('RISK_W_SEV',    0.15);
    $w4 = (float) envv('RISK_W_HEUR',   0.15);

    $sum = max(1e-6, $w1 + $w2 + $w3 + $w4);
    $w1 /= $sum;
    $w2 /= $sum;
    $w3 /= $sum;
    $w4 /= $sum;

    $score = $w1 * $max1 + $w2 * $avgTop2 + $w3 * $sev + $w4 * $heurN;

    // 4) làm mịn (smoothing) để đỡ “tụ” 0/50/70: logistic nhẹ
    $k = 0.035; // độ cong, chỉnh tuỳ dữ liệu
    $smoothed = 100.0 / (1.0 + exp(-$k * ($score - 50)));

    // 5) trả kết quả chuẩn hóa + bằng chứng
    $out = [
        'overall_risk' => (int) round($smoothed),
        'labels' => [
            'misinformation'  => $mis >= 50,
            'propaganda'      => $pro >= 50,
            'scam_phishing'   => $scm >= 50,
            'hate_speech'     => $hat >= 50,
            'violence_threat' => $vio >= 50,
        ],
        'per_label_scores' => [
            'misinformation'  => round($mis, 1),
            'propaganda'      => round($pro, 1),
            'scam_phishing'   => round($scm, 1),
            'hate_speech'     => round($hat, 1),
            'violence_threat' => round($vio, 1),
        ],
        'severity_hint' => round($sev, 1),
        'flagged_terms' => [], // bạn có thể map thêm từ _pre_screen_text
        'warnings' => [],      // có thể dựng từ evidence_spans
        'safe_to_share' => ($smoothed <= (int)envv('SAFE_SHARE_THRESHOLD', 45)),
        'notes' => 'calibrated_v2'
    ];

    // bằng chứng: lấy top 1–2 câu mỗi nhãn
    $ev = $modelOut['evidence_spans'] ?? [];
    foreach (['misinformation', 'propaganda', 'scam_phishing', 'hate_speech', 'violence_threat'] as $k) {
        foreach (array_slice($ev[$k] ?? [], 0, 2) as $span) {
            $out['warnings'][] = [
                'title' => "Bằng chứng: $k",
                'severity' => ($out['per_label_scores'][$k] >= 70 ? 'high' : ($out['per_label_scores'][$k] >= 50 ? 'medium' : 'low')),
                'evidence' => (string)$span,
                'suggestion' => (string)($modelOut['rewrite_suggestion'] ?? '')
            ];
        }
    }

    return $out;
}
