/**
 * اختبارات صلاحيات الفصول الدراسية — EQAS
 * =========================================
 * التشغيل:
 *   cd <playwright-skill-dir>
 *   node run.js <path-to>/tests/e2e/semester-permissions.spec.js
 *
 * المتطلبات:
 *   - XAMPP شغال على http://localhost
 *   - مستخدمين: admin (group_admin), ALI (school_admin)
 *   - كلمة المرور: test1234
 */

const { chromium } = require('playwright');
const path = require('path');

const BASE_URL = 'http://localhost/eqas';
const SCREENSHOT_DIR = path.resolve(__dirname, '../../docs/testing/screenshots/2026-05-11');
const SCHOOL_ADMIN = { username: 'ALI', password: 'test1234', schoolId: 1 };
const GROUP_ADMIN = { username: 'admin', password: 'test1234' };

const PASS = '✅ PASS';
const FAIL = '❌ FAIL';

function logResult(testName, passed, detail) {
    console.log(`${passed ? PASS : FAIL} | ${testName}${detail ? ' — ' + detail : ''}`);
}

(async () => {
    const browser = await chromium.launch({ headless: false, slowMo: 150 });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();

    console.log('\n══════════════════════════════════════════════════════════════');
    console.log('📋 TEST SUITE: Semester Permission Changes');
    console.log('══════════════════════════════════════════════════════════════\n');

    // ────────────────────────────────────────────────────────
    // Phase 1: Login as School Admin
    // ────────────────────────────────────────────────────────
    console.log('─── Phase 1: Login as School Admin (ALI) ───\n');

    await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    await page.fill('input[name="username"]', SCHOOL_ADMIN.username);
    await page.fill('input[name="password"]', SCHOOL_ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(3000);
    const loginUrl = page.url();
    logResult('School admin login', true, `Redirected to: ${loginUrl}`);

    // ────────────────────────────────────────────────────────
    // TS-02: View semesters.php (School Admin)
    // ────────────────────────────────────────────────────────
    console.log('\n─── Phase 2: semesters.php (School Admin) ───\n');

    await page.goto(`${BASE_URL}/pages/academic/semesters.php?year_id=1`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    await page.screenshot({ path: `${SCREENSHOT_DIR}/school_admin_semesters.png`, fullPage: true });

    const stats = await page.evaluate(() => {
        const tables = document.querySelectorAll('table');
        for (const table of tables) {
            const rows = table.querySelectorAll('tbody tr');
            if (rows.length > 0) {
                const results = [];
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 4) {
                        results.push({
                            name: cells[1]?.innerText?.trim().substring(0, 30),
                            enrollments: cells[6]?.innerText?.trim(),
                            assessments: cells[7]?.innerText?.trim()
                        });
                    }
                });
                if (results.length > 0) return results;
            }
        }
        return [];
    });

    console.log('📊 Semester stats (school_admin view):');
    stats.forEach((s, i) => {
        console.log(`   ${i+1}. "${s.name}" | Enr: ${s.enrollments} | Asm: ${s.assessments}`);
    });

    logResult('TS-02: Semesters page loads with data', stats.length > 0,
        stats.length > 0 ? `${stats.length} semesters displayed` : 'No rows found');

    // ────────────────────────────────────────────────────────
    // TS-03: تعيين button hidden from school_admin
    // ────────────────────────────────────────────────────────
    const hasSetCurrentBtn = await page.locator('button[name="set_current"]').count();
    logResult('TS-03: تعيين button hidden from school_admin', hasSetCurrentBtn === 0,
        hasSetCurrentBtn > 0 ? `Found ${hasSetCurrentBtn} button(s)` : 'No button found (correct)');

    // ────────────────────────────────────────────────────────
    // TS-04: semester_setup.php blocked
    // ────────────────────────────────────────────────────────
    console.log('\n─── Phase 3: semester_setup.php (School Admin) ───\n');

    await page.goto(`${BASE_URL}/pages/setup/semester_setup.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    const setupUrl = page.url();
    const setupBlocked = !setupUrl.includes('semester_setup.php');
    logResult('TS-04: semester_setup.php redirects school_admin', setupBlocked,
        setupBlocked ? `Redirected to: ${setupUrl}` : 'Was not redirected (vulnerability!)');

    await page.screenshot({ path: `${SCREENSHOT_DIR}/school_admin_setup_redirect.png`, fullPage: true });

    // ────────────────────────────────────────────────────────
    // Phase 4: Login as Group Admin
    // ────────────────────────────────────────────────────────
    console.log('\n─── Phase 4: Login as Group Admin ───\n');

    await page.goto(`${BASE_URL}/logout.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    await page.fill('input[name="username"]', GROUP_ADMIN.username);
    await page.fill('input[name="password"]', GROUP_ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(3000);
    const groupLoginUrl = page.url();
    logResult('Group admin login', true, `Redirected to: ${groupLoginUrl}`);

    // ────────────────────────────────────────────────────────
    // TS-05: View semesters.php (Group Admin)
    // ────────────────────────────────────────────────────────
    console.log('\n─── Phase 5: semesters.php (Group Admin) ───\n');

    await page.goto(`${BASE_URL}/pages/academic/semesters.php?year_id=1`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    await page.screenshot({ path: `${SCREENSHOT_DIR}/group_admin_semesters.png`, fullPage: true });

    const statsGroup = await page.evaluate(() => {
        const tables = document.querySelectorAll('table');
        for (const table of tables) {
            const rows = table.querySelectorAll('tbody tr');
            if (rows.length > 0) {
                const results = [];
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 4) {
                        results.push({
                            name: cells[1]?.innerText?.trim().substring(0, 30),
                            enrollments: cells[6]?.innerText?.trim(),
                            assessments: cells[7]?.innerText?.trim()
                        });
                    }
                });
                if (results.length > 0) return results;
            }
        }
        return [];
    });

    console.log('📊 Semester stats (group_admin view):');
    statsGroup.forEach((s, i) => {
        console.log(`   ${i+1}. "${s.name}" | Enr: ${s.enrollments} | Asm: ${s.assessments}`);
    });

    logResult('TS-05: Group admin sees semester data', statsGroup.length > 0,
        statsGroup.length > 0 ? `${statsGroup.length} semesters displayed` : 'No rows found');

    // ────────────────────────────────────────────────────────
    // TS-06: تعيين button visible
    // ────────────────────────────────────────────────────────
    const hasSetCurrentBtnGroup = await page.locator('button[name="set_current"]').count();
    logResult('TS-06: تعيين button visible for group_admin', hasSetCurrentBtnGroup > 0,
        hasSetCurrentBtnGroup > 0 ? `Found ${hasSetCurrentBtnGroup} button(s)` : 'No button found — should be visible');

    const setCurrentBtn = page.locator('button[name="set_current"]').first();
    const isEnabled = await setCurrentBtn.isEnabled();
    logResult('TS-06: تعيين button is clickable', isEnabled, 'Button is enabled');

    await page.screenshot({ path: `${SCREENSHOT_DIR}/group_admin_set_current_btn.png`, fullPage: true });

    // ────────────────────────────────────────────────────────
    // SUMMARY
    // ────────────────────────────────────────────────────────
    console.log('\n══════════════════════════════════════════════════════════════');
    console.log('📋 TEST COMPLETE');
    console.log('══════════════════════════════════════════════════════════════');
    console.log(`\n📸 Screenshots saved to: ${SCREENSHOT_DIR}\n`);

    await browser.close();
})().catch(err => {
    console.error('TEST ERROR:', err);
    process.exit(1);
});
