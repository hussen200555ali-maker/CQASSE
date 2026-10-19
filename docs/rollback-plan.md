# خطة الرجوع (Rollback Plan) — تحصين P0

تاريخ الإنشاء: 2026-10-20

## نقطة الرجوع المعتمدة

| النوع | القيمة |
|-------|--------|
| وسم Git | `pre-p0-hardening-2026-10-20` |
| الالتزام | `9b7835b` |
| نسخة قاعدة البيانات | `backups/pre_p0_2026-10-20.sql` (1.57 MB، غير متتبَّعة في Git) |

> نسخة قاعدة البيانات محلية فقط. عند العمل على خادم آخر أنشئ نسخة مماثلة قبل البدء:
> `mysqldump -u ROOT -p --routines --triggers --single-transaction --result-file=BACKUP.sql eqas_db`

## ما الذي عدّلته هذه الدفعة؟

1. تقييد الوصول لصفحة إقرار ولي الأمر حسب المدرسة (إصلاح IDOR).
2. تحويل تسجيل أولياء الأمور إلى "بانتظار موافقة مدير المدرسة".
3. إضافة تحقق CSRF لنقاط API التي تعدّل بيانات.
4. إزالة كود تصحيح يطبع محتوى الجلسة.
5. إضافة معالج استثناءات/أخطاء عام + سجلات في `logs/`.
6. حماية تحميل `vendor/autoload.php` في محرك التقارير.

لا توجد تغييرات على مخطط قاعدة البيانات (Schema) في هذه الدفعة.

## الاستعادة الكاملة (كارثة)

نفّذ من جذر المشروع على PowerShell:

```powershell
# 1) إيقاف أي كتابة جديدة (اختياري: أوقف Apache مؤقتاً من لوحة XAMPP)

# 2) الرجوع للكود
git fetch --tags
git checkout main
git reset --hard pre-p0-hardening-2026-10-20

# 3) استعادة قاعدة البيانات
& "C:\xampp\mysql\bin\mysql.exe" -u root eqas_db --execute="DROP DATABASE IF EXISTS eqas_db; CREATE DATABASE eqas_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cmd /c '"C:\xampp\mysql\bin\mysql.exe" -u root eqas_db < "C:\xampp\htdocs\cqasse\backups\pre_p0_2026-10-20.sql"'

# 4) تنظيف الحالة المؤقتة
Remove-Item temp\sessions\* -Force -ErrorAction SilentlyContinue
Remove-Item logs\*.log -Force -ErrorAction SilentlyContinue

# 5) التحقق
& "C:\xampp\php\php.exe" tests\phpunit.phar -c tests\phpunit.xml
```

## استعادة جزئية (ملف واحد)

```powershell
git checkout pre-p0-hardening-2026-10-20 -- path/to/file.php
```

## تحقق ما بعد الاستعادة

- `http://localhost/cqasse/login.php` يعيد 200.
- تسجيل الدخول بـ `admin / Admin@123` يعمل (بيئة التطوير).
- `php -l` على الملفات المستعادة بلا أخطاء.
- PHPUnit: 98 اختباراً ناجحاً.

## ملاحظات

- الوسم يغطي الكود فقط؛ قاعدة البيانات تُستعاد من النسخة أعلاه.
- بعد أي ترحيل مستقبلي، أنشئ وسم `pre-...` ونسخة DB جديدة قبل التنفيذ.
