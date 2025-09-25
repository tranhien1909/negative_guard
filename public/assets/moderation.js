// public/assets/moderation.js
(function () {
  // Lấy CSRF từ meta
  const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  // Gọi action.php và hiện lỗi rõ ràng
  async function callAction(fd) {
    const res = await fetch('/admin/action.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',            // gửi cookie / session
      headers: { 'Accept': 'application/json' }
    });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { data = null; }
    if (!res.ok) throw new Error((data && data.error) || text || ('HTTP ' + res.status));
    return data || {};
  }

  // Mở rộng / thu gọn nội dung
  document.querySelectorAll('[data-toggle]').forEach(btn => {
    btn.addEventListener('click', () => {
      const msg = btn.closest('.card').querySelector('.msg');
      const collapsed = msg.getAttribute('data-collapsed') === '1';
      msg.style.maxHeight = collapsed ? 'unset' : '4.5em';
      msg.style.overflow  = collapsed ? 'visible' : 'hidden';
      msg.setAttribute('data-collapsed', collapsed ? '0' : '1');
      btn.textContent = collapsed ? 'Thu gọn' : 'Hiện đầy đủ';
    });
  });

  // Gắn sự kiện cho từng thẻ comment
  document.querySelectorAll('.card').forEach(card => {
    const id = card.getAttribute('data-id');
    const replyBtn  = card.querySelector('[data-reply]');
    const hideBtn   = card.querySelector('[data-hide]');
    const unhideBtn = card.querySelector('[data-unhide]');

    // Trả lời
    if (replyBtn) replyBtn.addEventListener('click', async () => {
      const message = prompt('Nhập nội dung trả lời:');
      if (!message) return;
      const old = replyBtn.textContent; replyBtn.disabled = true; replyBtn.textContent = 'Đang gửi…';
      try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('action', 'comment');
        fd.append('id', id);
        fd.append('message', message);
        await callAction(fd);
        alert('Đã gửi phản hồi.');
      } catch (e) { alert('Lỗi trả lời: ' + e.message); }
      finally { replyBtn.disabled = false; replyBtn.textContent = old; }
    });

    // Ẩn
    if (hideBtn) hideBtn.addEventListener('click', async () => {
      const old = hideBtn.textContent; hideBtn.disabled = true; hideBtn.textContent = 'Đang ẩn…';
      try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('action', 'hide_comment');
        fd.append('id', id);
        fd.append('hide', '1');
        await callAction(fd);
        alert('Đã ẩn bình luận.');
      } catch (e) { alert('Lỗi ẩn: ' + e.message); }
      finally { hideBtn.disabled = false; hideBtn.textContent = old; }
    });

    // Hiện
    if (unhideBtn) unhideBtn.addEventListener('click', async () => {
      const old = unhideBtn.textContent; unhideBtn.disabled = true; unhideBtn.textContent = 'Đang hiện…';
      try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('action', 'hide_comment');
        fd.append('id', id);
        fd.append('hide', '0');
        await callAction(fd);
        alert('Đã hiện bình luận.');
      } catch (e) { alert('Lỗi hiện: ' + e.message); }
      finally { unhideBtn.disabled = false; unhideBtn.textContent = old; }
    });
  });

  // Nút “Quét ngay”
  const scanBtn  = document.getElementById('scanBtn');
  const scanText = document.getElementById('scanText');
  if (scanBtn) scanBtn.addEventListener('click', async () => {
    const old = scanText ? scanText.textContent : ''; scanBtn.disabled = true;
    if (scanText) scanText.textContent = 'Đang quét…';
    try {
      const fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('action', 'scan_now');
      fd.append('window', '30');
      const data = await callAction(fd);
      alert(`Đã quét: ${data.scanned}\nVượt ngưỡng: ${data.high_risk}\nĐã trả lời: ${data.replied}\nĐã ẩn: ${data.hidden}`);
      location.reload();
    } catch (e) {
      alert('Lỗi quét: ' + e.message);
      scanBtn.disabled = false;
      if (scanText) scanText.textContent = old;
    }
  });

  const csrf = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';

  async function postAction(action, payload = {}) {
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', action);
    Object.entries(payload).forEach(([k,v])=>fd.append(k, v));
    const res = await fetch('/admin/action.php', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: {'Accept':'application/json'}
    });
    const txt = await res.text();
    let data; try { data = JSON.parse(txt); } catch { data = null; }
    if (!res.ok) throw new Error((data&&data.error) || txt || ('HTTP '+res.status));
    return data || {};
  }

  const scanPostsBtn  = document.getElementById('scanPostsBtn');
  const scanPostsText = document.getElementById('scanPostsText');

  if (scanPostsBtn) {
    scanPostsBtn.addEventListener('click', async ()=>{
      scanPostsBtn.disabled = true;
      if (scanPostsText) scanPostsText.textContent = 'Đang quét bài viết…';
      try {
        const data = await postAction('scan_posts_now', { window: '60' });
        alert(`Bài viết đã quét: ${data.scanned}\nĐã cảnh báo: ${data.warned}\nBỏ qua: ${data.skipped}\nLỗi: ${data.errors}`);
        location.reload();
      } catch (e) {
        alert('Lỗi quét bài viết: ' + e.message);
        scanPostsBtn.disabled = false;
        if (scanPostsText) scanPostsText.textContent = '';
      }
    });
      }
})();
