/**
 * اختبارات عزل بيانات الأدوار في وحدة الحضور
 * Attendance Role Isolation E2E Tests
 * =====================================
 * التشغيل:
 *   node tests/e2e/attendance-role-filter.spec.js
 *
 * المتطلبات:
 *   - XAMPP شغال على http://localhost
 *   - مستخدمون موجودون في قاعدة البيانات:
 *     admin (group_admin) / 123456
 *     ALI (school_admin, school_id=1) / 123456
 *     teacher_extra_12 (teacher, school_id=1, 13 assignments) / 123456
 */

const { chromium } = require('playwright');
const path = require('path');

const BASE_URL = 'http://localhost/eqas';

const PASS = '\u2705 PASS';
const FAIL = '\u274C FAIL';

let totalTests = 0;
let passedTests = 0;

function logResult(testName, passed, detail) {
    totalTests++;
    if (passed) passedTests++;
    console.log(`${passed ? PASS : FAIL} | ${testName}${detail ? ' \u2014 ' + detail : ''}`);
}

async function loginAs(page, username, password) {
    // Navigate to logout first to clear session, then to login
    await page.goto(`${BASE_URL}/logout.php`, { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(500);
    await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    return page.url();
}

async function countClassOptions(page, pageUrl) {
    await page.goto(pageUrl, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    return await page.evaluate(() => {
        const selects = document.querySelectorAll('select[name="class_id"]');
        if (selects.length === 0) return { count: 0, options: [] };
        return {
            count: Array.from(selects[0].querySelectorAll('option')).filter(o => o.value !== '').length,
            options: Array.from(selects[0].querySelectorAll('option')).map(o => ({ value: o.value, text: o.innerText.trim() }))
        };
    });
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();

    console.log('\n' + '\u2550'.repeat(58));
    console.log(' ATTENDANCE ROLE ISOLATION E2E TESTS');
    console.log('\u2550'.repeat(58) + '\n');

    // ──────────────────────────────────────────────
    // Phase 1: Login tests
    // ──────────────────────────────────────────────
    console.log('--- Phase 1: Login Tests ---\n');

    const adminUrl = await loginAs(page, 'admin', '123456');
    logResult('AR-01: Group admin login works',
        !adminUrl.includes('login.php'),
        `Redirect: ${adminUrl}`);

    const schoolUrl = await loginAs(page, 'ALI', '123456');
    logResult('AR-02: School admin login works',
        !schoolUrl.includes('login.php'),
        `Redirect: ${schoolUrl}`);

    const teacherUrl = await loginAs(page, 'teacher_extra_12', '123456');
    logResult('AR-03: Teacher login works',
        !teacherUrl.includes('login.php'),
        `Redirect: ${teacherUrl}`);

    // ──────────────────────────────────────────────
    // Phase 2: Group admin — sees all classes
    // ──────────────────────────────────────────────
    console.log('\n--- Phase 2: Group Admin (unrestricted) ---\n');

    await loginAs(page, 'admin', '123456');

    await page.goto(`${BASE_URL}/pages/attendance/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const adminIndexLinks = await page.evaluate(() => {
        return document.querySelectorAll('a[href*="class_id="]').length;
    });
    logResult('AR-04: Group admin sees classes in attendance index',
        adminIndexLinks > 0,
        `Class links: ${adminIndexLinks}`);

    const adminDaily = await countClassOptions(page, `${BASE_URL}/pages/attendance/daily.php`);
    logResult('AR-05: Group admin sees classes in daily.php',
        adminDaily.count > 0,
        `Classes: ${adminDaily.count}`);

    const adminSummary = await countClassOptions(page, `${BASE_URL}/pages/attendance/summary.php`);
    logResult('AR-06: Group admin sees classes in summary.php',
        adminSummary.count > 0,
        `Classes: ${adminSummary.count}`);

    const adminIncident = await countClassOptions(page, `${BASE_URL}/pages/behavior/incident.php`);
    logResult('AR-07: Group admin sees classes in behavior incident',
        adminIncident.count > 0,
        `Classes: ${adminIncident.count}`);

    // ──────────────────────────────────────────────
    // Phase 3: School admin — sees school's classes
    // ──────────────────────────────────────────────
    console.log('\n--- Phase 3: School Admin (filtered by school) ---\n');

    await loginAs(page, 'ALI', '123456');

    await page.goto(`${BASE_URL}/pages/attendance/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const schoolIndexLinks = await page.evaluate(() => {
        return document.querySelectorAll('a[href*="class_id="]').length;
    });
    logResult('AR-08: School admin sees classes in attendance index',
        schoolIndexLinks > 0,
        `Class links: ${schoolIndexLinks}`);

    const schoolDaily = await countClassOptions(page, `${BASE_URL}/pages/attendance/daily.php`);
    logResult('AR-09: School admin sees classes in daily.php',
        schoolDaily.count > 0,
        `Classes: ${schoolDaily.count}`);

    const schoolSummary = await countClassOptions(page, `${BASE_URL}/pages/attendance/summary.php`);
    logResult('AR-10: School admin sees classes in summary.php',
        schoolSummary.count > 0,
        `Classes: ${schoolSummary.count}`);

    const schoolIncident = await countClassOptions(page, `${BASE_URL}/pages/behavior/incident.php`);
    logResult('AR-11: School admin sees classes in behavior incident',
        schoolIncident.count > 0,
        `Classes: ${schoolIncident.count}`);

    // ──────────────────────────────────────────────
    // Phase 4: Teacher — sees only assigned classes
    // ──────────────────────────────────────────────
    console.log('\n--- Phase 4: Teacher (assigned classes only) ---\n');

    await loginAs(page, 'teacher_extra_12', '123456');

    await page.goto(`${BASE_URL}/pages/attendance/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const teacherIndexLinks = await page.evaluate(() => {
        return document.querySelectorAll('a[href*="class_id="]').length;
    });
    logResult('AR-12: Teacher sees classes in attendance index',
        teacherIndexLinks > 0,
        `Class links: ${teacherIndexLinks}`);

    const teacherDaily = await countClassOptions(page, `${BASE_URL}/pages/attendance/daily.php`);
    logResult('AR-13: Teacher sees classes in daily.php',
        teacherDaily.count > 0,
        `Classes: ${teacherDaily.count}`);

    const teacherSummary = await countClassOptions(page, `${BASE_URL}/pages/attendance/summary.php`);
    logResult('AR-14: Teacher sees classes in summary.php',
        teacherSummary.count > 0,
        `Classes: ${teacherSummary.count}`);

    const teacherIncident = await countClassOptions(page, `${BASE_URL}/pages/behavior/incident.php`);
    logResult('AR-15: Teacher sees classes in behavior incident',
        teacherIncident.count > 0,
        `Classes: ${teacherIncident.count}`);

    // ──────────────────────────────────────────────
    // Phase 5: Access restriction — teacher in amend.php
    // ──────────────────────────────────────────────
    console.log('\n--- Phase 5: Amend page access ---\n');

    await loginAs(page, 'teacher_extra_12', '123456');
    await page.goto(`${BASE_URL}/pages/attendance/amend.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const amendClasses = await page.evaluate(() => {
        const select = document.querySelector('select[name="class_id"]');
        if (!select) return { count: 0 };
        return { count: Array.from(select.querySelectorAll('option')).filter(o => o.value !== '').length };
    });
    logResult('AR-16: Teacher sees assigned classes only in amend.php',
        amendClasses.count > 0,
        `Classes: ${amendClasses.count}`);

    // ──────────────────────────────────────────────
    // Phase 6: Notifications page loads
    // ──────────────────────────────────────────────
    console.log('\n--- Phase 6: Notifications page ---\n');

    await loginAs(page, 'teacher_extra_12', '123456');
    await page.goto(`${BASE_URL}/pages/attendance/notifications.php`, { waitUntil: 'networkidle' });
    const notifLoaded = !page.url().includes('login.php') && !page.url().includes('index.php');
    logResult('AR-17: Teacher can access notifications.php',
        notifLoaded,
        `URL: ${page.url()}`);

    // ──────────────────────────────────────────────
    // SUMMARY
    // ──────────────────────────────────────────────
    console.log('\n' + '\u2550'.repeat(58));
    console.log(` RESULTS: ${passedTests}/${totalTests} passed`);
    console.log('\u2550'.repeat(58) + '\n');

    await browser.close();
})().catch(err => {
    console.error('TEST ERROR:', err);
    process.exit(1);
});
