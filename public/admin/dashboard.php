<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fb_graph.php';
require_once __DIR__ . '/../../lib/openai_client.php';
require_admin();
send_security_headers();


$err = '';
$posts = [];
try {
    $posts = fb_get_page_posts(20)['data'] ?? [];
} catch (Exception $e) {
    $err = $e->getMessage();
}
?>
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Fanpage</title>
    <link rel="stylesheet" href="/assets/styles.css">
    <style>
        body {
            font-family: system-ui, Segoe UI, Roboto, Arial, sans-serif;
            margin: 0;
            background: #ffffffff;
            color: while
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
            background: #9ca2d9ff;
            border-bottom: 1px solid #20274a
        }

        h1 {
            font-size: 20px;
            margin: 0
        }

        nav a {
            color: #9cc1ff;
            text-decoration: none
        }

        main {
            max-width: 900px;
            margin: 24px auto;
            padding: 0 16px
        }

        form textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #2c3566;
            background: white;
            color: black;
        }

        button {
            margin-top: 12px;
            padding: 10px 16px;
            border-radius: 12px;
            border: 0;
            background: #3759ff;
            color: #fff;
            cursor: pointer
        }

        .checkbox {
            display: block;
            margin-top: 8px;
            color: #a5b4fc
        }

        #result {
            margin-top: 24px
        }

        .warning {
            border: 1px solid #374151;
            border-left: 6px solid #64748b;
            border-radius: 10px;
            padding: 12px;
            margin: 10px 0;
            background: #fefefeff
        }

        .warning.high {
            border-left-color: #f59e0b
        }

        .warning.critical {
            border-left-color: #ef4444
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #1f2a5a;
            color: #9cc1ff;
            margin-right: 6px
        }

        #risk {
            font-weight: 600;
            margin-bottom: 8px
        }
    </style>
</head>

<body>
    <header style="display:flex;gap:12px;align-items:center;justify-content:space-between;padding:16px 24px;background:#0f1530;border-bottom:1px solid #20274a">
        <div><strong style="color: red;">Admin Dashboard</strong></div>
        <nav>
            <a href="/admin/moderation.php" class="badge btn-danger">Cảnh báo cao</a>
            <a href="/logout.php" class="badge btn-success">Đăng xuất</a>
        </nav>
    </header>
    <main style="max-width:1100px;margin:24px auto;padding:0 16px">
        <?php if ($err): ?><div class="warning critical">Lỗi Graph API: <?= htmlspecialchars($err) ?></div><?php endif; ?>

        <form method="post" action="/admin/action.php" onsubmit="return publishNotice(event)" style="margin:16px 0">
            <div class="row" style="display: flex; gap: 10px;">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="publish_post">
                <textarea rows="2" name="message" id="" class="col-md-7" placeholder="Nhập nội dung đăng bài cảnh báo!"></textarea>
                <button type="submit" class="col-md-5">Đăng bài thông báo</button>
            </div>
        </form>


        <?php foreach ($posts as $p): ?>
            <article class="warning" style="border-left-color:#3759ff">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center">
                    <div>
                        <div><a class="badge" target="_blank" href="<?= htmlspecialchars($p['permalink_url'] ?? '#') ?>">Mở Facebook</a> <span class="badge"><?= htmlspecialchars($p['id']) ?></span></div>
                        <div style="margin-top:8px;white-space:pre-wrap;"><?= htmlspecialchars($p['message'] ?? '[Không có nội dung]') ?></div>
                        <div style="opacity:.7;margin-top:6px;">Đăng lúc: <?= htmlspecialchars($p['created_time'] ?? '') ?></div>
                    </div>
                    <?php if (!empty($p['full_picture'])): ?>
                        <img src="<?= htmlspecialchars($p['full_picture']) ?>" alt="thumb" style="max-width:200px;border-radius:10px">
                    <?php endif; ?>
                </div>


                <details style="margin-top:10px">
                    <summary>Bình luận (<?= (int)($p['comments']['summary']['total_count'] ?? 0) ?>)</summary>
                    <div>
                        <?php foreach (($p['comments']['data'] ?? []) as $c): ?>
                            <div class="warning">
                                <div style="font-weight:600;"><?= htmlspecialchars(($c['from']['name'] ?? 'Ẩn danh') . ' — ' . ($c['created_time'] ?? '')) ?></div>
                                <div style="white-space:pre-wrap;"><?= htmlspecialchars($c['message'] ?? '') ?></div>
                                <form method="post" action="/admin/action.php" onsubmit="return doComment(event, '<?= htmlspecialchars($c['id']) ?>')">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="comment">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
                                    <textarea name="message" placeholder="Phản hồi cảnh báo..." style="width:100%; margin-top: 10px;"></textarea>
                                    <button class="badge" type="submit">Trả lời</button>
                                </form>
                                <div id="res-<?= htmlspecialchars($c['id']) ?>" class="warning" style="display:none"></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>


                <!-- <button class="analyze-post-btn" data-post-id="<?= htmlspecialchars($p['id']) ?>">
                    Phân tích bài viết
                </button> -->
                <div id="ana-<?= htmlspecialchars($p['id']) ?>" class="analysis-box"></div>
            </article>
        <?php endforeach; ?>
    </main>
    <script>
        async function analyze(targetId, text) {
            const res = await fetch('/analyze.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    text,
                    saveLog: true
                })
            });
            return await res.json();
        }

        function render(el, data) {
            el.style.display = 'block';
            el.innerHTML = `<div class="badge">Risk ${data.overall_risk}</div>` +
                (data.warnings || []).map(w => `<div class="warning ${w.severity}"><strong>${w.title}</strong><div>${w.evidence}</div><div><em>Gợi ý:</em> ${w.suggestion}</div></div>`).join('');
        }

        function analyzePost(e, id, text) {
            e.preventDefault();
            const el = document.getElementById('res-' + id);
            el.textContent = 'Đang phân tích...';
            analyze(id, text).then(d => render(el, d));
            return false;
        }

        function analyzeComment(e, id, text) {
            e.preventDefault();
            const el = document.getElementById('res-' + id);
            el.textContent = 'Đang phân tích...';
            analyze(id, text).then(d => render(el, d));
            return false;
        }
    </script>

    <!-- post và comment -->
    <script>
        async function doComment(e, id) {
            e.preventDefault();
            const fd = new FormData(e.target);
            const res = await fetch('/admin/action.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.error) alert('Lỗi: ' + data.error);
            else alert('Đã bình luận!');
            return false;
        }
        async function toggleHide(id, hide) {
            const fd = new FormData();
            fd.append('csrf', '<?= htmlspecialchars(csrf_token()) ?>');
            fd.append('action', 'hide_comment');
            fd.append('id', id);
            fd.append('hide', hide ? '1' : '0');
            const res = await fetch('/admin/action.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.error) alert('Lỗi: ' + data.error);
            else alert(hide ? 'Đã ẩn' : 'Đã hiện');
        }
    </script>

    <!-- Đăng thông báo -->
    <script>
        async function publishNotice(e) {
            e.preventDefault();
            const fd = new FormData(e.target);
            const res = await fetch('/admin/action.php', {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (data.error) alert('Lỗi: ' + data.error);
            else alert('Đã đăng bài: ' + (data.id || 'OK'));
            return false;
        }
    </script>
    <script>
        async function renderAnalysis(container, data) {
            const html = [];
            if (data.post) {
                html.push(`<div class="card">
      <div><b>Post</b> — rủi ro: ${data.post.risk}/100
        · <a target="_blank" href="${data.post.permalink_url||'#'}">Mở Facebook</a></div>
    </div>`);
            }
            if (data.comments?.length) {
                data.comments.sort((a, b) => b.risk - a.risk);
                for (const c of data.comments) {
                    html.push(`<div class="card">
        <div><b>${c.from||'N/A'}</b> — rủi ro: ${c.risk}/100</div>
        <div style="white-space:pre-wrap">${(c.message||'').replace(/[<>&]/g,m=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[m]))}</div>
        <div class="muted">${c.created_time||''}</div>
      </div>`);
                }
            } else {
                html.push('<div class="muted">Không có bình luận để phân tích.</div>');
            }
            container.innerHTML = html.join('');
        }

        document.querySelectorAll('.analyze-post-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.postId;
                const box = document.getElementById('ana-' + id);
                btn.disabled = true;
                const old = btn.textContent;
                btn.textContent = 'Đang phân tích...';
                try {
                    const fd = new FormData();
                    fd.append('csrf', '<?= htmlspecialchars(csrf_token()) ?>');
                    fd.append('action', 'analyze_post');
                    fd.append('id', id);
                    const res = await fetch('/admin/action.php', {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();
                    if (data.error) {
                        box.textContent = 'Lỗi: ' + data.error;
                    } else {
                        await renderAnalysis(box, data);
                    }
                } catch (e) {
                    box.textContent = 'Lỗi kết nối: ' + e.message;
                } finally {
                    btn.disabled = false;
                    btn.textContent = old;
                }
            });
        });
    </script>

</body>

</html>