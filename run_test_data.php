<?php
/**
 * تشغيل بيانات اختبار التنبيهات
 */

require_once __DIR__ . '/includes/database.php';

echo "بدء إنشاء بيانات الاختبار...\n\n";

// إنشاء اتصال بقاعدة البيانات
$db = Database::getInstance();
$conn = $db->getConnection();

$sql = file_get_contents(__DIR__ . '/scripts/generate_alerts_test_data.sql');

// تقسيم إلى عبارات منفصة
$statements = array_filter(array_map('trim', explode(';', $sql)));

$success = 0;
$failed = 0;
$errors = [];

foreach ($statements as $statement) {
    if (empty($statement) || strpos($statement, 'SELECT') === 0) {
        continue; // تخطي SELECT النهائي
    }
    
    try {
        $conn->exec($statement);
        $success++;
    } catch (Exception $e) {
        $failed++;
        $errors[] = $e->getMessage();
    }
}

// عرض النتيجة
echo "========== نتيجة الإنشاء ==========\n";
echo "✅ نجحت: $success عبارة\n";
echo "❌ فشلت: $failed عبارة\n";

if (!empty($errors)) {
    echo "\n--- الأخطاء ---\n";
    $uniqueErrors = array_unique($errors);
    foreach ($uniqueErrors as $err) {
        echo "- " . substr($err, 0, 100) . "\n";
    }
}

// التحقق من وجود طلاب جدد
echo "\n========== التحقق من الطلاب ==========\n";
$stmt = $conn->query("SELECT id, student_number, full_name_ar FROM students WHERE id >= 9001 AND id <= 9005");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "✅ موجود: ID {$row['id']} - {$row['full_name_ar']}\n";
}

// عرض عدد التنبيهات المُنشأة
echo "\n========== التنبيهات ==========\n";
$stmt = $conn->query("SELECT COUNT(*) as total FROM alerts WHERE school_id = 3");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "📊 إجمالي التنبيهات في المدرسة 3: " . $result['total'] . "\n";

// إذا لم تكن هناك تنبيهات، أنشئها مباشرة
if ($result['total'] < 5) {
    echo "\n--- إنشاء تنبيهات يدوية ---\n";
    
    // تنبيهات أكاديمية
    $conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
        VALUES (3, 9001, 'academic', 'critical', '🚨 تراجع أكاديمي حرج', 'الطالب أحمد محمد علي يحصل على معدل 20%', 0, 0, NOW())");
    $conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
        VALUES (3, 9002, 'academic', 'danger', '⚠️ تراجع أكاديمي', 'الطالب سارة إبراهيم حسن تحصل على معدل 35%', 0, 0, NOW())");
    
    // تنبيهات حضور
    $conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
        VALUES (3, 9001, 'attendance', 'warning', '📋 معدل حضور منخفض', 'الطالب أحمد محمد علي لديه نسبة حضور 60%', 0, 0, NOW())");
    
    // تنبيهات سلوك
    $conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
        VALUES (3, 9001, 'behavior', 'danger', '⚠️ تقييم سلوكي منخفض', 'الطالب أحمد محمد علي حصل على 25% في التقييم السلوكي', 0, 0, NOW())");
    $conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
        VALUES (3, 9002, 'behavior', 'warning', '⚠️ سلوك يحتاج متابعة', 'الطالب سارة إبراهيم حسن حصلت على 55% في التقييم السلوكي', 0, 0, NOW())");
    
    echo "✅ تم إنشاء 5 تنبيهات جديدة!\n";
}

// عرض التنبيهات النهائية
echo "\n========== التنبيهات المُنشأة ==========\n";
$stmt = $conn->query("
    SELECT a.id, a.alert_type, a.severity, a.title_ar, s.full_name_ar as student_name
    FROM alerts a
    LEFT JOIN students s ON a.student_id = s.id
    WHERE a.school_id = 3
    ORDER BY a.created_at DESC
    LIMIT 10
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $icon = $row['severity'] === 'critical' ? '🔴' : ($row['severity'] === 'danger' ? '🟠' : ($row['severity'] === 'warning' ? '🟡' : '🔵'));
    echo sprintf("[%s] %s - %s (%s)\n", 
        $row['alert_type'], 
        $icon, 
        $row['title_ar'], 
        $row['student_name'] ?? 'عام'
    );
}

echo "\n✅ اكتمل!\n";