/**
 * اختبارات نطاق المعلم — EQAS Teacher Scope E2E Tests
 * ====================================================
 * التشغيل:
 *   node tests/e2e/teacher-scope.spec.js
 *
 * المتطلبات:
 *   - XAMPP شغال على http://localhost
 *   - MySQL database eqas_db مع user root (بدون كلمة سر)
 *   - المستخدمون:
 *     e2e_tch_scope (معلم) / test1234 — school_id=1
 *     admin (group_admin) / 123456 — group_admin
 *   - Data seeding: run setup/seed script before these tests
 *
 * التنصيب:
 *   يجب تشغيل سكريبت البذر أولاً:
 *   php scripts/seed_e2e_teacher_scope.php
 */

const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

const BASE_URL = 'http://localhost/eqas';
const SCREENSHOT_DIR = path.resolve(__dirname, '../../docs/testing/screenshots/2026-05-19');

const TEACHER = { username: 'e2e_tch_scope', password: 'test1234' };
const GROUP_ADMIN = { username: 'admin', password: '123456' };

const PASS = '\u2705 PASS';
const FAIL = '\u274C FAIL';

let totalTests = 0;
let passedTests = 0;
let EVAL_IDS = {
    behaviorAssigned: null,
    behaviorUnassigned: null,
    skillsAssigned: null,
    skillsUnassigned: null
};

function logResult(testName, passed, detail) {
    totalTests++;
    if (passed) passedTests++;
    console.log(`${passed ? PASS : FAIL} | ${testName}${detail ? ' \u2014 ' + detail : ''}`);
}

async function loginAs(page, username, password) {
    await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2500);
    return page.url();
}

function fetchEvalIds() {
    const fs = require('fs');
    const evalFile = path.join(__dirname, '../../docs/testing/eval_ids.json');
    try {
        const data = fs.readFileSync(evalFile, 'utf8');
        const rows = JSON.parse(data);
        for (const row of rows) {
            if (row.eval_type === 'behavior_score' && row.class_id == 900) EVAL_IDS.behaviorAssigned = row.id;
            if (row.eval_type === 'behavior_score' && row.class_id == 902) EVAL_IDS.behaviorUnassigned = row.id;
            if (row.eval_type === 'skills_checklist' && row.class_id == 901) EVAL_IDS.skillsAssigned = row.id;
            if (row.eval_type === 'skills_checklist' && row.class_id == 902) EVAL_IDS.skillsUnassigned = row.id;
        }
        console.log('Eval IDs:', JSON.stringify(EVAL_IDS));
    } catch (e) {
        console.error('Failed to read eval IDs:', e.message);
    }
}

(async () => {
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await context.newPage();

    console.log('\n\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550');
    console.log(' TEST SUITE: Teacher Access Scope E2E');
    console.log('\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\n');

    // ────────────────────────────────────────────────────────
    // Setup: Fetch dynamic evaluation IDs
    // ────────────────────────────────────────────────────────
    console.log('--- Setup: Fetching evaluation IDs ---');
    fetchEvalIds();
    console.log('');

    // ────────────────────────────────────────────────────────
    // Phase 1: Teacher Login
    // ────────────────────────────────────────────────────────
    console.log('--- Phase 1: Teacher Login ---\n');

    const teacherUrl = await loginAs(page, TEACHER.username, TEACHER.password);
    logResult('TS-TCH-01: Teacher login works',
        !teacherUrl.includes('login.php'),
        `Redirected to: ${teacherUrl}`);

    // ────────────────────────────────────────────────────────
    // Phase 2: Behavior Index — class filter
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 2: Behavior Module ---\n');

    await page.goto(`${BASE_URL}/pages/behavior/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    const behaviorClassOptions = await page.evaluate(() => {
        const selects = document.querySelectorAll('select[name="class_id"]');
        if (selects.length === 0) return [];
        return Array.from(selects[0].querySelectorAll('option')).map(o => ({
            value: o.value,
            text: o.innerText.trim()
        }));
    });

    const behaviorClassCount = behaviorClassOptions.filter(o => o.value !== '').length;
    const hasClass900 = behaviorClassOptions.some(o => o.value === '900');
    const hasClass902 = behaviorClassOptions.some(o => o.value === '902');

    logResult('TS-TCH-02: Behavior index shows assigned classes only',
        behaviorClassCount === 2 && hasClass900 && !hasClass902,
        `Assigned classes: ${behaviorClassCount} (900= ${hasClass900}, 902= ${hasClass902})`);

    // ────────────────────────────────────────────────────────
    // Phase 2b: Behavior View — assigned class
    // ────────────────────────────────────────────────────────
    await page.goto(`${BASE_URL}/pages/behavior/view.php?id=${EVAL_IDS.behaviorAssigned}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const viewAssignedUrl = page.url();
    const viewAssignedOk = viewAssignedUrl.includes('view.php');
    logResult('TS-TCH-03: Teacher can view behavior in assigned class',
        viewAssignedOk,
        viewAssignedOk ? `Stayed on view.php` : `Redirected to: ${viewAssignedUrl}`);

    await page.screenshot({ path: `${SCREENSHOT_DIR}/tch-behavior-view-assigned.png`, fullPage: true });

    // ────────────────────────────────────────────────────────
    // Phase 2c: Behavior View — unassigned class
    // ────────────────────────────────────────────────────────
    await page.goto(`${BASE_URL}/pages/behavior/view.php?id=${EVAL_IDS.behaviorUnassigned}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const viewUnassignedUrl = page.url();
    const viewUnassignedBlocked = !viewUnassignedUrl.includes('view.php');
    logResult('TS-TCH-04: Teacher redirected from unassigned class behavior view',
        viewUnassignedBlocked,
        viewUnassignedBlocked ? `Redirected to: ${viewUnassignedUrl}` : `Stayed on view.php (vulnerability!)`);

    await page.screenshot({ path: `${SCREENSHOT_DIR}/tch-behavior-view-unassigned.png`, fullPage: true });

    // ────────────────────────────────────────────────────────
    // Phase 3: Skills Module
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 3: Skills Module ---\n');

    await page.goto(`${BASE_URL}/pages/skills/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    const skillsClassOptions = await page.evaluate(() => {
        const selects = document.querySelectorAll('select[name="class_id"]');
        if (selects.length === 0) return [];
        return Array.from(selects[0].querySelectorAll('option')).map(o => ({
            value: o.value,
            text: o.innerText.trim()
        }));
    });

    const skillsClassCount = skillsClassOptions.filter(o => o.value !== '').length;
    const skillsHasClass901 = skillsClassOptions.some(o => o.value === '901');
    const skillsHasClass902 = skillsClassOptions.some(o => o.value === '902');

    logResult('TS-TCH-05: Skills index shows assigned classes only',
        skillsClassCount === 2 && skillsHasClass901 && !skillsHasClass902,
        `Assigned classes: ${skillsClassCount} (901= ${skillsHasClass901}, 902= ${skillsHasClass902})`);

    // ────────────────────────────────────────────────────────
    // Phase 3b: Skills View — assigned class
    // ────────────────────────────────────────────────────────
    await page.goto(`${BASE_URL}/pages/skills/view.php?id=${EVAL_IDS.skillsAssigned}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const skillsViewAssignedUrl = page.url();
    const skillsViewAssignedOk = skillsViewAssignedUrl.includes('view.php');
    logResult('TS-TCH-06: Teacher can view skills in assigned class',
        skillsViewAssignedOk,
        skillsViewAssignedOk ? 'Stayed on view.php' : `Redirected to: ${skillsViewAssignedUrl}`);

    await page.screenshot({ path: `${SCREENSHOT_DIR}/tch-skills-view-assigned.png`, fullPage: true });

    // ────────────────────────────────────────────────────────
    // Phase 3c: Skills View — unassigned class
    // ────────────────────────────────────────────────────────
    await page.goto(`${BASE_URL}/pages/skills/view.php?id=${EVAL_IDS.skillsUnassigned}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const skillsViewUnassignedUrl = page.url();
    const skillsViewUnassignedBlocked = !skillsViewUnassignedUrl.includes('view.php');
    logResult('TS-TCH-07: Teacher redirected from unassigned class skills view',
        skillsViewUnassignedBlocked,
        skillsViewUnassignedBlocked ? `Redirected to: ${skillsViewUnassignedUrl}` : 'Stayed on view.php (vulnerability!)');

    await page.screenshot({ path: `${SCREENSHOT_DIR}/tch-skills-view-unassigned.png`, fullPage: true });

    // ────────────────────────────────────────────────────────
    // Phase 4: Academic Assessment (dashboard — no class dropdown)
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 4: Academic Assessment (Dashboard) ---\n');

    await page.goto(`${BASE_URL}/pages/academic_assessment/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const acadUrl = page.url();
    const acadLoaded = acadUrl.includes('academic_assessment/index.php');
    logResult('TS-TCH-08: Academic assessment dashboard loads for teacher',
        acadLoaded,
        acadLoaded ? 'Page loaded successfully' : `Redirected to: ${acadUrl}`);

    // ────────────────────────────────────────────────────────
    // Phase 5: Attendance (dashboard — no class dropdown)
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 5: Attendance (Dashboard) ---\n');

    await page.goto(`${BASE_URL}/pages/attendance/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const attUrl = page.url();
    const attLoaded = attUrl.includes('attendance/index.php');
    logResult('TS-TCH-09: Attendance dashboard loads for teacher',
        attLoaded,
        attLoaded ? 'Page loaded successfully' : `Redirected to: ${attUrl}`);

    // ────────────────────────────────────────────────────────
    // Phase 6: Behavior Incident & Scores
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 6: Behavior Sub-pages ---\n');

    await page.goto(`${BASE_URL}/pages/behavior/incident.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    const incClassOptions = await page.evaluate(() => {
        const selects = document.querySelectorAll('select[name="class_id"]');
        if (selects.length === 0) return [];
        return Array.from(selects[0].querySelectorAll('option')).map(o => ({
            value: o.value,
            text: o.innerText.trim()
        }));
    });

    const incClassCount = incClassOptions.filter(o => o.value !== '').length;
    const incHasClass900 = incClassOptions.some(o => o.value === '900');
    const incHasClass902 = incClassOptions.some(o => o.value === '902');

    logResult('TS-TCH-10: Incident page shows assigned classes only',
        incClassCount === 2 && incHasClass900 && !incHasClass902,
        `Assigned classes: ${incClassCount} (900= ${incHasClass900}, 902= ${incHasClass902})`);

    // ────────────────────────────────────────────────────────
    // Phase 7: Behavior Scores
    // ────────────────────────────────────────────────────────
    await page.goto(`${BASE_URL}/pages/behavior/scores.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    const scoresClassOptions = await page.evaluate(() => {
        const selects = document.querySelectorAll('select[name="class_id"]');
        if (selects.length === 0) return [];
        return Array.from(selects[0].querySelectorAll('option')).map(o => ({
            value: o.value,
            text: o.innerText.trim()
        }));
    });

    const scoresClassCount = scoresClassOptions.filter(o => o.value !== '').length;
    const scoresHasClass900 = scoresClassOptions.some(o => o.value === '900');
    const scoresHasClass902 = scoresClassOptions.some(o => o.value === '902');

    logResult('TS-TCH-11: Scores page shows assigned classes only',
        scoresClassCount === 2 && scoresHasClass900 && !scoresHasClass902,
        `Assigned classes: ${scoresClassCount} (900= ${scoresHasClass900}, 902= ${scoresHasClass902})`);

    // ────────────────────────────────────────────────────────
    // Phase 7: Attendance Daily — verify page loads for GET (guard is in POST)
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 7: Attendance Daily ---\n');

    await page.goto(`${BASE_URL}/pages/attendance/daily.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const dailyUrl = page.url();
    const dailyLoaded = dailyUrl.includes('daily.php');
    logResult('TS-TCH-12: Attendance daily loads for teacher GET',
        dailyLoaded,
        dailyLoaded ? 'Page loaded (guard is on POST only)' : `Redirected: ${dailyUrl}`);

    await page.screenshot({ path: `${SCREENSHOT_DIR}/tch-attendance-daily.png`, fullPage: true });

    // ────────────────────────────────────────────────────────
    // Phase 9: Permission regression — teacher cannot access admin pages
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 8: Permission Regression ---\n');

    await page.goto(`${BASE_URL}/pages/setup/semester_setup.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const setupUrl = page.url();
    const setupBlocked = !setupUrl.includes('semester_setup.php');
    logResult('TS-TCH-13: Teacher blocked from admin page',
        setupBlocked,
        setupBlocked ? `Redirected to: ${setupUrl}` : 'Access granted (vulnerability!)');

    // ────────────────────────────────────────────────────────
    // Phase 10: Login as group_admin and verify unrestricted access
    // ────────────────────────────────────────────────────────
    console.log('\n--- Phase 10: Group Admin (unrestricted) ---\n');

    await page.goto(`${BASE_URL}/logout.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    await loginAs(page, GROUP_ADMIN.username, GROUP_ADMIN.password);

    // Group admin should see behavior index without restriction
    await page.goto(`${BASE_URL}/pages/behavior/index.php`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const adminUrl = page.url();
    const adminLoaded = adminUrl.includes('behavior/index.php');
    logResult('TS-TCH-14: Group admin behavior index loads without restriction',
        adminLoaded,
        adminLoaded ? 'Page loaded' : `Redirected: ${adminUrl}`);

    // Also verify group admin can access behavior view for ANY class (unassigned to teacher)
    await page.goto(`${BASE_URL}/pages/behavior/view.php?id=${EVAL_IDS.behaviorUnassigned}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);
    const adminViewUrl = page.url();
    const adminViewOk = adminViewUrl.includes('view.php');
    logResult('TS-TCH-14b: Group admin can view any behavior evaluation',
        adminViewOk,
        adminViewOk ? 'Access granted (expected)' : `Redirected: ${adminViewUrl}`);

    // ────────────────────────────────────────────────────────
    // SUMMARY
    // ────────────────────────────────────────────────────────
    console.log('\n\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550');
    console.log(` RESULTS: ${passedTests}/${totalTests} passed`);
    console.log('\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\u2550\n');

    await browser.close();
})().catch(err => {
    console.error('TEST ERROR:', err);
    process.exit(1);
});
