<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/auth.php';
send_security_headers();
?>
<!doctype html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Phân tích bài viết MXH</title>
    <link rel="stylesheet" href="/assets/styles.css">
    <!-- favicon-->
    <link rel="shortcut icon" href="./favicon.svg" type="image/svg+xml">

    <!--css-->
    <link rel="stylesheet" href="./assets/css/style.css">


    <!-- google font link-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Roboto:wght@400;500;600&display=swap"
        rel="stylesheet">
</head>

<body>
    <!-- <header>
        <h1>Kiểm tra an toàn & định hướng thông tin</h1>
        <nav>
            <a href="/login.php">Đăng nhập Admin</a>
        </nav>
    </header> -->

    <!-- HEADER-->
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
                <a href="/login.php" class="btn">Admin Login</a>
                <button class="nav-toggle-btn" aria-label="Toggle menu" data-nav-toggler>
                    <ion-icon name="menu-sharp" aria-hidden="true" class="menu-icon"></ion-icon>
                    <ion-icon name="close-sharp" aria-hidden="true" class="close-icon"></ion-icon>
                </button>
            </div>
        </div>
    </header>
    <main>
        <article>
            <!--HERO-->
            <section class="section hero" id="home" style="background-image: url('./assets/images/hero-bg.png')"
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
                        <img src="./assets/images/iuh.jpg" width="587" height="839" alt="hero banner" class="w-100">
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
                        <h3 class="h3 section-title">
                            We Care About You</h3>
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
            <style>
                .card {
                    background: linear-gradient(135deg, #f8d7da, #f1b0b7);
                    color: #721c24;
                    padding: 20px 25px;
                    border-radius: 12px;
                    border: 1px solid #f5c6cb;
                    box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
                    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                    max-width: 600px;
                    margin: 10px 0 10px 0;
                    text-align: left;
                    animation: fadeIn 0.4s ease-in-out;
                    position: relative;
                }

                /* Tiêu đề trong thẻ */
                .card h3 {
                    margin-top: 0;
                    font-size: 18px;
                    font-weight: 600;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                /* Nội dung chi tiết */
                .card p {
                    margin: 8px 0 0 0;
                    font-size: 15px;
                    line-height: 1.6;
                }

                /* Hiệu ứng xuất hiện */
                @keyframes fadeIn {
                    from {
                        opacity: 0;
                        transform: translateY(-5px);
                    }

                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
            </style>

            <!-- ANALYZE -->
            <section class="section" id="analyze" style="padding:40px;">
                <div class="container">
                    <form id="analyzeForm">
                        <h3 class="h3 section-title">
                            Phân tích bài viết Facebook (hoặc MXH khác)</h3>
                        <textarea style="width: 100%; padding: 10px;" id="text" name="text" rows="10" maxlength="<?= htmlspecialchars(envv('MAX_TEXT_LEN', 5000)) ?>" required></textarea>
                        <button type="button" id="analyzeBtn" class="btn">Phân tích</button>
                    </form>
                    <section id="result" hidden>
                        <h2>Kết quả</h2>
                        <div>
                            <div id="risk"></div>
                            <div id="warnings"></div>
                        </div>
                    </section>
                </div>
            </section>
        </article>
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
    <script src="./assets/js/script.js" defer></script>
    <!--ionicon link-->
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    <script src="/assets/app.js"></script>
</body>

</html>