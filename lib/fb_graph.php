<?php
require_once __DIR__ . '/config.php';

function fb_api($endpoint, $params = [])
{
    $version = envv('FB_GRAPH_VERSION', 'v23.0');
    $token   = envv('FB_PAGE_ACCESS_TOKEN');
    $base    = "https://graph.facebook.com/$version";
    $params['access_token'] = $token;
    $url = $base . $endpoint . '?' . http_build_query($params);
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $res = curl_exec($ch);
    if ($res === false) throw new Exception('Graph cURL: ' . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new Exception("Graph HTTP $code: $res");
    return json_decode($res, true);
}

function fb_api_post($endpoint, $params = [])
{
    $version = envv('FB_GRAPH_VERSION', 'v23.0');
    $token   = envv('FB_PAGE_ACCESS_TOKEN');
    $base    = "https://graph.facebook.com/$version";
    $params['access_token'] = $token;
    $ch = curl_init($base . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    if ($res === false) throw new Exception('Graph cURL: ' . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new Exception("Graph HTTP $code: $res");
    return json_decode($res, true);
}

function fb_api_delete($endpoint, $params = [])
{
    $version = envv('FB_GRAPH_VERSION', 'v23.0');
    $token   = envv('FB_PAGE_ACCESS_TOKEN');
    $base    = "https://graph.facebook.com/$version";
    $params['access_token'] = $token;
    $ch = curl_init($base . $endpoint . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    if ($res === false) throw new Exception('Graph cURL: ' . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) throw new Exception("Graph HTTP $code: $res");
    return json_decode($res, true);
}

// Lấy bài viết Page (đÃ bỏ insights.metric(...))
function fb_get_page_posts($limit = 20)
{
    $pageId = envv('FB_PAGE_ID');
    $fields = 'id,message,created_time,permalink_url,from,full_picture,reactions.summary(true),comments.summary(true){id,from,message,created_time,like_count,permalink_url},shares';
    return fb_api("/{$pageId}/posts", ['fields' => $fields, 'limit' => $limit]);
}

// Đăng bài thông báo lên Page
function fb_publish_post($message)
{
    $pageId = envv('FB_PAGE_ID');
    return fb_api_post("/{$pageId}/feed", ['message' => $message]); // POST /{page-id}/feed
}

// Bình luận vào post/hoặc reply vào comment
function fb_comment($objectId, $message)
{
    return fb_api_post("/{$objectId}/comments", ['message' => $message]); // POST /{object-id}/comments
}

// Ẩn/hiện bình luận
function fb_hide_comment($commentId, $hidden = true)
{
    return fb_api_post("/{$commentId}", ['is_hidden' => $hidden ? 'true' : 'false']);
}

// Xoá bình luận (nếu cần)
function fb_delete_comment($commentId)
{
    return fb_api_delete("/{$commentId}");
}

// Lấy bài mới theo khoảng thời gian
function fb_get_page_posts_since($since_unix, $limit = 25)
{
    $pageId = envv('FB_PAGE_ID');
    $fields = 'id,message,created_time,permalink_url';
    return fb_api("/{$pageId}/posts", ['fields' => $fields, 'since' => $since_unix, 'limit' => $limit]);
}

// Lấy comment chi tiết của 1 post (lọc theo since)
function fb_get_post_comments_since($postId, $since_unix, $limit = 100)
{
    $fields = 'id,from,message,created_time,like_count,permalink_url,is_hidden';
    return fb_api("/{$postId}/comments", [
        'fields' => $fields,
        'filter' => 'stream',
        'since'  => $since_unix,
        'limit'  => $limit,
        'order'  => 'reverse_chronological'
    ]);
}

// Lấy chi tiết 1 comment để render nhanh trong Admin
function fb_get_comment($commentId)
{
    $fields = 'id,from{name,id},message,created_time,permalink_url,is_hidden,parent{id}';
    return fb_api("/{$commentId}", ['fields' => $fields]);
}
