<?php
/**
 * ============================================================
 * EQAS - محسب السلوك والانضباط
 * نظام التقييم الشامل - نظام النقاط (100 نقطة أساس)
 * ============================================================
 * 
 * نظام النقاط:
 * - يبدأ كل طالب بـ 100 نقطة (سلوك ممتاز)
 * - السلوك الإيجابي يضيف نقاط (حتى 120 كحد أقصى)
 * - السلوك السلبي يخصم نقاط
 * - النسبة = current_points / 100 * 100 (capped at 100)
 * - is_passing = percentage >= 60%
 * 
 * الدمج مع EQI:
 * - 50% نظام النقاط + 50% تقييم الاستمارة
 */

class BehaviorCalculator {
    /** @var PDO اتصال قاعدة البيانات */
    private $db;
    
    /** @var AlertEngine محرك التنبيهات */
    private $alertEngine;
    
    /** @var int نقاط البداية (100 نقطة = سلوك ممتاز) */
    private $startingPoints = 100;
    
    /** @var int الحد الأقصى للنقاط (120 = إضافة حتى 20 نقطة إيجابية) */
    private $maxPoints = 120;
    
    /** @var float الحد الأدنى للنجاح السلوكي (60%) */
    private $passingThreshold = 60;
    
    /** @var float عتبة التنبيه (50% - قبل الرسوب) */
    private $alertThreshold = 50;
    
    /**
     * إنشاء كائن محسب السلوك
     * @param PDO $db اتصال قاعدة البيانات
     * @param AlertEngine|null $alertEngine محرك التنبيهات (يُؤشأ تلقائياً إذا لم يُمرر)
     */
    public function __construct($db, $alertEngine = null) {
        $this->db = $db;
        require_once __DIR__ . '/alerts.php';
        $this->alertEngine = $alertEngine ?: new AlertEngine($this->db);
        $this->loadSettings();
    }
    
    /**
     * تحميل إعدادات السلوك من قاعدة البيانات
     */
    private function loadSettings() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'behavior_%' OR setting_key LIKE 'alert_threshold_behavior_%'");
            while ($row = $stmt->fetch()) {
                switch ($row['setting_key']) {
                    case 'behavior_starting_points':
                        $this->startingPoints = (int)$row['setting_value'];
                        break;
                    case 'behavior_max_points':
                        $this->maxPoints = (int)$row['setting_value'];
                        break;
                    case 'behavior_passing_threshold':
                    case 'alert_threshold_behavior_pass':
                        $this->passingThreshold = (float)$row['setting_value'];
                        break;
                    case 'behavior_alert_threshold':
                    case 'alert_threshold_behavior_alert':
                        $this->alertThreshold = (float)$row['setting_value'];
                        break;
                }
            }
        } catch (Exception $e) {
            // استخدام القيم الافتراضية
            error_log('BehaviorCalculator: استخدام القيم الافتراضية - ' . $e->getMessage());
        }
    }
    
    // ========================================================================
    // تهيئة سجل السلوك
    // ========================================================================
    
    /**
     * تهيئة سجل سلوك لطالب جديد
     * starting_points = 100, current_percentage = 100
     * 
     * @param int $studentId معرف الطالب
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج التهيئة
     */
    public function initializeBehaviorScore($studentId, $classId, $academicYearId, $semesterId) {
        try {
            // التحقق من عدم وجود سجل سلوك سابق
            $stmt = $this->db->prepare("
                SELECT id FROM behavior_scores 
                WHERE student_id = ? AND class_id = ? 
                    AND academic_year_id = ? AND semester_id = ? AND is_active = 1
            ");
            $stmt->execute([$studentId, $classId, $academicYearId, $semesterId]);
            
            if ($stmt->fetch()) {
                return [
                    'success' => true,
                    'message' => 'سجل السلوك موجود بالفعل',
                    'already_exists' => true
                ];
            }
            
            // إنشاء سجل سلوك جديد
            $sql = "INSERT INTO behavior_scores 
                    (student_id, class_id, academic_year_id, semester_id, 
                     starting_points, earned_points, deducted_points, current_points, 
                     current_percentage, is_passing, is_active, created_at)
                    VALUES (:student_id, :class_id, :year_id, :semester_id, 
                            :starting, 0, 0, :starting, 
                            100, 1, 1, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':starting' => $this->startingPoints
            ]);
            
            return [
                'success' => true,
                'message' => 'تم تهيئة سجل السلوك بنجاح',
                'score_id' => $this->db->lastInsertId(),
                'starting_points' => $this->startingPoints,
                'current_percentage' => 100,
                'is_passing' => true,
                'already_exists' => false
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::initializeBehaviorScore - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء تهيئة سجل السلوك'];
        }
    }
    
    // ========================================================================
    // إضافة السلوك الإيجابي
    // ========================================================================
    
    /**
     * إضافة سلوك إيجابي (زيادة نقاط)
     * 
     * @param int $studentId معرف الطالب
     * @param int $categoryId معرف فئة السلوك
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param string $description وصف السلوك
     * @param int $reportedBy معرف المُبلغ
     * @return array نتائج الإضافة
     */
    public function addPositiveBehavior($studentId, $categoryId, $classId, $academicYearId, $semesterId, $description, $reportedBy) {
        try {
            // 1. جلب نقاط الفئة
            $stmt = $this->db->prepare("
                SELECT id, name_ar, points, is_active 
                FROM behavior_categories 
                WHERE id = ? AND category_type = 'positive' AND is_active = 1
            ");
            $stmt->execute([$categoryId]);
            $category = $stmt->fetch();
            
            if (!$category) {
                return ['success' => false, 'message' => 'فئة السلوك الإيجابي غير موجودة أو غير نشطة'];
            }
            
            $points = (float)$category['points'];
            
            // 2. إنشاء سجل الحادثة السلوكية
            $sql = "INSERT INTO behavior_incidents 
                    (student_id, category_id, class_id, academic_year_id, semester_id,
                     incident_type, points, description, reported_by, incident_date, is_active, created_at)
                    VALUES (:student_id, :category_id, :class_id, :year_id, :semester_id,
                            'positive', :points, :description, :reported_by, CURDATE(), 1, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':category_id' => $categoryId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':points' => $points,
                ':description' => $description,
                ':reported_by' => $reportedBy
            ]);
            
            $incidentId = $this->db->lastInsertId();
            
            // 3. تحديث سجل السلوك (إضافة النقاط المكتسبة)
            $stmt = $this->db->prepare("
                UPDATE behavior_scores 
                SET earned_points = earned_points + :points,
                    current_points = LEAST(starting_points + earned_points + :points2 - deducted_points, :max_points),
                    updated_at = NOW()
                WHERE student_id = :student_id AND class_id = :class_id 
                    AND academic_year_id = :year_id AND semester_id = :semester_id AND is_active = 1
            ");
            $stmt->execute([
                ':points' => $points,
                ':points2' => $points,
                ':max_points' => $this->maxPoints,
                ':student_id' => $studentId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId
            ]);
            
            // 4. إعادة حساب النسبة السلوكية
            $percentageResult = $this->calculateCurrentPercentage($studentId, $classId, $academicYearId, $semesterId);
            
            return [
                'success' => true,
                'message' => 'تم إضافة السلوك الإيجابي بنجاح',
                'incident_id' => $incidentId,
                'category_name' => $category['name_ar'],
                'points_added' => $points,
                'current_percentage' => $percentageResult['current_percentage'],
                'current_points' => $percentageResult['current_points'],
                'is_passing' => $percentageResult['is_passing']
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::addPositiveBehavior - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء إضافة السلوك الإيجابي'];
        }
    }
    
    // ========================================================================
    // تسجيل السلوك السلبي
    // ========================================================================
    
    /**
     * تسجيل سلوك سلبي (خصم نقاط)
     * 
     * @param int $studentId معرف الطالب
     * @param int $categoryId معرف فئة السلوك
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param string $description وصف السلوك
     * @param int $reportedBy معرف المُبلغ
     * @return array نتائج التسجيل
     */
    public function addNegativeBehavior($studentId, $categoryId, $classId, $academicYearId, $semesterId, $description, $reportedBy) {
        try {
            // 1. جلب نقاط الفئة (سلبية)
            $stmt = $this->db->prepare("
                SELECT id, name_ar, points, is_active 
                FROM behavior_categories 
                WHERE id = ? AND category_type = 'negative' AND is_active = 1
            ");
            $stmt->execute([$categoryId]);
            $category = $stmt->fetch();
            
            if (!$category) {
                return ['success' => false, 'message' => 'فئة السلوك السلبي غير موجودة أو غير نشطة'];
            }
            
            $points = abs((float)$category['points']); // نتأكد أن القيمة موجبة للخصم
            
            // 2. إنشاء سجل الحادثة السلوكية
            $sql = "INSERT INTO behavior_incidents 
                    (student_id, category_id, class_id, academic_year_id, semester_id,
                     incident_type, points, description, reported_by, incident_date, is_active, created_at)
                    VALUES (:student_id, :category_id, :class_id, :year_id, :semester_id,
                            'negative', :points, :description, :reported_by, CURDATE(), 1, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':category_id' => $categoryId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':points' => $points,
                ':description' => $description,
                ':reported_by' => $reportedBy
            ]);
            
            $incidentId = $this->db->lastInsertId();
            
            // 3. تحديث سجل السلوك (خصم النقاط)
            $stmt = $this->db->prepare("
                UPDATE behavior_scores 
                SET deducted_points = deducted_points + :points,
                    current_points = GREATEST(starting_points + earned_points - (deducted_points + :points2), 0),
                    updated_at = NOW()
                WHERE student_id = :student_id AND class_id = :class_id 
                    AND academic_year_id = :year_id AND semester_id = :semester_id AND is_active = 1
            ");
            $stmt->execute([
                ':points' => $points,
                ':points2' => $points,
                ':student_id' => $studentId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId
            ]);
            
            // 4. إعادة حساب النسبة السلوكية
            $percentageResult = $this->calculateCurrentPercentage($studentId, $classId, $academicYearId, $semesterId);
            
            // 5. التحقق من الحاجة لتنبيه
            $needsAlert = false;
            if ($percentageResult['current_percentage'] < $this->alertThreshold) {
                $needsAlert = true;
                $this->createBehaviorAlert($studentId, $classId, $academicYearId, $semesterId, $percentageResult['current_percentage']);
            }
            
            return [
                'success' => true,
                'message' => 'تم تسجيل السلوك السلبي بنجاح',
                'incident_id' => $incidentId,
                'category_name' => $category['name_ar'],
                'points_deducted' => $points,
                'current_percentage' => $percentageResult['current_percentage'],
                'current_points' => $percentageResult['current_points'],
                'is_passing' => $percentageResult['is_passing'],
                'needs_alert' => $needsAlert
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::addNegativeBehavior - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء تسجيل السلوك السلبي'];
        }
    }
    
    // ========================================================================
    // حساب النسبة السلوكية
    // ========================================================================
    
    /**
     * حساب النسبة السلوكية الحالية
     * percentage = min(current_points / 100 * 100, 100)
     * where current_points = starting_points + earned_points - deducted_points
     * 
     * @param int $studentId معرف الطالب
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array النسبة السلوكية الحالية
     */
    public function calculateCurrentPercentage($studentId, $classId, $academicYearId, $semesterId) {
        try {
            // جلب سجل السلوك
            $stmt = $this->db->prepare("
                SELECT id, starting_points, earned_points, deducted_points, current_points,
                       current_percentage, is_passing
                FROM behavior_scores 
                WHERE student_id = ? AND class_id = ? 
                    AND academic_year_id = ? AND semester_id = ? AND is_active = 1
            ");
            $stmt->execute([$studentId, $classId, $academicYearId, $semesterId]);
            $score = $stmt->fetch();
            
            if (!$score) {
                // تهيئة سجل السلوك إذا لم يكن موجوداً
                $initResult = $this->initializeBehaviorScore($studentId, $classId, $academicYearId, $semesterId);
                return [
                    'current_points' => $this->startingPoints,
                    'current_percentage' => 100.00,
                    'is_passing' => true,
                    'starting_points' => $this->startingPoints,
                    'earned_points' => 0,
                    'deducted_points' => 0
                ];
            }
            
            // حساب النقاط الحالية
            $startingPoints = (float)$score['starting_points'];
            $earnedPoints = (float)$score['earned_points'];
            $deductedPoints = (float)$score['deducted_points'];
            $currentPoints = max(0, $startingPoints + $earnedPoints - $deductedPoints);
            $currentPoints = min($currentPoints, $this->maxPoints); // حد أقصى
            
            // حساب النسبة المئوية (معيارية على 100)
            $percentage = min(($currentPoints / $this->startingPoints) * 100, 100);
            $percentage = round(max(0, $percentage), 2);
            
            // تحديد حالة النجاح
            $isPassing = $percentage >= $this->passingThreshold;
            
            // تحديث السجل
            $stmt = $this->db->prepare("
                UPDATE behavior_scores 
                SET current_points = :current_points,
                    current_percentage = :percentage,
                    is_passing = :is_passing,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':current_points' => $currentPoints,
                ':percentage' => $percentage,
                ':is_passing' => $isPassing ? 1 : 0,
                ':id' => $score['id']
            ]);
            
            return [
                'score_id' => $score['id'],
                'starting_points' => $startingPoints,
                'earned_points' => $earnedPoints,
                'deducted_points' => $deductedPoints,
                'current_points' => $currentPoints,
                'current_percentage' => $percentage,
                'is_passing' => $isPassing,
                'max_points' => $this->maxPoints,
                'passing_threshold' => $this->passingThreshold
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::calculateCurrentPercentage - ' . $e->getMessage());
            return [
                'current_points' => 0,
                'current_percentage' => 0,
                'is_passing' => false,
                'starting_points' => $this->startingPoints,
                'earned_points' => 0,
                'deducted_points' => 0
            ];
        }
    }
    
    // ========================================================================
    // سجل السلوك الكامل
    // ========================================================================
    
    /**
     * الحصول على سجل السلوك الكامل للطالب
     * 
     * @param int $studentId معرف الطالب
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array سجل السلوك الكامل
     */
    public function getBehaviorHistory($studentId, $classId, $academicYearId, $semesterId) {
        try {
            // 1. جلب ملخص السلوك
            $stmt = $this->db->prepare("
                SELECT bs.* 
                FROM behavior_scores bs
                WHERE bs.student_id = ? AND bs.class_id = ? 
                    AND bs.academic_year_id = ? AND bs.semester_id = ? AND bs.is_active = 1
            ");
            $stmt->execute([$studentId, $classId, $academicYearId, $semesterId]);
            $summary = $stmt->fetch();
            
            if (!$summary) {
                return [
                    'summary' => null,
                    'incidents' => [],
                    'message' => 'لم يتم تهيئة سجل السلوك بعد'
                ];
            }
            
            // 2. جلب جميع الحوادث السلوكية
            $stmt = $this->db->prepare("
                SELECT bi.id, bi.category_id, bi.incident_type, bi.points, 
                       bi.description, bi.incident_date, bi.created_at,
                       bc.name_ar AS category_name, bc.category_type,
                       u.full_name_ar AS reporter_name
                FROM behavior_incidents bi
                LEFT JOIN behavior_categories bc ON bc.id = bi.category_id
                LEFT JOIN users u ON u.id = bi.reported_by
                WHERE bi.student_id = ? AND bi.class_id = ? 
                    AND bi.academic_year_id = ? AND bi.semester_id = ? AND bi.is_active = 1
                ORDER BY bi.incident_date DESC, bi.created_at DESC
            ");
            $stmt->execute([$studentId, $classId, $academicYearId, $semesterId]);
            $incidents = $stmt->fetchAll();
            
            // 3. فصل الحوادث الإيجابية والسلبية
            $positiveIncidents = [];
            $negativeIncidents = [];
            foreach ($incidents as $incident) {
                if ($incident['incident_type'] === 'positive') {
                    $positiveIncidents[] = $incident;
                } else {
                    $negativeIncidents[] = $incident;
                }
            }
            
            // 4. إعادة حساب النسبة الحالية
            $currentCalc = $this->calculateCurrentPercentage($studentId, $classId, $academicYearId, $semesterId);
            
            return [
                'summary' => [
                    'starting_points' => (float)$summary['starting_points'],
                    'earned_points' => (float)$summary['earned_points'],
                    'deducted_points' => (float)$summary['deducted_points'],
                    'current_points' => $currentCalc['current_points'],
                    'current_percentage' => $currentCalc['current_percentage'],
                    'is_passing' => $currentCalc['is_passing']
                ],
                'incidents' => $incidents,
                'positive_incidents' => $positiveIncidents,
                'negative_incidents' => $negativeIncidents,
                'positive_count' => count($positiveIncidents),
                'negative_count' => count($negativeIncidents),
                'total_earned' => array_sum(array_column($positiveIncidents, 'points')),
                'total_deducted' => array_sum(array_column($negativeIncidents, 'points'))
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::getBehaviorHistory - ' . $e->getMessage());
            return ['summary' => null, 'incidents' => [], 'message' => 'حدث خطأ أثناء جلب سجل السلوك'];
        }
    }
    
    // ========================================================================
    // متوسط السلوك للصف
    // ========================================================================
    
    /**
     * حساب متوسط السلوك لصف كامل
     * 
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array متوسط السلوك
     */
    public function calculateClassBehaviorAverage($classId, $academicYearId, $semesterId) {
        try {
            $stmt = $this->db->prepare("
                SELECT bs.student_id, bs.current_percentage, bs.is_passing,
                       s.full_name_ar, s.student_number
                FROM behavior_scores bs
                INNER JOIN students s ON s.id = bs.student_id
                WHERE bs.class_id = ? AND bs.academic_year_id = ? 
                    AND bs.semester_id = ? AND bs.is_active = 1 AND s.is_active = 1
            ");
            $stmt->execute([$classId, $academicYearId, $semesterId]);
            $scores = $stmt->fetchAll();
            
            if (empty($scores)) {
                return [
                    'class_id' => $classId,
                    'average_percentage' => 0,
                    'total_students' => 0,
                    'passing_count' => 0,
                    'failing_count' => 0,
                    'highest_percentage' => 0,
                    'lowest_percentage' => 0
                ];
            }
            
            $totalPercentage = 0;
            $passingCount = 0;
            $failingCount = 0;
            $highest = 0;
            $lowest = 100;
            
            foreach ($scores as $score) {
                $pct = (float)$score['current_percentage'];
                $totalPercentage += $pct;
                
                if ($pct >= $this->passingThreshold) {
                    $passingCount++;
                } else {
                    $failingCount++;
                }
                
                $highest = max($highest, $pct);
                $lowest = min($lowest, $pct);
            }
            
            $totalCount = count($scores);
            
            return [
                'class_id' => $classId,
                'average_percentage' => round($totalPercentage / $totalCount, 2),
                'total_students' => $totalCount,
                'passing_count' => $passingCount,
                'failing_count' => $failingCount,
                'passing_rate' => round(($passingCount / $totalCount) * 100, 2),
                'highest_percentage' => round($highest, 2),
                'lowest_percentage' => round($lowest, 2),
                'students' => $scores
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::calculateClassBehaviorAverage - ' . $e->getMessage());
            return ['average_percentage' => 0, 'total_students' => 0];
        }
    }
    
    // ========================================================================
    // عتبة السلوك والترفيع
    // ========================================================================
    
    /**
     * التحقق من عتبة السلوك (للترفيع)
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array حالة عتبة السلوك
     */
    public function checkBehaviorThreshold($studentId, $academicYearId, $semesterId) {
        try {
            // جلب سجل السلوك من أي فصل للطالب
            $stmt = $this->db->prepare("
                SELECT bs.current_percentage, bs.is_passing, bs.class_id
                FROM behavior_scores bs
                WHERE bs.student_id = ? AND bs.academic_year_id = ? 
                    AND bs.semester_id = ? AND bs.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$studentId, $academicYearId, $semesterId]);
            $score = $stmt->fetch();
            
            if (!$score) {
                return [
                    'percentage' => null,
                    'is_passing' => null,
                    'needs_override' => null,
                    'message' => 'لم يتم العثور على سجل سلوك',
                    'threshold' => $this->passingThreshold
                ];
            }
            
            $percentage = (float)$score['current_percentage'];
            $isPassing = $percentage >= $this->passingThreshold;
            
            // التحقق من وجود تجاوز
            $override = $this->getPromotionOverride($studentId, $academicYearId, $semesterId, 'behavior');
            $needsOverride = !$isPassing;
            $hasOverride = $override !== null;
            
            return [
                'percentage' => $percentage,
                'is_passing' => $isPassing,
                'needs_override' => $needsOverride,
                'has_override' => $hasOverride,
                'override' => $override,
                'threshold' => $this->passingThreshold,
                'message' => $isPassing 
                    ? 'الطالب مستوٍ لمعيار السلوك' 
                    : ($hasOverride 
                        ? 'الطالب لديه تجاوز معتمد لمعيار السلوك' 
                        : 'الطالب غير مستوٍ لمعيار السلوك ويحتاج تجاوز')
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::checkBehaviorThreshold - ' . $e->getMessage());
            return ['percentage' => null, 'is_passing' => null, 'needs_override' => null, 'message' => 'حدث خطأ'];
        }
    }
    
    // ========================================================================
    // تجاوز معيار الترفيع
    // ========================================================================
    
    /** @deprecated-v2.0.3 Should be moved to a dedicated PromotionService — verify no callers before removing */
    /**
     * إنشاء تجاوز لمعيار الترفيع
     * يتطلب موافقة group_admin
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param string $criterion المعيار (behavior, academic, attendance)
     * @param string $reason سبب التجاوز
     * @param int $approvedBy معرف المستخدم الذي وافق (يجب أن يكون group_admin)
     * @return array نتائج التجاوز
     */
    public function createPromotionOverride($studentId, $academicYearId, $semesterId, $criterion, $reason, $approvedBy) {
        try {
            // 1. التحقق من أن المستخدم المعتمد هو group_admin
            $stmt = $this->db->prepare("
                SELECT r.name FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.id = ? AND u.is_active = 1
            ");
            $stmt->execute([$approvedBy]);
            $userRole = $stmt->fetch();
            
            if (!$userRole || $userRole['name'] !== 'group_admin') {
                return [
                    'success' => false,
                    'message' => 'فقط مدير النظام (group_admin) يمكنه اعتماد التجاوز'
                ];
            }
            
            // 2. التحقق من عدم وجود تجاوز سابق
            $existing = $this->getPromotionOverride($studentId, $academicYearId, $semesterId, $criterion);
            if ($existing) {
                return [
                    'success' => false,
                    'message' => 'يوجد تجاوز معتمد سابق لهذا المعيار',
                    'existing_override' => $existing
                ];
            }
            
            // 3. إنشاء سجل التجاوز
            $sql = "INSERT INTO promotion_overrides 
                    (student_id, academic_year_id, semester_id, criterion, 
                     reason, approved_by, approved_at, is_active, created_at)
                    VALUES (:student_id, :year_id, :semester_id, :criterion, 
                            :reason, :approved_by, NOW(), 1, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':criterion' => $criterion,
                ':reason' => $reason,
                ':approved_by' => $approvedBy
            ]);
            
            return [
                'success' => true,
                'message' => 'تم إنشاء التجاوز بنجاح واعتماده من مدير النظام',
                'override_id' => $this->db->lastInsertId(),
                'criterion' => $criterion,
                'approved_by_role' => $userRole['name']
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::createPromotionOverride - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء إنشاء التجاوز'];
        }
    }
    
    /**
     * جلب تجاوز معيار ترفيع موجود
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param string $criterion المعيار
     * @return array|null بيانات التجاوز أو null
     */
    private function getPromotionOverride($studentId, $academicYearId, $semesterId, $criterion) {
        try {
            $stmt = $this->db->prepare("
                SELECT po.*, u.full_name_ar AS approver_name
                FROM promotion_overrides po
                LEFT JOIN users u ON u.id = po.approved_by
                WHERE po.student_id = ? AND po.academic_year_id = ? 
                    AND po.semester_id = ? AND po.criterion = ? AND po.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$studentId, $academicYearId, $semesterId, $criterion]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    // ========================================================================
    // تقييم الاستمارة السلوكية
    // ========================================================================
    
    /**
     * حساب نتيجة التقييم السلوكي (من محرك التقييم)
     * يختلف عن نظام النقاط - هذا من استمارة behavior_eval
     * 
     * @param int $studentId معرف الطالب
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array درجة التقييم السلوكي
     */
    public function calculateBehaviorAssessmentScore($studentId, $classId, $academicYearId, $semesterId) {
        try {
            // 1. جلب نموذج التقييم السلوكي
            $stmt = $this->db->prepare("
                SELECT id FROM assessment_forms 
                WHERE form_key = 'behavior_eval' AND is_active = 1
                ORDER BY is_default DESC, created_at DESC LIMIT 1
            "); // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
            $stmt->execute([]);
            $form = $stmt->fetch();
            
            if (!$form) {
                return [
                    'score' => null,
                    'message' => 'لم يتم العثور على نموذج تقييم سلوكي'
                ];
            }
            
            // 2. جلب استجابات التقييم للطالب
            $stmt = $this->db->prepare("
                SELECT ar.item_id, ar.numeric_value, ai.weight, ai.tool_type, 
                       ai.is_inverse, ai.max_value, ai.text_ar
                FROM assessment_responses ar
                INNER JOIN assessment_structure ai ON ai.id = ar.item_id
                WHERE ar.form_id = ? AND ar.student_id = ? AND ar.class_id = ?
                    AND ar.academic_year_id = ? AND ar.semester_id = ? 
                    AND ar.is_active = 1
                ORDER BY ai.sort_order
            ");
            $stmt->execute([$form['id'], $studentId, $classId, $academicYearId, $semesterId]);
            $responses = $stmt->fetchAll();
            
            if (empty($responses)) {
                return [
                    'score' => null,
                    'message' => 'لا توجد استجابات تقييم سلوكي لهذا الطالب'
                ];
            }
            
            // 3. تطبيع ووزن الاستجابات
            $totalWeight = 0;
            $weightedSum = 0;
            
            foreach ($responses as $response) {
                $value = (float)($response['numeric_value'] ?? 0);
                $weight = (float)($response['weight']);
                $toolType = $response['tool_type'];
                $maxValue = (float)($response['max_value'] ?? 100);
                $isInverse = (bool)$response['is_inverse'];
                
                // تطبيع القيمة إلى مقياس 0-100
                $normalized = 0;
                switch ($toolType) {
                    case 'likert_5':
                    case 'frequency_5':
                    case 'satisfaction_5':
                        $normalized = ($value / 5) * 100;
                        break;
                    case 'yes_no':
                        $normalized = $value * 100;
                        break;
                    case 'rubric_4':
                        $normalized = ($value / 4) * 100;
                        break;
                    case 'numeric_100':
                        $normalized = $value;
                        break;
                    case 'numeric_custom':
                        $normalized = $maxValue > 0 ? ($value / $maxValue) * 100 : 0;
                        break;
                    default:
                        $normalized = $value;
                }
                
                // تطبيق الانعكاس
                if ($isInverse) {
                    $normalized = 100 - $normalized;
                }
                
                $normalized = max(0, min(100, $normalized));
                
                $weightedSum += $normalized * $weight;
                $totalWeight += $weight;
            }
            
            // 4. حساب الدرجة الكلية
            $score = $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;
            
            return [
                'score' => $score,
                'total_items' => count($responses),
                'total_weight' => $totalWeight,
                'form_id' => $form['id'],
                'message' => 'تم حساب درجة التقييم السلوكي بنجاح'
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::calculateBehaviorAssessmentScore - ' . $e->getMessage());
            return ['score' => null, 'message' => 'حدث خطأ أثناء حساب درجة التقييم السلوكي'];
        }
    }
    
    // ========================================================================
    // الدمج مع EQI
    // ========================================================================
    
    /**
     * دمج نقطتين السلوك (نظام النقاط + تقييم الاستمارة)
     * للإدخال في EQI: 50% نقاط + 50% استمارة
     * 
     * @param int $studentId معرف الطالب
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array الدرجة المدمجة
     */
    public function getCombinedBehaviorScore($studentId, $classId, $academicYearId, $semesterId) {
        try {
            // 1. الحصول على نسبة نظام النقاط
            $pointScore = $this->calculateCurrentPercentage($studentId, $classId, $academicYearId, $semesterId);
            $pointPercentage = $pointScore['current_percentage'];
            
            // 2. الحصول على درجة تقييم الاستمارة
            $assessmentScore = $this->calculateBehaviorAssessmentScore($studentId, $classId, $academicYearId, $semesterId);
            $assessmentPercentage = $assessmentScore['score'];
            
            // 3. حساب الدرجة المدمجة (50% + 50%)
            $combinedScore = null;
            $weightsUsed = [];
            
            if ($pointPercentage !== null && $assessmentPercentage !== null) {
                // كلتا النقطتين متوفرتان: 50% + 50%
                $combinedScore = round(($pointPercentage * 0.5) + ($assessmentPercentage * 0.5), 2);
                $weightsUsed = ['points' => 0.5, 'assessment' => 0.5];
            } elseif ($pointPercentage !== null) {
                // فقط نظام النقاط متوفر
                $combinedScore = round($pointPercentage, 2);
                $weightsUsed = ['points' => 1.0, 'assessment' => 0];
            } elseif ($assessmentPercentage !== null) {
                // فقط تقييم الاستمارة متوفر
                $combinedScore = round($assessmentPercentage, 2);
                $weightsUsed = ['points' => 0, 'assessment' => 1.0];
            }
            
            // 4. تحديد التصنيف
            $rating = $this->getBehaviorRating($combinedScore);
            
            return [
                'student_id' => $studentId,
                'point_based_score' => $pointPercentage,
                'assessment_based_score' => $assessmentPercentage,
                'combined_score' => $combinedScore,
                'weights_used' => $weightsUsed,
                'rating' => $rating,
                'is_passing' => $combinedScore !== null ? $combinedScore >= $this->passingThreshold : null,
                'passing_threshold' => $this->passingThreshold
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::getCombinedBehaviorScore - ' . $e->getMessage());
            return [
                'point_based_score' => null,
                'assessment_based_score' => null,
                'combined_score' => null,
                'rating' => null,
                'is_passing' => null
            ];
        }
    }
    
    // ========================================================================
    // دوال مساعدة
    // ========================================================================
    
    /**
     * إنشاء تنبيه سلوكي
     * 
     * @param int $studentId معرف الطالب
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param float $percentage النسبة السلوكية الحالية
     * @return bool نجاح أو فشل
     */
    private function createBehaviorAlert($studentId, $classId, $academicYearId, $semesterId, $percentage) {
        try {
            // الحصول على معلومات الطالب
            $stmt = $this->db->prepare("
                SELECT s.id, s.full_name_ar, s.student_number, s.school_id
                FROM students s
                WHERE s.id = :student_id AND s.is_active = 1
            ");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
            
            if (!$student) return false;
            
            // تحديد مستوى الخطورة
            $severity = 'warning';
            if ($percentage < 30) {
                $severity = 'critical';
            } elseif ($percentage < 40) {
                $severity = 'danger';
            }
            
            // إنشاء التنبيه عبر AlertEngine (يتضمن منع التكرار + إنشاء الإشعارات)
            $alertId = $this->alertEngine->createAlert([
                'school_id' => $student['school_id'],
                'student_id' => $studentId,
                'alert_type' => 'behavior',
                'severity' => $severity,
                'title_ar' => 'تنبيه سلوكي - ' . $student['full_name_ar'],
                'message_ar' => sprintf(
                    'انخفضت النسبة السلوكية للطالب %s (رقم: %s) إلى %.2f%% وهي أقل من الحد المطلوب (%.0f%%). يرجى اتخاذ الإجراءات اللازمة.',
                    $student['full_name_ar'],
                    $student['student_number'],
                    $percentage,
                    $this->passingThreshold
                ),
            ]);
            
            return $alertId !== false;
        } catch (Exception $e) {
            error_log('BehaviorCalculator::createBehaviorAlert - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * الحصول على تصنيف السلوك
     * 
     * @param float|null $score النسبة المئوية
     * @return array التصنيف
     */
    public function getBehaviorRating($score) {
        if ($score === null) {
            return ['label' => 'غير محدد', 'class' => 'unknown', 'color' => '#6c757d'];
        }
        
        if ($score >= 90) return ['label' => 'ممتاز', 'class' => 'excellent', 'color' => '#28a745'];
        if ($score >= 80) return ['label' => 'جيد جداً', 'class' => 'very-good', 'color' => '#17a2b8'];
        if ($score >= 70) return ['label' => 'جيد', 'class' => 'good', 'color' => '#20c997'];
        if ($score >= 60) return ['label' => 'مقبول', 'class' => 'fair', 'color' => '#ffc107'];
        if ($score >= 50) return ['label' => 'ضعيف', 'class' => 'poor', 'color' => '#fd7e14'];
        return ['label' => 'ضعيف جداً', 'class' => 'very-poor', 'color' => '#dc3545'];
    }
    
    /**
     * الحصول على فئات السلوك المتاحة
     * 
     * @param string|null $type نوع الفئة (positive أو negative أو null للكل)
     * @return array فئات السلوك
     */
    public function getBehaviorCategories($type = null) {
        try {
            $sql = "SELECT * FROM behavior_categories WHERE is_active = 1";
            $params = [];
            
            if ($type) {
                $sql .= " AND category_type = ?";
                $params[] = $type;
            }
            
            $sql .= " ORDER BY sort_order ASC, name_ar ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('BehaviorCalculator::getBehaviorCategories - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * الحصول على سجل السلوك لطالب في سنة دراسية كاملة
     * يجمع الفصلين الدراسيين
     * 
     * @param int $studentId معرف الطالب
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @return array سجل السنة الكامل
     */
    public function getFullYearBehavior($studentId, $classId, $academicYearId) {
        try {
            // جلب فصول السنة الدراسية
            $stmt = $this->db->prepare("
                SELECT id, name_ar FROM semesters 
                WHERE academic_year_id = ? AND is_active = 1 
                ORDER BY start_date ASC
            ");
            $stmt->execute([$academicYearId]);
            $semesters = $stmt->fetchAll();
            
            $yearData = [];
            $totalPercentage = 0;
            $semesterCount = 0;
            
            foreach ($semesters as $semester) {
                $history = $this->getBehaviorHistory($studentId, $classId, $academicYearId, $semester['id']);
                $yearData[$semester['id']] = [
                    'semester_name' => $semester['name_ar'],
                    'semester_id' => $semester['id'],
                    'data' => $history
                ];
                
                if ($history['summary']) {
                    $totalPercentage += $history['summary']['current_percentage'];
                    $semesterCount++;
                }
            }
            
            $yearAverage = $semesterCount > 0 ? round($totalPercentage / $semesterCount, 2) : 0;
            
            return [
                'student_id' => $studentId,
                'academic_year_id' => $academicYearId,
                'semesters' => $yearData,
                'year_average' => $yearAverage,
                'year_rating' => $this->getBehaviorRating($yearAverage),
                'is_passing_year' => $yearAverage >= $this->passingThreshold
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::getFullYearBehavior - ' . $e->getMessage());
            return ['semesters' => [], 'year_average' => 0];
        }
    }
    
    /**
     * تهيئة سجلات السلوك لجميع طلاب الفصل
     * 
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج التهيئة
     */
    public function initializeClassBehaviorScores($classId, $academicYearId, $semesterId) {
        try {
            // جلب طلاب الفصل
            $stmt = $this->db->prepare("
                SELECT se.student_id
                FROM student_enrollments se
                INNER JOIN students s ON s.id = se.student_id
                WHERE se.class_id = ? AND se.academic_year_id = ? 
                    AND se.status = 'enrolled' AND se.is_active = 1 AND s.is_active = 1
            ");
            $stmt->execute([$classId, $academicYearId]);
            $students = $stmt->fetchAll();
            
            $initialized = 0;
            $alreadyExists = 0;
            $errors = 0;
            
            foreach ($students as $student) {
                $result = $this->initializeBehaviorScore(
                    $student['student_id'], $classId, $academicYearId, $semesterId
                );
                
                if ($result['success']) {
                    if (isset($result['already_exists']) && $result['already_exists']) {
                        $alreadyExists++;
                    } else {
                        $initialized++;
                    }
                } else {
                    $errors++;
                }
            }
            
            return [
                'success' => true,
                'class_id' => $classId,
                'total_students' => count($students),
                'initialized' => $initialized,
                'already_exists' => $alreadyExists,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            error_log('BehaviorCalculator::initializeClassBehaviorScores - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء تهيئة سجلات السلوك'];
        }
    }
}
