// public/assets/app.js
console.log('app.js loaded');

const btn = document.getElementById('analyzeBtn');
const textarea = document.getElementById('text');
const resultEl = document.getElementById('result');
const riskEl = document.getElementById('risk');
const warningsEl = document.getElementById('warnings');
const saveLogEl = document.getElementById('saveLog');

async function runAnalyze() {
  const text = (textarea?.value || '').trim();
  if (!text) { alert('Vui lòng nhập nội dung.'); return; }

  if (resultEl) resultEl.hidden = false;
  if (riskEl) riskEl.textContent = 'Đang phân tích...';
  if (warningsEl) warningsEl.innerHTML = '';

  try {
    const res = await fetch('/analyze.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ text, saveLog: !!(saveLogEl && saveLogEl.checked) })
    });
    const data = await res.json();
    console.log('analyze result:', data);

    if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));

    riskEl.textContent = `Điểm rủi ro: ${data.overall_risk}/100 — ${data.safe_to_share ? 'Có thể chia sẻ' : 'Không nên chia sẻ'}`;

    (data.warnings || []).forEach(w => {
      const div = document.createElement('div');
      div.className = 'warning ' + (w.severity || 'low');
      div.innerHTML =
        `<div class="badge">${w.severity || ''}</div><strong>${w.title || ''}</strong>` +
        `<div>${w.evidence || ''}</div><div><em>Gợi ý:</em> ${w.suggestion || ''}</div>`;
      warningsEl.appendChild(div);
    });
  } catch (err) {
    console.error(err);
    riskEl.textContent = 'Lỗi: ' + err.message;
  }
}

if (btn) {
  btn.addEventListener('click', () => {
    console.log('click analyze');
    runAnalyze();
  });
} else {
  console.warn('#analyzeBtn not found');
}


btn.addEventListener('click', async () => {
  btn.disabled = true;
  try { await runAnalyze(); } finally { btn.disabled = false; }
});