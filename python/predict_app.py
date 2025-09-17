from typing import Optional, Dict
import os, math, joblib
from fastapi import FastAPI, HTTPException, Header
from fastapi.responses import HTMLResponse
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

app = FastAPI(title="iCheck - Information Connection", version="1.2.0")

# ========= Config =========
CONFIDENCE_THRESHOLD = float(os.getenv("CONFIDENCE_THRESHOLD", "0.60"))  # p_max tối thiểu
MARGIN_THRESHOLD     = float(os.getenv("MARGIN_THRESHOLD", "0.20"))      # p_max - p_second tối thiểu
W_NEG, W_UNC_MARGIN, W_UNC_ENTROPY = 0.75, 0.15, 0.10                    # trọng số risk

ALLOWED_ORIGINS = [o.strip() for o in os.getenv("ALLOWED_ORIGINS", "http://localhost,http://127.0.0.1").split(",") if o.strip()]
API_KEY = os.getenv("PREDICT_API_KEY", "").strip()

# ========= Helpers =========
def _entropy(probs):
    """Entropy chuẩn hoá về [0,1]."""
    k = len(probs)
    if k <= 1: return 0.0
    s = 0.0
    for p in probs:
        p = max(min(float(p), 1-1e-12), 1e-12)
        s -= p * math.log(p)
    return s / math.log(k)

def _classes_from(pipe):
    """Lấy classes_ từ bước cuối của Pipeline/CalibratedClassifierCV."""
    try:
        last_name, last_est = list(pipe.named_steps.items())[-1]
        return list(last_est.classes_)
    except Exception:
        return list(getattr(pipe, "classes_", []))

# ========= Static / CORS =========
ASSETS_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "assets"))
if os.path.isdir(ASSETS_DIR):
    app.mount("/assets", StaticFiles(directory=ASSETS_DIR), name="assets")

app.add_middleware(
    CORSMiddleware,
    allow_origins=ALLOWED_ORIGINS,   # đổi "*" -> nguồn tin cậy khi lên prod
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ========= Model I/O =========
class PredictIn(BaseModel):
    text: str

class PredictOut(BaseModel):
    label: str
    proba: Dict[str, float]
    risk_score: float
    confidence: Optional[float] = None  # p_max
    margin: Optional[float] = None      # p_max - p_second
    entropy: Optional[float] = None     # [0..1]
    low_confidence: Optional[bool] = None

ARTIFACT_PATH = os.getenv(
    "MODEL_ARTIFACT",
    os.path.join(os.path.dirname(__file__), "artifacts", "model_pipeline.pkl")
)
if not os.path.exists(ARTIFACT_PATH):
    raise RuntimeError(f"Model file not found: {ARTIFACT_PATH}. Run train_model_calibrated.py first.")
PIPE = joblib.load(ARTIFACT_PATH)



# Trang giao diện iCheck
@app.get("/", response_class=HTMLResponse)
async def home():
    return """
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>iCheck - information connection</title>
        <link rel="stylesheet" href="/assets/css/style.css">
        <script src="/assets/js/script.js" defer></script>
        <style>
            .result { margin-top: 20px; padding: 15px; border-radius: 5px; color: white; font-weight: bold; }
            .safe { background-color: #4CAF50; }   /* Xanh lá */
            .medium { background-color: #FFC107; color: black; } /* Vàng */
            .danger { background-color: #F44336; } /* Đỏ */
        </style>

            <!-- favicon-->
    <link rel="shortcut icon" href="./favicon.svg" type="image/svg+xml">

    <!--css-->
    <link rel="stylesheet" href="/assets/css/style.css">


    <!-- google font link-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Roboto:wght@400;500;600&display=swap"
        rel="stylesheet">
        <style>
    body, textarea, input, button {
        font-family: 'Roboto', 'Arial', 'Helvetica', sans-serif;
    }

    textarea {
        font-size: 16px;
        line-height: 1.5;
        font-family: 'Roboto', 'Arial', sans-serif; /* font hỗ trợ tiếng Việt */
    }

    #result {
        font-size: 16px;
        font-family: 'Roboto', 'Arial', sans-serif;
    }

        /* Style cho nút Phân tích */
    .analyze-btn {
        margin-top: 10px;
        padding: 12px 30px;
        font-size: 16px;
        font-weight: 600;
        color: #fff;
        background: linear-gradient(135deg, #007BFF, #0056b3); /* xanh gradient */
        border: none;
        border-radius: 8px;
        cursor: pointer;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
    }

    .analyze-btn:hover {
        background: linear-gradient(135deg, #0056b3, #00408d);
        transform: translateY(-2px);
        box-shadow: 0 6px 10px rgba(0,0,0,0.25);
    }

    .analyze-btn:active {
        transform: translateY(0);
        box-shadow: 0 3px 6px rgba(0,0,0,0.2);
    }
</style>
    </head>
    <body id="top">
<header class="header">
        <div class="header-top">
            <div class="container">
                <ul class="contact-list">
                    <li class="contact-item">
                        <ion-icon name="mail-outline"></ion-icon>
                        <a href="mailto:iCheck@gmail.com" class="contact-link">iCheck@gmail.com</a>
                    </li>
                    <li class="contact-item">
                        <ion-icon name="call-outline"></ion-icon>
                        <a href="tel:+917558951351" class="contact-link">+0123456789</a>
                    </li>
                </ul>
                <ul class="social-list">
                    <li>
                        <a href="#" class="social-link">
                            <ion-icon name="logo-facebook"></ion-icon>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="social-link">
                            <ion-icon name="logo-instagram"></ion-icon>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="social-link">
                            <ion-icon name="logo-twitter"></ion-icon>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="social-link">
                            <ion-icon name="logo-youtube"></ion-icon>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <div class="header-bottom" data-header>
            <div class="container">
                <a href="#" class="logo">iCheck</a>
                <nav class="navbar container" data-navbar>
                    <ul class="navbar-list">
                        <li>
                            <a href="index.php" class="navbar-link" data-nav-link>Home</a>
                        </li>
                        <li>
                            <a href="#service" class="navbar-link" data-nav-link>Analyze</a>
                        </li>
                        <li>
                            <a href="#about" class="navbar-link" data-nav-link>Reports</a>
                        </li>
                        <li>
                            <a href="#blog" class="navbar-link" data-nav-link>Blog</a>
                        </li>
                        <li>
                            <a href="contact.php" class="navbar-link" data-nav-link>Contact</a>
                        </li>
                    </ul>
                </nav>
                <a href="http://localhost/negative-info-guard/php/admin/login.php" class="btn">Login / Register</a>

                <button class="nav-toggle-btn" aria-label="Toggle menu" data-nav-toggler>
                    <ion-icon name="menu-sharp" aria-hidden="true" class="menu-icon"></ion-icon>
                    <ion-icon name="close-sharp" aria-hidden="true" class="close-icon"></ion-icon>
                </button>
            </div>
        </div>
    </header>

        <main>

                    <!--HERO-->
            <section class="section hero" id="home" style="background-image: url('/assets/images/hero-bg.png')"
                aria-label="hero">
                <div class="container">
                    <div class="hero-content">
                        <img src="assets/images/iCheck_logo.png" alt="ICON" width="70" height="70">
                        <p class="section-subtitle">Welcome To iCheck</p>
                        <h1 class="h1 hero-title"></h1>
                        <p class="hero-text">

                        </p>
                    </div>
                    <figure class="hero-banner">
                        <img src="/assets/images/iuh.jpg" width="587" height="839" alt="hero banner" class="w-100">
                    </figure>
                </div>
            </section>

            <!--ABOUT-->
            <section class="section about" id="about" aria-label="about">
                <div class="container">
                    <figure class="about-banner">
                        <img src="/assets/images/trust_new.jpg" width="470" height="538" loading="lazy"
                            alt="about banner" class="w-100">
                    </figure>
                    <div class="about-content">
                        <p class="section-subtitle">About Us</p>
                        <h2 class="h2 section-title">
                            We Care About You</h2>
                        <p class="section-text section-text-1">
                            Lorem ipsum dolor sit amet, consectetur adipisicing elit. Aliquid sint maxime recusandae
                            porro autem. Quasi voluptatum temporibus alias, quaerat similique, ea provident repellendus
                            dolore ipsam sit nihil iste natus quam.
                        </p>
                        <p class="section-text">
                            Lorem ipsum dolor sit amet consectetur, adipisicing elit. Cupiditate a rerum quidem laborum
                            dignissimos corrupti neque id porro laboriosam amet ad fuga, accusantium cum odio provident.
                            Ullam, cum maxime! Incidunt?
                        </p>
                        <a href="about.html" class="btn">Read more About Us</a>
                    </div>
                </div>
            </section>

            <!-- ANALYZE -->
            <section class="section" id="service" style="padding:40px;">
                <div class="container">
                <h2 class="h2 section-title">
                            Phân tích bài viết</h2>
                    <textarea id="inputText" rows="6" style="width:100%;padding:10px;" placeholder="Nhập bài viết..."></textarea><br>
                    <button onclick="analyze()" class="analyze-btn">🔍 Phân tích</button>
                    <div id="result"></div>
                </div>
            </section>
        </main>

<!--FOOTER-->
    <footer class="footer">
        <div class="footer-top section">
            <div class="container">
                <div class="footer-brand">
                    <a href="#" class="logo">iCheck</a>
                    <p class="footer-text">
                        Lorem ipsum dolor sit amet, consectetur adipisicing elit. Architecto laudantium deserunt
                        delectus quae beatae consequatur asperiores tempore libero laboriosam numquam, excepturi autem
                        harum quasi iusto eaque nobis commodi doloremque corporis!
                    </p>
                    <div class="schedule">
                        <div class="schedule-icon">
                            <ion-icon name="time-outline"></ion-icon>
                        </div>
                        <span class="span">
                            24 X 7:<br>
                            365 Days
                        </span>
                    </div>
                </div>
                <ul class="footer-list">
                    <li>
                        <p class="footer-list-title">Other Links</p>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Home</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Analyze</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Reports</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Blog</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Contact</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Login / Register</span>
                        </a>
                    </li>
                </ul>
                <ul class="footer-list">
                    <li>
                        <p class="footer-list-title">Our Services</p>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">xxxxxxxxx</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">xxxxxxxxx</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">xxxxxxxxx</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">xxxxxxxxx</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">xxxxxxxxx</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">xxxxxxxxx</span>
                        </a>
                    </li>
                </ul>
                <ul class="footer-list">
                    <li>
                        <p class="footer-list-title">Contact Us</p>
                    </li>
                    <li class="footer-item">
                        <div class="item-icon">
                            <ion-icon name="location-outline"></ion-icon>
                        </div>
                        <a href="https://goo.gl/maps/BYA5MxQUg5B8ZFLcA">
                            <address class="item-text">
                                TP.HCM, Viet Nam
                            </address>
                        </a>
                    </li>
                    <li class="footer-item">
                        <div class="item-icon">
                            <ion-icon name="call-outline"></ion-icon>
                        </div>
                        <a href="tel:+0123456789" class="footer-link">+0123456789</a>
                    </li>
                    <li class="footer-item">
                        <div class="item-icon">
                            <ion-icon name="mail-outline"></ion-icon>
                        </div>
                        <a href="mailto:help@example.com" class="footer-link">iCheck@gmail.com</a>
                    </li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <div class="container">
                <p class="copyright">
                    &copy; 2025 All Rights Reserved by iCheck
                </p>
                <ul class="social-list">
                    <li>
                        <a href="#" class="social-link">
                            <ion-icon name="logo-facebook"></ion-icon>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="social-link">
                            <ion-icon name="logo-instagram"></ion-icon>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="social-link">
                            <ion-icon name="logo-twitter"></ion-icon>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </footer>
    <!--BACK TO TOP-->
    <a href="#top" class="back-top-btn" aria-label="back to top" data-back-top-btn>
        <ion-icon name="caret-up" aria-hidden="true"></ion-icon>
    </a>

    <!--custom js link-->
    <script src="/assets/js/script.js" defer></script>
    <!--ionicon link-->
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>


        <script>
async function analyze(){
  const elOut = document.getElementById("result");
  const text = (document.getElementById("inputText").value || "").trim();
  if(!text){ elOut.innerHTML = "<div class='result medium'>⚠️ Vui lòng nhập nội dung!</div>"; return; }

  try{
    const res = await fetch("/predict", {
      method: "POST",
      headers: {"Content-Type":"application/json"},
      body: JSON.stringify({ text })
    });
    const data = await res.json(); // <-- BẮT BUỘC: lấy data từ res

    const risk = data.risk_score ?? 0;
    const label = data.label ?? "-";
    const confidence = data.confidence ?? Math.max(...Object.values(data.proba || { [label]: 1 }));
    const margin = data.margin ?? 0;
    const entropy = data.entropy ?? 0;
    const lowConf = data.low_confidence ?? (confidence < 0.6 || margin < 0.2);

    let cssClass = "safe", status = "🟢 An toàn";
    if (risk < 0.4) { cssClass = "safe"; status = "🟢 An toàn"; }
    else if (risk < 0.7) { cssClass = "medium"; status = lowConf ? "🟠 Nghi ngờ (cần duyệt)" : "🟡 Trung bình"; }
    else { cssClass = "danger"; status = "🔴 Nguy hiểm"; }

    elOut.innerHTML = `
      <div class="result ${cssClass}">
        <p><b>📝 Kết quả phân tích:</b></p>
        <ul style="margin:0;padding-left:18px;">
          <li><b>Label:</b> ${label}</li>
          <li><b>Độ rủi ro:</b> ${(risk*100).toFixed(1)}%</li>
          <li><b>Độ tin cậy (p_max):</b> ${(confidence*100).toFixed(1)}%</li>
          <li><b>Biên (margin):</b> ${(margin*100).toFixed(1)}%</li>
          <li><b>Độ mơ hồ (entropy):</b> ${(entropy*100).toFixed(1)}%</li>
          <li><b>Mức cảnh báo:</b> ${status}</li>
          <li><b>Khuyến nghị:</b>
            ${
              lowConf
                ? "🟠 Mô hình chưa chắc chắn — vui lòng người duyệt xem lại."
                : (risk < 0.4
                    ? "✅ Nội dung an toàn, có thể đăng."
                    : risk < 0.7
                      ? "⚠️ Cần xem xét trước khi đăng."
                      : "🚨 Nội dung tiêu cực, cần gỡ/kiểm duyệt gấp!")
            }
          </li>
          <li><b>Thời gian phân tích:</b> ${new Date().toLocaleString()}</li>
        </ul>
      </div>`;
  }catch(e){
    elOut.innerHTML = "<div class='result danger'>❌ Lỗi kết nối tới API!</div>";
  }
}
</script>
</body></html>
"""

# ========= API =========
@app.get("/healthz")
def healthz():
    return {"status":"ok"}

@app.post("/predict", response_model=PredictOut)
def predict(inp: PredictIn, x_api_key: Optional[str] = Header(default=None)):
    # Nếu đặt API_KEY thì yêu cầu header
    if API_KEY and (not x_api_key or x_api_key != API_KEY):
        raise HTTPException(401, "Invalid or missing API key.")

    text = (inp.text or "").strip()
    if not text:
        raise HTTPException(400, "Text is required.")

    label = PIPE.predict([text])[0]

    # Phân bố xác suất
    proba: Dict[str, float] = {}
    probs, classes = [], []
    if hasattr(PIPE, "predict_proba"):
        probs = PIPE.predict_proba([text])[0].tolist()
        classes = _classes_from(PIPE) or ["negative","neutral","positive"][:len(probs)]
        proba = {classes[i]: float(probs[i]) for i in range(len(probs))}
    else:
        proba, probs = {label: 1.0}, [1.0]

    # Các đại lượng từ phân bố
    p_neg = float(proba.get("negative", 0.0))
    p_sorted = sorted(probs, reverse=True)
    p_max = float(p_sorted[0])
    p_second = float(p_sorted[1]) if len(p_sorted) > 1 else 0.0
    margin = p_max - p_second
    ent = _entropy(probs)

    # Risk tổng hợp (dựa trên xác suất đã calibrate nếu bạn train bằng CalibratedClassifierCV)
    risk = W_NEG*p_neg + W_UNC_MARGIN*(1 - margin) + W_UNC_ENTROPY*ent
    risk = max(0.0, min(1.0, risk))

    low_conf = (p_max < CONFIDENCE_THRESHOLD) or (margin < MARGIN_THRESHOLD)

    return PredictOut(
        label=label,
        proba=proba,
        risk_score=risk,
        confidence=p_max,
        margin=margin,
        entropy=ent,
        low_confidence=low_conf,
    )