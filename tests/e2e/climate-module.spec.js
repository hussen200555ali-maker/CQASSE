/**
 * اختبارات وحدة المناخ المدرسي — EQAS
 * ==========================================
 * يختبر:
 * - صلاحيات الصفحات (group_admin vs school_admin)
 * - تحميل لوحة المعلومات الاستراتيجية
 * - تحميل تحليل المدرسة
 * - قائمة الاستبيانات ونموذج الإنشاء
 * - القوائم المنسدلة للفصل الدراسي (0/1/2)
 * - الشريط الجانبي (إخفاء index.php من group_admin)
 *
 * التشغيل:
 *   node run.js <path-to>/tests/e2e/climate-module.spec.js
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
const SCHOOL_ADMIN = { username: 'ALI', password: 'test1234', schoolId: 1 };
const GROUP_ADMIN = { username: 'admin', password: 'test1234' };

const PASS = '✅ PASS';
const FAIL = '❌ FAIL';

let testsRun = 0, testsPassed = 0, testsFailed = 0;

function logResult(name, passed, detail) {
    testsRun++;
    if (passed) testsPassed++; else testsFailed++;
    console.log(`${passed ? PASS : FAIL} | ${name}${detail ? ' — ' + detail : ''}`);
}

async function login(page, user) {
    await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    await page.fill('input[name="username"]', user.username);
    await page.fill('input[name="password"]', user.password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
}

async function logout(page) {
    await page.goto(`${BASE_URL}/logout.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
}

async function checkPageLoads(page, url, expectedContent) {
    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout: 15000 });
        await page.waitForTimeout(1000);
        const bodyText = await page.textContent('body');
        if (expectedContent) {
            return bodyText.includes(expectedContent);
        }
        return true;
    } catch (e) {
        return false;
    }
}

async function assertSemesterDropdown(page, selectSelector, expectedValues) {
    const options = await page.evaluate((sel) => {
        const select = document.querySelector(sel);
        if (!select) return [];
        return Array.from(select.options).map(o => ({ value: o.value, text: o.text.trim() }));
    }, selectSelector);
    
    for (const exp of expectedValues) {
        const found = options.some(o => o.value === exp.value && o.text.includes(exp.text));
        if (!found) return false;
    }
    return true;
}

(async () => {
    const browser = await chromium.launch({ headless: false, slowMo: 100 });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();

    console.log('\n══════════════════════════════════════════════════════════════');
    console.log('📋 CLIMATE MODULE E2E TESTS');
    console.log('══════════════════════════════════════════════════════════════\n');

    try {
        // ══════════════════════════════════════════════════
        // PHASE 1: Group Admin Tests
        // ══════════════════════════════════════════════════
        console.log('─── Phase 1: Group Admin ───\n');

        await login(page, GROUP_ADMIN);

        // CM-01: dashboard.php loads for group_admin
        const dashOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/dashboard.php`, 'لوحة المناخ الاستراتيجية');
        logResult('CM-01: dashboard.php loads for group_admin', dashOk,
            dashOk ? 'Page loaded with title' : 'Page failed to load');
        await page.screenshot({ path: `${SCREENSHOT_DIR}/group_admin_climate_dashboard.png`, fullPage: true });

        // CM-02: school.php loads for group_admin
        const schoolOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/school.php?school_id=1`, 'تحليل المناخ');
        logResult('CM-02: school.php loads for group_admin', schoolOk,
            schoolOk ? 'Page loaded with analysis' : 'Page failed to load');
        await page.screenshot({ path: `${SCREENSHOT_DIR}/group_admin_climate_school.png`, fullPage: true });

        // CM-03: dashboard.php has 0/1/2 semester dropdown
        const dashDropdown = await assertSemesterDropdown(page, 'select[name="semester_id"]', [
            { value: '0', text: 'جميع' },
            { value: '1', text: 'الأول' },
            { value: '2', text: 'الثاني' }
        ]);
        logResult('CM-03: dashboard.php has 0/1/2 semester dropdown', dashDropdown,
            dashDropdown ? 'Options: 0=الكل, 1=الأول, 2=الثاني' : 'Incorrect dropdown options');

        // CM-04: school.php has 0/1/2 semester dropdown
        const schoolDropdown = await assertSemesterDropdown(page, 'select[name="semester_id"]', [
            { value: '0', text: 'جميع' },
            { value: '1', text: 'الأول' },
            { value: '2', text: 'الثاني' }
        ]);
        logResult('CM-04: school.php has 0/1/2 semester dropdown', schoolDropdown,
            schoolDropdown ? 'Options: 0=الكل, 1=الأول, 2=الثاني' : 'Incorrect dropdown options');

        // CM-05: school.php filters work (select semester 1)
        await page.selectOption('select[name="semester_id"]', '1');
        await page.click('button[type="submit"]');
        await page.waitForTimeout(1000);
        const urlAfterFilter = page.url();
        logResult('CM-05: school.php semester filter submits correctly', urlAfterFilter.includes('semester_id=1'),
            urlAfterFilter.includes('semester_id=1') ? 'semester_id=1 in URL' : `URL: ${urlAfterFilter}`);

        // CM-06: dashboard.php KPI cards visible
        await page.goto(`${BASE_URL}/pages/climate/dashboard.php`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1000);
        const kpiCards = await page.locator('.card').count();
        logResult('CM-06: dashboard.php has KPI cards', kpiCards >= 4,
            kpiCards >= 4 ? `${kpiCards} cards found` : `Only ${kpiCards} cards`);

        // CM-07: dashboard.php has charts
        const charts = await page.locator('canvas').count();
        logResult('CM-07: dashboard.php has Chart.js canvases', charts >= 1,
            charts >= 1 ? `${charts} canvases found` : 'No canvases');

        // CM-08: index.php redirects (or is accessible but not in sidebar)
        // Group admin can still access index.php directly via URL even if sidebar link is removed
        const indexOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/index.php`, 'استبيان');
        logResult('CM-08: index.php accessible by group_admin via URL', indexOk,
            indexOk ? 'Page loads (URL access not blocked)' : 'Page blocked');

        await logout(page);

        // ══════════════════════════════════════════════════
        // PHASE 2: School Admin Tests
        // ══════════════════════════════════════════════════
        console.log('\n─── Phase 2: School Admin ───\n');

        await login(page, SCHOOL_ADMIN);

        // CM-09: index.php loads for school_admin
        const indexSchOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/index.php`, 'استبيان');
        logResult('CM-09: index.php loads for school_admin', indexSchOk,
            indexSchOk ? 'Page loaded with survey list' : 'Page failed to load');
        await page.screenshot({ path: `${SCREENSHOT_DIR}/school_admin_climate_index.png`, fullPage: true });

        // CM-10: index.php has 0/1/2 semester dropdown
        await page.goto(`${BASE_URL}/pages/climate/index.php`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(500);
        const idxDropdown = await assertSemesterDropdown(page, 'select[name="semester_id"]', [
            { value: '0', text: 'جميع' },
            { value: '1', text: 'الأول' },
            { value: '2', text: 'الثاني' }
        ]);
        logResult('CM-10: index.php has 0/1/2 semester dropdown', idxDropdown,
            idxDropdown ? 'Options: 0=الكل, 1=الأول, 2=الثاني' : 'Incorrect dropdown options');

        // CM-11: survey.php loads for school_admin
        const surveyOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/survey.php`, 'استبيان');
        logResult('CM-11: survey.php loads for school_admin', surveyOk,
            surveyOk ? 'Page loaded with survey form' : 'Page failed to load');

        // CM-12: survey.php has 1/2 semester dropdown (no "الكل")
        const survDropdown = await assertSemesterDropdown(page, 'select[name="semester_id"]', [
            { value: '1', text: 'الأول' },
            { value: '2', text: 'الثاني' }
        ]);
        logResult('CM-12: survey.php has 1/2 semester dropdown', survDropdown,
            survDropdown ? 'Options: 1=الأول, 2=الثاني (no 0=الكل)' : 'Incorrect dropdown options');

        // CM-13: survey.php does NOT have option 0
        const hasOption0 = await page.evaluate(() => {
            const sel = document.querySelector('select[name="semester_id"]');
            if (!sel) return true;
            return Array.from(sel.options).some(o => o.value === '0');
        });
        logResult('CM-13: survey.php does not have semester=0 option', !hasOption0,
            !hasOption0 ? 'No option 0 found (correct for form)' : 'Found option 0 (should not exist)');

        // CM-14: edit.php loads for school_admin
        const editOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/edit.php?id=1`, 'تعديل');
        logResult('CM-14: edit.php?id=1 loads for school_admin', editOk,
            editOk ? 'Page loaded with edit form' : 'Page failed to load');
        await page.screenshot({ path: `${SCREENSHOT_DIR}/school_admin_climate_edit.png`, fullPage: true });

        // CM-15: view.php loads for school_admin
        const viewOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/view.php?id=1`, 'استبيان');
        logResult('CM-15: view.php?id=1 loads for school_admin', viewOk,
            viewOk ? 'Page loaded with survey view' : 'Page failed to load');
        await page.screenshot({ path: `${SCREENSHOT_DIR}/school_admin_climate_view.png`, fullPage: true });

        // CM-16: school.php loads for school_admin with school restriction
        const schSchOk = await checkPageLoads(page, `${BASE_URL}/pages/climate/school.php`, 'تحليل');
        logResult('CM-16: school.php loads for school_admin', schSchOk,
            schSchOk ? 'Page loaded' : 'Page failed to load');

        await logout(page);

        // ══════════════════════════════════════════════════
        // PHASE 3: Sidebar Verification
        // ══════════════════════════════════════════════════
        console.log('\n─── Phase 3: Sidebar Verification ───\n');

        await login(page, GROUP_ADMIN);

        // CM-17: Group admin sidebar does NOT have "استبيان المناخ" link
        await page.goto(`${BASE_URL}/pages/dashboard/admin.php`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1000);
        const sidebarTextGa = await page.textContent('.sidebar');
        const hasClimateSurveyLink = sidebarTextGa.includes('نتائج المناخ التنظيمي');
        logResult('CM-17: Group admin sidebar hides climate/index.php', !hasClimateSurveyLink,
            !hasClimateSurveyLink ? 'index.php link not found in sidebar (correct)' : 'index.php link STILL in sidebar');

        // CM-18: Group admin sidebar has "لوحة المناخ الاستراتيجية"
        const hasDashboardLink = sidebarTextGa.includes('لوحة المناخ الاستراتيجية');
        logResult('CM-18: Group admin sidebar shows dashboard link', hasDashboardLink,
            hasDashboardLink ? 'dashboard.php link found' : 'dashboard.php link missing');

        // CM-19: Group admin sidebar has "تحليل مناخ المدرسة"
        const hasSchoolLink = sidebarTextGa.includes('تحليل مناخ المدرسة');
        logResult('CM-19: Group admin sidebar shows school analysis link', hasSchoolLink,
            hasSchoolLink ? 'school.php link found' : 'school.php link missing');

        await logout(page);

        await login(page, SCHOOL_ADMIN);

        // CM-20: School admin sidebar HAS "المناخ المدرسي" link
        await page.goto(`${BASE_URL}/index.php`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1000);
        const sidebarTextSa = await page.textContent('.sidebar');
        const hasSchoolClimateLink = sidebarTextSa.includes('المناخ المدرسي');
        logResult('CM-20: School admin sidebar shows climate link', hasSchoolClimateLink,
            hasSchoolClimateLink ? 'climate/index.php link found in sidebar' : 'climate/index.php link missing');

        await logout(page);

    } catch (err) {
        console.error('\n❌ TEST ERROR:', err.message);
        await page.screenshot({ path: `${SCREENSHOT_DIR}/crash.png`, fullPage: true });
    } finally {
        // ══════════════════════════════════════════════════
        // SUMMARY
        // ══════════════════════════════════════════════════
        console.log('\n══════════════════════════════════════════════════════════════');
        console.log(`📊 RESULTS: ${testsPassed}/${testsRun} passed, ${testsFailed} failed`);
        console.log(`📸 Screenshots saved to: ${SCREENSHOT_DIR}`);
        console.log('══════════════════════════════════════════════════════════════\n');

        await browser.close();
        process.exit(testsFailed > 0 ? 1 : 0);
    }
})();
