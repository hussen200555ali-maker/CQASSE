<?php
// Standalone test page for responsive CSS verification
define('EQAS_ASSETS_URL', 'assets');
define('EQAS_URL', '');
function baseUrl($url) { return $url; }
$pageTitle = 'اختبار الاستجابة';
$currentScript = '/test-responsive.php';
$currentSection = 'assessment';
$userRole = 'group_admin';
$userName = 'مدير النظام';
$userSchool = 'مدرسة النموذجية';
$userInitials = 'م';
$notificationCount = 3;
$userRoleLabel = 'مدير النظام';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | نظام التقييم الشامل</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;600;700&family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { margin: 0; background: #f5f7fa; }
        .test-header { background: #1a5632; color: white; padding: 8px 16px; font-size: 12px; text-align: center; position: fixed; top: 0; left: 0; right: 0; z-index: 9999; }
        .test-viewports { display: flex; gap: 8px; justify-content: center; margin-top: 4px; }
        .test-viewports button { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); color: white; padding: 2px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; }
        .test-viewports button.active { background: #c8a951; border-color: #c8a951; }
        .page-wrapper { margin-top: 48px; }
        html { scroll-behavior: smooth; }
    </style>
</head>
<body>
<div class="test-header" id="testBar">
    <div>🧪 EQAS Responsive Test — اضغط على الأزرار لتغيير حجم الشاشة</div>
    <div class="test-viewports">
        <button onclick="setSize(375,667)" class="active" id="btn-xs">📱 جوال 375px</button>
        <button onclick="setSize(768,1024)" id="btn-md">📟 تابلت 768px</button>
        <button onclick="setSize(991,1024)" id="btn-lg">💻 تابلت عريض 991px</button>
        <button onclick="setSize(1200,900)" id="btn-xl">🖥 ديسكتوب 1200px</button>
        <button onclick="setSize(1920,1080)" id="btn-2xl">🖥 كبير 1920px</button>
        <button onclick="setSize(window.innerWidth,window.innerHeight)" id="btn-reset">↺ فتح</button>
    </div>
</div>

<div class="page-wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <div class="logo-icon"><i class="fas fa-graduation-cap"></i></div>
            <h2>EQAS</h2>
            <div class="logo-subtitle">نظام التقييم الشامل</div>
        </div>
        <div class="sidebar-user">
            <div class="sidebar-user-avatar"><?php echo $userInitials; ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo $userName; ?></div>
                <div class="sidebar-user-role"><?php echo $userRoleLabel; ?></div>
                <div class="sidebar-user-role" style="font-size:10px; color:rgba(255,255,255,0.5);"><?php echo $userSchool; ?></div>
            </div>
        </div>
        <nav class="sidebar-nav" aria-label="القائمة الرئيسية">
            <div class="sidebar-section-title">الرئيسية</div>
            <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-home"></i></span><span class="menu-text">الرئيسية</span></a>

            <div class="sidebar-section-title">الإدارة</div>
            <a href="#" class="sidebar-menu-link active"><span class="menu-icon"><i class="fas fa-users-cog"></i></span><span class="menu-text">إدارة المستخدمين</span></a>
            <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-school"></i></span><span class="menu-text">إدارة المدارس</span></a>

            <div class="sidebar-section-title">الإعدادات الهيكلية</div>
            <div class="sidebar-menu-section">
                <button class="sidebar-menu-link active" data-toggle="submenu" aria-expanded="true">
                    <span class="menu-icon"><i class="fas fa-cogs"></i></span>
                    <span class="menu-text">الإعدادات الهيكلية</span>
                    <span class="sidebar-menu-arrow"><i class="fas fa-chevron-left"></i></span>
                </button>
                <div class="sidebar-submenu show">
                    <a href="#" class="sidebar-menu-link active"><span class="menu-icon"><i class="fas fa-book"></i></span><span class="menu-text">المواد الدراسية</span></a>
                    <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-balance-scale"></i></span><span class="menu-text">توزيع الأوزان</span></a>
                    <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-layer-group"></i></span><span class="menu-text">الفصول والشعب</span></a>
                </div>
            </div>

            <div class="sidebar-section-title">التقييمات</div>
            <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-tachometer-alt"></i></span><span class="menu-text">لوحة التقييمات</span></a>
            <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-file-alt"></i></span><span class="menu-text">إدارة الاستمارات</span></a>

            <div class="sidebar-section-title">التقارير</div>
            <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-chart-bar"></i></span><span class="menu-text">التقارير</span></a>

            <div class="sidebar-section-title">النظام</div>
            <a href="#" class="sidebar-menu-link"><span class="menu-icon"><i class="fas fa-cog"></i></span><span class="menu-text">الإعدادات</span></a>
        </nav>
        <div class="sidebar-footer">
            <a href="#" class="sidebar-logout-btn"><span><i class="fas fa-question-circle"></i> المساعدة</span></a>
            <a href="#" class="sidebar-logout-btn"><span><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</span></a>
        </div>
    </aside>

    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <div class="main-content" id="main-content">
        <header class="topbar" id="topbar">
            <div class="topbar-right">
                <button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-label="فتح القائمة">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>اختبار الاستجابة المتجاوبة</h1>
                    <nav class="breadcrumb-nav" aria-label="مسار التنقل">
                        <a href="#">الرئيسية</a>
                        <span class="separator">/</span>
                        <span class="current">اختبار</span>
                    </nav>
                </div>
            </div>
            <div class="topbar-left">
                <div class="quick-search">
                    <input type="text" placeholder="بحث سريع..." aria-label="بحث سريع">
                    <i class="fas fa-search"></i>
                </div>
                <div class="topbar-item notification-bell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </div>
                <div class="topbar-item user-dropdown">
                    <button class="user-btn" type="button">
                        <i class="fas fa-user-circle"></i>
                        <span>مدير النظام</span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
            </div>
        </header>

        <div class="main-content-inner" id="main-content-area">
            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">١٬٢٥٠</div>
                        <div class="stat-label">إجمالي الطلاب</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">٨٥</div>
                        <div class="stat-label">المعلمون</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-school"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">١٢</div>
                        <div class="stat-label">المدارس</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-content">
                        <div class="stat-value">٨٥٪</div>
                        <div class="stat-label">مؤشر الجودة</div>
                    </div>
                </div>
            </div>

            <!-- Grid Layout Test -->
            <div class="section-title">جرب التنسيق الشبكي</div>
            <div class="grid-4">
                <div class="card">
                    <div class="card-header"><h3>بطاقة ١</h3></div>
                    <div class="card-body">محتوى البطاقة الأولى مع بعض النص التجريبي</div>
                </div>
                <div class="card">
                    <div class="card-header"><h3>بطاقة ٢</h3></div>
                    <div class="card-body">محتوى البطاقة الثانية</div>
                </div>
                <div class="card">
                    <div class="card-header"><h3>بطاقة ٣</h3></div>
                    <div class="card-body">محتوى البطاقة الثالثة</div>
                </div>
                <div class="card">
                    <div class="card-header"><h3>بطاقة ٤</h3></div>
                    <div class="card-body">محتوى البطاقة الرابعة</div>
                </div>
            </div>

            <!-- Table Test -->
            <div class="section-title">اختبار الجدول</div>
            <div class="table-wrapper">
                <table class="data-table card-view">
                    <thead>
                        <tr>
                            <th>الاسم</th>
                            <th>المدرسة</th>
                            <th>الصف</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td data-label="الاسم">أحمد محمد</td>
                            <td data-label="المدرسة">مدرسة النموذجية</td>
                            <td data-label="الصف">الصف الخامس</td>
                            <td data-label="الحالة"><span class="badge badge-success">نشط</span></td>
                        </tr>
                        <tr>
                            <td data-label="الاسم">سارة علي</td>
                            <td data-label="المدرسة">مدرسة الفرقان</td>
                            <td data-label="الصف">الصف السادس</td>
                            <td data-label="الحالة"><span class="badge badge-warning">معلق</span></td>
                        </tr>
                        <tr>
                            <td data-label="الاسم">خالد عمر</td>
                            <td data-label="المدرسة">مدرسة النور</td>
                            <td data-label="الصف">الصف الرابع</td>
                            <td data-label="الحالة"><span class="badge badge-danger">غير نشط</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Form Test -->
            <div class="section-title">اختبار النموذج</div>
            <div class="card">
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">الاسم الكامل</label>
                            <input type="text" class="form-control" placeholder="أدخل الاسم">
                        </div>
                        <div class="form-group">
                            <label class="form-label">البريد الإلكتروني</label>
                            <input type="email" class="form-control" placeholder="example@email.com">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">نوع المستخدم</label>
                            <select class="form-control"><option>مدير</option><option>معلم</option></select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">الحالة</label>
                            <select class="form-control"><option>نشط</option><option>غير نشط</option></select>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px; justify-content:flex-start; margin-top:16px;">
                        <button class="btn btn-primary">حفظ</button>
                        <button class="btn btn-light">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function setSize(w, h) {
    window.resizeTo(w, h);
    document.querySelectorAll('.test-viewports button').forEach(b => b.classList.remove('active'));
    const names = {375:'xs',768:'md',991:'lg',1200:'xl',1920:'2xl'};
    const btn = document.getElementById('btn-' + (names[w] || 'reset'));
    if (btn) btn.classList.add('active');
}
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggle = document.getElementById('sidebar-toggle');
    if (toggle) {
        toggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }
    document.querySelectorAll('[data-toggle="submenu"]').forEach(btn => {
        btn.addEventListener('click', function() {
            const sub = this.nextElementSibling;
            if (sub) sub.classList.toggle('show');
        });
    });
});
</script>
</body>
</html>
