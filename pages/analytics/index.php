<?php
/**
 * ============================================================
 * بوابة التحليلات الاستكشافية
 * نظام التقييم الشامل لجودة التعليم المدرسي  - EQAS
 * ============================================================
 */

$pageTitle = 'بوابة التحليلات';
$breadcrumb = [
    'الإدارة' => null,
    'التحليلات' => null
];

require_once __DIR__ . '/../../includes/header.php';

// التحقق من الصلاحيات - فقط مدير المجموعة
if (!hasPermission('group_admin')) {
    setFlash('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'danger');
    redirect('../../index.php');
}
?>
<style>
    .analytics-hub {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 2rem;
        margin-top: 2rem;
    }
    .analytics-card {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        text-align: center;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .analytics-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    }
    .analytics-icon {
        font-size: 3rem;
        margin-bottom: 1rem;
        color: #3b82f6;
    }
    .analytics-title {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #1e293b;
    }
    .analytics-description {
        color: #64748b;
        line-height: 1.6;
    }
    .analytics-link {
        display: inline-block;
        margin-top: 1.5rem;
        padding: 0.5rem 1rem;
        background-color: #3b82f6;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        font-weight: 500;
        transition: background-color 0.2s ease;
    }
    .analytics-link:hover {
        background-color: #2563eb;
    }
    .feature-list {
        text-align: right;
        margin-top: 1rem;
        padding-right: 1rem;
    }
    .feature-list li {
        margin-bottom: 0.5rem;
        display: flex;
        justify-content: flex-start;
        align-items: center;
    }
    .feature-list li::before {
        content: "✓";
        color: #10b981;
        font-weight: bold;
        margin-right: 0.5rem;
    }
</style>
<div class="container mx-auto px-4 py-8">

        <!-- Main Content -->
        <div class="container mx-auto px-4 py-8">
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900"><?= e($pageTitle) ?></h1>
                <p class="text-gray-600">استكشف البيانات المدرسية عبر أدوات تحليلية تفاعلية</p>
            </div>

            <!-- Analytics Hub -->
            <div class="analytics-hub">
                <!-- بطاقة الاتجاهات الزمنية -->
                <div class="analytics-card">
                    <div class="analytics-icon">📈</div>
                    <div class="analytics-title">الاتجاهات الزمنية</div>
                    <p class="analytics-description">
                        تحليل تطور مؤشرات الأداء عبر السنوات والفصول الدراسية. اكتشف الاتجاهات العامة للمجموعة ومقارنة الأداء عبر الزمن.
                    </p>
                    <div class="feature-list">
                        <ul>
                            <li>متوسط EQI عبر السنوات</li>
                            <li>مقارنة الأفواج (Cohort Analysis)</li>
                            <li>اتجاهات الحضور والنجاح</li>
                        </ul>
                    </div>
                    <a href="trends.php" class="analytics-link">استكشف الاتجاهات →</a>
                </div>

                <!-- بطاقة تحليل المدرسة -->
                <div class="analytics-card">
                    <div class="analytics-icon">🏫</div>
                    <div class="analytics-title">تحليل المدرسة</div>
                    <p class="analytics-description">
                        غوص عميق في أداء مدرسة محددة. تحليل شامل للشعب، المواد، الاتجاهات، وتوزيع الطلاب.
                    </p>
                    <div class="feature-list">
                        <ul>
                            <li>تحليل EQI والاتجاهات</li>
                            <li>توزيع الطلاب حسب الصف</li>
                            <li>أداء الشعب والمواد</li>
                            <li>روابط للتحليل العميق للشعب والمواد</li>
                        </ul>
                    </div>
                    <a href="school.php" class="analytics-link">ابدأ التحليل →</a>
                </div>

                <!-- بطاقة مقارنة المدارس -->
                <div class="analytics-card">
                    <div class="analytics-icon">🔍</div>
                    <div class="analytics-title">مقارنة المدارس</div>
                    <p class="analytics-description">
                        مقارنة أداء المدارس المتعددة عبر مؤشرات محددة. اختر المدارس والمؤشرات لرؤية مقارنة بصرية فورية.
                    </p>
                    <div class="feature-list">
                        <ul>
                            <li>مقارنة EQI والدرجات</li>
                            <li>مقارنةعداد الطلاب والمعلمين</li>
                            <li>مؤشرات مخصصة حسب المادة</li>
                        </ul>
                    </div>
                    <a href="compare.php" class="analytics-link">ابدأ المقارنة →</a>
                </div>

                <!-- بطاقة تحليل الشعبة -->
                <div class="analytics-card">
                    <div class="analytics-icon">👥</div>
                    <div class="analytics-title">تحليل الشعبة</div>
                    <p class="analytics-description">
                        تحليل مفصل لأداء شعبة محددة. توزيع الدرجات، أداء المواد، الاتجاهات عبر الفصول، وقائمة الطلاب.
                    </p>
                    <div class="feature-list">
                        <ul>
                            <li>توزيع الدرجات (راسب، مقبول، جيد، ممتاز)</li>
                            <li>متوسط أداء المواد</li>
                            <li>اتجاه الأداء عبر الفصول</li>
                            <li>قائمة الطلاب المفصلة</li>
                        </ul>
                    </div>
                    <a href="class.php" class="analytics-link">استكشف الشعبة →</a>
                </div>

                <!-- بطاقة تحليل المادة -->
                <div class="analytics-card">
                    <div class="analytics-icon">📚</div>
                    <div class="analytics-title">تحليل المادة</div>
                    <p class="analytics-description">
                        تحليل أداء مادة محددة عبر المدارس والشعب. توزيع الدرجات، متوسط الأداء، ومقارنة الأداء بين المدارس.
                    </p>
                    <div class="feature-list">
                        <ul>
                            <li>توزيع الدرجات للمادة</li>
                            <li>متوسط الأداء عبر المدارس</li>
                            <li>مقارنة الأداء بين الشعب</li>
                            <li>تحليل الاتجاهات عبر الفصول</li>
                        </ul>
                    </div>
                    <a href="subject.php" class="analytics-link">استكشف المادة →</a>
                </div>

                <!-- بطاقة تقارير متقدمة -->
                <div class="analytics-card">
                    <div class="analytics-icon">📊</div>
                    <div class="analytics-title">تقارير متقدمة</div>
                    <p class="analytics-description">
                        تقارير مخصصة ومجدولة لتحليل الأداء العميق. تصدير البيانات، تقارير الاتجاهات المخصصة، وتحليلات المخاطر.
                    </p>
                    <div class="feature-list">
                        <ul>
                            <li>تقارير مخصصة للفلاتر</li>
                            <li>تصدير البيانات بصيغ متعددة</li>
                            <li>جدولة التقارير التلقائية</li>
                            <li>تحليلات المخاطر والإنذار المبكر</li>
                        </ul>
                    </div>
                    <a href="#" class="analytics-link disabled">قريباً →</a>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <?php require_once __DIR__ . '/../../includes/footer.php'; ?>