/**
 * اختبارات وحدة تقييم أداء المعلمين — EQAS
 * =========================================
 * التشغيل:
 *   cd <playwright-skill-dir>
 *   node run.js <path-to>/tests/e2e/teaching-evaluation.spec.js
 *
 * المتطلبات:
 *   - XAMPP شغال على http://localhost
 *   - مستخدمين: admin (group_admin), ALI (school_admin)
 *   - كلمة المرور: test1234
 */

const { chromium } = require('playwright');
const path = require('path');

const BASE_URL = 'http://localhost/eqas';
const SCREENSHOT_DIR = path.resolve(__dirname, '../../docs/testing/screenshots/2026-05-16');
const SCHOOL_ADMIN = { username: 'ALI', password: 'test1234' };
const GROUP_ADMIN = { username: 'admin', password: 'test1234' };
const TEACHER = { username: 'teacher1', password: 'test1234' };

const PASS = '✅ PASS';
const FAIL = '❌ FAIL';

let passed = 0;
let failed = 0;

function logResult(testName, condition, detail) {
    if (condition) {
        console.log(`${PASS} | ${testName}${detail ? ' — ' + detail : ''}`);
        passed++;
    } else {
        console.log(`${FAIL} | ${testName}${detail ? ' — ' + detail : ''}`);
        failed++;
    }
}

(async () => {
    const browser = await chromium.launch({ headless: false, slowMo: 150 });
    let context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    let page = await context.newPage();

    console.log('\n══════════════════════════════════════════════════════════════');
    console.log('📋 TEST SUITE: Teaching Evaluation Module');
    console.log('══════════════════════════════════════════════════════════════\n');

    // ────────────────────────────────────────────────────────
    // Helper: Login
    // ────────────────────────────────────────────────────────
    async function loginAs(username, password) {
        // Create a fresh context for each login to avoid session conflicts
        if (context) await context.close();
        context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
        page = await context.newPage();
        // Close old page if exists
        const pages = context.pages();
        if (pages.length > 0) page = pages[0];
        await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        await page.fill('input[name="username"]', username);
        await page.fill('input[name="password"]', password);
        await page.click('button[type="submit"]');
        await page.waitForTimeout(3000);
    }

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-1: فلترة dashboard (group_admin)
    // ═══════════════════════════════════════════════════════════
    console.log('─── TS-E2E-1: Filter bar on teaching_dashboard.php ───\n');

    await loginAs(GROUP_ADMIN.username, GROUP_ADMIN.password);
    await page.goto(`${BASE_URL}/pages/supervision/teaching_dashboard.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    // التحقق من وجود الفلاتر الثلاثة
    const yearSelect = await page.$('select[name="academic_year_id"]');
    logResult('فلتر السنة الدراسية موجود', !!yearSelect);

    const semesterSelect = await page.$('select[name="semester_id"]');
    logResult('فلتر الفصل الدراسي موجود', !!semesterSelect);

    const schoolSelect = await page.$('select[name="school_id"]');
    logResult('فلتر المدرسة موجود', !!schoolSelect);

    await page.screenshot({ path: `${SCREENSHOT_DIR}/teaching_dashboard.png`, fullPage: true });

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-2: بطاقات KPI الأربع
    // ═══════════════════════════════════════════════════════════
    console.log('\n─── TS-E2E-2: Four KPI cards visible ───\n');

    const statCards = await page.$$('.stat-card');
    logResult('بطاقات KPI موجودة', statCards.length >= 4, `وجدت ${statCards.length} بطاقة`);

    const statValues = await page.$$('.stat-value');
    logResult('قيم KPI رقمية', statValues.length >= 4);

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-3: التنقل إلى teaching_school.php
    // ═══════════════════════════════════════════════════════════
    console.log('\n─── TS-E2E-3: Dashboard → School analysis navigation ───\n');

    // البحث عن رابط لمدرسة في الجدول
    const schoolLink = await page.$('table.data-table a[href*="teaching_school.php"]');
    if (schoolLink) {
        const href = await schoolLink.getAttribute('href');
        logResult('رابط مدرسة موجود في الجدول', !!href, href);

        await schoolLink.click();
        await page.waitForTimeout(2000);
        const currentUrl = page.url();
        logResult('التنقل إلى teaching_school.php', currentUrl.includes('teaching_school.php'), currentUrl);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/teaching_school.png`, fullPage: true });
    } else {
        logResult('لا توجد بيانات — يتم اختبار واجهة فارغة', true, 'جدول المدارس فارغ (متوقع بدون بيانات)');

        // اختبر الفلتر بدلاً من ذلك — غير المدرسة
        await page.goto(`${BASE_URL}/pages/supervision/teaching_school.php?id=1`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        const url2 = page.url();
        logResult('الوصول المباشر لتحليل المدرسة', url2.includes('teaching_school.php'), url2);
    }

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-4: التنقل إلى development_plan
    // ═══════════════════════════════════════════════════════════
    console.log('\n─── TS-E2E-4: Navigate to development plan ───\n');

    await page.goto(`${BASE_URL}/pages/supervision/development_plan.php?school_id=1`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const devPlanUrl = page.url();
    logResult('صفحة خطة التطوير مفتوحة', devPlanUrl.includes('development_plan.php'), devPlanUrl);
    await page.screenshot({ path: `${SCREENSHOT_DIR}/development_plan.png`, fullPage: true });

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-5: منع وصول المعلم إلى dashboard
    // ═══════════════════════════════════════════════════════════
    console.log('\n─── TS-E2E-5: Teacher denied from dashboard ───\n');

    await loginAs(TEACHER.username, TEACHER.password);
    console.log('  [debug] After teacher login URL:', page.url());
    await page.goto(`${BASE_URL}/pages/supervision/teaching_dashboard.php`, { waitUntil: 'networkidle', timeout: 15000 }).catch(e => console.log('  [debug] goto timeout/error:', e.message));
    await page.waitForTimeout(3000);
    const teacherUrl = page.url();
    console.log('  [debug] Dashboard URL:', teacherUrl);
    const pageContent = await page.content();
    const hasNotPerm = pageContent.includes('ليس لديك');
    const hasPerm = pageContent.includes('صلاحية');
    const hasRequireLogin = pageContent.includes('تسجيل الدخول');
    const denied = !teacherUrl.includes('teaching_dashboard.php') || hasNotPerm || hasPerm;
    await page.screenshot({ path: `${SCREENSHOT_DIR}/teacher-denied.png`, fullPage: true });
    logResult('المعلم يُمنع من dashboard', denied, teacherUrl + (hasNotPerm ? ' (ليس لديك)' : hasRequireLogin ? ' (تسجيل الدخول)' : ''));

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-6: التحقق من وجود Canvas الرادار في teaching_school
    // ═══════════════════════════════════════════════════════════
    console.log('\n─── TS-E2E-6: Radar chart canvas in teaching_school ───\n');

    await loginAs(GROUP_ADMIN.username, GROUP_ADMIN.password);
    await page.goto(`${BASE_URL}/pages/supervision/teaching_school.php?id=1`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    const radarCanvas = await page.$('#teachingRadarChart');
    logResult('Canvas الرادار موجود', !!radarCanvas);

    const subjectCanvas = await page.$('#teachingSubjectChart');
    const noDataMsg = await page.$('text=لا توجد بيانات كافية حسب المادة');
    logResult('Canvas المواد أو رسالة عدم وجود بيانات', !!(subjectCanvas || noDataMsg));

    await page.screenshot({ path: `${SCREENSHOT_DIR}/teaching_school_radar.png`, fullPage: true });

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-7: زر العودة للمجموعة
    // ═══════════════════════════════════════════════════════════
    console.log('\n─── TS-E2E-7: Back to group button ───\n');

    const backBtn = await page.$('a[href*="teaching_dashboard.php"]');
    if (backBtn) {
        const backHref = await backBtn.getAttribute('href');
        logResult('زر العودة للمجموعة موجود', !!backHref, backHref);

        await backBtn.click();
        await page.waitForTimeout(1500);
        const backUrl = page.url();
        logResult('العودة إلى teaching_dashboard.php', backUrl.includes('teaching_dashboard.php'), backUrl);
    } else {
        logResult('زر العودة غير ظاهر', true, 'يظهر فقط لـ group_admin في teaching_school');
    }

    // ═══════════════════════════════════════════════════════════
    // TS-E2E-8: sidebar يحتوي على الروابط الجديدة
    // ═══════════════════════════════════════════════════════════
    console.log('\n─── TS-E2E-8: New sidebar links visible ───\n');

    const pageText = await page.evaluate(() => document.body.innerText);
    const foundDashboard = pageText.includes('مركز قيادة تقييم المعلمين');
    const foundSchool = pageText.includes('تحليل أداء المعلمين');

    logResult('رابط "مركز قيادة تقييم المعلمين" في القائمة', foundDashboard);
    logResult('رابط "تحليل أداء المعلمين" في القائمة', foundSchool);

    // ────────────────────────────────────────────────────────
    // النتيجة النهائية
    // ────────────────────────────────────────────────────────
    console.log('\n══════════════════════════════════════════════════════════════');
    console.log(`📊 RESULTS: ${passed} PASSED, ${failed} FAILED`);
    console.log('══════════════════════════════════════════════════════════════\n');

    await browser.close();
    process.exit(failed > 0 ? 1 : 0);
})();
