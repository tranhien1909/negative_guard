<?php

declare(strict_types=1);

function fb_comment(string $objectId, string $message, string $pageAccessToken): array
{
    $url = "https://graph.facebook.com/v21.0/{$objectId}/comments";
    return http_post($url, ['message' => $message, 'access_token' => $pageAccessToken]);
}

function fb_hide_comment(string $commentId, bool $hidden, string $pageAccessToken): array
{
    // POST /{comment_id}?is_hidden=true|false
    $url = "https://graph.facebook.com/v21.0/{$commentId}";
    return http_post($url, ['is_hidden' => $hidden ? 'true' : 'false', 'access_token' => $pageAccessToken]);
}

function http_post(string $url, array $data): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($out === false) throw new RuntimeException('cURL: ' . curl_error($ch));
    curl_close($ch);
    $j = json_decode($out, true);
    if ($code >= 300) throw new RuntimeException("Graph error($code): $out");
    return $j ?: [];
}
