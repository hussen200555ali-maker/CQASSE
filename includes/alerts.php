<?php
/**
 * ============================================================
 * EQAS - Smart Alerts Engine
 * نظام التقييم الشامل - محرك التنبيهات الذكية
 * ============================================================
 * 
 * ثلاثة مصادر للتنبيهات:
 * 1. حدود عتبية: درجات أقل من النجاح، حضور أقل من 75%، سلوك أقل من 60%
 * 2. اتجاهات: تراجع الأداء خلال 3+ فترات
 * 3. أنماط: انخفاض مفاجئ، أنماط غياب متكررة
 */

class AlertEngine {
    private $db;
    
    // حدود العتبات الافتراضية
    private $thresholds = [
        'academic_pass' => ACADEMIC_PASS_RATE,      // 50%
        'behavior_pass' => BEHAVIOR_PASS_RATE,      // 60%
        'attendance_pass' => ATTENDANCE_PASS_RATE,  // 75%
        'sudden_drop_percentage' => 20,              // انخفاض مفاجئ 20%
        'consecutive_absence_days' => 3,             // غياب متتالي 3 أيام
    ];
    
    // أنواع التنبيهات بالعربية
    private $alertTypeLabels = [
        'academic'  => 'أكاديمي',
        'behavior'  => 'سلوكي',
        'attendance'=> 'حضور',
        'eqi'       => 'مؤشر الجودة',
        'promotion' => 'ترفيع',
    ];
    
    // مستويات الخطورة بالعربية
    private $severityLabels = [
        'info'     => 'معلوماتي',
        'warning'  => 'تحذير',
        'danger'   => 'خطر',
        'critical' => 'حرج',
    ];
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadThresholds();
    }
    
    /**
     * تحميل حدود العتبات من إعدادات النظام
     */
    private function loadThresholds() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'alert_threshold_%'");
            while ($row = $stmt->fetch()) {
                $key = str_replace('alert_threshold_', '', $row['setting_key']);
                if (isset($this->thresholds[$key])) {
                    $this->thresholds[$key] = (float)$row['setting_value'];
                }
            }
        } catch (Exception $e) {
            // استخدام القيم الافتراضية
            error_log('AlertEngine: Failed to load thresholds - ' . $e->getMessage());
        }
    }
    
    // ========================================================================
    // المصدر الأول: التنبيهات الحدية (Threshold-based Alerts)
    // ========================================================================
    
    /**
     * فحص الحدود الأكاديمية - درجات أقل من حد النجاح
     */
    public function checkAcademicThresholds($schoolId, $academicYearId, $semesterId) {
        $alertsCreated = 0;
        $passRate = $this->thresholds['academic_pass'];
        
        try {
            // الحصول على الطلاب الذين معدلهم أقل من حد النجاح
            $sql = "SELECT s.id AS student_id, s.full_name_ar, 
                           AVG(g.grade_value / g.max_value * 100) AS avg_score,
                           COUNT(g.id) AS grade_count
                    FROM students s
                    INNER JOIN student_enrollments se ON se.student_id = s.id AND se.is_active = 1
                    INNER JOIN classes c ON c.id = se.class_id AND c.school_id = :school_id
                    INNER JOIN grades g ON g.student_id = s.id 
                        AND g.academic_year_id = :year_id 
                        AND g.semester_id = :semester_id 
                        AND g.is_active = 1
                    WHERE s.school_id = :school_id2 
                        AND s.is_active = 1
                        AND se.academic_year_id = :year_id2
                    GROUP BY s.id
                    HAVING avg_score < :pass_rate AND grade_count > 0";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':school_id2' => $schoolId,
                ':year_id' => $academicYearId,
                ':year_id2' => $academicYearId,
                ':semester_id' => $semesterId,
                ':pass_rate' => $passRate
            ]);
            
            while ($row = $stmt->fetch()) {
                $severity = $this->determineAcademicSeverity($row['avg_score']);
                $alertData = [
                    'school_id' => $schoolId,
                    'student_id' => $row['student_id'],
                    'alert_type' => 'academic',
                    'severity' => $severity,
                    'title_ar' => 'أداء أكاديمي متدني',
                    'message_ar' => sprintf(
                        'الطالب/ة %s حصل على معدل %.1f%% وهو أقل من حد النجاح (%d%%) في الفصل الدراسي الحالي.',
                        $row['full_name_ar'],
                        $row['avg_score'],
                        $passRate
                    ),
                ];
                
                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkAcademicThresholds - ' . $e->getMessage());
        }
        
        return $alertsCreated;
    }
    
    /**
     * تحديد مستوى خطورة التنبيه الأكاديمي
     */
    private function determineAcademicSeverity($score) {
        if ($score < 25) return 'critical';
        if ($score < 35) return 'danger';
        if ($score < 45) return 'warning';
        return 'info';
    }
    
    /**
     * فحص حدود السلوك - درجات سلوك أقل من 60%
     */
    public function checkBehaviorThresholds($schoolId, $academicYearId, $semesterId) {
        $alertsCreated = 0;
        $passRate = $this->thresholds['behavior_pass'];
        
        try {
            $sql = "SELECT s.id AS student_id, s.full_name_ar,
                           be.current_percentage AS behavior_score
                    FROM students s
                    INNER JOIN behavior_scores be ON be.student_id = s.id 
                        AND be.academic_year_id = :year_id 
                        AND be.semester_id = :semester_id 
                        AND be.is_active = 1
                    WHERE s.school_id = :school_id 
                        AND s.is_active = 1
                        AND be.current_percentage < :pass_rate";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':pass_rate' => $passRate
            ]);
            
            while ($row = $stmt->fetch()) {
                $severity = $this->determineBehaviorSeverity($row['behavior_score']);
                $alertData = [
                    'school_id' => $schoolId,
                    'student_id' => $row['student_id'],
                    'alert_type' => 'behavior',
                    'severity' => $severity,
                    'title_ar' => 'سلوك غير مقبول',
                    'message_ar' => sprintf(
                        'الطالب/ة %s حصل على درجة سلوك %.1f%% وهي أقل من الحد المقبول (%d%%).',
                        $row['full_name_ar'],
                        $row['behavior_score'],
                        $passRate
                    ),
                ];
                
                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkBehaviorThresholds - ' . $e->getMessage());
        }
        
        return $alertsCreated;
    }
    
    /**
     * تحديد مستوى خطورة التنبيه السلوكي
     */
    private function determineBehaviorSeverity($score) {
        if ($score < 30) return 'critical';
        if ($score < 45) return 'danger';
        if ($score < 55) return 'warning';
        return 'info';
    }
    
    /**
     * فحص حدود الحضور - نسبة حضور أقل من 75%
     */
    public function checkAttendanceThresholds($schoolId, $academicYearId, $semesterId) {
        $alertsCreated = 0;
        $passRate = $this->thresholds['attendance_pass'];
        
        try {
            $sql = "SELECT s.id AS student_id, s.full_name_ar,
                           ats.attendance_rate
                    FROM students s
                    INNER JOIN attendance_summaries ats ON ats.student_id = s.id 
                        AND ats.academic_year_id = :year_id 
                        AND ats.semester_id = :semester_id 
                        AND ats.is_active = 1
                    WHERE s.school_id = :school_id 
                        AND s.is_active = 1
                        AND ats.attendance_rate < :pass_rate";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':pass_rate' => $passRate
            ]);
            
            while ($row = $stmt->fetch()) {
                $severity = $this->determineAttendanceSeverity($row['attendance_rate']);
                $alertData = [
                    'school_id' => $schoolId,
                    'student_id' => $row['student_id'],
                    'alert_type' => 'attendance',
                    'severity' => $severity,
                    'title_ar' => 'حضور غير كافٍ',
                    'message_ar' => sprintf(
                        'الطالب/ة %s نسبة حضوره %.1f%% وهي أقل من الحد المطلوب (%d%%). إجمالي أيام الغياب: %d يوم.',
                        $row['full_name_ar'],
                        $row['attendance_rate'],
                        $passRate,
                        $row['attendance_rate']
                    ),
                ];
                
                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkAttendanceThresholds - ' . $e->getMessage());
        }
        
        return $alertsCreated;
    }
    
    /**
     * تحديد مستوى خطورة تنبيه الحضور
     */
    private function determineAttendanceSeverity($rate) {
        if ($rate < 40) return 'critical';
        if ($rate < 55) return 'danger';
        if ($rate < 65) return 'warning';
        return 'info';
    }
    
    // ========================================================================
    // المصدر الثاني: تنبيهات الاتجاه (Trend-based Alerts)
    // ========================================================================
    
    /**
     * فحص الاتجاهات التنازلية - تراجع الأداء خلال N فترات
     * @param int $studentId معرف الطالب
     * @param string $metricType نوع المقياس (academic, behavior, attendance)
     * @param int $periods عدد الفترات
     * @return bool هل يوجد تراجع
     */
    public function checkDecliningTrends($studentId, $metricType = 'academic', $periods = 3) {
        $isDeclining = false;
        
        try {
            $values = $this->getMetricHistory($studentId, $metricType, $periods + 1);
            
            if (count($values) >= $periods) {
                $recentValues = array_slice($values, 0, $periods);
                $allDeclining = true;
                
                for ($i = 0; $i < count($recentValues) - 1; $i++) {
                    if ($recentValues[$i] >= $recentValues[$i + 1]) {
                        $allDeclining = false;
                        break;
                    }
                }
                
                // بدلنا: نتحقق من القيم تنازلياً (الأحدث أولاً)
                // نريد أن القيم تتناقص: values[0] > values[1] > values[2]
                $allDeclining = true;
                for ($i = 0; $i < count($recentValues) - 1; $i++) {
                    if ($recentValues[$i] <= $recentValues[$i + 1]) {
                        $allDeclining = false;
                        break;
                    }
                }
                
                if ($allDeclining) {
                    $isDeclining = true;
                    $student = $this->getStudentInfo($studentId);
                    $metricLabel = $this->getMetricLabel($metricType);
                    
                    $alertData = [
                        'school_id' => $student['school_id'] ?? null,
                        'student_id' => $studentId,
                        'alert_type' => $metricType === 'academic' ? 'academic' : ($metricType === 'behavior' ? 'behavior' : 'attendance'),
                        'severity' => 'warning',
                        'title_ar' => sprintf('تراجع مستمر في %s', $metricLabel),
                        'message_ar' => sprintf(
                            'الطالب/ة %s يظهر تراجعاً مستمراً في %s خلال الفترات الثلاث الأخيرة: %s.',
                            $student['full_name_ar'] ?? 'غير معروف',
                            $metricLabel,
                            implode(' ← ', array_map(function($v) { return number_format($v, 1) . '%'; }, $recentValues))
                        ),
                    ];
                    
                    if (!$this->alertExists($alertData)) {
                        $this->createAlert($alertData);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkDecliningTrends - ' . $e->getMessage());
        }
        
        return $isDeclining;
    }
    
    /**
     * الحصول على تاريخ القيم لمقياس معين
     */
    private function getMetricHistory($studentId, $metricType, $limit = 4) {
        $values = [];
        
        try {
            switch ($metricType) {
                case 'academic':
                    $sql = "SELECT AVG(grade_value / max_value * 100) AS avg_score
                            FROM grades 
                            WHERE student_id = :student_id AND is_active = 1
                            GROUP BY semester_id
                            ORDER BY semester_id DESC
                            LIMIT :limit";
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
                    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    while ($row = $stmt->fetch()) {
                        $values[] = (float)$row['avg_score'];
                    }
                    break;
                    
                case 'behavior':
                    $sql = "SELECT current_percentage 
                            FROM behavior_scores 
                            WHERE student_id = :student_id AND is_active = 1
                            ORDER BY semester_id DESC
                            LIMIT :limit";
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
                    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    while ($row = $stmt->fetch()) {
                        $values[] = (float)$row['current_percentage'];
                    }
                    break;
                    
                case 'attendance':
                    $sql = "SELECT attendance_rate 
                            FROM attendance_summaries 
                            WHERE student_id = :student_id AND is_active = 1
                            ORDER BY semester_id DESC
                            LIMIT :limit";
                    $stmt = $this->db->prepare($sql);
                    $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
                    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                    $stmt->execute();
                    while ($row = $stmt->fetch()) {
                        $values[] = (float)$row['attendance_rate'];
                    }
                    break;
            }
        } catch (Exception $e) {
            error_log('AlertEngine::getMetricHistory - ' . $e->getMessage());
        }
        
        return $values;
    }
    
    /**
     * الحصول على تسمية المقياس بالعربية
     */
    private function getMetricLabel($metricType) {
        $labels = [
            'academic' => 'الأداء الأكاديمي',
            'behavior' => 'السلوك',
            'attendance' => 'الحضور',
        ];
        return $labels[$metricType] ?? $metricType;
    }
    
    // ========================================================================
    // المصدر الثالث: تنبيهات الأنماط (Pattern-based Alerts)
    // ========================================================================
    
    /**
     * فحص أنماط الغياب - غياب متتالي، أنماط أيام محددة
     */
    public function checkAbsencePatterns($studentId) {
        $alertsCreated = 0;
        $consecutiveDays = $this->thresholds['consecutive_absence_days'];
        
        try {
            $student = $this->getStudentInfo($studentId);
            if (!$student) return 0;
            
            // فحص الغياب المتتالي
            $sql = "SELECT date, status FROM attendance 
                    WHERE student_id = :student_id AND is_active = 1
                    ORDER BY date DESC
                    LIMIT 30";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':student_id' => $studentId]);
            $records = $stmt->fetchAll();
            
            // حساب الغياب المتتالي
            $consecutiveCount = 0;
            $maxConsecutive = 0;
            foreach ($records as $record) {
                if ($record['status'] === 'absent') {
                    $consecutiveCount++;
                    $maxConsecutive = max($maxConsecutive, $consecutiveCount);
                } else {
                    $consecutiveCount = 0;
                }
            }
            
            if ($maxConsecutive >= $consecutiveDays) {
                $alertData = [
                    'school_id' => $student['school_id'] ?? null,
                    'student_id' => $studentId,
                    'alert_type' => 'attendance',
                    'severity' => $maxConsecutive >= 5 ? 'danger' : 'warning',
                    'title_ar' => 'نمط غياب متتالي',
                    'message_ar' => sprintf(
                        'الطالب/ة %s تغيب %d يوم متتالي وهو ما يشير إلى نمط غياب مقلق.',
                        $student['full_name_ar'],
                        $maxConsecutive
                    ),
                ];
                
                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }
            
            // فحص نمط الغياب في يوم محدد من الأسبوع
            $sql = "SELECT DAYOFWEEK(date) AS day_of_week, COUNT(*) AS absence_count
                    FROM attendance 
                    WHERE student_id = :student_id AND status = 'absent' AND is_active = 1
                    GROUP BY DAYOFWEEK(date)
                    HAVING absence_count >= 3
                    ORDER BY absence_count DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':student_id' => $studentId]);
            
            $dayNames = [1 => 'الأحد', 2 => 'الإثنين', 3 => 'الثلاثاء', 4 => 'الأربعاء', 5 => 'الخميس', 6 => 'الجمعة', 7 => 'السبت'];
            
            while ($row = $stmt->fetch()) {
                $dayName = $dayNames[$row['day_of_week']] ?? 'غير معروف';
                $alertData = [
                    'school_id' => $student['school_id'] ?? null,
                    'student_id' => $studentId,
                    'alert_type' => 'attendance',
                    'severity' => 'info',
                    'title_ar' => 'نمط غياب يومي',
                    'message_ar' => sprintf(
                        'الطالب/ة %s يغيب بشكل متكرر يوم %s (%d مرة). قد يكون هناك سبب محدد.',
                        $student['full_name_ar'],
                        $dayName,
                        $row['absence_count']
                    ),
                ];
                
                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkAbsencePatterns - ' . $e->getMessage());
        }
        
        return $alertsCreated;
    }
    
    /**
     * فحص الانخفاض المفاجئ - انخفاض كبير في الأداء
     */
    public function checkSuddenDrops($studentId, $metricType = 'academic') {
        $dropDetected = false;
        $dropThreshold = $this->thresholds['sudden_drop_percentage'];
        
        try {
            $values = $this->getMetricHistory($studentId, $metricType, 2);
            
            if (count($values) >= 2) {
                $currentValue = $values[0];
                $previousValue = $values[1];
                
                if ($previousValue > 0) {
                    $dropPercentage = (($previousValue - $currentValue) / $previousValue) * 100;
                    
                    if ($dropPercentage >= $dropThreshold) {
                        $dropDetected = true;
                        $student = $this->getStudentInfo($studentId);
                        $metricLabel = $this->getMetricLabel($metricType);
                        
                        $alertData = [
                            'school_id' => $student['school_id'] ?? null,
                            'student_id' => $studentId,
                            'alert_type' => $metricType === 'academic' ? 'academic' : ($metricType === 'behavior' ? 'behavior' : 'attendance'),
                            'severity' => $dropPercentage >= 30 ? 'danger' : 'warning',
                            'title_ar' => sprintf('انخفاض مفاجئ في %s', $metricLabel),
                            'message_ar' => sprintf(
                                'الطالب/ة %s انخفض %s بنسبة %.1f%% من %.1f%% إلى %.1f%% مقارنة بالفترة السابقة.',
                                $student['full_name_ar'] ?? 'غير معروف',
                                $metricLabel,
                                $dropPercentage,
                                $previousValue,
                                $currentValue
                            ),
                        ];
                        
                        if (!$this->alertExists($alertData)) {
                            $this->createAlert($alertData);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkSuddenDrops - ' . $e->getMessage());
        }
        
        return $dropDetected;
    }
    
    // ========================================================================
    // إدارة التنبيهات
    // ========================================================================
    
    /**
     * إنشاء تنبيه جديد
     */
    public function createAlert($data) {
        try {
            $sql = "INSERT INTO alerts (school_id, student_id, alert_type, severity, title_ar, message_ar, is_read, is_resolved)
                    VALUES (:school_id, :student_id, :alert_type, :severity, :title_ar, :message_ar, 0, 0)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $data['school_id'] ?? null,
                ':student_id' => $data['student_id'] ?? null,
                ':alert_type' => $data['alert_type'],
                ':severity' => $data['severity'],
                ':title_ar' => $data['title_ar'],
                ':message_ar' => $data['message_ar'],
            ]);
            
            $alertId = $this->db->lastInsertId();
            
            // إنشاء إشعارات للمستخدمين المعنيين
            $this->createNotificationsForAlert($alertId, $data);
            
            return $alertId;
        } catch (Exception $e) {
            error_log('AlertEngine::createAlert - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * إنشاء إشعارات للمستخدمين المعنيين بتنبيه
     */
    private function createNotificationsForAlert($alertId, $alertData) {
        try {
            $userIds = [];
            
            // مدير المدرسة
            if (!empty($alertData['school_id'])) {
                $stmt = $this->db->prepare("SELECT id FROM users WHERE school_id = :school_id AND is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'school_admin')");
                $stmt->execute([':school_id' => $alertData['school_id']]);
                while ($row = $stmt->fetch()) {
                    $userIds[] = $row['id'];
                }
            }
            
            // مدير النظام
            $stmt = $this->db->prepare("SELECT id FROM users WHERE is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'group_admin')");
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                $userIds[] = $row['id'];
            }
            
            // ولي أمر الطالب
            if (!empty($alertData['student_id'])) {
                $stmt = $this->db->prepare("SELECT u.id FROM users u INNER JOIN parent_students ps ON ps.parent_id = u.id WHERE ps.student_id = :student_id AND ps.is_active = 1 AND u.is_active = 1");
                $stmt->execute([':student_id' => $alertData['student_id']]);
                while ($row = $stmt->fetch()) {
                    $userIds[] = $row['id'];
                }
            }
            
            // إزالة التكرار
            $userIds = array_unique($userIds);
            
            // إنشاء الإشعارات
            $sql = "INSERT INTO notifications (user_id, type, title_ar, message_ar, related_entity_type, related_entity_id, is_read)
                    VALUES (:user_id, 'alert', :title, :message, 'alert', :alert_id, 0)";
            $stmt = $this->db->prepare($sql);
            
            foreach ($userIds as $userId) {
                $stmt->execute([
                    ':user_id' => $userId,
                    ':title' => $alertData['title_ar'],
                    ':message' => $alertData['message_ar'],
                    ':alert_id' => $alertId,
                ]);
            }
        } catch (Exception $e) {
            error_log('AlertEngine::createNotificationsForAlert - ' . $e->getMessage());
        }
    }
    
    /**
     * التحقق من وجود تنبيه مماثل (منع التكرار)
     */
    private function alertExists($data) {
        try {
            $sql = "SELECT COUNT(*) FROM alerts 
                    WHERE school_id = :school_id 
                        AND student_id = :student_id 
                        AND alert_type = :alert_type 
                        AND title_ar = :title_ar 
                        AND is_resolved = 0
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $data['school_id'] ?? null,
                ':student_id' => $data['student_id'] ?? null,
                ':alert_type' => $data['alert_type'],
                ':title_ar' => $data['title_ar'],
            ]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * الحصول على التنبيهات مع فلاتر
     */
    public function getAlerts($filters = []) {
        $where = [];
        $params = [];
        
        if (!empty($filters['school_id'])) {
            $where[] = 'a.school_id = :school_id';
            $params[':school_id'] = $filters['school_id'];
        }
        if (!empty($filters['student_id'])) {
            $where[] = 'a.student_id = :student_id';
            $params[':student_id'] = $filters['student_id'];
        }
        if (!empty($filters['alert_type'])) {
            $where[] = 'a.alert_type = :alert_type';
            $params[':alert_type'] = $filters['alert_type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'a.severity = :severity';
            $params[':severity'] = $filters['severity'];
        }
        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $where[] = 'a.is_read = :is_read';
            $params[':is_read'] = (int)$filters['is_read'];
        }
        if (isset($filters['is_resolved']) && $filters['is_resolved'] !== '') {
            $where[] = 'a.is_resolved = :is_resolved';
            $params[':is_resolved'] = (int)$filters['is_resolved'];
        }
        
        $whereClause = $where ? implode(' AND ', $where) : '1';
        
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int)($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        
        $orderBy = in_array($filters['sort'] ?? '', ['created_at', 'severity', 'alert_type']) 
            ? $filters['sort'] : 'created_at';
        $orderDir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        
        // ترتيب خاص للخطورة
        if ($orderBy === 'severity') {
            $orderBy = "FIELD(a.severity, 'critical', 'danger', 'warning', 'info')";
            $orderDir = '';
        }
        
        $sql = "SELECT a.*, s.full_name_ar AS student_name, sc.name_ar AS school_name
                FROM alerts a
                LEFT JOIN students s ON s.id = a.student_id
                LEFT JOIN schools sc ON sc.id = a.school_id
                WHERE {$whereClause}
                ORDER BY {$orderBy} {$orderDir}
                LIMIT {$perPage} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * الحصول على عدد التنبيهات
     */
    public function getAlertsCount($filters = []) {
        $where = [];
        $params = [];
        
        if (!empty($filters['school_id'])) {
            $where[] = 'school_id = :school_id';
            $params[':school_id'] = $filters['school_id'];
        }
        if (!empty($filters['alert_type'])) {
            $where[] = 'alert_type = :alert_type';
            $params[':alert_type'] = $filters['alert_type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = :severity';
            $params[':severity'] = $filters['severity'];
        }
        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $where[] = 'is_read = :is_read';
            $params[':is_read'] = (int)$filters['is_read'];
        }
        if (isset($filters['is_resolved']) && $filters['is_resolved'] !== '') {
            $where[] = 'is_resolved = :is_resolved';
            $params[':is_resolved'] = (int)$filters['is_resolved'];
        }
        
        $whereClause = $where ? implode(' AND ', $where) : '1';
        $sql = "SELECT COUNT(*) FROM alerts WHERE {$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * الحصول على تنبيه واحد
     */
    public function getAlert($alertId) {
        try {
            $sql = "SELECT a.*, s.full_name_ar AS student_name, s.student_number,
                           sc.name_ar AS school_name,
                           u.full_name_ar AS resolver_name
                    FROM alerts a
                    LEFT JOIN students s ON s.id = a.student_id
                    LEFT JOIN schools sc ON sc.id = a.school_id
                    LEFT JOIN users u ON u.id = a.resolved_by
                    WHERE a.id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $alertId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * تحديد التنبيه كمقروء
     */
    public function markAsRead($alertId) {
        try {
            $stmt = $this->db->prepare("UPDATE alerts SET is_read = 1 WHERE id = :id AND is_read = 0");
            return $stmt->execute([':id' => $alertId]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * تحديد عدة تنبيهات كمقروءة
     */
    public function markMultipleAsRead($alertIds) {
        try {
            if (empty($alertIds)) return 0;
            $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
            $stmt = $this->db->prepare("UPDATE alerts SET is_read = 1 WHERE id IN ({$placeholders}) AND is_read = 0");
            $stmt->execute($alertIds);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * حل تنبيه مع ملاحظات
     */
    public function resolveAlert($alertId, $resolvedBy, $notes = '') {
        try {
            $stmt = $this->db->prepare("UPDATE alerts SET is_resolved = 1, resolved_by = :resolved_by, resolved_at = NOW(), notes = :notes WHERE id = :id AND is_resolved = 0");
            return $stmt->execute([
                ':id' => $alertId,
                ':resolved_by' => $resolvedBy,
                ':notes' => $notes,
            ]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * حل عدة تنبيهات
     */
    public function resolveMultiple($alertIds, $resolvedBy) {
        try {
            if (empty($alertIds)) return 0;
            $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
            $params = array_merge($alertIds, [$resolvedBy]);
            $stmt = $this->db->prepare("UPDATE alerts SET is_resolved = 1, resolved_by = ?, resolved_at = NOW() WHERE id IN ({$placeholders}) AND is_resolved = 0");
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * الحصول على عدد التنبيهات غير المقروءة لمستخدم
     */
    public function getUnreadCount($userId = null) {
        try {
            // الحصول على المدرسة المرتبطة بالمستخدم
            $schoolFilter = '';
            $params = [];
            
            if ($userId) {
                $stmt = $this->db->prepare("SELECT school_id, role_id FROM users WHERE id = :id");
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $roleStmt = $this->db->prepare("SELECT name FROM roles WHERE id = :id");
                    $roleStmt->execute([':id' => $user['role_id']]);
                    $role = $roleStmt->fetchColumn();
                    
                    if ($role === 'school_admin' && $user['school_id']) {
                        $schoolFilter = ' AND school_id = ' . (int)$user['school_id'];
                    } elseif ($role === 'teacher' && $user['school_id']) {
                        $schoolFilter = ' AND school_id = ' . (int)$user['school_id'];
                    }
                }
            }
            
            $sql = "SELECT COUNT(*) FROM alerts WHERE is_read = 0 AND is_resolved = 0 {$schoolFilter}";
            $stmt = $this->db->query($sql);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * الحصول على إحصائيات التنبيهات
     */
    public function getAlertStats($schoolId = null) {
        $stats = [
            'total' => 0,
            'unread' => 0,
            'unresolved' => 0,
            'by_type' => [],
            'by_severity' => [],
        ];
        
        try {
            $schoolFilter = $schoolId ? " AND school_id = " . (int)$schoolId : '';
            
            // الإجمالي
            $stmt = $this->db->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1 {$schoolFilter}");
            $stats['total'] = (int)$stmt->fetchColumn();
            
            // غير المقروءة
            $stmt = $this->db->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1 AND is_read = 0 {$schoolFilter}");
            $stats['unread'] = (int)$stmt->fetchColumn();
            
            // غير المحلولة
            $stmt = $this->db->query("SELECT COUNT(*) FROM alerts WHERE is_active = 1 AND is_resolved = 0 {$schoolFilter}");
            $stats['unresolved'] = (int)$stmt->fetchColumn();
            
            // حسب النوع
            $sql = "SELECT alert_type, COUNT(*) AS cnt FROM alerts WHERE is_active = 1 {$schoolFilter} GROUP BY alert_type";
            $stmt = $this->db->query($sql);
            while ($row = $stmt->fetch()) {
                $stats['by_type'][$row['alert_type']] = (int)$row['cnt'];
            }
            
            // حسب الخطورة
            $sql = "SELECT severity, COUNT(*) AS cnt FROM alerts WHERE is_active = 1 {$schoolFilter} GROUP BY severity";
            $stmt = $this->db->query($sql);
            while ($row = $stmt->fetch()) {
                $stats['by_severity'][$row['severity']] = (int)$row['cnt'];
            }
        } catch (Exception $e) {
            error_log('AlertEngine::getAlertStats - ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * الحصول على التنبيهات المرتبطة بطالب
     */
    public function getRelatedAlerts($studentId, $excludeId = null, $limit = 5) {
        try {
            $sql = "SELECT a.*, sc.name_ar AS school_name
                    FROM alerts a
                    LEFT JOIN schools sc ON sc.id = a.school_id
                    WHERE a.student_id = :student_id AND a.is_active = 1";
            $params = [':student_id' => $studentId];
            
            if ($excludeId) {
                $sql .= " AND a.id != :exclude_id";
                $params[':exclude_id'] = $excludeId;
            }
            
            $sql .= " ORDER BY a.created_at DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':student_id', $studentId, PDO::PARAM_INT);
            if ($excludeId) {
                $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * الحصول على معلومات الطالب
     */
    private function getStudentInfo($studentId) {
        try {
            $stmt = $this->db->prepare("SELECT id, full_name_ar, school_id, student_number FROM students WHERE id = :id AND is_active = 1");
            $stmt->execute([':id' => $studentId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
    
    // ========================================================================
    // تشغيل جميع الفحوصات
    // ========================================================================
    
    /**
     * تشغيل جميع فحوصات التنبيهات لمدرسة وفصل دراسي
     * يُستدعى بعد تحديث بيانات التقييم
     */
    public function runAllChecks($schoolId, $academicYearId, $semesterId) {
        $results = [
            'academic_thresholds' => 0,
            'behavior_thresholds' => 0,
            'attendance_thresholds' => 0,
            'declining_trends' => 0,
            'absence_patterns' => 0,
            'sudden_drops' => 0,
            'total_new' => 0,
        ];
        
        try {
            // 1. فحص الحدود
            $results['academic_thresholds'] = $this->checkAcademicThresholds($schoolId, $academicYearId, $semesterId);
            $results['behavior_thresholds'] = $this->checkBehaviorThresholds($schoolId, $academicYearId, $semesterId);
            $results['attendance_thresholds'] = $this->checkAttendanceThresholds($schoolId, $academicYearId, $semesterId);
            
            // 2. فحص الاتجاهات والأنماط لكل الطلاب
            $stmt = $this->db->prepare("SELECT id FROM students WHERE school_id = :school_id AND is_active = 1");
            $stmt->execute([':school_id' => $schoolId]);
            $students = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($students as $studentId) {
                // فحص الاتجاهات التنازلية
                if ($this->checkDecliningTrends($studentId, 'academic')) $results['declining_trends']++;
                if ($this->checkDecliningTrends($studentId, 'behavior')) $results['declining_trends']++;
                if ($this->checkDecliningTrends($studentId, 'attendance')) $results['declining_trends']++;
                
                // فحص أنماط الغياب
                $results['absence_patterns'] += $this->checkAbsencePatterns($studentId);
                
                // فحص الانخفاض المفاجئ
                if ($this->checkSuddenDrops($studentId, 'academic')) $results['sudden_drops']++;
                if ($this->checkSuddenDrops($studentId, 'behavior')) $results['sudden_drops']++;
            }
            
            $results['total_new'] = $results['academic_thresholds'] + $results['behavior_thresholds'] 
                + $results['attendance_thresholds'] + $results['declining_trends'] 
                + $results['absence_patterns'] + $results['sudden_drops'];
                
        } catch (Exception $e) {
            error_log('AlertEngine::runAllChecks - ' . $e->getMessage());
        }
        
        return $results;
    }
    
    // ========================================================================
    // إعدادات التنبيهات
    // ========================================================================
    
    /**
     * الحصول على إعدادات التنبيهات
     */
    public function getSettings() {
        return $this->thresholds;
    }
    
    /**
     * تحديث إعدادات التنبيهات
     */
    public function updateSettings($settings) {
        try {
            foreach ($settings as $key => $value) {
                if (isset($this->thresholds[$key])) {
                    $this->thresholds[$key] = (float)$value;
                    
                    $stmt = $this->db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type) 
                                                VALUES (:key, :value, 'number')
                                                ON DUPLICATE KEY UPDATE setting_value = :value2");
                    $stmt->execute([
                        ':key' => 'alert_threshold_' . $key,
                        ':value' => $value,
                        ':value2' => $value,
                    ]);
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('AlertEngine::updateSettings - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * تصدير التنبيهات كتقرير
     */
    public function exportAlerts($filters = [], $format = 'csv') {
        $alerts = $this->getAlerts(array_merge($filters, ['per_page' => 1000]));
        
        if ($format === 'csv') {
            return $this->exportToCSV($alerts);
        }
        
        return $alerts;
    }
    
    /**
     * تصدير إلى CSV
     */
    private function exportToCSV($alerts) {
        $output = fopen('php://temp', 'r+');
        
        // BOM for UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // العناوين
        fputcsv($output, [
            'رقم التنبيه', 'المدرسة', 'الطالب', 'النوع', 'الخطورة', 
            'العنوان', 'الرسالة', 'مقروء', 'محلول', 'التاريخ'
        ]);
        
        foreach ($alerts as $alert) {
            fputcsv($output, [
                $alert['id'],
                $alert['school_name'] ?? '',
                $alert['student_name'] ?? '',
                $this->alertTypeLabels[$alert['alert_type']] ?? $alert['alert_type'],
                $this->severityLabels[$alert['severity']] ?? $alert['severity'],
                $alert['title_ar'],
                $alert['message_ar'],
                $alert['is_read'] ? 'نعم' : 'لا',
                $alert['is_resolved'] ? 'نعم' : 'لا',
                $alert['created_at'],
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}
