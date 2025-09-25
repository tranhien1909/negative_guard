// public/assets/app.js
console.log("app.js loaded");

// ---- DOM refs ---------------------------------------------------------------
const btn        = document.getElementById("analyzeBtn");
const textarea   = document.getElementById("text");
const resultEl   = document.getElementById("result");
const riskEl     = document.getElementById("risk");
const warningsEl = document.getElementById("warnings");
const saveLogEl  = document.getElementById("saveLog");

// ---- Helpers: mapping + dedupe ---------------------------------------------
function normalizeTitle(raw) {
  const t = (raw || "").toLowerCase().trim();
  const stripped = t.replace(/^b(ă|a)ng ch(ứ|u)ng:\s*/i, ""); // bỏ "Bằng chứng:"

  if (stripped.includes("hate"))        return "Ngôn từ xúc phạm/công kích";
  if (stripped.includes("scam") || stripped.includes("phishing"))
                                        return "Mời chào/lừa đảo/phishing";
  if (stripped.includes("misinfo"))     return "Thông tin thiếu kiểm chứng";
  if (stripped.includes("violence"))    return "Kích động/đe doạ bạo lực";
  if (stripped.includes("propaganda"))  return "Tuyên truyền/phiến diện";
  if (stripped.includes("link") || stripped.includes("url"))
                                        return "Liên kết ngoài";
  if (stripped.includes("phone"))       return "Số điện thoại";
  return "Dấu hiệu rủi ro";
}
function sevRank(s) { return ({ critical:4, high:3, medium:2, low:1 }[s] || 1); }
function maxSev(a, b) { return sevRank(a) >= sevRank(b) ? a : b; }
function bulletJoin(arr) {
  const uniq = Array.from(new Set((arr || [])
    .map(x => (x || "").trim())
    .filter(Boolean)));
  if (!uniq.length) return "";
  return "• " + uniq.join("\n• ");
}

// Gộp & Việt-hoá cảnh báo, rồi render đẹp
function renderWarningsDedup(containerEl, data) {
  const raw = Array.isArray(data?.warnings) ? data.warnings : [];

  // 1) Gộp theo tiêu đề Việt-hoá
  const buckets = {}; // title -> { title, severity, ev:[], suggestion }
  raw.forEach(w => {
    const title = normalizeTitle(w?.title);
    const sev   = (["low","medium","high","critical"].includes(w?.severity) ? w.severity : "low");
    const ev    = (w?.evidence || "").trim();
    const sug   = (w?.suggestion || "").trim();

    if (!buckets[title]) {
      buckets[title] = { title, severity: sev, ev: [], suggestion: sug };
    } else {
      buckets[title].severity = maxSev(buckets[title].severity, sev);
      if (sug && (!buckets[title].suggestion || sug.length > buckets[title].suggestion.length)) {
        buckets[title].suggestion = sug;
      }
    }
    if (ev) buckets[title].ev.push(ev);
  });

  // 2) Bổ sung theo labels (phòng khi LLM không trả warnings)
  const L = data?.labels || {};
  const ensure = (cond, title, sev, ev) => {
    if (!cond) return;
    if (!buckets[title]) buckets[title] = { title, severity: sev, ev: [], suggestion: "" };
    if (ev) buckets[title].ev.push(ev);
  };
  ensure(L.hate_speech,     "Ngôn từ xúc phạm/công kích", "medium");
  ensure(L.scam_phishing,   "Mời chào/lừa đảo/phishing",  "high");
  ensure(L.misinformation,  "Thông tin thiếu kiểm chứng", "medium");
  ensure(L.violence_threat, "Kích động/đe doạ bạo lực",   "high");
  if (Array.isArray(L.propaganda) ? L.propaganda.length : false) {
    ensure(true, "Tuyên truyền/phiến diện", "medium");
  }

  // 3) Sắp xếp: severity cao trước
  const list = Object.values(buckets).sort((a,b) => sevRank(b.severity) - sevRank(a.severity));

  // 4) Render
  containerEl.innerHTML = "";
  if (!list.length) {
    containerEl.innerHTML = `<div class="muted">Không phát hiện cảnh báo cụ thể.</div>`;
    return;
  }

  list.forEach(w => {
    const badge = `<span class="badge">${w.severity}</span>`;
    const title = `<strong>${w.title}</strong>`;
    const evidence = bulletJoin(w.ev);
    const sug = w.suggestion || "Giữ văn minh, kiểm chứng nguồn; cảnh giác mời chào/đường link.";

    const card = document.createElement("div");
    card.className = "card";
    card.innerHTML = `
      <div>${badge} ${title}</div>
      ${evidence ? `<div style="white-space:pre-wrap">${evidence}</div>` : ``}
      <div><em>Gợi ý:</em> ${sug}</div>
    `;
    containerEl.appendChild(card);
  });
}

// ---- Main analyze -----------------------------------------------------------
async function runAnalyze() {
  const text = (textarea?.value || "").trim();
  if (!text) { alert("Vui lòng nhập nội dung."); return; }

  if (resultEl)   resultEl.hidden = false;
  if (riskEl)     riskEl.textContent = "Đang phân tích...";
  if (warningsEl) warningsEl.innerHTML = "";

  try {
    const res = await fetch("/analyze.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        text,
        saveLog: !!(saveLogEl && saveLogEl.checked)
      })
    });

    // Có thể gặp lỗi do server trả HTML/500
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) {
      const msg = data?.error || `HTTP ${res.status}`;
      throw new Error(msg);
    }

    // Risk summary
    const risk = Number.isFinite(data.overall_risk) ? data.overall_risk : 0;
    const share = data.safe_to_share ? "Có thể chia sẻ" : "Không nên chia sẻ";
    if (riskEl) riskEl.textContent = `Điểm rủi ro: ${risk}/100 — ${share}`;

    // Warnings (gộp & Việt hoá)
    renderWarningsDedup(warningsEl, data);

  } catch (err) {
    console.error(err);
    if (riskEl) riskEl.textContent = "Lỗi: " + err.message;
    if (warningsEl) warningsEl.innerHTML = "";
  }
}

// ---- Bind once --------------------------------------------------------------
if (btn) {
  btn.addEventListener("click", async () => {
    btn.disabled = true;
    try { await runAnalyze(); }
    finally { btn.disabled = false; }
  });
} else {
  console.warn("#analyzeBtn not found");
}
