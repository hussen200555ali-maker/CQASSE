<?php
/**
 * ============================================================
 * EQAS - EQI Hybrid Calculation Engine
 * نظام التقييم الشامل - محرك حساب مؤشر جودة التعليم الهجين
 * ============================================================
 * 
 * طريقة الحساب الهجينة:
 * 50% توزيع نسبي + 50% توزيع متساوٍ لأوزان الأبعاد المفقودة
 * 
 * الأبعاد الستة:
 * 1. الأداء الأكاديمي (35%)
 * 2. جودة التدريس (25%)
 * 3. المناخ المدرسي (15%)
 * 4. السلوك (10%)
 * 5. المهارات (10%)
 * 6. الحضور (5%)
 */

class EQICalculator {
    private $db;
    
    // الأبعاد الستة مع الأوزان الافتراضية
    private $dimensions = [
        'academic'   => ['name' => 'الأداء الأكاديمي', 'weight' => 0.35, 'icon' => '📚'],
        'teaching'   => ['name' => 'جودة التدريس',     'weight' => 0.25, 'icon' => '👨‍🏫'],
        'climate'    => ['name' => 'المناخ المدرسي',    'weight' => 0.15, 'icon' => '🏫'],
        'behavior'   => ['name' => 'السلوك',            'weight' => 0.10, 'icon' => '⭐'],
        'skills'     => ['name' => 'المهارات',           'weight' => 0.10, 'icon' => '🎯'],
        'attendance' => ['name' => 'الحضور',             'weight' => 0.05, 'icon' => '📋'],
    ];
    
    // تصنيفات مؤشر الجودة
    private $ratings = [
        ['min' => 85, 'text' => 'ممتاز',       'class' => 'excellent',  'color' => '#28a745'],
        ['min' => 70, 'text' => 'جيد جداً',     'class' => 'very-good',  'color' => '#17a2b8'],
        ['min' => 50, 'text' => 'مقبول',        'class' => 'fair',       'color' => '#ffc107'],
        ['min' => 0,  'text' => 'ضعيف',         'class' => 'poor',       'color' => '#dc3545'],
    ];
    
    // توصيات لكل بعد
    private $recommendations = [
        'academic' => [
            'poor' => 'يجب اتخاذ إجراءات عاجلة لتحسين الأداء الأكاديمي: دروس دعم إضافية، خطط تعليمية فردية، متابعة يومية',
            'fair' => 'تحسين الأداء الأكاديمي مطلوب: تفعيل دروس التقوية، تخصيص وقت إضافي للمواد الأساسية',
            'very-good' => 'الأداء الأكاديمي جيد مع إمكانية التحسين: تشجيع المنافسة العلمية، برامج إثرائية',
            'excellent' => 'الحفاظ على المستوى المتميز: برامج تحدي للمتفوقين، مشاركة في مسابقات',
        ],
        'teaching' => [
            'poor' => 'تطوير عاجل لجودة التدريس: دورات تدريبية مكثفة، إشراف تربوي مباشر، زيارات صفية متكررة',
            'fair' => 'تحسين جودة التدريس مطلوب: ورش عمل تدريبية، تبادل خبرات بين المعلمين',
            'very-good' => 'جودة التدريس جيدة: تشجيع الابتكار في教学方法، نشر التجارب الناجحة',
            'excellent' => 'الحفاظ على التميز: قيادة التطوير المهني، توجيه المعلمين الجدد',
        ],
        'climate' => [
            'poor' => 'تحسين عاجل للمناخ المدرسي: معالجة مشاكل البنية التحتية، تعزيز الأمن والسلامة',
            'fair' => 'تحسين المناخ المدرسي مطلوب: أنشطة لا صفية، تحسين البيئة المادية',
            'very-good' => 'المناخ المدرسي جيد: تعزيز ثقافة الحوار والمشاركة',
            'excellent' => 'الحفاظ على المناخ المتميز: نموذج للمدارس الأخرى',
        ],
        'behavior' => [
            'poor' => 'معالجة عاجلة للسلوك: برامج توجيه وإرشاد، متابعة سلوكية مكثفة، إشراك أولياء الأمور',
            'fair' => 'تحسين السلوك مطلوب: أنشطة تربوية، حوافز إيجابية، برامج توعية',
            'very-good' => 'السلوك جيد: تعزيز القيم الإيجابية، برامج تطوع',
            'excellent' => 'الحفاظ على المستوى السلوكي المتميز: تكريم الطلاب المتميزين سلوكياً',
        ],
        'skills' => [
            'poor' => 'تطوير عاجل للمهارات: برامج مهنية مكثفة، تدريب عملي، مشاريع تطبيقية',
            'fair' => 'تحسين المهارات مطلوب: أنشطة مهارية، مسابقات، معارض علمية',
            'very-good' => 'المهارات جيدة: توسيع نطاق الأنشطة المهارية',
            'excellent' => 'الحفاظ على التميز المهاري: قيادة المشاريع المجتمعية',
        ],
        'attendance' => [
            'poor' => 'معالجة عاجلة لمشكلة الحضور: تواصل فوري مع أولياء الأمور، خطط حضور إلزامية',
            'fair' => 'تحسين الحضور مطلوب: متابعة يومية، حوافز للحضور المنتظم',
            'very-good' => 'الحضور جيد: تشجيع الاستمرارية',
            'excellent' => 'الحفاظ على الانتظام: تكريم الطلاب المنتظمين',
        ],
    ];
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadWeights();
    }
    
    /**
     * تحميل الأوزان من قاعدة البيانات
     */
    private function loadWeights() {
        try {
            $stmt = $this->db->query("SELECT dimension_name, default_weight FROM eqi_dimension_weights WHERE is_active = 1");
            while ($row = $stmt->fetch()) {
                if (isset($this->dimensions[$row['dimension_name']])) {
                    $this->dimensions[$row['dimension_name']]['weight'] = (float)$row['default_weight'] / 100;
                }
            }
        } catch (Exception $e) {
            // استخدام الأوزان الافتراضية
            error_log('EQICalculator: Using default weights - ' . $e->getMessage());
        }
    }
    
    // ========================================================================
    // حساب مؤشر جودة التعليم الرئيسي
    // ========================================================================
    
    /**
     * حساب مؤشر جودة التعليم بطريقة هجينة
     * 
     * @param int $schoolId معرف المدرسة
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج الحساب
     */
    public function calculate($schoolId, $academicYearId, $semesterId) {
        $scores = [];
        $availableDimensions = [];
        $missingDimensions = [];
        $dimensionDetails = [];
        
        // 1. حساب درجة كل بعد
        $academicScore = $this->calculateAcademicScore($schoolId, $academicYearId, $semesterId);
        if ($academicScore !== null) { 
            $scores['academic'] = $academicScore; 
            $availableDimensions[] = 'academic'; 
            $dimensionDetails['academic'] = ['score' => $academicScore, 'status' => 'available', 'source_type' => 'direct', 'source' => 'grades'];
        } else { 
            $missingDimensions[] = 'academic'; 
            $dimensionDetails['academic'] = ['score' => null, 'status' => 'missing', 'source_type' => null, 'source' => null];
        }
        
        $usedTeachingProxy = false;
        $teachingScore = $this->calculateTeachingScore($schoolId, $academicYearId, $semesterId, $usedTeachingProxy);
        if ($teachingScore !== null) { 
            $scores['teaching'] = $teachingScore; 
            $availableDimensions[] = 'teaching'; 
            $teachingSourceType = $usedTeachingProxy ? 'proxy' : 'direct';
            $teachingSource = $usedTeachingProxy ? 'grades_proxy' : 'teaching_evaluation_summary';
            $dimensionDetails['teaching'] = ['score' => $teachingScore, 'status' => 'available', 'source_type' => $teachingSourceType, 'source' => $teachingSource];
        } else { 
            $missingDimensions[] = 'teaching'; 
            $dimensionDetails['teaching'] = ['score' => null, 'status' => 'missing', 'source_type' => null, 'source' => null];
        }
        
        $climateScore = $this->calculateClimateScore($schoolId, $academicYearId, $semesterId);
        if ($climateScore !== null) { 
            $scores['climate'] = $climateScore; 
            $availableDimensions[] = 'climate'; 
            $dimensionDetails['climate'] = ['score' => $climateScore, 'status' => 'available', 'source_type' => 'direct', 'source' => 'surveys'];
        } else { 
            $missingDimensions[] = 'climate'; 
            $dimensionDetails['climate'] = ['score' => null, 'status' => 'missing', 'source_type' => null, 'source' => null];
        }
        
        $behaviorScore = $this->calculateBehaviorScore($schoolId, $academicYearId, $semesterId);
        if ($behaviorScore !== null) { 
            $scores['behavior'] = $behaviorScore; 
            $availableDimensions[] = 'behavior'; 
            $dimensionDetails['behavior'] = ['score' => $behaviorScore, 'status' => 'available', 'source_type' => 'direct', 'source' => 'evaluations'];
        } else { 
            $missingDimensions[] = 'behavior'; 
            $dimensionDetails['behavior'] = ['score' => null, 'status' => 'missing', 'source_type' => null, 'source' => null];
        }
        
        $skillsScore = $this->calculateSkillsScore($schoolId, $academicYearId, $semesterId);
        if ($skillsScore !== null) { 
            $scores['skills'] = $skillsScore; 
            $availableDimensions[] = 'skills'; 
            $dimensionDetails['skills'] = ['score' => $skillsScore, 'status' => 'available', 'source_type' => 'direct', 'source' => 'evaluations'];
        } else { 
            $missingDimensions[] = 'skills'; 
            $dimensionDetails['skills'] = ['score' => null, 'status' => 'missing', 'source_type' => null, 'source' => null];
        }
        
        $attendanceScore = $this->calculateAttendanceScore($schoolId, $academicYearId, $semesterId);
        if ($attendanceScore !== null) { 
            $scores['attendance'] = $attendanceScore; 
            $availableDimensions[] = 'attendance'; 
            $dimensionDetails['attendance'] = ['score' => $attendanceScore, 'status' => 'available', 'source_type' => 'direct', 'source' => 'summary'];
        } else { 
            $missingDimensions[] = 'attendance'; 
            $dimensionDetails['attendance'] = ['score' => null, 'status' => 'missing', 'source_type' => null, 'source' => null];
        }
        
        // 2. إذا لم تتوفر أي أبعاد
        if (empty($availableDimensions)) {
            return [
                'scores' => [],
                'weights' => [],
                'eqi' => null,
                'available_dimensions' => [],
                'missing_dimensions' => array_keys($this->dimensions),
                'dimension_details' => $dimensionDetails,
                'rating' => $this->getEQIRating(0),
                'redistribution_info' => null,
                'message' => 'لا تتوفر بيانات كافية لحساب المؤشر. يرجى إدخال بيانات التقييم أولاً.',
            ];
        }
        
        // 3. إعادة توزيع الأوزان (طريقة هجينة)
        $weights = $this->redistributeWeights($availableDimensions, $missingDimensions);
        
        // معلومات إعادة التوزيع
        $redistributionInfo = null;
        if (!empty($missingDimensions)) {
            $redistributionInfo = [
                'missing' => $missingDimensions,
                'missing_names' => array_map(function($d) { return $this->dimensions[$d]['name']; }, $missingDimensions),
                'missing_weight' => array_sum(array_map(function($d) { return $this->dimensions[$d]['weight']; }, $missingDimensions)),
                'original_weights' => [],
                'new_weights' => $weights,
                'method' => 'هجين (50% نسبي + 50% متساوٍ)',
            ];
            foreach ($availableDimensions as $dim) {
                $redistributionInfo['original_weights'][$dim] = $this->dimensions[$dim]['weight'];
            }
        }
        
        // 4. حساب المؤشر الموزون
        $eqi = 0;
        foreach ($availableDimensions as $dim) {
            $eqi += $scores[$dim] * $weights[$dim];
            $dimensionDetails[$dim]['original_weight'] = $this->dimensions[$dim]['weight'];
            $dimensionDetails[$dim]['effective_weight'] = $weights[$dim];
            $dimensionDetails[$dim]['weighted_score'] = $scores[$dim] * $weights[$dim];
        }
        
        $eqi = round($eqi, 2);
        
        // 5. حفظ الحساب
        $this->saveCalculation($schoolId, $academicYearId, $semesterId, $scores, $weights, $eqi);
        
        return [
            'scores' => $scores,
            'weights' => $weights,
            'eqi' => $eqi,
            'available_dimensions' => $availableDimensions,
            'missing_dimensions' => $missingDimensions,
            'dimension_details' => $dimensionDetails,
            'rating' => $this->getEQIRating($eqi),
            'redistribution_info' => $redistributionInfo,
        ];
    }
    
    // ========================================================================
    // إعادة توزيع الأوزان (طريقة هجينة)
    // ========================================================================
    
    /**
     * إعادة توزيع أوزان الأبعاد المفقودة بالطريقة الهجينة
     * 50% توزيع نسبي + 50% توزيع متساوٍ
     */
    private function redistributeWeights($available, $missing) {
        // إذا لم توجد أبعاد مفقودة، استخدام الأوزان الأصلية
        if (empty($missing)) {
            $weights = [];
            foreach ($this->dimensions as $key => $dim) {
                $weights[$key] = $dim['weight'];
            }
            return $weights;
        }
        
        // حساب الوزن المفقود الإجمالي
        $missingWeight = 0;
        foreach ($missing as $dim) {
            $missingWeight += $this->dimensions[$dim]['weight'];
        }
        
        // حساب الوزن الإجمالي للأبعاد المتاحة
        $totalAvailableWeight = 0;
        foreach ($available as $dim) {
            $totalAvailableWeight += $this->dimensions[$dim]['weight'];
        }
        
        // تجنب القسمة على صفر
        if ($totalAvailableWeight <= 0) {
            $equalWeight = 1.0 / count($available);
            $weights = [];
            foreach ($available as $dim) {
                $weights[$dim] = $equalWeight;
            }
            return $weights;
        }
        
        // حساب الأوزان الجديدة بالطريقة الهجينة
        $newWeights = [];
        foreach ($available as $dim) {
            $originalWeight = $this->dimensions[$dim]['weight'];
            
            // 50% توزيع نسبي (حسب وزن البعد الأصلي)
            $proportionalShare = ($originalWeight / $totalAvailableWeight) * ($missingWeight * 0.5);
            
            // 50% توزيع متساوٍ
            $equalShare = ($missingWeight * 0.5) / count($available);
            
            $newWeights[$dim] = round($originalWeight + $proportionalShare + $equalShare, 6);
        }
        
        return $newWeights;
    }
    
    // ========================================================================
    // حسابات الأبعاد الفردية
    // ========================================================================
    
    /**
     * حساب درجة الأداء الأكاديمي
     * متوسط جميع درجات الطلاب في المدرسة معيارية إلى 0-100
     */
    private function calculateAcademicScore($schoolId, $yearId, $semesterId) {
        try {
            $sql = "SELECT AVG(g.grade_value / g.max_value * 100) AS avg_score
                    FROM grades g
                    INNER JOIN students s ON s.id = g.student_id
                    WHERE s.school_id = :school_id 
                        AND g.academic_year_id = :year_id 
                        AND g.semester_id = :semester_id 
                        AND g.is_active = 1 
                        AND s.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            return $result !== null && $result !== false ? round((float)$result, 2) : null;
        } catch (Exception $e) {
            error_log('EQICalculator::calculateAcademicScore - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * حساب درجة جودة التدريس
     * مرجح: ذاتي 20%، أقران 30%، إشرافي 50%
     * 
     * آلية احتياطية تدريجية:
     * 1. المحاولة الأولى: تقييمات التدريس الفعلية من teaching_evaluation_summary
     * 2. الاحتياطي: متوسط درجات الطلاب (وكيل) عند غياب بيانات التقييم
     * 3. تسجيل تحذير في سجل الأخطاء عند استخدام الاحتياطي
     * 
     * @param int $schoolId
     * @param int $yearId
     * @param int $semesterId
     * @param bool $usedProxy مرجع يُضبط على true إذا تم استخدام الاحتياطي
     * @return float|null
     */
    private function calculateTeachingScore($schoolId, $yearId, $semesterId, &$usedProxy = false) {
        try {
            // 1. المحاولة الأولى: تقييمات التدريس الفعلية (مُجمّعة مسبقاً في teaching_evaluation_summary)
            $sql = "SELECT COALESCE(AVG(tes.combined_score), 0)
                    FROM teaching_evaluation_summary tes
                    JOIN users u ON tes.teacher_id = u.id AND u.school_id = :school_id
                    WHERE tes.academic_year_id = :year_id
                      AND tes.semester_id = :semester_id
                      AND tes.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':school_id' => $schoolId, ':year_id' => $yearId, ':semester_id' => $semesterId]);
            $result = $stmt->fetchColumn();

            if ($result !== null && $result !== false && $result > 0) {
                return round((float)$result, 2);
            }

            // 2. وكيل احتياطي: متوسط درجات الطلاب عند غياب بيانات تقييم التدريس
            $proxySql = "SELECT AVG(g.grade_value / g.max_value * 100) AS avg_score
                         FROM grades g
                         INNER JOIN students s ON s.id = g.student_id
                         WHERE s.school_id = :school_id 
                             AND g.academic_year_id = :year_id 
                             AND g.semester_id = :semester_id 
                             AND g.is_active = 1 
                             AND s.is_active = 1";
            $proxyStmt = $this->db->prepare($proxySql);
            $proxyStmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $proxyResult = $proxyStmt->fetchColumn();

            if ($proxyResult !== null && $proxyResult !== false) {
                $usedProxy = true;
                error_log("EQICalculator::calculateTeachingScore - WARNING: Using proxy (student grades) for school_id=$schoolId, year_id=$yearId, semester_id=$semesterId. No teaching evaluation data found.");
                return round((float)$proxyResult, 2);
            }

            return null;
        } catch (Exception $e) {
            error_log('EQICalculator::calculateTeachingScore - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * حساب درجة المناخ المدرسي
     * متوسط درجات استبيان المناخ
     */
    private function calculateClimateScore($schoolId, $yearId, $semesterId) {
        try {
            $sql = "SELECT AVG(cs.overall_score) AS avg_score
                    FROM climate_surveys cs
                    WHERE cs.school_id = :school_id 
                        AND cs.academic_year_id = :year_id 
                        AND cs.semester_id = :semester_id 
                        AND cs.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            return $result !== null && $result !== false ? round((float)$result, 2) : null;
        } catch (Exception $e) {
            error_log('EQICalculator::calculateClimateScore - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * حساب درجة السلوك
     * متوسط درجات التقييم السلوكي
     */
    private function calculateBehaviorScore($schoolId, $yearId, $semesterId) {
        try {
            $sql = "SELECT AVG(bs.current_percentage) AS avg_score
                    FROM behavior_scores bs
                    INNER JOIN students s ON s.id = bs.student_id
                    WHERE s.school_id = :school_id 
                        AND bs.academic_year_id = :year_id 
                        AND bs.semester_id = :semester_id 
                        AND bs.is_active = 1
                        AND s.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            $score = $result !== null && $result !== false ? round((float)$result, 2) : null;
            return $score !== null ? min(100, $score) : null;
        } catch (Exception $e) {
            error_log('EQICalculator::calculateBehaviorScore - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * حساب درجة المهارات
     * متوسط درجات قائمة المهارات
     */
    private function calculateSkillsScore($schoolId, $yearId, $semesterId) {
        try {
            $sql = "SELECT AVG(se.score) AS avg_score
                    FROM skills_evaluations se
                    WHERE se.school_id = :school_id 
                        AND se.academic_year_id = :year_id 
                        AND se.semester_id = :semester_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            return $result !== null && $result !== false ? round((float)$result, 2) : null;
        } catch (Exception $e) {
            error_log('EQICalculator::calculateSkillsScore - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * حساب درجة الحضور
     * متوسط نسبة الحضور مضروب في 100
     */
    private function calculateAttendanceScore($schoolId, $yearId, $semesterId) {
        try {
            $sql = "SELECT AVG(ats.attendance_rate) AS avg_rate
                    FROM attendance_summaries ats
                    INNER JOIN students s ON s.id = ats.student_id
                    WHERE s.school_id = :school_id 
                        AND ats.academic_year_id = :year_id 
                        AND ats.semester_id = :semester_id 
                        AND ats.is_active = 1
                        AND s.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            $result = $stmt->fetchColumn();
            
            return $result !== null && $result !== false ? round((float)$result, 2) : null;
        } catch (Exception $e) {
            error_log('EQICalculator::calculateAttendanceScore - ' . $e->getMessage());
            return null;
        }
    }
    
    // ========================================================================
    // حفظ واسترجاع الحسابات
    // ========================================================================
    
    /**
     * حفظ حساب المؤشر
     */
    private function saveCalculation($schoolId, $yearId, $semesterId, $scores, $weights, $eqi) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM equi_results WHERE school_id = :school_id AND academic_year_id = :year_id AND semester_id = :semester_id");
            $stmt->execute([':school_id' => $schoolId, ':year_id' => $yearId, ':semester_id' => $semesterId]);
            $existing = $stmt->fetch();

            $params = [
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
                ':academic' => $scores['academic'] ?? null,
                ':teaching' => $scores['teaching'] ?? null,
                ':climate' => $scores['climate'] ?? null,
                ':behavior' => $scores['behavior'] ?? null,
                ':skills' => $scores['skills'] ?? null,
                ':attendance' => $scores['attendance'] ?? null,
                ':eqi' => $eqi,
                ':calc_by' => $_SESSION['user']['id'] ?? 0,
            ];

            if ($existing) {
                $sql = "UPDATE equi_results SET 
                        academic_score = :academic, teaching_score = :teaching,
                        climate_score = :climate, behavior_score = :behavior,
                        skills_score = :skills, attendance_score = :attendance,
                        overall_eqi = :eqi, calculated_by = :calc_by,
                        calculated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params = [
                    ':academic' => $scores['academic'] ?? null,
                    ':teaching' => $scores['teaching'] ?? null,
                    ':climate' => $scores['climate'] ?? null,
                    ':behavior' => $scores['behavior'] ?? null,
                    ':skills' => $scores['skills'] ?? null,
                    ':attendance' => $scores['attendance'] ?? null,
                    ':eqi' => $eqi,
                    ':calc_by' => $_SESSION['user']['id'] ?? 0,
                    ':id' => $existing['id'],
                ];
            } else {
                $sql = "INSERT INTO equi_results 
                        (school_id, academic_year_id, semester_id, academic_score, teaching_score, 
                         climate_score, behavior_score, skills_score, attendance_score, overall_eqi, calculated_by)
                        VALUES (:school_id, :year_id, :semester_id, :academic, :teaching, 
                                :climate, :behavior, :skills, :attendance, :eqi, :calc_by)";
                $params = [
                    ':school_id' => $schoolId,
                    ':year_id' => $yearId,
                    ':semester_id' => $semesterId,
                    ':academic' => $scores['academic'] ?? null,
                    ':teaching' => $scores['teaching'] ?? null,
                    ':climate' => $scores['climate'] ?? null,
                    ':behavior' => $scores['behavior'] ?? null,
                    ':skills' => $scores['skills'] ?? null,
                    ':attendance' => $scores['attendance'] ?? null,
                    ':eqi' => $eqi,
                    ':calc_by' => $_SESSION['user']['id'] ?? 0,
                ];
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $this->db->lastInsertId() ?: true;
        } catch (Exception $e) {
            error_log('EQICalculator::saveCalculation - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * الحصول على تاريخ حسابات المؤشر
     */
    public function getEQIHistory($schoolId, $limit = 10) {
        try {
            $sql = "SELECT ec.*, ay.name AS year_name, sem.name_ar AS semester_name
                    FROM equi_results ec
                    INNER JOIN academic_years ay ON ay.id = ec.academic_year_id
                    INNER JOIN semesters sem ON sem.id = ec.semester_id
                    WHERE ec.school_id = :school_id AND ec.is_active = 1
                    ORDER BY ec.calculated_at DESC
                    LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':school_id', $schoolId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('EQICalculator::getEQIHistory - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * الحصول على تفاصيل أبعاد حساب معين
     */
    public function getEQIDimensions($schoolId, $yearId, $semesterId) {
        try {
            $sql = "SELECT * FROM equi_results 
                    WHERE school_id = :school_id 
                        AND academic_year_id = :year_id 
                        AND semester_id = :semester_id 
                        AND is_active = 1
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':school_id' => $schoolId,
                ':year_id' => $yearId,
                ':semester_id' => $semesterId,
            ]);
            
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * الحصول على حساب واحد
     */
    public function getCalculation($calculationId) {
        try {
            $sql = "SELECT ec.*, s.name_ar AS school_name, ay.name AS year_name, sem.name_ar AS semester_name,
                           u.full_name_ar AS calculator_name
                    FROM equi_results ec
                    INNER JOIN schools s ON s.id = ec.school_id
                    INNER JOIN academic_years ay ON ay.id = ec.academic_year_id
                    INNER JOIN semesters sem ON sem.id = ec.semester_id
                    LEFT JOIN users u ON u.id = ec.calculated_by
                    WHERE ec.id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $calculationId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
    
    // ========================================================================
    // تصنيف المؤشر
    // ========================================================================
    
    /**
     * الحصول على تصنيف المؤشر
     */
    public function getEQIRating($score) {
        foreach ($this->ratings as $rating) {
            if ($score >= $rating['min']) {
                return [
                    'text' => $rating['text'],
                    'class' => $rating['class'],
                    'color' => $rating['color'],
                ];
            }
        }
        return ['text' => 'ضعيف', 'class' => 'poor', 'color' => '#dc3545'];
    }
    
    /**
     * الحصول على توصيات لكل بعد
     */
    public function getRecommendations($scores) {
        $recs = [];
        foreach ($scores as $dim => $score) {
            if ($score === null || !isset($this->recommendations[$dim])) continue;
            
            $rating = $this->getEQIRating($score);
            $level = $rating['class'];
            
            $recs[$dim] = [
                'dimension' => $this->dimensions[$dim]['name'],
                'score' => $score,
                'rating' => $rating,
                'recommendation' => $this->recommendations[$dim][$level] ?? 'لا توجد توصيات محددة.',
            ];
        }
        return $recs;
    }
    
    // ========================================================================
    // فحص جاهزية البيانات
    // ========================================================================

    /**
     * فحص مدى توفر البيانات اللازمة لحساب المؤشر لكل بُعد
     */
    public function checkDataAvailability($schoolId, $yearId, $semesterId) {
        $checks = [];

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM grades g
                INNER JOIN students s ON s.id = g.student_id
                WHERE s.school_id = ? AND g.academic_year_id = ? AND g.semester_id = ? AND g.is_active = 1 AND s.is_active = 1");
            $stmt->execute([$schoolId, $yearId, $semesterId]);
            $count = (int)$stmt->fetchColumn();
            $checks['academic'] = ['available' => $count > 0, 'count' => $count, 'page' => 'academic_assessment/grade_entry.php', 'page_label' => 'إدخال الدرجات'];
        } catch (Exception $e) { $checks['academic'] = ['available' => false, 'count' => 0, 'page' => 'academic_assessment/grade_entry.php', 'page_label' => 'إدخال الدرجات']; }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM subject_teachers st
                JOIN classes c ON c.id = st.classroom_id
                WHERE c.school_id = ? AND c.academic_year_id = ? AND st.semester_id = ?");
            $stmt->execute([$schoolId, $yearId, $semesterId]);
            $count = (int)$stmt->fetchColumn();
            $available = $count > 0;
            $checks['teaching'] = [
                'available' => $available,
                'partial' => $available,
                'count' => $count,
                'page' => 'teaching/self_evaluate.php',
                'page_label' => 'تقييم التدريس (تقديري)',
            ];
        } catch (Exception $e) { $checks['teaching'] = ['available' => false, 'partial' => false, 'count' => 0, 'page' => 'teaching/self_evaluate.php', 'page_label' => 'تقييم التدريس (تقديري)']; }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM climate_surveys
                WHERE school_id = ? AND academic_year_id = ? AND semester_id = ? AND is_active = 1");
            $stmt->execute([$schoolId, $yearId, $semesterId]);
            $count = (int)$stmt->fetchColumn();
            $checks['climate'] = ['available' => $count > 0, 'count' => $count, 'page' => 'climate/survey.php', 'page_label' => 'استبيان المناخ'];
        } catch (Exception $e) { $checks['climate'] = ['available' => false, 'count' => 0, 'page' => 'climate/survey.php', 'page_label' => 'استبيان المناخ']; }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM behavior_scores bs
                INNER JOIN students s ON s.id = bs.student_id
                WHERE s.school_id = ? AND bs.academic_year_id = ? AND bs.semester_id = ? AND bs.is_active = 1 AND s.is_active = 1");
            $stmt->execute([$schoolId, $yearId, $semesterId]);
            $count = (int)$stmt->fetchColumn();
            $checks['behavior'] = ['available' => $count > 0, 'count' => $count, 'page' => 'behavior/evaluate.php', 'page_label' => 'تقييم السلوك'];
        } catch (Exception $e) { $checks['behavior'] = ['available' => false, 'count' => 0, 'page' => 'behavior/evaluate.php', 'page_label' => 'تقييم السلوك']; }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM skills_evaluations se
                INNER JOIN students s ON s.id = se.student_id
                WHERE s.school_id = ? AND se.academic_year_id = ? AND se.semester_id = ? AND se.is_active = 1 AND s.is_active = 1");
            $stmt->execute([$schoolId, $yearId, $semesterId]);
            $count = (int)$stmt->fetchColumn();
            $checks['skills'] = ['available' => $count > 0, 'count' => $count, 'page' => 'skills/evaluate.php', 'page_label' => 'تقييم المهارات'];
        } catch (Exception $e) { $checks['skills'] = ['available' => false, 'count' => 0, 'page' => 'skills/evaluate.php', 'page_label' => 'تقييم المهارات']; }

        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM attendance_summaries ats
                INNER JOIN students s ON s.id = ats.student_id
                WHERE s.school_id = ? AND ats.academic_year_id = ? AND ats.semester_id = ? AND ats.is_active = 1 AND s.is_active = 1");
            $stmt->execute([$schoolId, $yearId, $semesterId]);
            $count = (int)$stmt->fetchColumn();
            $checks['attendance'] = ['available' => $count > 0, 'count' => $count, 'page' => 'attendance/index.php', 'page_label' => 'سجلات الحضور'];
        } catch (Exception $e) { $checks['attendance'] = ['available' => false, 'count' => 0, 'page' => 'attendance/index.php', 'page_label' => 'سجلات الحضور']; }

        return $checks;
    }

    // ========================================================================
    // المقارنات
    // ========================================================================
    
    /**
     * مقارنة بين مدارس
     */
    public function compareSchools($schoolIds, $yearId, $semesterId) {
        $comparisons = [];
        
        foreach ($schoolIds as $schoolId) {
            $calc = $this->getEQIDimensions($schoolId, $yearId, $semesterId);
            if ($calc) {
                $school = $this->getSchoolInfo($schoolId);
                $comparisons[] = array_merge($calc, [
                    'school_name' => $school['name_ar'] ?? '',
                    'rating' => $this->getEQIRating($calc['overall_eqi'] ?? 0),
                ]);
            }
        }
        
        return $comparisons;
    }
    
    /**
     * مقارنة بين فترات زمنية
     */
    public function comparePeriods($schoolId, $periods) {
        $comparisons = [];
        
        foreach ($periods as $period) {
            $calc = $this->getEQIDimensions($schoolId, $period['year_id'], $period['semester_id']);
            if ($calc) {
                $comparisons[] = $calc;
            }
        }
        
        return $comparisons;
    }
    
    /**
     * الحصول على معلومات المدرسة
     */
    private function getSchoolInfo($schoolId) {
        try {
            $stmt = $this->db->prepare("SELECT id, name_ar, name_en, reference_code, school_type FROM schools WHERE id = :id");
            $stmt->execute([':id' => $schoolId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            return null;
        }
    }
    
    // ========================================================================
    // التقارير
    // ========================================================================
    
    /**
     * الحصول على إحصائيات المؤشر لجميع المدارس
     */
    public function getAllSchoolsEQI($yearId, $semesterId) {
        try {
            $sql = "SELECT ec.*, s.name_ar AS school_name, s.reference_code AS school_code
                    FROM equi_results ec
                    INNER JOIN schools s ON s.id = ec.school_id
                    WHERE ec.academic_year_id = :year_id 
                        AND ec.semester_id = :semester_id 
                        AND ec.is_active = 1
                    ORDER BY ec.overall_eqi DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':year_id' => $yearId, ':semester_id' => $semesterId]);
            
            $results = $stmt->fetchAll();
            foreach ($results as &$row) {
                $row['rating'] = $this->getEQIRating($row['overall_eqi'] ?? 0);
            }
            
            return $results;
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * الحصول على ترتيب المدارس
     */
    public function getSchoolRanking($yearId, $semesterId, $limit = 20) {
        try {
            $sql = "SELECT ec.overall_eqi, s.name_ar AS school_name, s.reference_code AS school_code
                    FROM equi_results ec
                    INNER JOIN schools s ON s.id = ec.school_id
                    WHERE ec.academic_year_id = :year_id 
                        AND ec.semester_id = :semester_id 
                        AND ec.is_active = 1
                    ORDER BY ec.overall_eqi DESC
                    LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':year_id', $yearId, PDO::PARAM_INT);
            $stmt->bindValue(':semester_id', $semesterId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $ranking = $stmt->fetchAll();
            $rank = 1;
            foreach ($ranking as &$row) {
                $row['rank'] = $rank++;
                $row['rating'] = $this->getEQIRating($row['overall_eqi'] ?? 0);
            }
            
            return $ranking;
        } catch (Exception $e) {
            return [];
        }
    }
    
    // ========================================================================
    // إعدادات الأوزان
    // ========================================================================
    
    /**
     * الحصول على الأبعاد والأوزان
     */
    public function getDimensions() {
        return $this->dimensions;
    }
    
    /**
     * تحديث أوزان الأبعاد
     */
    public function updateWeights($weights) {
        try {
            $totalWeight = array_sum($weights);
            if (abs($totalWeight - 100) > 0.01) {
                return ['success' => false, 'message' => sprintf('مجموع الأوزان يجب أن يكون 100%%. المجموع الحالي: %.2f%%', $totalWeight)];
            }
            
            foreach ($weights as $dimName => $weight) {
                if (isset($this->dimensions[$dimName])) {
                    $this->dimensions[$dimName]['weight'] = $weight / 100;
                    
                    $exists = $this->db->prepare("SELECT COUNT(*) FROM eqi_dimension_weights WHERE dimension_name = ?");
                    $exists->execute([$dimName]);
                    if ($exists->fetchColumn() > 0) {
                        $sql = "UPDATE eqi_dimension_weights SET default_weight = :weight, dimension_name_ar = :name_ar WHERE dimension_name = :name";
                    } else {
                        $sql = "INSERT INTO eqi_dimension_weights (dimension_name, dimension_name_ar, default_weight, is_active)
                                VALUES (:name, :name_ar, :weight, 1)";
                    }
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        ':name' => $dimName,
                        ':name_ar' => $this->dimensions[$dimName]['name'],
                        ':weight' => $weight,
                    ]);
                }
            }
            
            return ['success' => true, 'message' => 'تم تحديث الأوزان بنجاح'];
        } catch (Exception $e) {
            error_log('EQICalculator::updateWeights - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء تحديث الأوزان'];
        }
    }
    
    /**
     * تصدير تقرير المؤشر
     */
    public function exportReport($schoolId, $yearId, $semesterId, $format = 'csv') {
        $calc = $this->getEQIDimensions($schoolId, $yearId, $semesterId);
        $school = $this->getSchoolInfo($schoolId);
        
        if (!$calc) return null;
        
        $rating = $this->getEQIRating($calc['overall_eqi'] ?? 0);
        $recommendations = $this->getRecommendations([
            'academic' => $calc['academic_score'],
            'teaching' => $calc['teaching_score'],
            'climate' => $calc['climate_score'],
            'behavior' => $calc['behavior_score'],
            'skills' => $calc['skills_score'],
            'attendance' => $calc['attendance_score'],
        ]);
        
        if ($format === 'csv') {
            return $this->exportToCSV($calc, $school, $rating, $recommendations);
        }
        
        return [
            'calculation' => $calc,
            'school' => $school,
            'rating' => $rating,
            'recommendations' => $recommendations,
        ];
    }
    
    /**
     * تصدير إلى CSV
     */
    private function exportToCSV($calc, $school, $rating, $recommendations) {
        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        
        fputcsv($output, ['تقرير مؤشر جودة التعليم - ' . ($school['name_ar'] ?? '')]);
        fputcsv($output, []);
        fputcsv($output, ['المؤشر الكلي', $calc['overall_eqi'], $rating['text']]);
        fputcsv($output, []);
        fputcsv($output, ['البعد', 'الدرجة', 'الوزن', 'التصنيف']);
        
        $dimKeys = ['academic', 'teaching', 'climate', 'behavior', 'skills', 'attendance'];
        foreach ($dimKeys as $key) {
            $score = $calc[$key . '_score'];
            if ($score !== null) {
                $dimRating = $this->getEQIRating($score);
                fputcsv($output, [
                    $this->dimensions[$key]['name'],
                    $score,
                    ($this->dimensions[$key]['weight'] * 100) . '%',
                    $dimRating['text'],
                ]);
            }
        }
        
        fputcsv($output, []);
        fputcsv($output, ['التوصيات']);
        foreach ($recommendations as $dim => $rec) {
            fputcsv($output, [$rec['dimension'], $rec['recommendation']]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}
