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
        'academic_pass' => ACADEMIC_PASS_RATE,          // 50%
        'behavior_pass' => BEHAVIOR_PASS_RATE,          // 60%
        'behavior_alert' => 50,                          // حد التحذير السلوكي (قبل الرسوب)
        'attendance_pass' => ATTENDANCE_PASS_RATE,      // 75%
        'sudden_drop_percentage' => 20,                  // انخفاض مفاجئ 20%
        'consecutive_absence_days' => 3,                 // غياب متتالي 3 أيام
    ];
    
    // أنواع التنبيهات بالعربية
    private $alertTypeLabels = [
        'academic'  => 'أكاديمي',
        'behavior'  => 'سلوكي',
        'attendance'=> 'حضور',
        'eqi'       => 'مؤشر الجودة',
        'promotion' => 'ترفيع',
        'climate'   => 'المناخ المدرسي',
        'teaching'  => 'تقييم المعلمين',
        'improvement_plan' => 'خطط التحسين',
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
            $sql = "SELECT e.student_id, s.full_name_ar,
                           e.current_percentage AS behavior_score
                    FROM evaluations e
                    INNER JOIN students s ON s.id = e.student_id
                    WHERE e.eval_type = 'behavior_score'
                        AND e.school_id = :school_id
                        AND e.academic_year_id = :year_id 
                        AND e.semester_id = :semester_id 
                        AND e.is_active = 1
                        AND e.current_percentage < :pass_rate";
            
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
                           v.attendance_rate
                    FROM v_attendance_summary v
                    INNER JOIN students s ON s.id = v.student_id
                    WHERE s.school_id = :school_id
                        AND v.academic_year_id = :year_id 
                        AND v.semester_id = :semester_id 
                        AND v.attendance_rate < :pass_rate";
            
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
                        'الطالب/ة %s نسبة حضوره %.1f%% وهي أقل من الحد المطلوب (%d%%).',
                        $row['full_name_ar'],
                        $row['attendance_rate'],
                        $passRate
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
    
    /**
     * فحص حدود المناخ المدرسي - متوسط مناخ أقل من 50% أو مشاركة أقل من 60%
     */
    public function checkClimateThresholds($schoolId, $academicYearId, $semesterId) {
        $alertsCreated = 0;

        try {
            $stmt = $this->db->prepare("SELECT AVG(overall_score) as avg_score, COUNT(id) as survey_count FROM surveys WHERE survey_type = 'climate' AND school_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1");
            $stmt->execute([$schoolId, $academicYearId, $semesterId]);
            $row = $stmt->fetch();
            $avgScore = $row['avg_score'] !== null ? round((float)$row['avg_score'], 1) : null;
            $surveyCount = (int)$row['survey_count'];

            $tStmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND is_active = 1 AND role_id IN (SELECT id FROM roles WHERE name = 'teacher')");
            $tStmt->execute([$schoolId]);
            $expected = (int)$tStmt->fetchColumn();
            $sStmt = $this->db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND is_active = 1");
            $sStmt->execute([$schoolId]);
            $expected += (int)$sStmt->fetchColumn();

            $participationRate = $expected > 0 ? round(($surveyCount / $expected) * 100, 1) : 0;
            if ($participationRate > 100) $participationRate = 100;

            $schoolStmt = $this->db->prepare("SELECT name_ar FROM schools WHERE id = ?");
            $schoolStmt->execute([$schoolId]);
            $schoolName = $schoolStmt->fetchColumn();

            // القاعدة 1: مناخ منخفض
            if ($avgScore !== null && $avgScore < 50) {
                $severity = $avgScore < 30 ? 'critical' : 'danger';
                $alertData = [
                    'school_id' => $schoolId,
                    'student_id' => null,
                    'alert_type' => 'climate',
                    'severity' => $severity,
                    'title_ar' => 'مناخ مدرسي منخفض',
                    'message_ar' => sprintf(
                        '🚨 المناخ المدرسي في %s منخفض جداً (%.1f%%). مطلوب تدخل عاجل.',
                        $schoolName,
                        $avgScore
                    ),
                ];

                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }

            // القاعدة 2: مشاركة منخفضة
            if ($participationRate < 60 && $surveyCount > 0) {
                $sev = $participationRate < 40 ? 'danger' : 'warning';
                $alertData2 = [
                    'school_id' => $schoolId,
                    'student_id' => null,
                    'alert_type' => 'climate',
                    'severity' => $sev,
                    'title_ar' => 'مشاركة ضعيفة في استبيان المناخ',
                    'message_ar' => sprintf(
                        '⚠️ نسبة المشاركة في استبيان المناخ في %s منخفضة (%.1f%%).',
                        $schoolName,
                        $participationRate
                    ),
                ];

                if (!$this->alertExists($alertData2)) {
                    $this->createAlert($alertData2);
                    $alertsCreated++;
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkClimateThresholds - ' . $e->getMessage());
        }

        return $alertsCreated;
    }

    /**
     * فحص حدود مؤشر جودة التعليم (EQI)
     * القاعدة 1: EQI منخفض — أقل من 50 نقطة
     * القاعدة 2: تراجع حاد — انخفاض > 15% عن نفس الفصل من العام السابق
     * القاعدة 3: تحسين كبير — زيادة > 10 نقاط عن الفصل السابق
     */
    public function checkEQIThresholds($schoolId, $academicYearId, $semesterId) {
        $alertsCreated = 0;

        try {
            // الحصول على EQI الحالي للمدرسة
            $stmt = $this->db->prepare("
                SELECT overall_eqi FROM eqi_results
                WHERE school_id = :school_id
                  AND academic_year_id = :year_id
                  AND semester_id = :semester_id
                  AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
            ]);
            $currentEqi = $stmt->fetchColumn();

            if ($currentEqi === null || $currentEqi === false) {
                return 0;
            }

            $currentEqi = (float)$currentEqi;

            $stmt = $this->db->prepare("SELECT name_ar FROM schools WHERE id = ?");
            $stmt->execute([$schoolId]);
            $schoolName = $stmt->fetchColumn();

            // القاعدة 1: EQI منخفض — أقل من 50 نقطة
            if ($currentEqi < 50) {
                $severity = $currentEqi < 30 ? 'critical' : 'danger';
                $alertData = [
                    'school_id' => $schoolId,
                    'student_id' => null,
                    'alert_type' => 'eqi',
                    'severity' => $severity,
                    'title_ar' => 'مؤشر جودة منخفض',
                    'message_ar' => sprintf(
                        '🚨 مؤشر جودة التعليم في %s منخفض جداً (%.1f نقطة). مطلوب تدخل عاجل لتحسين الأداء.',
                        $schoolName,
                        $currentEqi
                    ),
                ];

                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }

            // القاعدة 2: تراجع حاد — نسبة الانخفاض > 15% عن نفس الفصل من العام السابق
            $semStmt = $this->db->prepare("SELECT name_ar, start_date FROM semesters WHERE id = ?");
            $semStmt->execute([$semesterId]);
            $semInfo = $semStmt->fetch();

            if ($semInfo) {
                $prevStmt = $this->db->prepare("
                    SELECT eq.overall_eqi
                    FROM eqi_results eq
                    JOIN semesters s ON eq.semester_id = s.id
                    WHERE eq.school_id = :school_id
                      AND s.name_ar = :name_ar
                      AND s.start_date < :start_date
                      AND s.is_active = 1
                      AND eq.is_active = 1
                    ORDER BY s.start_date DESC
                    LIMIT 1
                ");
                $prevStmt->execute([
                    ':school_id' => $schoolId,
                    ':name_ar' => $semInfo['name_ar'],
                    ':start_date' => $semInfo['start_date'],
                ]);
                $prevEqi = $prevStmt->fetchColumn();

                if ($prevEqi !== null && $prevEqi !== false && (float)$prevEqi > 0) {
                    $prevEqi = (float)$prevEqi;
                    $declinePct = (($prevEqi - $currentEqi) / $prevEqi) * 100;

                    if ($declinePct > 15) {
                        $alertData = [
                            'school_id' => $schoolId,
                            'student_id' => null,
                            'alert_type' => 'eqi',
                            'severity' => 'danger',
                            'title_ar' => 'تراجع حاد في مؤشر الجودة',
                            'message_ar' => sprintf(
                                '⚠️ تراجع حاد في مؤشر جودة التعليم في %s بنسبة %.1f%% (من %.1f إلى %.1f نقطة).',
                                $schoolName,
                                $declinePct,
                                $prevEqi,
                                $currentEqi
                            ),
                        ];

                        if (!$this->alertExists($alertData)) {
                            $this->createAlert($alertData);
                            $alertsCreated++;
                        }
                    }
                }

                // القاعدة 3: تحسين كبير — زيادة > 10 نقاط عن الفصل السابق
                // نبحث عن أقرب فصل سابق (أي فصل، وليس بالضرورة نفس الاسم)
                $prevAnyStmt = $this->db->prepare("
                    SELECT eq.overall_eqi
                    FROM eqi_results eq
                    JOIN semesters s ON eq.semester_id = s.id
                    WHERE eq.school_id = :school_id
                      AND s.start_date < :start_date
                      AND s.is_active = 1
                      AND eq.is_active = 1
                    ORDER BY s.start_date DESC
                    LIMIT 1
                ");
                $prevAnyStmt->execute([
                    ':school_id' => $schoolId,
                    ':start_date' => $semInfo['start_date'],
                ]);
                $prevAnyEqi = $prevAnyStmt->fetchColumn();

                if ($prevAnyEqi !== null && $prevAnyEqi !== false && (float)$prevAnyEqi > 0) {
                    $prevAnyEqi = (float)$prevAnyEqi;
                    $improvement = $currentEqi - $prevAnyEqi;

                    if ($improvement > 10) {
                        $alertData = [
                            'school_id' => $schoolId,
                            'student_id' => null,
                            'alert_type' => 'eqi',
                            'severity' => 'info',
                            'title_ar' => 'تحسين كبير في مؤشر الجودة',
                            'message_ar' => sprintf(
                                '✅ تحسن كبير في مؤشر جودة التعليم في %s بمقدار %.1f نقطة (من %.1f إلى %.1f).',
                                $schoolName,
                                $improvement,
                                $prevAnyEqi,
                                $currentEqi
                            ),
                        ];

                        if (!$this->alertExists($alertData)) {
                            $this->createAlert($alertData);
                            $alertsCreated++;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkEQIThresholds - ' . $e->getMessage());
        }

        return $alertsCreated;
    }

    /**
     * فحص حدود تقييم المعلمين
     * القاعدة 1: متوسط combined_score للمدرسة أقل من 50%
     * القاعدة 2: معلمون تقييمهم < 60% ولا توجد خطة تطوير نشطة
     */
    public function checkTeachingThresholds($schoolId, $academicYearId, $semesterId) {
        $alertsCreated = 0;

        try {
            // القاعدة 1: متوسط combined_score للمدرسة < 50%
            $stmt = $this->db->prepare("
                SELECT AVG(e.combined_score) AS school_avg
                FROM evaluations e
                WHERE e.eval_type = 'teaching_summary'
                  AND e.school_id = :school_id
                  AND e.academic_year_id = :year_id
                  AND e.semester_id = :semester_id
                  AND e.is_active = 1
            ");
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
            ]);
            $schoolAvg = $stmt->fetchColumn();

            if ($schoolAvg !== null && $schoolAvg < 50) {
                $alertData = [
                    'school_id' => $schoolId,
                    'alert_type' => 'teaching',
                    'severity' => 'danger',
                    'title_ar' => 'تراجع أداء التدريس',
                    'message_ar' => sprintf(
                        'متوسط تقييم أداء المعلمين في المدرسة %.1f%% وهو أقل من 50%%.',
                        round($schoolAvg, 1)
                    ),
                ];

                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }

            // القاعدة 2: معلمون تقييمهم < 60% بدون خطة تطوير نشطة
            $stmt = $this->db->prepare("
                SELECT e.teacher_id, u.full_name_ar, e.combined_score
                FROM evaluations e
                JOIN users u ON e.teacher_id = u.id AND u.school_id = :school_id
                LEFT JOIN improvement_plans ip
                    ON ip.teacher_id = e.teacher_id
                    AND ip.plan_type = 'teacher'
                    AND ip.status = 'active'
                    AND ip.is_active = 1
                WHERE e.eval_type = 'teaching_summary'
                  AND e.school_id = :school_id
                  AND e.academic_year_id = :year_id
                  AND e.semester_id = :semester_id
                  AND e.is_active = 1
                  AND e.combined_score < 60
                  AND ip.id IS NULL
            ");
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
            ]);

            while ($row = $stmt->fetch()) {
                $alertData = [
                    'school_id' => $schoolId,
                    'teacher_id' => $row['teacher_id'],
                    'alert_type' => 'teaching',
                    'severity' => 'warning',
                    'title_ar' => 'خطة تطوير مفقودة',
                    'message_ar' => sprintf(
                        'المعلم/ة %s تقييمه %.1f%% (أقل من 60%%) وليس لديه خطة تطوير نشطة.',
                        $row['full_name_ar'],
                        $row['combined_score']
                    ),
                ];

                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkTeachingThresholds - ' . $e->getMessage());
        }

        return $alertsCreated;
    }

    /**
     * فحص خطط التحسين المتأخرة (تجاوزت موعد المتابعة)
     * القاعدة 1: خطة معلم متأخرة عن موعد المتابعة
     * القاعدة 2: خطة طالب متأخرة عن موعد المتابعة
     */
    public function checkImprovementPlanOverdue($schoolId) {
        $alertsCreated = 0;

        try {
            $schoolStmt = $this->db->prepare("SELECT name_ar FROM schools WHERE id = ?");
            $schoolStmt->execute([$schoolId]);
            $schoolName = $schoolStmt->fetchColumn();

            // القاعدة 1: خطط معلمين متأخرة
            $stmt = $this->db->prepare("
                SELECT ip.id, ip.follow_up_date, ip.progress,
                       u.full_name_ar AS teacher_name,
                       DATEDIFF(NOW(), ip.follow_up_date) AS days_overdue
                FROM improvement_plans ip
                JOIN users u ON ip.teacher_id = u.id
                WHERE ip.plan_type = 'teacher'
                  AND ip.school_id = :school_id
                  AND ip.status = 'active'
                  AND ip.is_active = 1
                  AND ip.follow_up_date IS NOT NULL
                  AND ip.follow_up_date < CURDATE()
                ORDER BY ip.follow_up_date ASC
            ");
            $stmt->execute([':school_id' => $schoolId]);

            while ($row = $stmt->fetch()) {
                $severity = $row['days_overdue'] > 14 ? 'danger' : 'warning';
                $alertData = [
                    'school_id' => $schoolId,
                    'alert_type' => 'improvement_plan',
                    'severity' => $severity,
                    'title_ar' => 'خطة تطوير معلم متأخرة',
                    'message_ar' => sprintf(
                        '⏰ خطة تطوير المعلم/ة %s في %s متأخرة بـ %d يوم (موعد المتابعة: %s). التقدم: %d%%.',
                        $row['teacher_name'],
                        $schoolName,
                        $row['days_overdue'],
                        $row['follow_up_date'],
                        $row['progress']
                    ),
                ];

                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }

            // القاعدة 2: خطط طلاب متأخرة
            $stmt = $this->db->prepare("
                SELECT ip.id, ip.follow_up_date, ip.status AS plan_status,
                       s.full_name_ar AS student_name,
                       DATEDIFF(NOW(), ip.follow_up_date) AS days_overdue
                FROM improvement_plans ip
                JOIN students s ON ip.student_id = s.id
                WHERE ip.plan_type = 'student'
                  AND s.school_id = :school_id
                  AND ip.status = 'active'
                  AND ip.is_active = 1
                  AND ip.follow_up_date IS NOT NULL
                  AND ip.follow_up_date < CURDATE()
                ORDER BY ip.follow_up_date ASC
            ");
            $stmt->execute([':school_id' => $schoolId]);

            while ($row = $stmt->fetch()) {
                $severity = $row['days_overdue'] > 14 ? 'danger' : 'warning';
                $alertData = [
                    'school_id' => $schoolId,
                    'alert_type' => 'improvement_plan',
                    'severity' => $severity,
                    'title_ar' => 'خطة تحسين طالب متأخرة',
                    'message_ar' => sprintf(
                        '⏰ خطة تحسين الطالب/ة %s في %s متأخرة بـ %d يوم (موعد المتابعة: %s).',
                        $row['student_name'],
                        $schoolName,
                        $row['days_overdue'],
                        $row['follow_up_date']
                    ),
                ];

                if (!$this->alertExists($alertData)) {
                    $this->createAlert($alertData);
                    $alertsCreated++;
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkImprovementPlanOverdue - ' . $e->getMessage());
        }

        return $alertsCreated;
    }

    /**
     * فحص خطط تحسين المدرسة التي تحتاج إنشاء (المناخ أو المرافق < 55%)
     */
    public function checkSchoolImprovementNeeded($schoolId, $academicYearId, $semesterId) {
        $alertsCreated = 0;

        try {
            $stmt = $this->db->prepare("SELECT AVG(overall_score) as avg_score FROM surveys WHERE survey_type = 'climate' AND school_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1");
            $stmt->execute([$schoolId, $academicYearId, $semesterId]);
            $climateScore = $stmt->fetchColumn();

            $schoolStmt = $this->db->prepare("SELECT name_ar FROM schools WHERE id = ?");
            $schoolStmt->execute([$schoolId]);
            $schoolName = $schoolStmt->fetchColumn();

            if ($climateScore !== null && $climateScore < 55) {
                $hasPlan = $this->db->prepare("SELECT COUNT(*) FROM school_improvement_plans WHERE school_id = ? AND status = 'active' AND is_active = 1");
                $hasPlan->execute([$schoolId]);
                if ((int)$hasPlan->fetchColumn() === 0) {
                    $alertData = [
                        'school_id' => $schoolId,
                        'alert_type' => 'improvement_plan',
                        'severity' => 'danger',
                        'title_ar' => 'مطلوب خطة تحسين مدرسة',
                        'message_ar' => sprintf(
                            '🚨 المناخ المدرسي في %s منخفض (%.1f%%) ولا توجد خطة تحسين نشطة. يُرجى إنشاء خطة تحسين فوراً.',
                            $schoolName,
                            $climateScore
                        ),
                    ];

                    if (!$this->alertExists($alertData)) {
                        $this->createAlert($alertData);
                        $alertsCreated++;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('AlertEngine::checkSchoolImprovementNeeded - ' . $e->getMessage());
        }

        return $alertsCreated;
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
                    $sql = "SELECT e.current_percentage 
                            FROM evaluations e
                            WHERE e.eval_type = 'behavior_score'
                              AND e.student_id = :student_id AND e.is_active = 1
                            ORDER BY e.semester_id DESC
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
                    $sql = "SELECT v.attendance_rate 
                            FROM v_attendance_summary v
                            WHERE v.student_id = :student_id
                            ORDER BY v.semester_id DESC
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
    
    /**
     * خريطة أنواع التنبيهات للتطابق مع القيم القديمة والجديدة
     */
    private function getTypeMap() {
        return [
            'academic'   => ['academic', 'low_grade', 'failing_subject'],
            'behavior'   => ['behavior', 'poor_behavior', 'behavior_below_threshold'],
            'attendance' => ['attendance', 'high_absence', 'consecutive_absence', 'attendance_below_threshold'],
            'eqi'        => ['eqi', 'eqi_decline'],
            'promotion'  => ['promotion', 'promotion_risk'],
            'climate'    => ['climate'],
            'teaching'   => ['teaching'],
            'improvement_plan' => ['improvement_plan'],
        ];
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
            $typeMap = $this->getTypeMap();
            $validTypes = $typeMap[$filters['alert_type']] ?? [$filters['alert_type']];
            $placeholders = [];
            foreach ($validTypes as $i => $t) {
                $key = ':alert_type_' . $i;
                $placeholders[] = $key;
                $params[$key] = $t;
            }
            $where[] = 'a.alert_type IN (' . implode(',', $placeholders) . ')';
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
     * الحصول على عدد التنبيهات (متوافق مع getAlerts)
     */
    public function getAlertsCount($filters = []) {
        $where = [];
        $params = [];
        
        if (!empty($filters['school_id'])) {
            $where[] = 'school_id = :school_id';
            $params[':school_id'] = $filters['school_id'];
        }
        if (!empty($filters['alert_type'])) {
            $typeMap = $this->getTypeMap();
            $validTypes = $typeMap[$filters['alert_type']] ?? [$filters['alert_type']];
            $placeholders = [];
            foreach ($validTypes as $i => $t) {
                $key = ':alert_type_' . $i;
                $placeholders[] = $key;
                $params[$key] = $t;
            }
            $where[] = 'alert_type IN (' . implode(',', $placeholders) . ')';
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
            $stmt = $this->db->prepare("UPDATE alerts SET is_read = 1 WHERE id IN ({$placeholders})");
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
            $params = array_merge([$resolvedBy], $alertIds);
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
            $schoolId = null;
            
            if ($userId) {
                $stmt = $this->db->prepare("SELECT school_id, role_id FROM users WHERE id = :id");
                $stmt->execute([':id' => $userId]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $roleStmt = $this->db->prepare("SELECT name FROM roles WHERE id = :id");
                    $roleStmt->execute([':id' => $user['role_id']]);
                    $role = $roleStmt->fetchColumn();
                    
                    if (in_array($role, ['school_admin', 'teacher']) && $user['school_id']) {
                        $schoolId = (int)$user['school_id'];
                    }
                }
            }
            
            if ($schoolId) {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM alerts WHERE is_read = 0 AND is_resolved = 0 AND school_id = ?");
                $stmt->execute([$schoolId]);
            } else {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM alerts WHERE is_read = 0 AND is_resolved = 0");
                $stmt->execute();
            }
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
            $stmt = $this->db->query("SELECT COUNT(*) FROM alerts WHERE 1=1 {$schoolFilter}");
            $stats['total'] = (int)$stmt->fetchColumn();
            
            // غير المقروءة
            $stmt = $this->db->query("SELECT COUNT(*) FROM alerts WHERE is_read = 0 {$schoolFilter}");
            $stats['unread'] = (int)$stmt->fetchColumn();
            
            // غير المحلولة
            $stmt = $this->db->query("SELECT COUNT(*) FROM alerts WHERE is_resolved = 0 {$schoolFilter}");
            $stats['unresolved'] = (int)$stmt->fetchColumn();
            
            // حسب النوع
            $sql = "SELECT alert_type, COUNT(*) AS cnt FROM alerts WHERE 1=1 {$schoolFilter} GROUP BY alert_type";
            $stmt = $this->db->query($sql);
            $oldToNew = [
                'low_grade' => 'academic',
                'failing_subject' => 'academic',
                'poor_behavior' => 'behavior',
                'behavior_below_threshold' => 'behavior',
                'high_absence' => 'attendance',
                'consecutive_absence' => 'attendance',
                'attendance_below_threshold' => 'attendance',
                'eqi_decline' => 'eqi',
                'promotion_risk' => 'promotion',
                'climate' => 'climate',
                'teaching' => 'teaching',
                'improvement_plan' => 'improvement_plan',
            ];
            while ($row = $stmt->fetch()) {
                $normalized = $oldToNew[$row['alert_type']] ?? $row['alert_type'];
                if (!isset($stats['by_type'][$normalized])) {
                    $stats['by_type'][$normalized] = 0;
                }
                $stats['by_type'][$normalized] += (int)$row['cnt'];
            }
            
            // حسب الخطورة
            $sql = "SELECT severity, COUNT(*) AS cnt FROM alerts WHERE 1=1 {$schoolFilter} GROUP BY severity";
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
                    WHERE a.student_id = :student_id";
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
            'climate_thresholds' => 0,
            'teaching_thresholds' => 0,
            'eqi_thresholds' => 0,
            'improvement_plan_overdue' => 0,
            'improvement_plan_needed' => 0,
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
            $results['climate_thresholds'] = $this->checkClimateThresholds($schoolId, $academicYearId, $semesterId);
            $results['eqi_thresholds'] = $this->checkEQIThresholds($schoolId, $academicYearId, $semesterId);
            $results['teaching_thresholds'] = $this->checkTeachingThresholds($schoolId, $academicYearId, $semesterId);
            
            // 2. فحص خطط التحسين
            $results['improvement_plan_overdue'] = $this->checkImprovementPlanOverdue($schoolId);
            $results['improvement_plan_needed'] = $this->checkSchoolImprovementNeeded($schoolId, $academicYearId, $semesterId);
            
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
                + $results['attendance_thresholds'] + $results['climate_thresholds']
                + $results['eqi_thresholds'] + $results['teaching_thresholds']
                + $results['improvement_plan_overdue'] + $results['improvement_plan_needed']
                + $results['declining_trends'] 
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
                    
                    // كتابة مفتاح إضافي لتوافق BehaviorCalculator
                    $altKey = null;
                    if ($key === 'behavior_pass') $altKey = 'behavior_passing_threshold';
                    if ($key === 'behavior_alert') $altKey = 'behavior_alert_threshold';
                    if ($altKey) {
                        $stmt2 = $this->db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type) 
                                                     VALUES (:key, :value, 'number')
                                                     ON DUPLICATE KEY UPDATE setting_value = :value2");
                        $stmt2->execute([
                            ':key' => $altKey,
                            ':value' => $value,
                            ':value2' => $value,
                        ]);
                    }
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('AlertEngine::updateSettings - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * تنظيف الإشعارات والتنبيهات القديمة (أكثر من 90 يوماً والمقروءة)
     * @return array عدد المحذوفات
     */
    public function cleanupOldRecords() {
        $result = ['notifications' => 0, 'alerts' => 0];
        try {
            // حذف الإشعارات المقروءة الأقدم من 90 يوماً
            $stmt = $this->db->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $stmt->execute();
            $result['notifications'] = $stmt->rowCount();

            // حذف التنبيهات المقروءة والمحلولة الأقدم من 90 يوماً
            $stmt = $this->db->prepare("DELETE FROM alerts WHERE is_read = 1 AND is_resolved = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $stmt->execute();
            $result['alerts'] = $stmt->rowCount();
        } catch (Exception $e) {
            error_log('AlertEngine::cleanupOldRecords - ' . $e->getMessage());
        }
        return $result;
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
