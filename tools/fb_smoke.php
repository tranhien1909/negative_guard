<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/fb_graph.php';

$pageId = envv('FB_PAGE_ID');
$token  = envv('FB_PAGE_ACCESS_TOKEN');

echo "PAGE_ID = $pageId\n";
echo "TOKEN   = " . (strlen($token) ? substr($token, 0, 8) . '...' : '(empty)') . "\n\n";

try {
    $posts = fb_api("/$pageId/posts", [
        'limit'  => 10,
        'fields' => 'id,created_time,permalink_url'
    ]);
    $arr = $posts['data'] ?? [];
    echo "Posts fetched: " . count($arr) . "\n";
    foreach ($arr as $i => $p) {
        echo sprintf("  %2d) %s  %s\n", $i + 1, $p['id'], $p['created_time']);
        $cm = fb_api("/{$p['id']}/comments", [
            'filter' => 'stream',
            'limit'  => 5,
            'fields' => 'id,from{name},message,created_time'
        ]);
        $cmc = count($cm['data'] ?? []);
        echo "      comments: $cmc\n";
    }
} catch (Exception $e) {
    echo "Graph ERROR: " . $e->getMessage() . "\n";
}
