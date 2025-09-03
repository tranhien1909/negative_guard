from fastapi import FastAPI
from fastapi.responses import HTMLResponse
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
import joblib, os

app = FastAPI()

# Mount thư mục assets để load CSS/JS/Images
app.mount("/assets", StaticFiles(directory="../assets"), name="assets")

# Cho phép gọi từ trình duyệt
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Load model
MODEL_FILE = "artifacts/model_pipeline.pkl"
if not os.path.exists(MODEL_FILE):
    raise RuntimeError(f"Model file not found: {MODEL_FILE}. Run train_model.py first.")
model = joblib.load(MODEL_FILE)


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
                <a href="register.php" class="btn">Login / Register</a>
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
                        <form action="" class="hero-form" method="POST">
                            <input type="email" name="email_address" aria-label="email"
                                placeholder="Your Email Address..." required class="email-field">
                            <button type="submit" class="btn">Get Response Back</button>
                        </form>
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
                    <a href="#" class="logo">Red Stream</a>
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
        async function analyze() {
            let text = document.getElementById("inputText").value;
            if (!text.trim()) {
                document.getElementById("result").innerHTML = "<div class='result medium'>⚠️ Vui lòng nhập nội dung!</div>";
                return;
            }

            try {
                let res = await fetch("/predict", {
                    method: "POST",
                    headers: {"Content-Type": "application/json"},
                    body: JSON.stringify({text: text})
                });
                let data = await res.json();
                let risk = data.risk_score;
                let label = data.label;
                let cssClass = "safe", status = "🟢 An toàn";

                if (risk < 0.4) { cssClass = "safe"; status = "🟢 An toàn"; }
                else if (risk < 0.7) { cssClass = "medium"; status = "🟡 Trung bình"; }
                else { cssClass = "danger"; status = "🔴 Nguy hiểm"; }

                let now = new Date().toLocaleString();

                document.getElementById("result").innerHTML = `
                    <div class="result ${cssClass}">
                        <p><b>📝 Kết quả phân tích:</b></p>
                        <ul style="margin:0;padding-left:18px;">
                            <li><b>Label:</b> ${label}</li>
                            <li><b>Độ rủi ro:</b> ${(risk*100).toFixed(1)}%</li>
                            <li><b>Mức cảnh báo:</b> ${status}</li>
                            <li><b>Khuyến nghị:</b> 
                                ${risk < 0.4 
                                    ? "✅ Nội dung an toàn, có thể đăng tải." 
                                    : risk < 0.7 
                                        ? "⚠️ Cần xem xét trước khi đăng." 
                                        : "🚨 Nội dung tiêu cực, cần gỡ bỏ hoặc kiểm duyệt gấp!"}
                            </li>
                            <li><b>Thời gian phân tích:</b> ${now}</li>
                        </ul>
                    </div>
                `;
            } catch (err) {
                document.getElementById("result").innerHTML = "<div class='result danger'>❌ Lỗi kết nối tới API!</div>";
            }
        }
        </script>
    </body>
    </html>
    """


# API JSON (cho hệ thống khác gọi)
@app.post("/predict")
async def predict(payload: dict):
    text = payload.get("text", "")
    pred = model.predict([text])[0]
    prob = max(model.predict_proba([text])[0])
    return {"label": pred, "risk_score": float(prob)}
