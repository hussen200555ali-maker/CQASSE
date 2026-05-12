# اختبارات النظام الآلية — EQAS

## المتطلبات
- Node.js + Playwright (`npm install -D @playwright/test`)
- الخادم المحلي (XAMPP) شغال على `http://localhost`

## تشغيل الاختبارات

```bash
# 1. الانتقال لمجلد مهارة Playwright
cd C:\Users\IT\.agents\skills\playwright

# 2. تشغيل الاختبار
node run.js C:\xamppp\htdocs\eqas\tests\e2e\semester-permissions.spec.js
```

**ملاحظة:** مسار مهارة Playwright قد يختلف حسب بيئة التطوير. راجع `$SKILL_DIR` في إعداداتك.

## بيانات الاختبار

`tests/fixtures/seed.sql` يحتوي على بيانات أولية:
- مدرسة واحدة
- مستخدم `school_admin` + `group_admin`
- فصلين دراسيين
- بضعة فصول (شعب)

شغّل `seed.sql` عبر:

```bash
mysql -u root eqas_db < tests/fixtures/seed.sql
```

## إضافة اختبار جديد

1. أنشئ ملف `.spec.js` في `tests/e2e/`
2. استخدم نمط الاختبارات الموجودة كمرجع
3. سجل التغيير في `docs/testing/test-plan.md`
4. أضف النتائج في `docs/testing/test-results.md`
