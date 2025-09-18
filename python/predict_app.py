from typing import Optional, Dict
import os, math, joblib
from fastapi import FastAPI, HTTPException, Header
from fastapi.responses import HTMLResponse
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

# predict_app.py
import os, json, math, unicodedata
from joblib import load
import re

app = FastAPI()
MODEL_PATH = os.getenv("MODEL_PATH", "artifacts/model_pipeline.pkl")
THRESHOLD  = float(os.getenv("RISK_THRESHOLD", "0.6"))  # 0.6 khắt khe hơn

pipe = None
if os.path.exists(MODEL_PATH):
    try:
        pipe = load(MODEL_PATH)
    except Exception:
        pipe = None

class Inp(BaseModel):
    text: str

def strip_accents(s: str) -> str:
    # bỏ dấu tiếng Việt, chuẩn hóa
    s = s.replace("đ", "d").replace("Đ", "D")
    nfkd = unicodedata.normalize("NFKD", s)
    return "".join([c for c in nfkd if not unicodedata.combining(c)])

def vi_norm(s: str) -> str:
    s = unicodedata.normalize("NFD", s)
    s = "".join(ch for ch in s if unicodedata.category(ch) != "Mn")  # bỏ dấu
    s = s.lower()
    s = re.sub(r"\s+", " ", s).strip()
    return s


def normalize(s: str) -> str:
    s = strip_accents(s.lower())
    # rút gọn ký tự lặp: vclllll -> vcl
    out = []
    last = ""
    for ch in s:
        if not ch.isalnum() and ch not in " _":
            ch = " "
        if ch == last and ch.isalpha():
            continue
        last = ch
        out.append(ch)
    return " ".join("".join(out).split())

# Từ/cụm tục & xúc phạm phổ biến (không phân biệt dấu)
PROFANITY = [
    "dm", "dmm", "dkm", "dcm", "deo", "deo*", "deo*", "deo", "cmm",
    "dit", "ditme", "dit*me", "vcl", "vkl", "vl", "cl",
    "lon", "lolon", "cac", "buoi", "bu*oi", "loz", "loz*",
    "cc", "ccmm", "ngu", "oc cho", "occho", "cho chet", "khon nan", "khonnan", "shit"
]
# Ngữ cảnh giáo dục
EDU_CTX = ["truong", "lop", "giao vien", "giaovien", "hoc sinh", "hocsinh", "sinh vien", "sinhvien"]

def lexicon_score(text: str):
    t = normalize(text)
    hits = []
    score = 0.0

    def has(token: str) -> bool:
        return f" {token} " in f" {t} "

    # profanity mạnh
    strong = ["dkm", "dcm", "ditme", "loz", "lon", "buoi", "vch"]
    if any(has(w) for w in strong):
        score = max(score, 0.90)
        hits += [w for w in strong if has(w)]

    # profanity vừa
    medium = ["dm", "dmm", "deo", "vcl", "vkl", "cl", "ccmm", "cc"]
    if any(has(w) for w in medium):
        score = max(score, 0.80)
        hits += [w for w in medium if has(w)]

    # miệt thị/thoá mạ nhẹ
    light = ["ngu", "oc cho", "occho", "khon nan", "khonnan"]
    if any(has(w) for w in light):
        score = max(score, 0.60)
        hits += [w for w in light if has(w)]

    # nếu có ngữ cảnh giáo dục kèm profanity -> nâng mức
    if score >= 0.60 and any(has(c) for c in EDU_CTX):
        score = max(score, 0.90)
        hits.append("edu_ctx")

    return score if hits else 0.0, hits

def entropy(probs):
    return -sum(p * math.log(p + 1e-12) for p in probs)

# Profanity có dấu (ưu tiên match dạng này trước)
RE_PROFAN_RAW = [
    # --- mạnh / tục trực tiếp ---
    r"\bđịt\b", r"\bđụ\b",
    r"\bđịt\s+mẹ\b", r"\bđụ\s+mẹ\b", r"\bđù\s+má\b",
    r"\bđéo\b", r"\bđếch\b", r"\béo\b",

    r"\bcặc\b", r"\blồn\b", r"\bbuồi\b", r"\bvch\b",

    # --- gia đình / chửi rủa ---
    r"\bmẹ\s+mày\b", r"\bmá\s+mày\b", r"\bmẹ\s+kiếp\b", r"\bmả\s+mẹ\b",
    r"\btổ\s+sư\b", r"\btổ\s+cha\b",

    # --- xúc phạm nặng ---
    r"\bóc\s+chó\b", r"\bchó\s+chết\b", r"\bđồ\s+chó\b",
    r"\bkhốn\s+nạn\b", r"\bbố\s+láo\b", r"\bláo\s+(toét|chó)\b", r"\bđểu\s+cáng\b",
    r"\bmất\s+dạy\b", r"\bvô\s+học\b", r"\brác\s+rưởi\b", r"\bđồ\s+rác\s+rưởi\b",

    # --- xúc phạm vừa / miệt thị ---
    r"\bngu\b", r"\bđần\b", r"\bđần\s+độn\b", r"\bngu\s+si\b",
    r"\bnão\s+tàn\b", r"\bnão\s+phẳng\b",
    r"\bsúc\s+vật\b", r"\bsúc\s+sinh\b",
    r"\bbiến\s+thái\b",
    r"\bđồ\s+thần\s+kinh\b",     # cụ thể để tránh FP “khoa thần kinh”

    # --- đuổi / nạt nộ ---
    r"\bcút\b", r"\bcút\s+(đi|xéo)\b",

    # --- tiếng Anh phổ biến ---
    r"\bshit+\b", r"\bf+u+c+k+\b", r"\bv+c+h+\b",

    r"\bdit\b", r"\bdjt\b", r"\bdu\b",
    r"\bcac+k\b", r"\blon\b", r"\bbuoi\b",
    r"\boc cho\b", r"\boccho\b",
    r"\bmeo+?\b",  # optional
    r"\bmat net\b",            # ✨ mới
    r"\bmat day\b",            # ✨
    r"\bsuc vat\b", r"\bkhon nan\b", r"\bdo cho\b",
    r"\bngu\b", r"\bdan+ do+n\b",
]

# Biến thể viết lách/ẩn từ (không dấu / chèn ký tự) – chỉ dùng khi bạn đã có “trigger” gần đó
RE_PROFAN_OBFUSCATED = [
    r"\bl[\W_]*[ôo0\*]+n\b",      # l*n, l0n -> lồn
    r"\bb[u\*]+[ôo0][iíi]\b",     # bu*oi -> buồi
    r"\bđ[\W_]*[ịi\*]+t\b",       # đ*t -> địt
    r"\bc[\W_]*[ăa\*]+[ckq]+\b",  # c*ck -> cặc
    r"\bd[ck]m+\b",               # dcm/dkm
    r"\bcmm+\b",                  # cmm
    r"\bvcl+\b", r"\bvl+\b",      # vcl/vl
]

# viết tắt/booster mức độ
RE_BOOSTERS = [r"\bvcl\b", r"\bvl\b", r"\bvch\b", r"\bvcc\b"]
# ngữ cảnh nhạy cảm (mục tiêu là nhà trường/giáo viên…)
RE_EDU_CTX = [r"\bgiang vien\b", r"\bgiao vien\b", r"\btruong\b", r"\blop\b", r"\bban hoc\b"]

RAW_PATTERNS = [re.compile(p) for p in RE_PROFAN_RAW]

# Từ kích hoạt (dạng không dấu) để “mở khoá” kiểm tra không dấu
TRIGGERS = {"dm","dmm","dcm","dkm","dit","djt","ditme","dcm","cmm","vcl","vl","ngu","oc","occho","cac","buoi","loz"}

# Whitelist các ngữ cảnh an toàn
SAFE_PHRASES_RAW = [
    r"\bbuổi\b",                      # buổi học/buổi sáng...
    r"\blớn\b",                       # l(ớ)n (không phải l*n)
    r"\blon\s+(bia|nước|sữa)\b",      # lon bia/nước/sữa
    r"\bvỏ\s+lon\b", r"\blon\s+thiếc\b"
]
SAFE_PATTERNS_RAW = [re.compile(p) for p in SAFE_PHRASES_RAW]

def tokenize_ascii(s: str):
    return [w for w in re.split(r"\W+", s) if w]

def window_has_trigger(tokens, i, k=2):
    L = max(0, i-k); R = min(len(tokens), i+k+1)
    return any(t in TRIGGERS for t in tokens[L:R] if t)



def lexicon_score(text: str):
    """
    Trả về (score, hits). Giảm false positive cho 'buoi'/'lon' khi nghĩa vô hại.
    """
    raw = (text or "").lower()
    ascii_txt = normalize(text or "")   # đã bỏ dấu / chuẩn hoá
    toks = tokenize_ascii(ascii_txt)

    hits = set()
    score = 0.0

    # 1) ƯU TIÊN: match có dấu (ít false positive)
    for pat in RAW_PATTERNS:
        if pat.search(raw):
            hits.add(pat.pattern)
            score = max(score, 0.90)

    # 2) BỎ QUA nếu rơi vào whitelist an toàn
    for sp in SAFE_PATTERNS_RAW:
        if sp.search(raw):
            # ví dụ: có "buổi", "lon bia" -> đừng nâng score chỉ vì 'buoi/lon' ở ascii
            # (không return ngay, vì có thể đồng thời chứa từ thô khác)
            pass

    # 3) DẠNG KHÔNG DẤU: chỉ xét khi có trigger ở gần
    if score < 0.90:  # chưa bị match mạnh ở bước 1
        for i, tok in enumerate(toks):
            if tok in {"buoi", "lon"}:
                # a) nếu bản có dấu chứa 'buổi' hoặc 'lớn' -> bỏ qua
                if re.search(r"\bbuổi\b", raw) and tok == "buoi":
                    continue
                if re.search(r"\blớn\b", raw) and tok == "lon":
                    continue
                # b) nếu là các cụm “lon bia/vỏ lon…” -> bỏ qua
                if re.search(r"\blon\s+(bia|nước|sữa)\b", raw) or re.search(r"\bvỏ\s+lon\b", raw):
                    continue
                # c) chỉ flag khi gần trigger
                if window_has_trigger(toks, i, k=2):
                    hits.add(tok)
                    score = max(score, 0.80 if tok == "lon" else 0.75)

            # các biến thể phổ biến khác dạng không dấu
            if tok in {"dm","dmm","dcm","dkm","cmm","vcl","vl","dit","djt","ditme","occho"}:
                hits.add(tok)
                score = max(score, 0.80)

            if tok == "ngu":
                hits.add(tok)
                score = max(score, 0.60)

    # 4) Nâng mức khi có ngữ cảnh giáo dục + đã có bất kỳ hit tục
    EDU_CTX = {"truong","lop","giao","giaovien","hocsinh","sinhvien","hieu","co giao","thay"}
    if score >= 0.60 and any(c in ascii_txt for c in EDU_CTX):
        score = max(score, 0.90)
        hits.add("edu_ctx")

    return (score if hits else 0.0, sorted(hits))

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
                            <a href="#" class="navbar-link" data-nav-link>Home</a>
                        </li>
                        <li>
                            <a href="#analyze" class="navbar-link" data-nav-link>Analyze</a>
                        </li>
                        <li>
                            <a href="#contact" class="navbar-link" data-nav-link>Contact</a>
                        </li>
                    </ul>
                </nav>
                <a href="http://localhost/negative-info-guard/php/admin/login.php" class="btn">Login</a>

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
                            iCheck là trợ lý kiểm duyệt sử dụng AI và luật tiếng Việt giúp nhà trường, doanh nghiệp và cộng đồng
  <strong>phát hiện sớm nội dung tiêu cực</strong>: tục tĩu, miệt thị, kích động, lừa đảo, đường link độc hại…
  trên fanpage và bình luận. Chúng tôi muốn bạn <strong>an tâm truyền thông</strong>, còn việc “soi rủi ro” cứ để iCheck lo.
                        </p>
                        <p class="section-text">
                            Hệ thống <strong>tự động thu thập bài viết & bình luận</strong>, <strong>chấm điểm rủi ro theo thời gian thực</strong>,
  cảnh báo tức thì và cung cấp bảng điều khiển trực quan để duyệt/gỡ/chỉnh chỉ với <strong>1 lần bấm</strong>.
  Mọi thao tác đều được lưu vết, dữ liệu thuộc về bạn, và có thể tùy chỉnh danh sách từ nhạy cảm cho phù hợp bối cảnh giáo dục.
                        </p>
                        <a class="btn" href="#analyze">Tìm hiểu về iCheck</a>
                    </div>
                </div>
            </section>

            <!-- ANALYZE -->
            <section class="section" id="analyze" style="padding:40px;">
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
    <footer class="footer" id="contact">
        <div class="footer-top section">
            <div class="container">
                <div class="footer-brand">
                    <a href="#" class="logo">iCheck</a>
                    <p class="footer-text">
                        iCheck là trợ lý kiểm duyệt nội dung cho fanpage và cộng đồng. Hệ thống tự động thu thập
  bài viết & bình luận, chấm điểm rủi ro theo thời gian thực, cảnh báo tức thì và lưu vết xử lý.
  Giúp bạn an tâm truyền thông – việc “soi rủi ro” cứ để iCheck lo.
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
                        <a href="#analyze" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Analyze</span>
                        </a>
                    </li>
                    <li>
                        <a href="#contact" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Contact</span>
                        </a>
                    </li>
                    <li>
                        <a href="http://localhost/negative-info-guard/php/admin/login.php" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Login</span>
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
                            <span class="span">Thu thập post & comment tự động</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Chấm điểm rủi ro theo thời gian thực</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">Bộ lọc tục tiếng Việt có thể tùy chỉnh</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="footer-link">
                            <ion-icon name="add-outline"></ion-icon>
                            <span class="span">API & Webhook để tích hợp hệ thống</span>
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
function verdictFromRisk(r){
  if (r >= 0.70) return {css:"danger", text:"🔴 Nguy hiểm", action:"🚫 Gỡ/ẩn ngay & soạn bình luận cảnh báo."};
  if (r >= 0.40) return {css:"medium", text:"🟠 Cần duyệt", action:"✏️ Đưa vào hàng chờ duyệt, cân nhắc chỉnh/sửa từ ngữ."};
  return {css:"safe", text:"🟢 An toàn", action:"✅ Có thể đăng."};
}
function confLabel(v){
  if (v == null) return "Không rõ";
  if (v >= 0.75) return "Cao";
  if (v >= 0.55) return "Trung bình";
  return "Thấp";
}

/* --- FIX: chuyển regex hit -> từ dễ đọc --- */
function prettyHits(hitsRaw){
  const map = {
    "oc cho":"óc chó", "occho":"óc chó",
    "ditme":"địt mẹ", "djtme":"địt mẹ", "dit":"địt",
    "dm":"đm", "dmm":"đmm", "dcm":"đcm", "dkm":"đkm",
    "vl":"vãi l", "vcl":"vcl"
  };
  const cleaned = [];
  for (let h of (hitsRaw||[])){
    if (h === "edu_ctx") continue;            // để UI ghi riêng "ngữ cảnh giáo dục"
    let x = String(h);
    x = x.replace(/\\\\b/g, "");              // JSON: \\b
    x = x.replace(/\\b/g, "");                // fallback: \b
    x = x.replace(/[\\^$.*+?()[\]{}|]/g, ""); // loại ký tự regex
    x = x.replace(/\s+/g, " ").trim();
    if (!x) continue;
    x = map[x] || x;
    cleaned.push(x);
  }
  // unique, tối đa 5 từ
  return [...new Set(cleaned)].slice(0,5);
}

function topReasons(text, data){
  const reasons = [];
  const hitsPretty = prettyHits(data?.lexicon?.hits);
  if (hitsPretty.length){
    reasons.push("Từ ngữ vi phạm: " + hitsPretty.join(", "));
  }
  if ((data?.lexicon?.hits||[]).includes("edu_ctx")){
    reasons.push("Ngữ cảnh nhạy cảm: trường/lớp/giáo viên");
  }
  if (/(https?:\/\/|www\.)/i.test(text)) reasons.push("Có liên kết – cần kiểm tra nguồn");
  if (/[!]{2,}/.test(text)) reasons.push("Nhiều dấu cảm thán bất thường");

  if (!reasons.length){
    const r = data?.risk_score ?? 0;
    reasons.push(r >= 0.7 ? "Mô hình cảnh báo rủi ro cao"
                          : r >= 0.4 ? "Mô hình chưa chắc chắn – nên có người duyệt"
                                     : "Không phát hiện vi phạm rõ ràng");
  }
  return reasons.slice(0,3);
}

async function analyze(){
  const elOut = document.getElementById("result");
  const text = (document.getElementById("inputText").value || "").trim();
  if(!text){ elOut.innerHTML = "<div class='result medium'>⚠️ Vui lòng nhập nội dung!</div>"; return; }

  try{
    const res  = await fetch("/predict",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({text})});
    const data = await res.json();

    const risk = data?.risk_score ?? 0;
    const v    = verdictFromRisk(risk);
    const conf = confLabel(data?.ml?.p_max ?? data?.confidence ?? null);
    const reasons = topReasons(text, data);

    elOut.innerHTML = `
      <div class="result ${v.css}">
        <div style="font-size:18px;margin-bottom:6px;"><b>${v.text}</b> • Độ tin cậy: ${conf}</div>
        <div><b>Lý do chính:</b>
          <ul style="margin:6px 0 10px;padding-left:18px;">
            ${reasons.map(r=>`<li>${r}</li>`).join("")}
          </ul>
        </div>
        <div><b>Gợi ý xử lý:</b> ${v.action}</div>
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

@app.post("/predict")
def predict(inp: Inp):
    text = inp.text or ""
    # ----- ML -----
    ml_label = "neutral"
    ml_prob_toxic = 0.0
    pmax = 1.0
    margin = 1.0
    ent = 0.0

    if pipe is not None:
        try:
            if hasattr(pipe, "predict_proba"):
                probs = pipe.predict_proba([text])[0]
                # giả sử lớp 1 = "toxic/unsafe"; nếu khác, map theo pipe.classes_
                if hasattr(pipe, "classes_"):
                    idx = list(pipe.classes_).index(1) if 1 in getattr(pipe, "classes_", []) else int(len(probs) - 1)
                else:
                    idx = int(len(probs) - 1)
                ml_prob_toxic = float(probs[idx])
                p_sorted = sorted(probs, reverse=True)
                pmax = float(p_sorted[0])
                margin = float(p_sorted[0] - (p_sorted[1] if len(p_sorted) > 1 else 0.0))
                ent = float(entropy(probs))
                ml_label = "unsafe" if ml_prob_toxic >= THRESHOLD else "neutral"
            else:
                pred = pipe.predict([text])[0]
                ml_label = str(pred)
        except Exception:
            pass

    # ----- Lexicon -----
    lex_score, hits = lexicon_score(text)

    # ----- Fuse -----
    risk_score = max(ml_prob_toxic, lex_score)
    label = "unsafe" if risk_score >= THRESHOLD else "neutral"

    return {
        "label": label,
        "risk_score": round(risk_score, 4),
        "ml": {
            "label": ml_label,
            "p_toxic": round(ml_prob_toxic, 4),
            "p_max": round(pmax, 4),
            "margin": round(margin, 4),
            "entropy": round(ent, 4),
        },
        "lexicon": {"score": lex_score, "hits": hits},
        "threshold": THRESHOLD,
    }