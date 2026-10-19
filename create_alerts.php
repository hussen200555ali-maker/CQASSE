<?php
/**
 * إنشاء تنبيهات اختبار للمعلم فاطمة أحمد الذماري (teacher_id = 13)
 */

require_once __DIR__ . '/includes/database.php';

$db = Database::getInstance();
$conn = $db->getConnection();

echo "========== إنشاء تنبيهات اختبار للمعلم فاطمة أحمد الذماري ==========\n\n";

// الطلاب في المدرسة 3 (نختار 5 طلاب)
$studentIds = [23, 24, 25, 26, 27];

// 1. تنبيهات أكاديمية (طلاب ضعفاء)
echo "📚 إنشاء تنبيهات أكاديمية...\n";
$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 23, 'academic', 'critical', '🚨 تراجع أكاديمي حرج', 'الطالبة آمنة محمد الفلاح تحصل على معدل 20% في مادة الرياضيات - أقل من حد النجاح (50%)', 0, 0, NOW())");

$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 24, 'academic', 'danger', '⚠️ تراجع أكاديمي', 'الطالبة بثينة علي الحميري تحصل على معدل 35% في مادة العلوم', 0, 0, NOW())");

$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 25, 'academic', 'warning', '⚠️ أداء أكاديمي يحتاج متابعة', 'الطالبة تهاني أحمد العنسي تحصل على معدل 45% في مادة اللغة العربية', 0, 0, NOW())");

echo "✅ تم إنشاء 3 تنبيهات أكاديمية\n";

// 2. تنبيهات حضور (غياب منخفض)
echo "📋 إنشاء تنبيهات حضور...\n";
$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 23, 'attendance', 'warning', '📋 معدل حضور منخفض', 'الطالبة آنة محمد الفلاح لديها نسبة حضور 60% - أقل من الحد المطلوب (75%)', 0, 0, NOW())");

$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 26, 'attendance', 'danger', '⚠️ غياب متكرر', 'الطالبة حليمة خالد النوري لديها غياب متكرر خلال هذا الأسبوع', 0, 0, NOW())");

echo "✅ تم إنشاء 2 تنبيهات حضور\n";

// 3. تنبيهات سلوك
echo "⭐ إنشاء تنبيهات سلوكية...\n";
$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 23, 'behavior', 'danger', '⚠️ تقييم سلوكي منخفض', 'الطالبة آمنة محمد الفلاح حصلت على 25% في التقييم السلوكي - أقل من الحد المطلوب (60%)', 0, 0, NOW())");

$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 27, 'behavior', 'warning', '⚠️ سلوك يحتاج متابعة', 'الطالبة رنا عبدالله البعداني حصلت على 55% في التقييم السلوكي', 0, 0, NOW())");

echo "✅ تم إنشاء 2 تنبيهات سلوكية\n";

// 4. تنبيهات ترفيع (افتراضية)
echo "📈 إنشاء تنبيهات ترفيع...\n";
$conn->exec("INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved, created_at) 
    VALUES (3, 24, 'promotion', 'warning', '⚠️ قرار ترفيع مشروط', 'الطالبة بثينة علي الحميري حصلت على قرار ترفيع مشروط -需要在第二个学期提升成绩', 0, 0, NOW())");

echo "✅ تم إنشاء 1 تنبيه ترفيع\n";

// عرض النتيجة النهائية
echo "\n========== التنبيهات المُنشأة ==========\n";

$stmt = $conn->query("
    SELECT a.id, a.alert_type, a.severity, a.title_ar, s.full_name_ar as student_name
    FROM alerts a
    LEFT JOIN students s ON a.student_id = s.id
    WHERE a.school_id = 3
    ORDER BY a.id DESC
    LIMIT 20
");

$severityIcons = [
    'critical' => '🔴',
    'danger' => '🟠', 
    'warning' => '🟡',
    'info' => '🔵'
];

$typeIcons = [
    'academic' => '📚',
    'attendance' => '📋',
    'behavior' => '⭐',
    'promotion' => '📈',
    'eqi' => '📊',
    'climate' => '🌤️'
];

$total = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $total++;
    $sevIcon = $severityIcons[$row['severity']] ?? '⚪';
    $typeIcon = $typeIcons[$row['alert_type']] ?? '📌';
    echo sprintf("%d. %s %s - %s (%s)\n", 
        $row['id'],
        $typeIcon,
        $sevIcon,
        mb_substr($row['title_ar'], 0, 40),
        $row['student_name'] ?? 'عام'
    );
}

echo "\n✅ إجمالي التنبيهات: $total\n";
echo "\n💡 الآن سجّل دخول كـ teacher (فاطمة أحمد الذماري) واذهب لـ pages/alerts/index.php\n";
echo "   سترى تنبيهات طلاب مدرستك (الأكاديمي، السلوكي، الحضور، الترفيع)\n";