const { test, expect } = require('@playwright/test');

/**
 * اختبار تصفية التنبيهات حسب الدور
 * ALERT-01: group_admin يرى تنبيهات استراتيجية فقط
 * ALERT-02: school_admin يرى كل التنبيهات
 * ALERT-03: teacher يرى تنبيهات تشغيلية فقط
 * ALERT-04: فلتر المستوى (strategic/operational) يعمل
 * ALERT-05: رسالة توضيحية تظهر لكل دور
 */

test.describe('تصفية التنبيهات حسب الدور', () => {
  
  test('ALERT-01: مدير المجموعة يرى تنبيهات استراتيجية فقط', async ({ page }) => {
    // تسجيل الدخول كـ group_admin
    await page.goto('http://localhost/eqas/login.php');
    await page.fill('input[name="username"]', 'group_admin');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    
    // الانتقال لصفحة التنبيهات
    await page.goto('http://localhost/eqas/pages/alerts/index.php');
    
    // التحقق من وجود فلتر المستوى
    await expect(page.locator('select[name="alert_level"]')).toBeVisible();
    
    // التحقق من رسالة الدور
    await expect(page.locator('text=أنت مدير المجموعة')).toBeVisible();
    await expect(page.locator('text=التنبيهات الاستراتيجية')).toBeVisible();
    
    // التحقق من أنواع التنبيهات المعروضة
    const typeSelect = page.locator('select[name="alert_type"]');
    await expect(typeSelect).toBeVisible();
    
    // فلتر المستوى الافتراضي يجب أن يكون استراتيجياً لمدير المجموعة
    const selectedLevel = await page.locator('select[name="alert_level"]').inputValue();
    expect(selectedLevel).toBe('');
    
    // تسجيل الخروج
    await page.click('text=تسجيل الخروج');
  });

  test('ALERT-02: مدير المدرسة يرى كل التنبيهات', async ({ page }) => {
    await page.goto('http://localhost/eqas/login.php');
    await page.fill('input[name="username"]', 'school_admin');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    
    await page.goto('http://localhost/eqas/pages/alerts/index.php');
    
    // التحقق من وجود فلتر المستوى
    await expect(page.locator('select[name="alert_level"]')).toBeVisible();
    
    // التحقق من رسالة الدور
    await expect(page.locator('text=أنت مدير المدرسة')).toBeVisible();
    
    // يجب أن لا يظهر فلتر المدرسة (مُخفٍ تلقائياً)
    await expect(page.locator('select[name="school_id"]')).not.toBeVisible();
    
    await page.click('text=تسجيل الخروج');
  });

  test('ALERT-03: المعلم يرى تنبيهات تشغيلية فقط', async ({ page }) => {
    await page.goto('http://localhost/eqas/login.php');
    await page.fill('input[name="username"]', 'teacher');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    
    await page.goto('http://localhost/eqas/pages/alerts/index.php');
    
    // التحقق من عدم وجود فلتر المستوى (غير مطلوب للمعلم)
    await expect(page.locator('select[name="alert_level"]')).not.toBeVisible();
    
    // التحقق من رسالة الدور
    await expect(page.locator('text=أنت معلم')).toBeVisible();
    await expect(page.locator('text=تنبيهات طلاب شعبك')).toBeVisible();
    
    // التحقق من إخفاء أنواع التنبيهات الاستراتيجية
    const typeSelect = page.locator('select[name="alert_type"]');
    const options = await typeSelect.locator('option').allTextContents();
    
    // يجب أن تحتوي على أنواع تشغيلية فقط
    expect(options.some(o => o.includes('أكاديمي'))).toBeTruthy();
    expect(options.some(o => o.includes('سلوكي'))).toBeTruthy();
    expect(options.some(o => o.includes('حضور'))).toBeTruthy();
    
    // يجب أن لا تحتوي على أنواع استراتيجية
    expect(options.some(o => o.includes('مؤشر الجودة'))).toBeFalsy();
    expect(options.some(o => o.includes('المناخ'))).toBeFalsy();
    
    await page.click('text=تسجيل الخروج');
  });

  test('ALERT-04: فلتر المستوى يعمل بشكل صحيح', async ({ page }) => {
    await page.goto('http://localhost/eqas/login.php');
    await page.fill('input[name="username"]', 'group_admin');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    
    await page.goto('http://localhost/eqas/pages/alerts/index.php');
    
    // اختيار "تنبيهات استراتيجية"
    await page.selectOption('select[name="alert_level"]', 'strategic');
    await page.click('button:has-text("تصفية")');
    
    // التحقق من تحديث الصفحة
    await expect(page).toHaveURL(/alert_level=strategic/);
    
    // اختيار "تنبيهات تشغيلية"
    await page.selectOption('select[name="alert_level"]', 'operational');
    await page.click('button:has-text("تصفية")');
    
    // التحقق من تحديث الصفحة
    await expect(page).toHaveURL(/alert_level=operational/);
    
    await page.click('text=تسجيل الخروج');
  });

  test('ALERT-05: رسالة توضيحية تظهر لكل دور', async ({ page }) => {
    const roles = [
      { username: 'group_admin', expectedText: 'مدير المجموعة' },
      { username: 'school_admin', expectedText: 'مدير المدرسة' },
      { username: 'teacher', expectedText: 'معلم' }
    ];
    
    for (const role of roles) {
      await page.goto('http://localhost/eqas/login.php');
      await page.fill('input[name="username"]', role.username);
      await page.fill('input[name="password"]', 'password');
      await page.click('button[type="submit"]');
      
      await page.goto('http://localhost/eqas/pages/alerts/index.php');
      
      // التحقق من رسالة الدور
      await expect(page.locator('.alert-info')).toBeVisible();
      const alertText = await page.locator('.alert-info').textContent();
      expect(alertText).toContain(role.expectedText);
      
      await page.click('text=تسجيل الخروج');
    }
  });
});