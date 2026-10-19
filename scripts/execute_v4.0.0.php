<?php
/**
 * تنفيذ الترحيل v4.0.0 عبر PDO للحفاظ على ترميز UTF-8
 * 
 * الاستخدام: php -f scripts/execute_v4.0.0.php
 * 
 * سبب وجود هذا الملف: تشغيل migrate_v4.0.0.sql عبر PowerShell pipe
 * يفسد الترميز العربي ويحوله إلى علامات استفهام (?????)، بينما PDO
 * مع charset=utf8mb4 يحافظ على النص العربي سليماً.
 */

// ============================================================
// إعدادات
// ============================================================
$sqlFile = __DIR__ . '/migrate_v4.0.0.sql';
$rootDir = dirname(__DIR__);

// ============================================================
// تحميل البيئة
// ============================================================
require_once $rootDir . '/config.php';
require_once $rootDir . '/includes/database.php';

try {
    // الحصول على اتصال PDO
    $db = Database::getInstance()->getConnection();
    echo "[OK] تم الاتصال بقاعدة البيانات: " . DB_NAME . PHP_EOL;

    // ============================================================
    // التأكيد (دعم CLI argument --yes للتنفيذ غير التفاعلي)
    // ============================================================
    $confirm = in_array('--yes', $argv ?? []) ? 'yes' : '';
    if ($confirm !== 'yes') {
        echo "تحذير: هذا السكريبت سيعيد تعبئة بيانات الاستمارات (assessment_structure)." . PHP_EOL;
        echo "الاستمرار؟ (yes/no): ";
        $handle = fopen('php://stdin', 'r');
        $confirm = trim(fgets($handle));
        fclose($handle);

        if ($confirm !== 'yes') {
            echo "تم الإلغاء." . PHP_EOL;
            exit(0);
        }
    }

    // ============================================================
    // قراءة ملف SQL
    // ============================================================
    if (!file_exists($sqlFile)) {
        throw new Exception("ملف SQL غير موجود: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false || strlen($sql) === 0) {
        throw new Exception("فشل في قراءة ملف SQL أو الملف فارغ");
    }

    echo "[OK] تم قراءة " . strlen($sql) . " حرف من ملف SQL" . PHP_EOL;

    // ============================================================
    // تقسيم SQL إلى جمل منفصلة
    // ============================================================
    $statements = [];
    $current = '';

    foreach (explode("\n", $sql) as $line) {
        // تجاهل التعليقات والفراغ
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $current .= $line . "\n";

        // إذا انتهى السطر بـ ; فهذه نهاية جملة
        if (str_ends_with(trim($line), ';')) {
            $statements[] = trim($current);
            $current = '';
        }
    }

    // أية بقايا
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    echo "[OK] تم تقسيم SQL إلى " . count($statements) . " جملة" . PHP_EOL;

    // ============================================================
    // تنفيذ الجمل
    // ============================================================
    $total = count($statements);
    $success = 0;
    $errors = [];

    foreach ($statements as $i => $stmt) {
        try {
            // تجاهل جمل SET @var = ... وحدها (قد تسبب مشاكل مع PDO)
            $upper = strtoupper(substr(trim($stmt), 0, 10));
            $stmtClean = trim($stmt);

            if (empty($stmtClean)) {
                continue;
            }

            $db->exec($stmtClean);
            $success++;

            // عرض تقدم كل 10 جمل
            if ($success % 10 === 0 || $success === $total) {
                echo "[$success/$total] تم بنجاح" . PHP_EOL;
            }
        } catch (PDOException $e) {
            $errors[] = [
                'statement' => $i + 1,
                'sql' => mb_substr($stmt, 0, 100),
                'error' => $e->getMessage(),
            ];
            echo "[خطأ] الجملة " . ($i + 1) . ": " . $e->getMessage() . PHP_EOL;
        }
    }

    // ============================================================
    // الملخص
    // ============================================================
    echo PHP_EOL . str_repeat('=', 60) . PHP_EOL;
    echo "الملخص:" . PHP_EOL;
    echo "  نجاح: $success / $total" . PHP_EOL;
    echo "  أخطاء: " . count($errors) . PHP_EOL;

    if (count($errors) > 0) {
        echo PHP_EOL . "تفاصيل الأخطاء:" . PHP_EOL;
        foreach ($errors as $err) {
            echo "  [" . $err['statement'] . "] " . $err['error'] . PHP_EOL;
            echo "    SQL: " . $err['sql'] . "..." . PHP_EOL;
        }
        exit(1);
    }

    echo "[تم] الترحيل v4.0.0 اكتمل بنجاح!" . PHP_EOL;

} catch (Exception $e) {
    echo "[فشل] " . $e->getMessage() . PHP_EOL;
    exit(1);
}
