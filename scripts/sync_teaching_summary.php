<?php
/**
 * ============================================================
 * EQAS - مزامنة ملخص تقييم المعلمين
 * ============================================================
 * 
 * يقرأ هذا النص بيانات التقييم من جدول assessment_responses
 * ويحسب الدرجات المُجمّعة ويدرجها/يحدّثها في جدول teaching_evaluation_summary.
 *
 * الصيغة: combined_score = self_score * 0.20 + peer_score * 0.30 + admin_score * 0.50
 *
 * الاستخدام:
 *   php scripts/sync_teaching_summary.php                    # كل المدارس
 *   php scripts/sync_teaching_summary.php --school=5         # مدرسة محددة
 *   php scripts/sync_teaching_summary.php --dry-run           # محاكاة فقط
 *   php scripts/sync_teaching_summary.php --verbose           # عرض تفصيلي
 * ============================================================
 */

// ─── الإعدادات ────────────────────────────────────────────────

$options = getopt('', ['school::', 'dry-run', 'verbose', 'help']);
$schoolIdFilter = isset($options['school']) ? (int)$options['school'] : null;
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$help = isset($options['help']);

if ($help) {
    echo "EQAS - مزامنة ملخص تقييم المعلمين\n\n";
    echo "الاستخدام:\n";
    echo "  php scripts/sync_teaching_summary.php                    # كل المدارس\n";
    echo "  php scripts/sync_teaching_summary.php --school=5         # مدرسة محددة\n";
    echo "  php scripts/sync_teaching_summary.php --dry-run           # محاكاة فقط\n";
    echo "  php scripts/sync_teaching_summary.php --verbose           # عرض تفصيلي\n\n";
    exit(0);
}

// ─── الاتصال بقاعدة البيانات ─────────────────────────────────

putenv('EQAS_DEV_MODE=1');
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "خطأ: config.php غير موجود.\n");
    exit(1);
}
require_once $configFile;

$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage() . "\n");
    exit(1);
}

if ($verbose) {
    echo "📡 متصل بقاعدة البيانات: " . DB_NAME . "\n";
    if ($schoolIdFilter) echo "   المدرسة: #$schoolIdFilter\n";
    if ($dryRun) echo "   وضع المحاكاة (dry-run): لا تغييرات حقيقية\n";
    echo "\n";
}

// ─── الأوزان ──────────────────────────────────────────────────

$weights = ['self' => 0.20, 'peer' => 0.30, 'admin' => 0.50];

// ─── إنشاء جدول الملخص إذا لم يكن موجوداً ────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS teaching_evaluation_summary (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    academic_year_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    self_score DECIMAL(5,2) DEFAULT NULL,
    peer_score DECIMAL(5,2) DEFAULT NULL,
    admin_score DECIMAL(5,2) DEFAULT NULL,
    combined_score DECIMAL(5,2) DEFAULT NULL,
    adjusted_score DECIMAL(5,2) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_teaching_evaluation_summary (teacher_id, academic_year_id, semester_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── الحصول على معرفات النماذج ───────────────────────────────

$formIds = [];
foreach (['teaching_self', 'teaching_peer', 'teaching_admin'] as $key) {
    $stmt = $pdo->prepare("SELECT id FROM assessment_forms WHERE form_key = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$key]);
    $formIds[$key] = $stmt->fetchColumn();
}

$selfFormId = $formIds['teaching_self'];
$peerFormId = $formIds['teaching_peer'];
$adminFormId = $formIds['teaching_admin'];

if ($verbose) {
    echo "   self_form_id: " . ($selfFormId ?: '—') . "\n";
    echo "   peer_form_id: " . ($peerFormId ?: '—') . "\n";
    echo "   admin_form_id: " . ($adminFormId ?: '—') . "\n\n";
}

// ─── جلب معرفات النماذج الموجودة ────────────────────────────

$formIdList = array_filter([$selfFormId, $peerFormId, $adminFormId]);
if (empty($formIdList)) {
    echo "⚠️  لا توجد نماذج تقييم تدريس مفعلة.\n";
    exit(0);
}

// ─── جلب قائمة (المعلم, العام, الفصل) ────────────────────────

$schoolFilter = $schoolIdFilter ? "AND u.school_id = :school_id" : "";
$placeholders = implode(',', array_fill(0, count($formIdList), '?'));

$sql = "
    SELECT DISTINCT u.school_id, ar.teacher_id, ar.academic_year_id, ar.semester_id
    FROM assessment_responses ar
    JOIN users u ON ar.teacher_id = u.id AND u.is_active = 1
    WHERE ar.form_id IN ($placeholders) AND ar.is_active = 1
      $schoolFilter
    ORDER BY u.school_id, ar.teacher_id, ar.academic_year_id, ar.semester_id
";

try {
    $stmt = $pdo->prepare($sql);
    $params = array_values($formIdList);
    if ($schoolIdFilter) $params[] = $schoolIdFilter;
    $stmt->execute($params);
    $records = $stmt->fetchAll();
} catch (PDOException $e) {
    fwrite(STDERR, "خطأ في جلب السجلات: " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($records)) {
    echo "⚠️  لا توجد بيانات تقييم للمزامنة.\n";
    exit(0);
}

if ($verbose) echo "📋 تم العثور على " . count($records) . " مجموعة (معلم/عام/فصل)\n\n";

// ─── معالجة كل سجل ──────────────────────────────────────────

$updated = 0;
$inserted = 0;
$skipped = 0;

// الحصول على متوسط الدرجات لكل نوع تقييم من assessment_responses
$getAvgScore = function($formId, $teacherId, $yearId, $semesterId) use ($pdo) {
    if (!$formId) return null;
    $stmt = $pdo->prepare("
        SELECT AVG(ar.numeric_value) AS avg_score
        FROM assessment_responses ar
        WHERE ar.form_id = ? AND ar.teacher_id = ? AND ar.academic_year_id = ? AND ar.semester_id = ? AND ar.is_active = 1
    ");
    $stmt->execute([$formId, $teacherId, $yearId, $semesterId]);
    $row = $stmt->fetch();
    return $row && $row['avg_score'] !== null ? round((float)$row['avg_score'], 2) : null;
};

$count = count($records);
$processed = 0;

foreach ($records as $rec) {
    $processed++;
    $teacherId = (int)$rec['teacher_id'];
    $yearId = (int)$rec['academic_year_id'];
    $semesterId = (int)$rec['semester_id'];
    $schoolId = (int)$rec['school_id'];

    $selfScore = $getAvgScore($selfFormId, $teacherId, $yearId, $semesterId);
    $peerScore = $getAvgScore($peerFormId, $teacherId, $yearId, $semesterId);
    $adminScore = $getAvgScore($adminFormId, $teacherId, $yearId, $semesterId);

    $combined = 0;
    $totalWeight = 0;
    $scores = ['self' => $selfScore, 'peer' => $peerScore, 'admin' => $adminScore];
    foreach ($weights as $key => $w) {
        if ($scores[$key] !== null) {
            $combined += $scores[$key] * $w;
            $totalWeight += $w;
        }
    }
    $combinedScore = $totalWeight > 0 ? round($combined / $totalWeight, 2) : null;

    // حساب التعديل السياقي (إذا كان متاحاً)
    $adjustedScore = $combinedScore;
    if ($combinedScore !== null) {
        try {
            require_once __DIR__ . '/../includes/contextual_adjustment.php';
            $adjService = new ContextualAdjustmentService($pdo);
            $adjustedScore = $adjService->calculateAdjustedSQI($schoolId, $yearId, $combinedScore);
        } catch (Exception $e) {
            $adjustedScore = $combinedScore;
        }
    }

    if ($verbose) {
        $bar = str_repeat('█', round($processed / $count * 30));
        $bar .= str_repeat('░', 30 - round($processed / $count * 30));
        printf("\r[%s] %d/%d — معلم #%d (عام #%d, فصل #%d) ذاتي:%s أقران:%s إشرافي:%s = %.1f",
            $bar, $processed, $count,
            $teacherId, $yearId, $semesterId,
            $selfScore !== null ? number_format($selfScore, 1) : '—',
            $peerScore !== null ? number_format($peerScore, 1) : '—',
            $adminScore !== null ? number_format($adminScore, 1) : '—',
            $combinedScore ?? 0
        );
    }

    if ($combinedScore === null) {
        $skipped++;
        continue;
    }

    if ($dryRun) continue;

    // UPSERT
    $stmt = $pdo->prepare("
        INSERT INTO teaching_evaluation_summary (teacher_id, academic_year_id, semester_id, self_score, peer_score, admin_score, combined_score, adjusted_score, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            self_score = VALUES(self_score),
            peer_score = VALUES(peer_score),
            admin_score = VALUES(admin_score),
            combined_score = VALUES(combined_score),
            adjusted_score = VALUES(adjusted_score),
            is_active = 1
    ");
    $stmt->execute([$teacherId, $yearId, $semesterId, $selfScore, $peerScore, $adminScore, $combinedScore, $adjustedScore]);

    if ($stmt->rowCount() === 1) $inserted++;
    else $updated++;
}

if ($verbose) echo "\n\n";

// ─── النتيجة ──────────────────────────────────────────────────

echo "✅ اكتملت المزامنة.\n";
if ($dryRun) {
    echo "   (وضع المحاكاة: لم يتم تنفيذ أي تغييرات)\n";
} else {
    echo "   تم إدراج: $inserted سجل جديد\n";
    echo "   تم تحديث: $updated سجل\n";
}
echo "   تم التخطي: $skipped (لا توجد درجات كافية)\n";
echo "   الإجمالي: " . count($records) . " مجموعة\n";
