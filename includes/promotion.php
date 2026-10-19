<?php
/**
 * ============================================================
 * EQAS - Promotion Decision Engine
 * نظام التقييم الشامل - محرك قرارات الترفيع
 * ============================================================
 * 
 * ثلاثة معايير إلزامية للترفيع:
 * 1. الدرجة الأكاديمية >= 50%
 * 2. درجة السلوك >= 60%
 * 3. نسبة الحضور >= 75%
 * 
 * أنواع القرارات:
 * - promoted: استوفى جميع المعايير الثلاثة
 * - repeated: أخفق في معيارين أو أكثر
 * - conditional: أخفق في معيار واحد فقط (مع خطة تحسين)
 * - under_review: البيانات غير مكتملة
 */

class PromotionEngine {
    private $db;
    
    // حدود الترفيع
    private $thresholds = [
        'academic_pass' => ACADEMIC_PASS_RATE,      // 50%
        'behavior_pass' => BEHAVIOR_PASS_RATE,      // 60%
        'attendance_pass' => ATTENDANCE_PASS_RATE,  // 75%
    ];
    
    // تسميات القرارات بالعربية
    private $decisionLabels = [
        'promoted'     => 'مرفّع',
        'repeated'     => 'معيد',
        'conditional'  => 'ترفيع مشروط',
        'under_review' => 'قيد المراجعة',
    ];
    
    // ألوان القرارات
    private $decisionColors = [
        'promoted'     => ['bg' => '#d4edda', 'text' => '#155724', 'badge' => 'success'],
        'repeated'     => ['bg' => '#f8d7da', 'text' => '#721c24', 'badge' => 'danger'],
        'conditional'  => ['bg' => '#fff3cd', 'text' => '#856404', 'badge' => 'warning'],
        'under_review' => ['bg' => '#d1ecf1', 'text' => '#0c5460', 'badge' => 'info'],
    ];
    
    // ألوان النجاح/الرسوب
    private $passFailColors = [
        'pass' => ['bg' => '#d4edda', 'text' => '#155724', 'icon' => '✅'],
        'fail' => ['bg' => '#f8d7da', 'text' => '#721c24', 'icon' => '❌'],
        'na'   => ['bg' => '#e2e3e5', 'text' => '#383d41', 'icon' => '➖'],
    ];
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadThresholds();
    }
    
    /**
     * تحميل حدود الترفيع من إعدادات النظام
     */
    private function loadThresholds() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'promotion_threshold_%'");
            while ($row = $stmt->fetch()) {
                $key = str_replace('promotion_threshold_', '', $row['setting_key']);
                if (isset($this->thresholds[$key])) {
                    $this->thresholds[$key] = (float)$row['setting_value'];
                }
            }
        } catch (Exception $e) {
            error_log('PromotionEngine: Using default thresholds - ' . $e->getMessage());
        }
    }
    
    // ========================================================================
    // تقييم الطالب
    // ========================================================================
    
    /**
     * تقييم طالب للترفيع
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج التقييم
     */
    public function evaluateStudent($studentId, $academicYearId, $semesterId) {
        $academicScore = $this->getAcademicScore($studentId, $academicYearId, $semesterId);
        $behaviorScore = $this->getBehaviorScore($studentId, $academicYearId, $semesterId);
        $attendanceRate = $this->getAttendanceRate($studentId, $academicYearId, $semesterId);
        
        $academicPass = $academicScore !== null ? $academicScore >= $this->thresholds['academic_pass'] : null;
        $behaviorPass = $behaviorScore !== null ? $behaviorScore >= $this->thresholds['behavior_pass'] : null;
        $attendancePass = $attendanceRate !== null ? $attendanceRate >= $this->thresholds['attendance_pass'] : null;
        
        // حساب عدد المعايير الفاشلة
        $failCount = 0;
        if ($academicPass === false) $failCount++;
        if ($behaviorPass === false) $failCount++;
        if ($attendancePass === false) $failCount++;
        
        // التحقق من اكتمال البيانات
        $dataIncomplete = ($academicScore === null || $behaviorScore === null || $attendanceRate === null);
        
        // تحديد القرار
        if ($dataIncomplete) {
            $decision = 'under_review';
        } elseif ($failCount === 0) {
            $decision = 'promoted';
        } elseif ($failCount === 1) {
            $decision = 'conditional';
        } else {
            $decision = 'repeated';
        }
        
        // تحديد المعيار الفاشل للترفيع المشروط
        $failedCriteria = [];
        if ($academicPass === false) $failedCriteria[] = 'academic';
        if ($behaviorPass === false) $failedCriteria[] = 'behavior';
        if ($attendancePass === false) $failedCriteria[] = 'attendance';
        
        return [
            'student_id' => $studentId,
            'academic_score' => $academicScore,
            'behavior_score' => $behaviorScore,
            'attendance_rate' => $attendanceRate,
            'academic_pass' => $academicPass,
            'behavior_pass' => $behaviorPass,
            'attendance_pass' => $attendancePass,
            'decision' => $decision,
            'decision_label' => $this->decisionLabels[$decision],
            'decision_color' => $this->decisionColors[$decision],
            'fail_count' => $failCount,
            'failed_criteria' => $failedCriteria,
            'data_incomplete' => $dataIncomplete,
            'thresholds' => $this->thresholds,
        ];
    }
    
    // ========================================================================
    // حساب الدرجات الفردية
    // ========================================================================
    
    /**
     * حساب الدرجة الأكاديمية للطالب
     * متوسط جميع الدرجات معيارية إلى 0-100
     */
    private function getAcademicScore($studentId, $yearId, $semesterId) {
        try {
            $sql = "SELECT AVG(grade_value / max_value * 100) AS avg_score
                    FROM grades 
                    WHERE student_id = :student_id 
                        AND academic_year_id = :year_id 
                        AND semester_id = :semester_id 
                        AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            return $result !== null && $result !== false ? round((float)$result, 2) : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * الحصول على درجة السلوك للطالب
     */
    private function getBehaviorScore($studentId, $yearId, $semesterId) {
        try {
            $sql = "SELECT overall_score 
                    FROM behavior_scores 
                    WHERE student_id = :student_id 
                        AND academic_year_id = :year_id 
                        AND semester_id = :semester_id 
                        AND is_active = 1
                    ORDER BY evaluation_date DESC
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            return $result !== null && $result !== false ? round((float)$result, 2) : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * حساب نسبة حضور الطالب
     */
    private function getAttendanceRate($studentId, $yearId, $semesterId) {
        try {
            $sql = "SELECT attendance_rate 
                    FROM v_attendance_summary 
                    WHERE student_id = :student_id 
                        AND academic_year_id = :year_id 
                        AND semester_id = :semester_id 
                        AND is_active = 1
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            return $result !== null && $result !== false ? round((float)$result, 2) : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    // ========================================================================
    // حفظ واسترجاع القرارات
    // ========================================================================
    
    /**
     * حفظ قرار الترفيع
     */
    public function saveDecision($data) {
        try {
            $sql = "INSERT INTO promotions 
                    (student_id, academic_year_id, semester_id, academic_score, behavior_score, 
                     attendance_score, academic_pass, behavior_pass, attendance_pass, decision, 
                     notes, decided_by, created_at)
                    VALUES (:student_id, :year_id, :semester_id, :academic, :behavior, 
                            :attendance, :academic_pass, :behavior_pass, :attendance_pass, 
                            :decision, :notes, :decided_by, NOW())
                    ON DUPLICATE KEY UPDATE 
                        academic_score = VALUES(academic_score),
                        behavior_score = VALUES(behavior_score),
                        attendance_score = VALUES(attendance_score),
                        academic_pass = VALUES(academic_pass),
                        behavior_pass = VALUES(behavior_pass),
                        attendance_pass = VALUES(attendance_pass),
                        decision = VALUES(decision),
                        notes = VALUES(notes),
                        decided_by = VALUES(decided_by),
                        created_at = CURRENT_TIMESTAMP";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':student_id' => $data['student_id'],
                ':year_id' => $data['academic_year_id'],
                ':semester_id' => $data['semester_id'],
                ':academic' => $data['academic_score'],
                ':behavior' => $data['behavior_score'],
                ':attendance' => $data['attendance_rate'],
                ':academic_pass' => $data['academic_pass'] ? 1 : 0,
                ':behavior_pass' => $data['behavior_pass'] ? 1 : 0,
                ':attendance_pass' => $data['attendance_pass'] ? 1 : 0,
                ':decision' => $data['decision'],
                ':notes' => $data['notes'] ?? null,
                ':decided_by' => $_SESSION['user']['id'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log('PromotionEngine::saveDecision - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * الحصول على قرار ترفيع طالب
     */
    public function getStudentDecision($studentId, $yearId, $semesterId) {
        try {
            $sql = "SELECT pd.*, s.full_name_ar AS student_name, s.student_number,
                           u.full_name_ar AS decider_name
                    FROM promotions pd
                    INNER JOIN students s ON s.id = pd.student_id
                    LEFT JOIN users u ON u.id = pd.decided_by
                    WHERE pd.student_id = :student_id 
                        AND pd.academic_year_id = :year_id 
                        AND pd.semester_id = :semester_id 
                        AND pd.is_active = 1
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * الحصول على قرار بالمعرف
     */
    public function getDecisionById($decisionId) {
        try {
            $sql = "SELECT pd.*, s.full_name_ar AS student_name, s.student_number, 
                           s.school_id, sc.name_ar AS school_name,
                           c.name_ar AS class_name, gl.name_ar AS grade_name,
                           u.full_name_ar AS decider_name
                    FROM promotions pd
                    INNER JOIN students s ON s.id = pd.student_id
                    LEFT JOIN schools sc ON sc.id = s.school_id
                    LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.academic_year_id = pd.academic_year_id
                    LEFT JOIN classes c ON c.id = se.class_id
                    LEFT JOIN grade_levels gl ON gl.id = c.grade_level_id
                    LEFT JOIN users u ON u.id = pd.decided_by
                    WHERE pd.id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $decisionId]);
            
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
    
    // ========================================================================
    // التقييم الجماعي
    // ========================================================================
    
    /**
     * تقييم جماعي لجميع طلاب فصل
     */
    public function bulkEvaluate($classId, $academicYearId, $semesterId) {
        $results = [];
        
        try {
            // الحصول على طلاب الفصل
            $sql = "SELECT s.id, s.full_name_ar, s.student_number
                    FROM students s
                    INNER JOIN student_enrollments se ON se.student_id = s.id
                    WHERE se.class_id = :class_id 
                        AND se.academic_year_id = :year_id 
                        AND se.status = 'enrolled'
                        AND s.is_active = 1 
                        AND se.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
            ]);
            
            $students = $stmt->fetchAll();
            
            foreach ($students as $student) {
                $evaluation = $this->evaluateStudent($student['id'], $academicYearId, $semesterId);
                $evaluation['student_name'] = $student['full_name_ar'];
                $evaluation['student_number'] = $student['student_number'];
                $results[] = $evaluation;
            }
        } catch (Exception $e) {
            error_log('PromotionEngine::bulkEvaluate - ' . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * حفظ جميع قرارات الترفيع لفصل
     */
    public function saveBulkDecisions($evaluations, $academicYearId, $semesterId) {
        $saved = 0;
        $errors = 0;
        
        foreach ($evaluations as $eval) {
            $result = $this->saveDecision([
                'student_id' => $eval['student_id'],
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
                'academic_score' => $eval['academic_score'],
                'behavior_score' => $eval['behavior_score'],
                'attendance_rate' => $eval['attendance_rate'],
                'academic_pass' => $eval['academic_pass'] ?? false,
                'behavior_pass' => $eval['behavior_pass'] ?? false,
                'attendance_pass' => $eval['attendance_pass'] ?? false,
                'decision' => $eval['decision'],
                'notes' => null,
            ]);
            
            if ($result) {
                $saved++;
            } else {
                $errors++;
            }
        }
        
        return ['saved' => $saved, 'errors' => $errors];
    }
    
    // ========================================================================
    // الموافقة على القرارات
    // ========================================================================
    
    /**
     * الموافقة على قرار ترفيع
     */
    public function approveDecision($decisionId, $approvedBy) {
        try {
            $stmt = $this->db->prepare("UPDATE promotions 
                                         SET status = 'approved', 
                                             approved_by = :approved_by, 
                                             approved_at = NOW()
                                         WHERE id = :id AND is_active = 1");
            return $stmt->execute([
                ':id' => $decisionId,
                ':approved_by' => $approvedBy,
            ]);
        } catch (Exception $e) {
            error_log('PromotionEngine::approveDecision - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * الموافقة الجماعية على القرارات
     */
    public function approveBulkDecisions($decisionIds, $approvedBy) {
        $approved = 0;
        foreach ($decisionIds as $id) {
            if ($this->approveDecision($id, $approvedBy)) {
                $approved++;
            }
        }
        return $approved;
    }
    
    /**
     * رفض قرار ترفيع مع سبب
     */
    public function rejectDecision($decisionId, $rejectedBy, $reason = '') {
        try {
            $stmt = $this->db->prepare("UPDATE promotions 
                                         SET status = 'rejected',
                                             rejection_reason = :reason,
                                             decision = 'under_review'
                                         WHERE id = :id AND is_active = 1");
            return $stmt->execute([
                ':id' => $decisionId,
                ':reason' => $reason,
            ]);
        } catch (Exception $e) {
            error_log('PromotionEngine::rejectDecision - ' . $e->getMessage());
            return false;
        }
    }
    
    // ========================================================================
    // الترفيع المشروط وخطط التحسين
    // ========================================================================
    
    /**
     * الحصول على الطلاب المشروطين
     */
    public function getConditionalStudents($schoolId = null, $yearId = null, $semesterId = null) {
        try {
            $where = ["pd.decision = 'conditional'", "pd.is_active = 1"];
            $params = [];
            
            if ($schoolId) {
                $where[] = 's.school_id = :school_id';
                $params[':school_id'] = $schoolId;
            }
            if ($yearId) {
                $where[] = 'pd.academic_year_id = :year_id';
                $params[':year_id'] = $yearId;
            }
            if ($semesterId) {
                $where[] = 'pd.semester_id = :semester_id';
                $params[':semester_id'] = $semesterId;
            }
            
            $whereClause = implode(' AND ', $where);
            
            $sql = "SELECT pd.*, s.full_name_ar AS student_name, s.student_number,
                           sc.name_ar AS school_name
                    FROM promotions pd
                    INNER JOIN students s ON s.id = pd.student_id
                    LEFT JOIN schools sc ON sc.id = s.school_id
                    WHERE {$whereClause}
                    ORDER BY pd.created_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $results = $stmt->fetchAll();
            foreach ($results as &$row) {
                $row['decision_label'] = $this->decisionLabels[$row['decision']];
                $row['decision_color'] = $this->decisionColors[$row['decision']];
                $row['failed_criteria'] = $this->identifyFailedCriteria($row);
            }
            
            return $results;
        } catch (Exception $e) {
            error_log('PromotionEngine::getConditionalStudents - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * تحديد المعايير الفاشلة
     */
    private function identifyFailedCriteria($decision) {
        $failed = [];
        if (!$decision['academic_pass']) $failed[] = ['key' => 'academic', 'label' => 'الأداء الأكاديمي', 'score' => $decision['academic_score'], 'threshold' => $this->thresholds['academic_pass']];
        if (!$decision['behavior_pass']) $failed[] = ['key' => 'behavior', 'label' => 'السلوك', 'score' => $decision['behavior_score'], 'threshold' => $this->thresholds['behavior_pass']];
        if (!$decision['attendance_pass']) $failed[] = ['key' => 'attendance', 'label' => 'الحضور', 'score' => $decision['attendance_score'], 'threshold' => $this->thresholds['attendance_pass']];
        return $failed;
    }
    
    /**
     * إنشاء خطة تحسين لطالب مشروط
     */
    public function createImprovementPlan($studentId, $yearId, $semesterId, $planData) {
        try {
            // البحث عن قرار الترفيع المشروط
            $stmt = $this->db->prepare("SELECT id FROM promotions 
                                        WHERE student_id = :student_id 
                                            AND academic_year_id = :year_id 
                                            AND semester_id = :semester_id 
                                            AND decision = 'conditional'
                                            AND is_active = 1
                                        LIMIT 1");
            $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $decision = $stmt->fetch();
            $decisionId = $decision ? $decision['id'] : null;

            // حفظ خطة التحسين في جدول improvement_plans
            $stmt = $this->db->prepare("INSERT INTO improvement_plans 
                                        (student_id, decision_id, weaknesses, goals, strategies, timeline, 
                                         responsible_user_id, parent_involvement, follow_up_date, notes, 
                                         status, created_by, is_active)
                                        VALUES (:student_id, :decision_id, :weaknesses, :goals, :strategies, :timeline,
                                                :user_id, :parent_inv, :follow_up, :notes,
                                                'active', :created_by, 1)");
            return $stmt->execute([
                ':student_id' => $studentId,
                ':decision_id' => $decisionId,
                ':weaknesses' => $planData['weaknesses'] ?? $planData['weak_areas'] ?? null,
                ':goals' => $planData['objectives'] ?? $planData['goals'] ?? null,
                ':strategies' => $planData['actions'] ?? $planData['strategies'] ?? null,
                ':timeline' => $planData['timeline'] ?? $planData['deadline'] ?? null,
                ':user_id' => $planData['responsible_user_id'] ?? $planData['responsible_teacher_id'] ?? null,
                ':parent_inv' => $planData['parent_involvement'] ?? null,
                ':follow_up' => $planData['follow_up_date'] ?? null,
                ':notes' => $planData['notes'] ?? null,
                ':created_by' => $_SESSION['user']['id'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log('PromotionEngine::createImprovementPlan - ' . $e->getMessage());
            return false;
        }
    }
    
    // ========================================================================
    // التقارير والإحصائيات
    // ========================================================================
    
    /**
     * الحصول على قرارات الترفيع مع فلاتر
     */
    public function getDecisions($filters = []) {
        $where = ['pd.is_active = 1'];
        $params = [];
        
        if (!empty($filters['school_id'])) {
            $where[] = 's.school_id = :school_id';
            $params[':school_id'] = $filters['school_id'];
        }
        if (!empty($filters['class_id'])) {
            $where[] = 'se.class_id = :class_id';
            $params[':class_id'] = $filters['class_id'];
        }
        if (!empty($filters['academic_year_id'])) {
            $where[] = 'pd.academic_year_id = :year_id';
            $params[':year_id'] = $filters['academic_year_id'];
        }
        if (!empty($filters['semester_id'])) {
            $where[] = 'pd.semester_id = :semester_id';
            $params[':semester_id'] = $filters['semester_id'];
        }
        if (!empty($filters['decision'])) {
            $where[] = 'pd.decision = :decision';
            $params[':decision'] = $filters['decision'];
        }
        
        $whereClause = implode(' AND ', $where);
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int)($filters['per_page'] ?? 25)));
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT pd.*, s.full_name_ar AS student_name, s.student_number,
                       sc.name_ar AS school_name, c.name_ar AS class_name
                FROM promotions pd
                INNER JOIN students s ON s.id = pd.student_id
                LEFT JOIN schools sc ON sc.id = s.school_id
                LEFT JOIN student_enrollments se ON se.student_id = s.id AND se.academic_year_id = pd.academic_year_id
                LEFT JOIN classes c ON c.id = se.class_id
                WHERE {$whereClause}
                ORDER BY s.full_name_ar ASC
                LIMIT {$perPage} OFFSET {$offset}";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $results = $stmt->fetchAll();
        foreach ($results as &$row) {
            $row['decision_label'] = $this->decisionLabels[$row['decision']];
            $row['decision_color'] = $this->decisionColors[$row['decision']];
        }
        
        return $results;
    }
    
    /**
     * الحصول على عدد القرارات
     */
    public function getDecisionsCount($filters = []) {
        $where = ['pd.is_active = 1'];
        $params = [];
        
        if (!empty($filters['school_id'])) {
            $where[] = 's.school_id = :school_id';
            $params[':school_id'] = $filters['school_id'];
        }
        if (!empty($filters['academic_year_id'])) {
            $where[] = 'pd.academic_year_id = :year_id';
            $params[':year_id'] = $filters['academic_year_id'];
        }
        if (!empty($filters['semester_id'])) {
            $where[] = 'pd.semester_id = :semester_id';
            $params[':semester_id'] = $filters['semester_id'];
        }
        if (!empty($filters['decision'])) {
            $where[] = 'pd.decision = :decision';
            $params[':decision'] = $filters['decision'];
        }
        
        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM promotions pd
                INNER JOIN students s ON s.id = pd.student_id
                WHERE {$whereClause}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * الحصول على إحصائيات الترفيع
     */
    public function getStatistics($schoolId = null, $yearId = null, $semesterId = null) {
        $stats = [
            'total' => 0,
            'by_decision' => [
                'promoted' => 0,
                'conditional' => 0,
                'repeated' => 0,
                'under_review' => 0,
            ],
            'percentages' => [],
            'averages' => [
                'academic' => 0,
                'behavior' => 0,
                'attendance' => 0,
            ],
        ];
        
        try {
            $schoolFilter = $schoolId ? " AND s.school_id = " . (int)$schoolId : '';
            $yearFilter = $yearId ? " AND pd.academic_year_id = " . (int)$yearId : '';
            $semesterFilter = $semesterId ? " AND pd.semester_id = " . (int)$semesterId : '';
            $filter = $schoolFilter . $yearFilter . $semesterFilter;
            
            // الإجمالي
            $sql = "SELECT COUNT(*) FROM promotions pd 
                    INNER JOIN students s ON s.id = pd.student_id 
                    WHERE pd.is_active = 1 {$filter}";
            $stats['total'] = (int)$this->db->query($sql)->fetchColumn();
            
            // حسب القرار
            $sql = "SELECT pd.decision, COUNT(*) AS cnt 
                    FROM promotions pd 
                    INNER JOIN students s ON s.id = pd.student_id 
                    WHERE pd.is_active = 1 {$filter} 
                    GROUP BY pd.decision";
            $stmt = $this->db->query($sql);
            while ($row = $stmt->fetch()) {
                $stats['by_decision'][$row['decision']] = (int)$row['cnt'];
            }
            
            // النسب المئوية
            if ($stats['total'] > 0) {
                foreach ($stats['by_decision'] as $key => $count) {
                    $stats['percentages'][$key] = round(($count / $stats['total']) * 100, 1);
                }
            }
            
            // المتوسطات
            $sql = "SELECT AVG(pd.academic_score) AS avg_academic,
                           AVG(pd.behavior_score) AS avg_behavior,
                           AVG(pd.attendance_score) AS avg_attendance
                    FROM promotions pd 
                    INNER JOIN students s ON s.id = pd.student_id 
                    WHERE pd.is_active = 1 {$filter}";
            $stmt = $this->db->query($sql);
            $row = $stmt->fetch();
            if ($row) {
                $stats['averages']['academic'] = round((float)$row['avg_academic'], 1);
                $stats['averages']['behavior'] = round((float)$row['avg_behavior'], 1);
                $stats['averages']['attendance'] = round((float)$row['avg_attendance'], 1);
            }
        } catch (Exception $e) {
            error_log('PromotionEngine::getStatistics - ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * الحصول على قرارات طالب تاريخياً
     */
    public function getStudentHistory($studentId) {
        try {
            $sql = "SELECT pd.*, ay.name AS year_name, sem.name_ar AS semester_name
                    FROM promotions pd
                    INNER JOIN academic_years ay ON ay.id = pd.academic_year_id
                    INNER JOIN semesters sem ON sem.id = pd.semester_id
                    WHERE pd.student_id = :student_id AND pd.is_active = 1
                    ORDER BY pd.academic_year_id DESC, pd.semester_id DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':student_id' => $studentId]);
            
            $results = $stmt->fetchAll();
            foreach ($results as &$row) {
                $row['decision_label'] = $this->decisionLabels[$row['decision']];
                $row['decision_color'] = $this->decisionColors[$row['decision']];
            }
            
            return $results;
        } catch (Exception $e) {
            return [];
        }
    }
    
    // ========================================================================
    // إعدادات الترفيع
    // ========================================================================
    
    /**
     * الحصول على إعدادات الترفيع
     */
    public function getSettings() {
        return $this->thresholds;
    }
    
    /**
     * تحديث إعدادات الترفيع
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
                        ':key' => 'promotion_threshold_' . $key,
                        ':value' => $value,
                        ':value2' => $value,
                    ]);
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('PromotionEngine::updateSettings - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * الحصول على تسميات القرارات
     */
    public function getDecisionLabels() {
        return $this->decisionLabels;
    }
    
    /**
     * الحصول على ألوان القرارات
     */
    public function getDecisionColors() {
        return $this->decisionColors;
    }
    
    /**
     * تصدير تقرير الترفيع
     */
    public function exportReport($filters = [], $format = 'csv') {
        $decisions = $this->getDecisions(array_merge($filters, ['per_page' => 1000]));
        
        if ($format === 'csv') {
            return $this->exportToCSV($decisions);
        }
        
        return $decisions;
    }
    
    /**
     * تصدير إلى CSV
     */
    private function exportToCSV($decisions) {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        
        fputcsv($output, [
            'رقم الطالب', 'اسم الطالب', 'المدرسة', 'الفصل',
            'الدرجة الأكاديمية', 'نجح أكاديمياً', 'درجة السلوك', 'نجح سلوكياً',
            'نسبة الحضور', 'نجح حضورياً', 'القرار', 'تاريخ القرار'
        ]);
        
        foreach ($decisions as $d) {
            fputcsv($output, [
                $d['student_number'],
                $d['student_name'],
                $d['school_name'] ?? '',
                $d['class_name'] ?? '',
                $d['academic_score'] ?? 'غير متوفر',
                $d['academic_pass'] ? 'نعم' : 'لا',
                $d['behavior_score'] ?? 'غير متوفر',
                $d['behavior_pass'] ? 'نعم' : 'لا',
                $d['attendance_score'] ?? 'غير متوفر',
                $d['attendance_pass'] ? 'نعم' : 'لا',
                $this->decisionLabels[$d['decision']] ?? $d['decision'],
                $d['created_at'],
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}
