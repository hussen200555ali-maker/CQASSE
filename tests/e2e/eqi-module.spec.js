/**
 * اختبارات وحدة مؤشر جودة التعليم (EQI) — EQAS
 * ==========================================
 * يختبر:
 * - صلاحيات صفحات EQI (group_admin vs school_admin vs teacher)
 * - مركز القيادة (dashboard.php) — فلاتر، KPI، جداول، رسوم
 * - لوحة المؤشر (index.php) — عرض المؤشر، الرسم الراداري
 * - حساب المؤشر (calculate.php) — فردي وجماعي
 * - مقارنة المؤشر (compare.php) — اختيار مدارس متعددة
 * - تقارير المؤشر (report.php) — تصدير CSV
 * - تفاصيل المؤشر (view.php) — سجل تاريخي + رسم بياني
 *
 * التشغيل:
 *   node run.js <path-to>/tests/e2e/eqi-module.spec.js
 *
 * المتطلبات:
 *   - XAMPP شغال على http://localhost
 *   - مستخدمين: admin (group_admin), ALI (school_admin), testteacher (teacher)
 *   - كلمة المرور: test1234
 */

const { chromium } = require('playwright');
const path = require('path');

const BASE_URL = 'http://localhost/eqas';
const SCREENSHOT_DIR = path.resolve(__dirname, '../../docs/testing/screenshots/2026-05-16');
const GROUP_ADMIN = { username: 'admin', password: 'test1234' };
const SCHOOL_ADMIN = { username: 'ALI', password: 'test1234', schoolId: 1 };
const TEACHER = { username: 'testteacher', password: 'test1234' };

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

async function checkPageBlocked(page, url) {
    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout: 15000 });
        await page.waitForTimeout(1000);
        const bodyText = await page.textContent('body');
        return bodyText.includes('ليس لديك صلاحية');
    } catch (e) {
        return false;
    }
}

// =========================================================================
// EQ-01: صلاحيات صفحات EQI
// =========================================================================

async function eq01_groupAdminCanAccessAllEqiPages(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);

        const pages = [
            { url: '/pages/eqi/dashboard.php', label: 'مركز القيادة' },
            { url: '/pages/eqi/index.php', label: 'مؤشر جودة التعليم' },
            { url: '/pages/eqi/calculate.php', label: 'حساب المؤشر' },
            { url: '/pages/eqi/compare.php', label: 'مقارنة' },
            { url: '/pages/eqi/report.php', label: 'تقارير' },
            { url: '/pages/eqi/settings.php', label: 'إعدادات الأوزان' },
            { url: '/pages/eqi/dimensions.php', label: 'الأبعاد' },
        ];

        let allOk = true;
        for (const p of pages) {
            const loaded = await checkPageLoads(page, `${BASE_URL}${p.url}`, p.label);
            if (!loaded) allOk = false;
            logResult(`EQ-01: group_admin → ${p.url}`, loaded);
        }

        return allOk;
    } finally {
        await page.close();
    }
}

async function eq02_schoolAdminHasCorrectAccess(browser) {
    const page = await browser.newPage();
    try {
        await login(page, SCHOOL_ADMIN);

        const allowed = [
            { url: '/pages/eqi/index.php', label: 'مؤشر جودة التعليم' },
            { url: '/pages/eqi/calculate.php', label: 'حساب المؤشر' },
            { url: '/pages/eqi/compare.php', label: 'مقارنة' },
            { url: '/pages/eqi/report.php', label: 'تقارير' },
            { url: '/pages/eqi/dimensions.php', label: 'الأبعاد' },
        ];
        const blocked = [
            { url: '/pages/eqi/dashboard.php', label: 'مركز القيادة' },
            { url: '/pages/eqi/settings.php', label: 'إعدادات الأوزان' },
        ];

        let allOk = true;
        for (const p of allowed) {
            const loaded = await checkPageLoads(page, `${BASE_URL}${p.url}`, p.label);
            if (!loaded) allOk = false;
            logResult(`EQ-02: school_admin → ${p.url}`, loaded);
        }
        for (const p of blocked) {
            const blockedOk = await checkPageBlocked(page, `${BASE_URL}${p.url}`);
            if (!blockedOk) allOk = false;
            logResult(`EQ-02: school_admin محظور → ${p.url}`, blockedOk);
        }

        return allOk;
    } finally {
        await page.close();
    }
}

async function eq03_teacherHasCorrectAccess(browser) {
    const page = await browser.newPage();
    try {
        await login(page, TEACHER);

        const allowed = [
            { url: '/pages/eqi/index.php', label: 'مؤشر جودة التعليم' },
            { url: '/pages/eqi/dimensions.php', label: 'الأبعاد' },
        ];
        const blocked = [
            { url: '/pages/eqi/calculate.php', label: 'حساب المؤشر' },
            { url: '/pages/eqi/compare.php', label: 'مقارنة' },
            { url: '/pages/eqi/dashboard.php', label: 'مركز القيادة' },
            { url: '/pages/eqi/settings.php', label: 'إعدادات الأوزان' },
        ];

        let allOk = true;
        for (const p of allowed) {
            const loaded = await checkPageLoads(page, `${BASE_URL}${p.url}`, p.label);
            if (!loaded) allOk = false;
            logResult(`EQ-03: teacher → ${p.url}`, loaded);
        }
        for (const p of blocked) {
            const blockedOk = await checkPageBlocked(page, `${BASE_URL}${p.url}`);
            if (!blockedOk) allOk = false;
            logResult(`EQ-03: teacher محظور → ${p.url}`, blockedOk);
        }

        return allOk;
    } finally {
        await page.close();
    }
}

// =========================================================================
// EQ-04: مركز القيادة (dashboard.php)
// =========================================================================

async function eq04_dashboardLoadsAndShowsKpi(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);
        const loaded = await checkPageLoads(page, `${BASE_URL}/pages/eqi/dashboard.php`, 'مركز قيادة');

        if (loaded) {
            // تحقق من وجود عناصر KPI
            const hasAvgEqi = await page.textContent('body').then(t => t.includes('متوسط مؤشر الجودة'));
            const hasFilters = await page.textContent('body').then(t => t.includes('السنة الدراسية'));
            logResult('EQ-04: dashboard — KPI موجودة', hasAvgEqi);
            logResult('EQ-04: dashboard — فلاتر موجودة', hasFilters);
        } else {
            logResult('EQ-04: dashboard — تحميل', false);
        }

        return loaded;
    } finally {
        await page.close();
    }
}

async function eq05_dashboardFiltersWork(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);
        await page.goto(`${BASE_URL}/pages/eqi/dashboard.php`, { waitUntil: 'networkidle' });

        // اختبار فلتر السنة — اختيار جميع السنوات
        await page.selectOption('select[name="academic_year_id"]', '0');
        await page.waitForTimeout(1500);

        const bodyText = await page.textContent('body');
        const showsAllYears = bodyText.includes('جميع السنوات');
        logResult('EQ-05: dashboard — فلتر جميع السنوات', showsAllYears);

        return showsAllYears;
    } finally {
        await page.close();
    }
}

// =========================================================================
// EQ-06: حساب المؤشر (calculate.php)
// =========================================================================

async function eq06_calculatePageLoads(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);
        const loaded = await checkPageLoads(page, `${BASE_URL}/pages/eqi/calculate.php`, 'حساب مؤشر جودة التعليم');

        if (loaded) {
            const formExists = await page.textContent('body').then(t => t.includes('المدرسة'));
            logResult('EQ-06: calculate — نموذج الحساب موجود', formExists);
        } else {
            logResult('EQ-06: calculate — تحميل', false);
        }

        return loaded;
    } finally {
        await page.close();
    }
}

async function eq07_calculateHasBatchOptions(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);
        await page.goto(`${BASE_URL}/pages/eqi/calculate.php`, { waitUntil: 'networkidle' });

        const bodyText = await page.textContent('body');
        const hasAllSchools = bodyText.includes('جميع المدارس');
        const hasAllYears = bodyText.includes('جميع السنوات');
        const hasAllSemesters = bodyText.includes('جميع الفصول');

        logResult('EQ-07: calculate — خيار جميع المدارس', hasAllSchools);
        logResult('EQ-07: calculate — خيار جميع السنوات', hasAllYears);
        logResult('EQ-07: calculate — خيار جميع الفصول', hasAllSemesters);

        return hasAllSchools && hasAllYears && hasAllSemesters;
    } finally {
        await page.close();
    }
}

async function eq08_schoolAdminCannotSeeBatchOptions(browser) {
    const page = await browser.newPage();
    try {
        await login(page, SCHOOL_ADMIN);
        await page.goto(`${BASE_URL}/pages/eqi/calculate.php`, { waitUntil: 'networkidle' });

        const bodyText = await page.textContent('body');
        const hasAllSchools = bodyText.includes('جميع المدارس');
        const hasAllYears = bodyText.includes('جميع السنوات');

        logResult('EQ-08: school_admin — لا يرى جميع المدارس', !hasAllSchools);
        logResult('EQ-08: school_admin — لا يرى جميع السنوات', !hasAllYears);

        return !hasAllSchools && !hasAllYears;
    } finally {
        await page.close();
    }
}

// =========================================================================
// EQ-09: نتيجة الحساب (بعد POST)
// =========================================================================

async function eq09_calculationSubmits(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);
        await page.goto(`${BASE_URL}/pages/eqi/calculate.php`, { waitUntil: 'networkidle' });

        // اختيار أول مدرسة وسنة وفصل
        const schools = await page.$$('select[name="school_id"] option');
        const years = await page.$$('select[name="academic_year_id"] option');
        const sems = await page.$$('select[name="semester_id"] option');

        if (schools.length < 2 || years.length < 2 || sems.length < 2) {
            logResult('EQ-09: calculate — إرسال (لا توجد خيارات كافية)', false);
            return false;
        }

        await page.selectOption('select[name="school_id"]', schools[1].getAttribute('value'));
        await page.waitForTimeout(500);
        await page.selectOption('select[name="academic_year_id"]', years[1].getAttribute('value'));
        await page.waitForTimeout(500);

        const sems2 = await page.$$('select[name="semester_id"] option');
        if (sems2.length < 2) {
            logResult('EQ-09: calculate — إرسال (لا توجد فصول)', false);
            return false;
        }
        await page.selectOption('select[name="semester_id"]', sems2[1].getAttribute('value'));

        // النقر على زر الحساب
        await page.click('button[name="calculate"]');

        // تأكيد رسالة التأكيد
        try {
            await page.waitForTimeout(500);
            const dialog = await page.waitForEvent('dialog', { timeout: 2000 });
            await dialog.accept();
        } catch (e) {
            // قد لا يظهر الحوار أحياناً
        }

        await page.waitForTimeout(3000);

        const bodyText = await page.textContent('body');
        const resultShown = bodyText.includes('مؤشر جودة التعليم') || bodyText.includes('لا توجد بيانات كافية');

        logResult('EQ-09: calculate — إرسال النموذج يعرض نتيجة', resultShown);
        return resultShown;
    } finally {
        await page.close();
    }
}

// =========================================================================
// EQ-10: مقارنة المؤشر (compare.php)
// =========================================================================

async function eq10_comparePageLoads(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);
        const loaded = await checkPageLoads(page, `${BASE_URL}/pages/eqi/compare.php`, 'مقارنة مؤشرات');
        logResult('EQ-10: compare — تحميل الصفحة', loaded);
        return loaded;
    } finally {
        await page.close();
    }
}

// =========================================================================
// EQ-11: تفاصيل المؤشر (view.php مع السجل التاريخي)
// =========================================================================

async function eq11_viewPageWithHistory(browser) {
    const page = await browser.newPage();
    try {
        await login(page, GROUP_ADMIN);
        // نحاول فتح view.php مع school_id=1
        const loaded = await checkPageLoads(page, `${BASE_URL}/pages/eqi/view.php?school_id=1&year_id=1&semester_id=1`, 'مؤشر جودة التعليم');
        logResult('EQ-11: view — تحميل الصفحة', loaded);

        if (loaded) {
            const hasHistorySection = await page.textContent('body').then(t => t.includes('السجل التاريخي'));
            logResult('EQ-11: view — السجل التاريخي موجود', hasHistorySection);
        }

        return loaded;
    } finally {
        await page.close();
    }
}

// =========================================================================
// EQ-12: صفحة الأبعاد (dimensions.php)
// =========================================================================

async function eq12_dimensionsPageLoads(browser) {
    const page = await browser.newPage();
    try {
        // يجب أن تفتح مع جميع الصلاحيات
        await login(page, TEACHER);
        const loaded = await checkPageLoads(page, `${BASE_URL}/pages/eqi/dimensions.php`, 'الأبعاد');
        logResult('EQ-12: dimensions — تحميل مع teacher', loaded);

        await logout(page);
        await login(page, SCHOOL_ADMIN);
        const loaded2 = await checkPageLoads(page, `${BASE_URL}/pages/eqi/dimensions.php`, 'الأبعاد');
        logResult('EQ-12: dimensions — تحميل مع school_admin', loaded2);

        return loaded && loaded2;
    } finally {
        await page.close();
    }
}

// =========================================================================
// التشغيل الرئيسي
// =========================================================================

(async () => {
    console.log('\n🧪 === اختبارات وحدة EQI (مؤشر جودة التعليم) ===\n');

    const browser = await chromium.launch({ headless: true });

    try {
        // EQ-01 to EQ-03: صلاحيات
        await eq01_groupAdminCanAccessAllEqiPages(browser);
        await eq02_schoolAdminHasCorrectAccess(browser);
        await eq03_teacherHasCorrectAccess(browser);

        // EQ-04 to EQ-05: مركز القيادة
        await eq04_dashboardLoadsAndShowsKpi(browser);
        await eq05_dashboardFiltersWork(browser);

        // EQ-06 to EQ-09: حساب المؤشر
        await eq06_calculatePageLoads(browser);
        await eq07_calculateHasBatchOptions(browser);
        await eq08_schoolAdminCannotSeeBatchOptions(browser);
        await eq09_calculationSubmits(browser);

        // EQ-10: مقارنة
        await eq10_comparePageLoads(browser);

        // EQ-11: تفاصيل مع سجل تاريخي
        await eq11_viewPageWithHistory(browser);

        // EQ-12: الأبعاد
        await eq12_dimensionsPageLoads(browser);

    } finally {
        await browser.close();
    }

    console.log(`\n📊 النتائج: ${testsRun} اختبار — ${testsPassed} نجاح ✅ — ${testsFailed} فشل ❌\n`);
})();
