<?php
/**
 * ============================================================
 * EQAS - محسب الدرجات الأكاديمية
 * نظام التقييم الشامل - حسابات الدرجات + المعدل + الترتيب
 * ============================================================
 * 
 * يتضمن:
 * - حساب المعدل التراكمي (GPA)
 * - حساب النسبة المئوية
 * - تحديد الدرجة الحرفية (Letter Grade)
 * - حساب الترتيب في القسم
 * - نظام المواد الراسبة (Carry Over) - أساس vs ثانوي
 * - اختبارات التعويض (Makeup Exam) للثانوي
 * - حساب نتائج الفصل الدراسي
 */

class AcademicCalculator {
    /** @var PDO اتصال قاعدة البيانات */
    private $db;
    
    /** @var float درجة النجاح الافتراضية (50%) */
    private $passingGrade = 50.00;
    
    /** @var int الحد الأقصى للمواد الراسبة في الأساس */
    private $maxBasicCarryOver = 2;
    
    /** @var int الحد الأقصى لمحاولات التعويض */
    private $maxMakeupAttempts = 2;
    
    /**
     * إنشاء كائن محسب الدرجات الأكاديمية
     * @param PDO $db اتصال قاعدة البيانات
     */
    public function __construct($db) {
        $this->db = $db;
        $this->loadSettings();
    }
    
    /**
     * تحميل إعدادات الحساب من قاعدة البيانات
     */
    private function loadSettings() {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'academic_%'");
            while ($row = $stmt->fetch()) {
                switch ($row['setting_key']) {
                    case 'academic_passing_grade':
                        $this->passingGrade = (float)$row['setting_value'];
                        break;
                    case 'academic_max_basic_carryover':
                        $this->maxBasicCarryOver = (int)$row['setting_value'];
                        break;
                    case 'academic_max_makeup_attempts':
                        $this->maxMakeupAttempts = (int)$row['setting_value'];
                        break;
                }
            }
        } catch (Exception $e) {
            // استخدام القيم الافتراضية
            error_log('AcademicCalculator: استخدام القيم الافتراضية - ' . $e->getMessage());
        }
    }
    
    // ========================================================================
    // حساب المعدل التراكمي (GPA)
    // ========================================================================
    
    /**
     * حساب المعدل التراكمي للطالب في فصل دراسي
     * GPA = مجموع(grade_point × hours) / مجموع(hours)
     * مقياس 4.00
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج الحساب [gpa, total_hours, total_points, subjects_count]
     */
    public function calculateGPA($studentId, $academicYearId, $semesterId) {
        try {
            // 1. جلب ملخص درجات الطالب لجميع المواد في هذا الفصل
            $sql = "SELECT sss.subject_id, sss.percentage, sss.letter_grade, sss.grade_point,
                           sgy.hours_per_week, sub.name_ar AS subject_name
                    FROM student_subject_summary sss
                    INNER JOIN subjects sub ON sub.id = sss.subject_id
                    LEFT JOIN subject_grade_years sgy ON sgy.subject_id = sss.subject_id 
                        AND sgy.grade_level_id = (
                            SELECT grade_level_id FROM students WHERE id = :student_id2
                        )
                    WHERE sss.student_id = :student_id
                        AND sss.academic_year_id = :year_id
                        AND sss.semester_id = :semester_id
                        AND sss.is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':student_id2' => $studentId
            ]);
            
            $subjects = $stmt->fetchAll();
            
            // 2. إذا لم تتوفر ملخصات محسوبة، نحسبها من جدول الدرجات مباشرة
            if (empty($subjects)) {
                $subjects = $this->calculateAllSubjectPercentages($studentId, $academicYearId, $semesterId);
            }
            
            // 3. حساب المعدل التراكمي المرجح
            $totalWeightedPoints = 0;
            $totalHours = 0;
            $subjectCount = 0;
            
            foreach ($subjects as $subject) {
                $hours = max(1, (float)($subject['hours_per_week'] ?? 1));
                $gradePoint = (float)($subject['grade_point'] ?? 0);
                
                // إذا لم تكن نقطة الدرجة محسوبة، نحسبها من النسبة
                if ($gradePoint <= 0 && isset($subject['percentage'])) {
                    $letterInfo = $this->getLetterGrade((float)$subject['percentage']);
                    $gradePoint = $letterInfo['point'];
                }
                
                $totalWeightedPoints += $gradePoint * $hours;
                $totalHours += $hours;
                $subjectCount++;
            }
            
            // 4. حساب المعدل
            $gpa = $totalHours > 0 ? round($totalWeightedPoints / $totalHours, 2) : 0;
            
            // 5. حفظ في جدول semester_scores
            $this->saveSemesterScore($studentId, $academicYearId, $semesterId, $gpa, $totalHours);
            
            return [
                'gpa' => $gpa,
                'total_hours' => $totalHours,
                'total_weighted_points' => round($totalWeightedPoints, 2),
                'subjects_count' => $subjectCount,
                'scale' => 4.00
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::calculateGPA - ' . $e->getMessage());
            return ['gpa' => 0, 'total_hours' => 0, 'total_weighted_points' => 0, 'subjects_count' => 0, 'scale' => 4.00];
        }
    }
    
    /**
     * حساب النسب المئوية لجميع مواد الطالب
     * دالة مساعدة عند عدم توفر ملخصات محسوبة
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array مصفوفة النتائج لكل مادة
     */
    private function calculateAllSubjectPercentages($studentId, $academicYearId, $semesterId) {
        // جلب فصل الطالب
        $stmt = $this->db->prepare("
            SELECT se.class_id, c.grade_level_id 
            FROM student_enrollments se
            INNER JOIN classes c ON c.id = se.class_id
            WHERE se.student_id = ? AND se.academic_year_id = ? AND se.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$studentId, $academicYearId]);
        $enrollment = $stmt->fetch();
        
        if (!$enrollment) return [];
        
        // جلب مواد الفصل
        $stmt = $this->db->prepare("
            SELECT DISTINCT sub.id AS subject_id, sub.name_ar, sub.code
            FROM subject_teachers st
            INNER JOIN subjects sub ON sub.id = st.subject_id
            WHERE st.classroom_id = ? AND st.academic_year_id = ?
        ");
        $stmt->execute([$enrollment['class_id'], $academicYearId]);
        $subjects = $stmt->fetchAll();
        
        $results = [];
        foreach ($subjects as $subject) {
            $percentage = $this->calculateSubjectPercentage(
                $studentId, $subject['subject_id'], $enrollment['class_id'], $academicYearId, $semesterId
            );
            
            $letterInfo = $this->getLetterGrade($percentage);
            
            // جلب عدد الساعات
            $stmt = $this->db->prepare("
                SELECT hours_per_week FROM subject_grade_years 
                WHERE subject_id = ? AND grade_level_id = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$subject['subject_id'], $enrollment['grade_level_id']]);
            $hoursRow = $stmt->fetch();
            
            $results[] = [
                'subject_id' => $subject['subject_id'],
                'subject_name' => $subject['name_ar'],
                'percentage' => $percentage,
                'letter_grade' => $letterInfo['letter'],
                'grade_point' => $letterInfo['point'],
                'hours_per_week' => $hoursRow ? (float)$hoursRow['hours_per_week'] : 1
            ];
        }
        
        return $results;
    }
    
    // ========================================================================
    // حساب النسبة المئوية لمادة واحدة
    // ========================================================================
    
    /**
     * حساب النسبة المئوية لمادة واحدة
     * بناءً على توزيع الأوزان (subject_weight_distribution)
     * 
     * @param int $studentId معرف الطالب
     * @param int $subjectId معرف المادة
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return float النسبة المئوية (0-100)
     */
    public function calculateSubjectPercentage($studentId, $subjectId, $classId, $academicYearId, $semesterId) {
        try {
            // إصلاح class_id = null — SEC-002
            if (empty($classId)) {
                $enrollStmt = $this->db->prepare("SELECT classroom_id FROM student_enrollments WHERE student_id = ? AND academic_year_id = ? AND is_active = 1 LIMIT 1");
                $enrollStmt->execute([$studentId, $academicYearId]);
                $classId = $enrollStmt->fetchColumn() ?: 0;
            }
            if ($classId == 0) {
                error_log("calculateSubjectPercentage: classId is zero for student $studentId academic_year $academicYearId");
                return 0;
            }

            // 1. جلب توزيع الأوزان للمادة
            $stmt = $this->db->prepare("
                SELECT grade_type, weight_percentage 
                FROM subject_weight_distribution 
                WHERE subject_id = ? AND class_id = ? AND academic_year_id = ? AND is_active = 1
                ORDER BY grade_type
            ");
            $stmt->execute([$subjectId, $classId, $academicYearId]);
            $weights = $stmt->fetchAll();
            
            // إذا لم تتوفر أوزان مخصصة، نستخدم الأوزان الافتراضية
            if (empty($weights)) {
                $weights = $this->getDefaultWeights();
            }
            
            // 2. جلب جميع درجات الطالب في هذه المادة
            $stmt = $this->db->prepare("
                SELECT grade_type, grade_value, max_value 
                FROM grades 
                WHERE student_id = ? AND subject_id = ? AND class_id = ? 
                    AND academic_year_id = ? AND semester_id = ? AND is_active = 1
            ");
            $stmt->execute([$studentId, $subjectId, $classId, $academicYearId, $semesterId]);
            $grades = $stmt->fetchAll();
            
            if (empty($grades)) {
                return 0;
            }
            
            // 3. تجميع الدرجات حسب النوع وحساب المتوسط لكل نوع
            $gradesByType = [];
            foreach ($grades as $grade) {
                $type = $grade['grade_type'];
                if (!isset($gradesByType[$type])) {
                    $gradesByType[$type] = ['total' => 0, 'count' => 0, 'max' => 0];
                }
                $gradesByType[$type]['total'] += (float)$grade['grade_value'];
                $gradesByType[$type]['count']++;
                $gradesByType[$type]['max'] = max($gradesByType[$type]['max'], (float)$grade['max_value']);
            }
            
            // 4. حساب المتوسط المرجح
            $totalWeightedScore = 0;
            $totalAppliedWeight = 0;
            
            foreach ($weights as $weightRow) {
                $type = $weightRow['grade_type'];
                $weightPct = (float)$weightRow['weight_percentage'];
                
                if (isset($gradesByType[$type]) && $gradesByType[$type]['count'] > 0) {
                    // حساب المتوسط للنوع ثم معيرته إلى 100
                    $avgValue = $gradesByType[$type]['total'] / $gradesByType[$type]['count'];
                    $maxValue = $gradesByType[$type]['max'];
                    $normalizedPct = $maxValue > 0 ? ($avgValue / $maxValue) * 100 : 0;
                    
                    $totalWeightedScore += $normalizedPct * $weightPct;
                    $totalAppliedWeight += $weightPct;
                }
            }
            
            // إذا لم يطبق أي وزن (لا توجد درجات لأنواع ذات أوزان)
            $percentage = $totalAppliedWeight > 0 
                ? round($totalWeightedScore / $totalAppliedWeight, 2) 
                : 0;
            
            // 5. حفظ/تحديث ملخص المادة
            $this->saveSubjectSummary(
                $studentId, $subjectId, $classId, $academicYearId, $semesterId, $percentage
            );
            
            return $percentage;
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::calculateSubjectPercentage - ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * الحصول على الأوزان الافتراضية لتوزيع الدرجات
     * الأوزان الافتراضية حسب النظام 
     * 
     * @return array مصفوفة الأوزان
     */
    private function getDefaultWeights() {
        return [
            ['grade_type' => 'exam',          'weight_percentage' => 30],
            ['grade_type' => 'quiz',          'weight_percentage' => 15],
            ['grade_type' => 'homework',      'weight_percentage' => 10],
            ['grade_type' => 'project',       'weight_percentage' => 10],
            ['grade_type' => 'participation', 'weight_percentage' => 5],
            ['grade_type' => 'final',         'weight_percentage' => 30],
        ];
    }
    
    /**
     * حفظ ملخص درجات المادة
     * 
     * @param int $studentId معرف الطالب
     * @param int $subjectId معرف المادة
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param float $percentage النسبة المئوية
     * @return bool نجاح أو فشل
     */
    private function saveSubjectSummary($studentId, $subjectId, $classId, $academicYearId, $semesterId, $percentage) {
        try {
            $letterInfo = $this->getLetterGrade($percentage);
            $passingStatus = $percentage >= $this->passingGrade ? 'pass' : 'fail';
            
            $sql = "INSERT INTO student_subject_summary 
                    (student_id, subject_id, class_id, academic_year_id, semester_id, 
                     percentage, letter_grade, grade_point, passing_status, is_active, calculated_at)
                    VALUES (:student_id, :subject_id, :class_id, :year_id, :semester_id, 
                            :percentage, :letter, :point, :status, 1, NOW())
                    ON DUPLICATE KEY UPDATE 
                        percentage = :percentage2,
                        letter_grade = :letter2,
                        grade_point = :point2,
                        passing_status = :status2,
                        calculated_at = NOW()";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':student_id' => $studentId,
                ':subject_id' => $subjectId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':percentage' => $percentage,
                ':letter' => $letterInfo['letter'],
                ':point' => $letterInfo['point'],
                ':status' => $passingStatus,
                ':percentage2' => $percentage,
                ':letter2' => $letterInfo['letter'],
                ':point2' => $letterInfo['point'],
                ':status2' => $passingStatus
            ]);
        } catch (Exception $e) {
            error_log('AcademicCalculator::saveSubjectSummary - ' . $e->getMessage());
            return false;
        }
    }
    
    // ========================================================================
    // الدرجة الحرفية (Letter Grade)
    // ========================================================================
    
    /**
     * تحديد الدرجة الحرفية من النسبة المئوية
     * باستخدام جدول grade_scale
     * 
     * @param float $percentage النسبة المئوية (0-100)
     * @return array ['letter' => string, 'point' => float, 'name_ar' => string]
     */
    public function getLetterGrade($percentage) {
        try {
            $stmt = $this->db->prepare("
                SELECT letter, gpa_value, name_ar 
                FROM grade_scale 
                WHERE :percentage >= min_value AND :percentage2 <= max_value AND is_active = 1
                ORDER BY sort_order ASC 
                LIMIT 1
            ");
            $stmt->execute([
                ':percentage' => $percentage,
                ':percentage2' => $percentage
            ]);
            $result = $stmt->fetch();
            
            if ($result) {
                return [
                    'letter' => $result['letter'],
                    'point' => (float)$result['gpa_value'],
                    'name_ar' => $result['name_ar']
                ];
            }
        } catch (Exception $e) {
            error_log('AcademicCalculator::getLetterGrade - ' . $e->getMessage());
        }
        
        // مقياس افتراضي في حالة عدم وجود بيانات في الجدول
        return $this->getDefaultLetterGrade($percentage);
    }
    
    /**
     * الحصول على الدرجة الحرفية الافتراضية
     * المقياس  الافتراضي (مقياس 4.00)
     * 
     * @param float $percentage النسبة المئوية
     * @return array الدرجة الحرفية
     */
    private function getDefaultLetterGrade($percentage) {
        // المقياس  للدرجات الحرفية
        $scale = [
            ['min' => 95, 'max' => 100, 'letter' => 'A+', 'point' => 4.00, 'name' => 'ممتاز'],
            ['min' => 90, 'max' => 94.99, 'letter' => 'A',  'point' => 3.75, 'name' => 'ممتاز'],
            ['min' => 85, 'max' => 89.99, 'letter' => 'B+', 'point' => 3.50, 'name' => 'جيد جداً'],
            ['min' => 80, 'max' => 84.99, 'letter' => 'B',  'point' => 3.25, 'name' => 'جيد جداً'],
            ['min' => 75, 'max' => 79.99, 'letter' => 'C+', 'point' => 3.00, 'name' => 'جيد'],
            ['min' => 70, 'max' => 74.99, 'letter' => 'C',  'point' => 2.75, 'name' => 'جيد'],
            ['min' => 65, 'max' => 69.99, 'letter' => 'D+', 'point' => 2.50, 'name' => 'مقبول'],
            ['min' => 60, 'max' => 64.99, 'letter' => 'D',  'point' => 2.25, 'name' => 'مقبول'],
            ['min' => 50, 'max' => 59.99, 'letter' => 'E',  'point' => 1.00, 'name' => 'ضعيف'],
            ['min' => 0,  'max' => 49.99, 'letter' => 'F',  'point' => 0.00, 'name' => 'راسب'],
        ];
        
        foreach ($scale as $entry) {
            if ($percentage >= $entry['min'] && $percentage <= $entry['max']) {
                return [
                    'letter' => $entry['letter'],
                    'point' => $entry['point'],
                    'name_ar' => $entry['name']
                ];
            }
        }
        
        return ['letter' => 'F', 'point' => 0.00, 'name_ar' => 'راسب'];
    }
    
    // ========================================================================
    // حساب الترتيب
    // ========================================================================
    
    /**
     * حساب الترتيب في القسم
     * بناءً على المعدل التراكمي
     * 
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج الترتيب
     */
    public function calculateClassRank($classId, $academicYearId, $semesterId) {
        try {
            // 1. جلب جميع طلاب الفصل مع معدلاتهم
            $sql = "SELECT se.student_id, s.full_name_ar, s.student_number,
                           ss.gpa, ss.overall_percentage
                    FROM student_enrollments se
                    INNER JOIN students s ON s.id = se.student_id
                    LEFT JOIN semester_scores ss ON ss.student_id = se.student_id 
                        AND ss.academic_year_id = se.academic_year_id 
                        AND ss.semester_id = :semester_id2
                    WHERE se.class_id = :class_id
                        AND se.academic_year_id = :year_id
                        AND se.status = 'enrolled'
                        AND se.is_active = 1
                        AND s.is_active = 1
                    ORDER BY ss.gpa DESC, ss.overall_percentage DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':semester_id2' => $semesterId
            ]);
            
            $students = $stmt->fetchAll();
            $results = [];
            $rank = 1;
            $prevGpa = null;
            $prevPercentage = null;
            $sameRankCount = 0;
            
            foreach ($students as $index => $student) {
                $gpa = (float)($student['gpa'] ?? 0);
                $percentage = (float)($student['overall_percentage'] ?? 0);
                
                // التعامل مع الترتيب المتساوي (نفس المعدل = نفس الترتيب)
                if ($prevGpa !== null && $gpa < $prevGpa) {
                    $rank += $sameRankCount;
                    $sameRankCount = 1;
                } elseif ($prevGpa !== null && $gpa == $prevGpa && $percentage < $prevPercentage) {
                    $rank += $sameRankCount;
                    $sameRankCount = 1;
                } else {
                    $sameRankCount++;
                }
                
                // 2. تحديث الترتيب في semester_scores
                $this->updateStudentRank($student['student_id'], $academicYearId, $semesterId, $rank, 'class');
                
                $results[] = [
                    'student_id' => $student['student_id'],
                    'student_name' => $student['full_name_ar'],
                    'student_number' => $student['student_number'],
                    'gpa' => $gpa,
                    'percentage' => $percentage,
                    'rank' => $rank
                ];
                
                $prevGpa = $gpa;
                $prevPercentage = $percentage;
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::calculateClassRank - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * حساب الترتيب في المرحلة الدراسية
     * ترتيب الطلاب عبر جميع فصول نفس المرحلة
     * 
     * @param int $gradeLevelId معرف المرحلة الدراسية
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج الترتيب
     */
    public function calculateGradeRank($gradeLevelId, $academicYearId, $semesterId) {
        try {
            // جلب جميع طلاب المرحلة الدراسية مع معدلاتهم
            $sql = "SELECT se.student_id, s.full_name_ar, s.student_number,
                           ss.gpa, ss.overall_percentage, c.name_ar AS class_name
                    FROM student_enrollments se
                    INNER JOIN students s ON s.id = se.student_id
                    INNER JOIN classes c ON c.id = se.class_id
                    LEFT JOIN semester_scores ss ON ss.student_id = se.student_id 
                        AND ss.academic_year_id = se.academic_year_id 
                        AND ss.semester_id = :semester_id2
                    WHERE c.grade_level_id = :grade_level_id
                        AND se.academic_year_id = :year_id
                        AND se.status = 'enrolled'
                        AND se.is_active = 1
                        AND s.is_active = 1
                    ORDER BY ss.gpa DESC, ss.overall_percentage DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':grade_level_id' => $gradeLevelId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':semester_id2' => $semesterId
            ]);
            
            $students = $stmt->fetchAll();
            $results = [];
            $rank = 1;
            $sameRankCount = 0;
            $prevGpa = null;
            $prevPercentage = null;
            
            foreach ($students as $student) {
                $gpa = (float)($student['gpa'] ?? 0);
                $percentage = (float)($student['overall_percentage'] ?? 0);
                
                // التعامل مع الترتيب المتساوي
                if ($prevGpa !== null && ($gpa < $prevGpa || ($gpa == $prevGpa && $percentage < $prevPercentage))) {
                    $rank += $sameRankCount;
                    $sameRankCount = 1;
                } else {
                    $sameRankCount++;
                }
                
                // تحديث الترتيب في semester_scores
                $this->updateStudentRank($student['student_id'], $academicYearId, $semesterId, $rank, 'grade');
                
                $results[] = [
                    'student_id' => $student['student_id'],
                    'student_name' => $student['full_name_ar'],
                    'student_number' => $student['student_number'],
                    'class_name' => $student['class_name'],
                    'gpa' => $gpa,
                    'percentage' => $percentage,
                    'rank' => $rank
                ];
                
                $prevGpa = $gpa;
                $prevPercentage = $percentage;
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::calculateGradeRank - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * تحديث ترتيب الطالب في semester_scores
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param int $rank الترتيب
     * @param string $rankType نوع الترتيب (class أو grade)
     * @return bool نجاح أو فشل
     */
    private function updateStudentRank($studentId, $academicYearId, $semesterId, $rank, $rankType) {
        try {
            $field = $rankType === 'class' ? 'rank_in_class' : 'rank_in_grade';
            
            $sql = "UPDATE semester_scores SET {$field} = :rank 
                    WHERE student_id = :student_id 
                        AND academic_year_id = :year_id 
                        AND semester_id = :semester_id 
                        AND is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':rank' => $rank,
                ':student_id' => $studentId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId
            ]);
        } catch (Exception $e) {
            error_log('AcademicCalculator::updateStudentRank - ' . $e->getMessage());
            return false;
        }
    }
    
    // ========================================================================
    // نظام المواد الراسبة (Carry Over)
    // ========================================================================
    
    /**
     * نظام المواد الراسبة (Carry Over)
     * الأساس (1-9): ترفيع عادي مع حد أقصى مادتين carry_over
     * الثانوي (10-12): لا ترفيع مع carry_over - يجب اجتياز جميع المواد
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج المواد الراسبة
     */
    public function calculateCarryOver($studentId, $academicYearId, $semesterId) {
        try {
            // 1. جلب مرحلة الطالب (أساس/ثانوي)
            $stmt = $this->db->prepare("
                SELECT gl.id, gl.level_number, gl.stage, gl.name_ar
                FROM students s
                INNER JOIN grade_levels gl ON gl.id = s.grade_level_id
                WHERE s.id = ? AND s.is_active = 1
            ");
            $stmt->execute([$studentId]);
            $gradeLevel = $stmt->fetch();
            
            if (!$gradeLevel) {
                return ['error' => 'لم يتم العثور على بيانات المرحلة الدراسية'];
            }
            
            $isSecondary = ($gradeLevel['stage'] === 'secondary');
            $stageName = $isSecondary ? 'ثانوي' : 'أساس';
            
            // 2. جلب المواد الراسبة (نسبة أقل من درجة النجاح)
            $stmt = $this->db->prepare("
                SELECT sss.subject_id, sss.percentage, sss.letter_grade, sss.passing_status,
                       sub.name_ar AS subject_name, sub.code AS subject_code
                FROM student_subject_summary sss
                INNER JOIN subjects sub ON sub.id = sss.subject_id
                WHERE sss.student_id = ?
                    AND sss.academic_year_id = ?
                    AND sss.semester_id = ?
                    AND sss.percentage < ?
                    AND sss.is_active = 1
                ORDER BY sss.percentage ASC
            ");
            $stmt->execute([$studentId, $academicYearId, $semesterId, $this->passingGrade]);
            $failedSubjects = $stmt->fetchAll();
            
            // 3. جلب المواد الراسبة من فصول سابقة (لم يتم اجتيازها بعد)
            $previousCarryOvers = $this->getPreviousCarryOvers($studentId, $academicYearId, $semesterId);
            
            // 4. تطبيق القواعد حسب المرحلة
            $carryOverSubjects = [];
            $canBePromoted = true;
            $needsMakeup = false;
            
            if ($isSecondary) {
                // الثانوي: لا ترفيع - يجب اجتياز جميع المواد
                foreach ($failedSubjects as $subject) {
                    $carryOverSubjects[] = [
                        'subject_id' => $subject['subject_id'],
                        'subject_name' => $subject['subject_name'],
                        'subject_code' => $subject['subject_code'],
                        'percentage' => (float)$subject['percentage'],
                        'letter_grade' => $subject['letter_grade'],
                        'type' => 'carry_over',
                        'needs_makeup' => true
                    ];
                }
                
                // إضافة المواد الراسبة السابقة
                foreach ($previousCarryOvers as $prevSubject) {
                    $carryOverSubjects[] = array_merge($prevSubject, ['needs_makeup' => true]);
                }
                
                if (!empty($carryOverSubjects)) {
                    $canBePromoted = false;
                    $needsMakeup = true;
                }
                
            } else {
                // الأساس: ترفيع مع حد أقصى مادتين carry_over
                $totalFailures = count($failedSubjects) + count($previousCarryOvers);
                
                foreach ($failedSubjects as $subject) {
                    $carryOverSubjects[] = [
                        'subject_id' => $subject['subject_id'],
                        'subject_name' => $subject['subject_name'],
                        'subject_code' => $subject['subject_code'],
                        'percentage' => (float)$subject['percentage'],
                        'letter_grade' => $subject['letter_grade'],
                        'type' => 'carry_over',
                        'needs_makeup' => false
                    ];
                }
                
                // إضافة المواد الراسبة السابقة
                foreach ($previousCarryOvers as $prevSubject) {
                    $carryOverSubjects[] = array_merge($prevSubject, ['needs_makeup' => false]);
                }
                
                // التحقق من حد المواد الراسبة
                if (count($carryOverSubjects) > $this->maxBasicCarryOver) {
                    $canBePromoted = false;
                }
            }
            
            // 5. حفظ المواد الراسبة في semester_scores
            $this->saveCarryOverSubjects($studentId, $academicYearId, $semesterId, $carryOverSubjects);
            
            return [
                'student_id' => $studentId,
                'grade_level' => $gradeLevel,
                'stage' => $stageName,
                'is_secondary' => $isSecondary,
                'failed_subjects' => $failedSubjects,
                'carry_over_subjects' => $carryOverSubjects,
                'previous_carry_overs' => $previousCarryOvers,
                'total_carry_over_count' => count($carryOverSubjects),
                'can_be_promoted' => $canBePromoted,
                'needs_makeup' => $needsMakeup,
                'max_basic_carryover' => $this->maxBasicCarryOver
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::calculateCarryOver - ' . $e->getMessage());
            return ['error' => 'حدث خطأ أثناء حساب المواد الراسبة'];
        }
    }
    
    /**
     * جلب المواد الراسبة من فصول سابقة
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية الحالية
     * @param int $semesterId معرف الفصل الدراسي الحالي
     * @return array المواد الراسبة السابقة
     */
    private function getPreviousCarryOvers($studentId, $academicYearId, $semesterId) {
        try {
            $stmt = $this->db->prepare("
                SELECT ss.carry_over_subjects
                FROM semester_scores ss
                WHERE ss.student_id = ?
                    AND (ss.academic_year_id < ? OR (ss.academic_year_id = ? AND ss.semester_id < ?))
                    AND ss.carry_over_subjects IS NOT NULL
                    AND ss.is_active = 1
                ORDER BY ss.academic_year_id DESC, ss.semester_id DESC
                LIMIT 1
            ");
            $stmt->execute([$studentId, $academicYearId, $academicYearId, $semesterId]);
            $result = $stmt->fetch();
            
            if ($result && !empty($result['carry_over_subjects'])) {
                $carryOvers = json_decode($result['carry_over_subjects'], true);
                return is_array($carryOvers) ? $carryOvers : [];
            }
            
            return [];
        } catch (Exception $e) {
            error_log('AcademicCalculator::getPreviousCarryOvers - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * حفظ المواد الراسبة في سجل الفصل
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @param array $carryOverSubjects قائمة المواد الراسبة
     * @return bool نجاح أو فشل
     */
    private function saveCarryOverSubjects($studentId, $academicYearId, $semesterId, $carryOverSubjects) {
        try {
            $json = json_encode($carryOverSubjects, JSON_UNESCAPED_UNICODE);
            
            $sql = "UPDATE semester_scores 
                    SET carry_over_subjects = :json 
                    WHERE student_id = :student_id 
                        AND academic_year_id = :year_id 
                        AND semester_id = :semester_id 
                        AND is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':json' => $json,
                ':student_id' => $studentId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId
            ]);
        } catch (Exception $e) {
            error_log('AcademicCalculator::saveCarryOverSubjects - ' . $e->getMessage());
            return false;
        }
    }
    
    // ========================================================================
    // اختبارات التعويض (Makeup Exam) - الثانوي فقط
    // ========================================================================
    
    /**
     * اختبارات التعويض (Makeup Exam) - الثانوي فقط
     * الطلاب في المرحلة الثانوية الذين رسبوا في مواد
     * يحق لهم إعادة الاختبار
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج إنشاء اختبارات التعويض
     */
    public function createMakeupExams($studentId, $academicYearId, $semesterId) {
        try {
            // 1. التحقق من أن الطالب في المرحلة الثانوية
            $stmt = $this->db->prepare("
                SELECT gl.stage, gl.level_number, gl.name_ar
                FROM students s
                INNER JOIN grade_levels gl ON gl.id = s.grade_level_id
                WHERE s.id = ? AND s.is_active = 1
            ");
            $stmt->execute([$studentId]);
            $gradeLevel = $stmt->fetch();
            
            if (!$gradeLevel || $gradeLevel['stage'] !== 'secondary') {
                return [
                    'success' => false,
                    'message' => 'اختبارات التعويض متاحة فقط لطلاب المرحلة الثانوية'
                ];
            }
            
            // 2. جلب المواد الراسبة
            $carryOverResult = $this->calculateCarryOver($studentId, $academicYearId, $semesterId);
            $failedSubjects = $carryOverResult['carry_over_subjects'] ?? [];
            
            if (empty($failedSubjects)) {
                return [
                    'success' => true,
                    'message' => 'لا توجد مواد راسبة - لا حاجة لاختبارات تعويض',
                    'makeup_exams' => []
                ];
            }
            
            // 3. إنشاء اختبارات التعويض
            $makeupExams = [];
            foreach ($failedSubjects as $subject) {
                // التحقق من عدد المحاولات السابقة
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) AS attempt_count 
                    FROM makeup_exams 
                    WHERE student_id = ? AND subject_id = ? 
                        AND academic_year_id = ? AND semester_id = ? 
                        AND is_active = 1
                ");
                $stmt->execute([$studentId, $subject['subject_id'], $academicYearId, $semesterId]);
                $attemptRow = $stmt->fetch();
                $attemptCount = (int)($attemptRow['attempt_count'] ?? 0);
                
                if ($attemptCount >= $this->maxMakeupAttempts) {
                    $makeupExams[] = [
                        'subject_id' => $subject['subject_id'],
                        'subject_name' => $subject['subject_name'] ?? '',
                        'status' => 'max_attempts_reached',
                        'message' => 'تم الوصول للحد الأقصى من المحاولات (' . $this->maxMakeupAttempts . ')'
                    ];
                    continue;
                }
                
                // إنشاء سجل اختبار التعويض
                $sql = "INSERT INTO makeup_exams 
                        (student_id, subject_id, academic_year_id, semester_id, 
                         attempt_number, original_percentage, status, is_active, created_at)
                        VALUES (:student_id, :subject_id, :year_id, :semester_id, 
                                :attempt, :original_pct, 'pending', 1, NOW())";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':student_id' => $studentId,
                    ':subject_id' => $subject['subject_id'],
                    ':year_id' => $academicYearId,
                    ':semester_id' => $semesterId,
                    ':attempt' => $attemptCount + 1,
                    ':original_pct' => $subject['percentage'] ?? 0
                ]);
                
                $makeupExams[] = [
                    'subject_id' => $subject['subject_id'],
                    'subject_name' => $subject['subject_name'] ?? '',
                    'attempt_number' => $attemptCount + 1,
                    'original_percentage' => $subject['percentage'] ?? 0,
                    'status' => 'pending',
                    'makeup_exam_id' => $this->db->lastInsertId()
                ];
            }
            
            return [
                'success' => true,
                'message' => 'تم إنشاء اختبارات التعويض بنجاح',
                'student_id' => $studentId,
                'grade_level' => $gradeLevel,
                'makeup_exams' => $makeupExams,
                'total_exams' => count($makeupExams)
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::createMakeupExams - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء إنشاء اختبارات التعويض'];
        }
    }
    
    /**
     * تسجيل نتيجة اختبار التعويض
     * 
     * @param int $makeupExamId معرف اختبار التعويض
     * @param float $newPercentage النسبة الجديدة
     * @param int $gradedBy معرف المصحح
     * @return array نتائج التسجيل
     */
    public function recordMakeupExamResult($makeupExamId, $newPercentage, $gradedBy) {
        try {
            // جلب بيانات اختبار التعويض
            $stmt = $this->db->prepare("SELECT * FROM makeup_exams WHERE id = ? AND is_active = 1");
            $stmt->execute([$makeupExamId]);
            $exam = $stmt->fetch();
            
            if (!$exam) {
                return ['success' => false, 'message' => 'لم يتم العثور على اختبار التعويض'];
            }
            
            if ($exam['status'] !== 'pending') {
                return ['success' => false, 'message' => 'اختبار التعويض ليس في حالة الانتظار'];
            }
            
            // تحديث نتيجة اختبار التعويض
            $newStatus = $newPercentage >= $this->passingGrade ? 'pass' : 'fail';
            
            $stmt = $this->db->prepare("
                UPDATE makeup_exams 
                SET new_percentage = :new_pct, status = :status, graded_by = :graded_by, graded_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':new_pct' => $newPercentage,
                ':status' => $newStatus,
                ':graded_by' => $gradedBy,
                ':id' => $makeupExamId
            ]);
            
            // إذا نجح في التعويض، نحدّث ملخص المادة
            if ($newStatus === 'pass') {
                $this->saveSubjectSummary(
                    $exam['student_id'],
                    $exam['subject_id'],
                    null, // class_id سيتم جلبه
                    $exam['academic_year_id'],
                    $exam['semester_id'],
                    $newPercentage
                );
            }
            
            return [
                'success' => true,
                'message' => $newStatus === 'pass' ? 'نجح الطالب في اختبار التعويض' : 'لم يجتز الطالب اختبار التعويض',
                'new_status' => $newStatus,
                'new_percentage' => $newPercentage,
                'original_percentage' => (float)$exam['original_percentage']
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::recordMakeupExamResult - ' . $e->getMessage());
            return ['success' => false, 'message' => 'حدث خطأ أثناء تسجيل النتيجة'];
        }
    }
    
    // ========================================================================
    // حساب نتائج الفصل الدراسي الكاملة
    // ========================================================================
    
    /**
     * حساب نتائج الفصل الدراسي الكاملة
     * يشمل: GPA, نسبة مئوية إجمالية, ترتيب, carry_over
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج الفصل الكاملة
     */
    public function calculateSemesterResults($studentId, $academicYearId, $semesterId) {
        try {
            // 1. حساب النسب المئوية لجميع المواد
            $subjects = $this->calculateAllSubjectPercentages($studentId, $academicYearId, $semesterId);
            
            // 2. حساب المعدل التراكمي
            $gpaResult = $this->calculateGPA($studentId, $academicYearId, $semesterId);
            
            // 3. حساب النسبة المئوية الإجمالية
            $totalPercentage = 0;
            $subjectCount = count($subjects);
            $passedCount = 0;
            $failedCount = 0;
            $failedSubjects = [];
            
            foreach ($subjects as $subject) {
                $pct = (float)($subject['percentage'] ?? 0);
                $totalPercentage += $pct;
                
                if ($pct >= $this->passingGrade) {
                    $passedCount++;
                } else {
                    $failedCount++;
                    $failedSubjects[] = $subject;
                }
            }
            
            $overallPercentage = $subjectCount > 0 
                ? round($totalPercentage / $subjectCount, 2) 
                : 0;
            
            // 4. حساب المواد الراسبة
            $carryOverResult = $this->calculateCarryOver($studentId, $academicYearId, $semesterId);
            
            // 5. تحديث سجل الفصل
            $this->updateSemesterScoreFull(
                $studentId, $academicYearId, $semesterId,
                $gpaResult['gpa'], $overallPercentage,
                $passedCount, $failedCount, $subjectCount
            );
            
            // 6. تجميع النتائج
            $letterInfo = $this->getLetterGrade($overallPercentage);
            
            return [
                'student_id' => $studentId,
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
                'gpa' => $gpaResult['gpa'],
                'gpa_scale' => 4.00,
                'overall_percentage' => $overallPercentage,
                'letter_grade' => $letterInfo['letter'],
                'grade_name' => $letterInfo['name_ar'],
                'subjects' => $subjects,
                'subjects_count' => $subjectCount,
                'passed_count' => $passedCount,
                'failed_count' => $failedCount,
                'failed_subjects' => $failedSubjects,
                'carry_over' => $carryOverResult,
                'is_passing' => $failedCount === 0,
                'passing_grade' => $this->passingGrade
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::calculateSemesterResults - ' . $e->getMessage());
            return ['error' => 'حدث خطأ أثناء حساب نتائج الفصل'];
        }
    }
    
    /**
     * حساب نتائج الفصل لجميع طلاب الصف
     * 
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array نتائج جميع الطلاب
     */
    public function calculateClassSemesterResults($classId, $academicYearId, $semesterId) {
        try {
            // 1. جلب طلاب الفصل
            $stmt = $this->db->prepare("
                SELECT se.student_id, s.full_name_ar, s.student_number
                FROM student_enrollments se
                INNER JOIN students s ON s.id = se.student_id
                WHERE se.class_id = ? AND se.academic_year_id = ? 
                    AND se.status = 'enrolled' AND se.is_active = 1 AND s.is_active = 1
                ORDER BY s.full_name_ar
            ");
            $stmt->execute([$classId, $academicYearId]);
            $students = $stmt->fetchAll();
            
            $results = [];
            foreach ($students as $student) {
                // حساب نتائج كل طالب
                $semesterResult = $this->calculateSemesterResults(
                    $student['student_id'], $academicYearId, $semesterId
                );
                $semesterResult['student_name'] = $student['full_name_ar'];
                $semesterResult['student_number'] = $student['student_number'];
                $results[] = $semesterResult;
            }
            
            // 2. حساب الترتيب في الفصل
            $rankResults = $this->calculateClassRank($classId, $academicYearId, $semesterId);
            
            // دمج الترتيب مع النتائج
            $rankMap = [];
            foreach ($rankResults as $r) {
                $rankMap[$r['student_id']] = $r['rank'];
            }
            
            foreach ($results as &$result) {
                $result['rank_in_class'] = $rankMap[$result['student_id']] ?? null;
            }
            
            return [
                'class_id' => $classId,
                'academic_year_id' => $academicYearId,
                'semester_id' => $semesterId,
                'total_students' => count($results),
                'results' => $results
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::calculateClassSemesterResults - ' . $e->getMessage());
            return ['error' => 'حدث خطأ أثناء حساب نتائج الفصل'];
        }
    }
    
    // ========================================================================
    // حفظ سجلات الفصل الدراسي
    // ========================================================================
    
    /**
     * حفظ سجل درجات الفصل (GPA فقط)
     */
    private function saveSemesterScore($studentId, $academicYearId, $semesterId, $gpa, $totalHours) {
        try {
            $sql = "INSERT INTO semester_scores 
                    (student_id, academic_year_id, semester_id, gpa, total_hours, is_active, calculated_at)
                    VALUES (:student_id, :year_id, :semester_id, :gpa, :hours, 1, NOW())
                    ON DUPLICATE KEY UPDATE 
                        gpa = :gpa2, total_hours = :hours2, calculated_at = NOW()";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':gpa' => $gpa,
                ':hours' => $totalHours,
                ':gpa2' => $gpa,
                ':hours2' => $totalHours
            ]);
        } catch (Exception $e) {
            error_log('AcademicCalculator::saveSemesterScore - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * تحديث سجل درجات الفصل بالكامل
     */
    private function updateSemesterScoreFull($studentId, $academicYearId, $semesterId, 
            $gpa, $overallPercentage, $passedCount, $failedCount, $totalSubjects) {
        try {
            $sql = "INSERT INTO semester_scores 
                    (student_id, academic_year_id, semester_id, gpa, overall_percentage, 
                     total_subjects, passed_subjects, failed_subjects, is_active, calculated_at)
                    VALUES (:student_id, :year_id, :semester_id, :gpa, :percentage, 
                            :total, :passed, :failed, 1, NOW())
                    ON DUPLICATE KEY UPDATE 
                        gpa = :gpa2, overall_percentage = :percentage2,
                        total_subjects = :total2, passed_subjects = :passed2, 
                        failed_subjects = :failed2, calculated_at = NOW()";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':student_id' => $studentId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId,
                ':gpa' => $gpa,
                ':percentage' => $overallPercentage,
                ':total' => $totalSubjects,
                ':passed' => $passedCount,
                ':failed' => $failedCount,
                ':gpa2' => $gpa,
                ':percentage2' => $overallPercentage,
                ':total2' => $totalSubjects,
                ':passed2' => $passedCount,
                ':failed2' => $failedCount
            ]);
        } catch (Exception $e) {
            error_log('AcademicCalculator::updateSemesterScoreFull - ' . $e->getMessage());
            return false;
        }
    }
    
    // ========================================================================
    // تفاصيل أداء الطالب
    // ========================================================================
    
    /**
     * ملخص أداء الطالب في مادة واحدة
     * تفصيل حسب نوع التقييم (شهري، واجب، نهائي...)
     * 
     * @param int $studentId معرف الطالب
     * @param int $subjectId معرف المادة
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array تفاصيل الأداء
     */
    public function getStudentSubjectDetail($studentId, $subjectId, $classId, $academicYearId, $semesterId) {
        try {
            // 1. جلب جميع درجات الطالب في المادة
            $stmt = $this->db->prepare("
                SELECT g.id, g.grade_type, g.grade_value, g.max_value, 
                       g.created_at, g.updated_at
                FROM grades g
                WHERE g.student_id = :student_id AND g.subject_id = :subject_id 
                    AND g.class_id = :class_id AND g.academic_year_id = :year_id 
                    AND g.semester_id = :semester_id AND g.is_active = 1
                ORDER BY g.grade_type, g.created_at
            ");
            $stmt->execute([
                ':student_id' => $studentId,
                ':subject_id' => $subjectId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId,
                ':semester_id' => $semesterId
            ]);
            $grades = $stmt->fetchAll();
            
            // 2. جلب أوزان المادة
            $stmt = $this->db->prepare("
                SELECT grade_type, weight_percentage 
                FROM subject_weight_distribution 
                WHERE subject_id = :subject_id AND class_id = :class_id 
                    AND academic_year_id = :year_id AND is_active = 1
            ");
            $stmt->execute([
                ':subject_id' => $subjectId,
                ':class_id' => $classId,
                ':year_id' => $academicYearId
            ]);
            $weights = $stmt->fetchAll();
            
            if (empty($weights)) {
                $weights = $this->getDefaultWeights();
            }
            
            // 3. تجميع الدرجات حسب النوع
            $details = [];
            $typesMap = [
                'exam' => 'اختبار شهري',
                'quiz' => 'اختبار قصير',
                'homework' => 'واجب منزلي',
                'project' => 'مشروع',
                'participation' => 'مشاركة',
                'final' => 'اختبار نهائي'
            ];
            
            foreach ($weights as $weightRow) {
                $type = $weightRow['grade_type'];
                $weightPct = (float)$weightRow['weight_percentage'];
                $typeName = $typesMap[$type] ?? $type;
                
                // فلترة الدرجات حسب النوع
                $typeGrades = array_filter($grades, function($g) use ($type) {
                    return $g['grade_type'] === $type;
                });
                
                $avgValue = 0;
                $maxValue = 100;
                $normalizedPct = 0;
                $weightedContribution = 0;
                $gradeCount = count($typeGrades);
                
                if ($gradeCount > 0) {
                    $sum = array_sum(array_column($typeGrades, 'grade_value'));
                    $avgValue = $sum / $gradeCount;
                    $maxValue = (float)reset($typeGrades)['max_value'];
                    $normalizedPct = $maxValue > 0 ? ($avgValue / $maxValue) * 100 : 0;
                    $weightedContribution = $normalizedPct * ($weightPct / 100);
                }
                
                $details[] = [
                    'grade_type' => $type,
                    'type_name_ar' => $typeName,
                    'weight_percentage' => $weightPct,
                    'grade_count' => $gradeCount,
                    'average_value' => round($avgValue, 2),
                    'max_value' => $maxValue,
                    'normalized_percentage' => round($normalizedPct, 2),
                    'weighted_contribution' => round($weightedContribution, 2),
                    'grades' => array_values($typeGrades)
                ];
            }
            
            // 4. حساب الإجمالي
            $totalPercentage = $this->calculateSubjectPercentage(
                $studentId, $subjectId, $classId, $academicYearId, $semesterId
            );
            $letterInfo = $this->getLetterGrade($totalPercentage);
            
            // 5. جلب اسم المادة
            $stmt = $this->db->prepare("SELECT name_ar, code FROM subjects WHERE id = ?");
            $stmt->execute([$subjectId]);
            $subject = $stmt->fetch();
            
            return [
                'student_id' => $studentId,
                'subject_id' => $subjectId,
                'subject_name' => $subject['name_ar'] ?? '',
                'subject_code' => $subject['code'] ?? '',
                'class_id' => $classId,
                'overall_percentage' => $totalPercentage,
                'letter_grade' => $letterInfo['letter'],
                'grade_point' => $letterInfo['point'],
                'grade_name_ar' => $letterInfo['name_ar'],
                'is_passing' => $totalPercentage >= $this->passingGrade,
                'breakdown' => $details
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::getStudentSubjectDetail - ' . $e->getMessage());
            return ['error' => 'حدث خطأ أثناء جلب تفاصيل الأداء'];
        }
    }
    
    // ========================================================================
    // حالة النجاح/الرسوب
    // ========================================================================
    
    /**
     * التحقق من حالة النجاح/الرسوب
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array حالة النجاح
     */
    public function getPassingStatus($studentId, $academicYearId, $semesterId) {
        try {
            // جلب ملخصات المواد
            $stmt = $this->db->prepare("
                SELECT sss.subject_id, sss.percentage, sss.letter_grade, sss.passing_status,
                       sub.name_ar AS subject_name
                FROM student_subject_summary sss
                INNER JOIN subjects sub ON sub.id = sss.subject_id
                WHERE sss.student_id = ? AND sss.academic_year_id = ? 
                    AND sss.semester_id = ? AND sss.is_active = 1
            ");
            $stmt->execute([$studentId, $academicYearId, $semesterId]);
            $subjects = $stmt->fetchAll();
            
            // إذا لم تتوفر ملخصات، نحسبها من الدرجات
            if (empty($subjects)) {
                $semesterResults = $this->calculateSemesterResults($studentId, $academicYearId, $semesterId);
                $subjects = $semesterResults['subjects'] ?? [];
            }
            
            $totalPassed = 0;
            $totalFailed = 0;
            $failedSubjects = [];
            $gpa = 0;
            
            foreach ($subjects as $subject) {
                $pct = (float)($subject['percentage'] ?? 0);
                if ($pct >= $this->passingGrade) {
                    $totalPassed++;
                } else {
                    $totalFailed++;
                    $failedSubjects[] = [
                        'subject_id' => $subject['subject_id'] ?? null,
                        'subject_name' => $subject['subject_name'] ?? $subject['name_ar'] ?? '',
                        'percentage' => $pct,
                        'letter_grade' => $subject['letter_grade'] ?? ''
                    ];
                }
            }
            
            // حساب المعدل التراكمي
            $gpaResult = $this->calculateGPA($studentId, $academicYearId, $semesterId);
            $gpa = $gpaResult['gpa'];
            
            return [
                'student_id' => $studentId,
                'total_passed' => $totalPassed,
                'total_failed' => $totalFailed,
                'total_subjects' => $totalPassed + $totalFailed,
                'failed_subjects' => $failedSubjects,
                'gpa' => $gpa,
                'is_passing' => $totalFailed === 0,
                'passing_grade' => $this->passingGrade
            ];
            
        } catch (Exception $e) {
            error_log('AcademicCalculator::getPassingStatus - ' . $e->getMessage());
            return [
                'student_id' => $studentId,
                'total_passed' => 0,
                'total_failed' => 0,
                'total_subjects' => 0,
                'failed_subjects' => [],
                'gpa' => 0,
                'is_passing' => false,
                'passing_grade' => $this->passingGrade
            ];
        }
    }
    
    // ========================================================================
    // سجل تغيير الدرجات
    // ========================================================================
    
    /**
     * تسجيل تغيير درجة
     * 
     * @param int $gradeId معرف الدرجة
     * @param float $oldValue القيمة القديمة
     * @param float $newValue القيمة الجديدة
     * @param string $reason سبب التغيير
     * @param int $changedBy معرف المستخدم الذي غيّر
     * @return bool نجاح أو فشل
     */
    public function logGradeChange($gradeId, $oldValue, $newValue, $reason, $changedBy) {
        try {
            $sql = "INSERT INTO grade_change_log 
                    (grade_id, old_value, new_value, reason, changed_by, changed_at, is_active)
                    VALUES (:grade_id, :old_value, :new_value, :reason, :changed_by, NOW(), 1)";
            
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':grade_id' => $gradeId,
                ':old_value' => $oldValue,
                ':new_value' => $newValue,
                ':reason' => $reason,
                ':changed_by' => $changedBy
            ]);
        } catch (Exception $e) {
            error_log('AcademicCalculator::logGradeChange - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * جلب سجل تغييرات درجة
     * 
     * @param int $gradeId معرف الدرجة
     * @return array سجل التغييرات
     */
    public function getGradeChangeLog($gradeId) {
        try {
            $stmt = $this->db->prepare("
                SELECT gcl.*, u.full_name_ar AS changer_name
                FROM grade_change_log gcl
                LEFT JOIN users u ON u.id = gcl.changed_by
                WHERE gcl.grade_id = ? AND gcl.is_active = 1
                ORDER BY gcl.changed_at DESC
            ");
            $stmt->execute([$gradeId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('AcademicCalculator::getGradeChangeLog - ' . $e->getMessage());
            return [];
        }
    }
    
    // ========================================================================
    // دوال مساعدة
    // ========================================================================
    
    /**
     * الحصول على ملخص درجات الطالب لفصل دراسي
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array|null ملخص الدرجات
     */
    public function getStudentSemesterSummary($studentId, $academicYearId, $semesterId) {
        try {
            $stmt = $this->db->prepare("
                SELECT ss.* 
                FROM semester_scores ss
                WHERE ss.student_id = ? AND ss.academic_year_id = ? 
                    AND ss.semester_id = ? AND ss.is_active = 1
            ");
            $stmt->execute([$studentId, $academicYearId, $semesterId]);
            $result = $stmt->fetch();
            
            if (!$result) {
                // حساب النتائج إذا لم تكن محسوبة
                $this->calculateSemesterResults($studentId, $academicYearId, $semesterId);
                $stmt->execute([$studentId, $academicYearId, $semesterId]);
                $result = $stmt->fetch();
            }
            
            return $result;
        } catch (Exception $e) {
            error_log('AcademicCalculator::getStudentSemesterSummary - ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * الحصول على جميع مواد الطالب مع الدرجات لفصل دراسي
     * 
     * @param int $studentId معرف الطالب
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array قائمة المواد مع الدرجات
     */
    public function getStudentSubjectsList($studentId, $academicYearId, $semesterId) {
        try {
            $stmt = $this->db->prepare("
                SELECT sss.*, sub.name_ar AS subject_name, sub.code AS subject_code
                FROM student_subject_summary sss
                INNER JOIN subjects sub ON sub.id = sss.subject_id
                WHERE sss.student_id = ? AND sss.academic_year_id = ? 
                    AND sss.semester_id = ? AND sss.is_active = 1
                ORDER BY sub.name_ar
            ");
            $stmt->execute([$studentId, $academicYearId, $semesterId]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('AcademicCalculator::getStudentSubjectsList - ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * الحصول على إحصائيات الصف الدراسي
     * 
     * @param int $classId معرف الفصل
     * @param int $academicYearId معرف السنة الدراسية
     * @param int $semesterId معرف الفصل الدراسي
     * @return array إحصائيات الصف
     */
    public function getClassStatistics($classId, $academicYearId, $semesterId) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT se.student_id) AS total_students,
                       AVG(ss.gpa) AS avg_gpa,
                       AVG(ss.overall_percentage) AS avg_percentage,
                       MAX(ss.overall_percentage) AS highest_percentage,
                       MIN(ss.overall_percentage) AS lowest_percentage,
                       SUM(CASE WHEN ss.failed_subjects = 0 THEN 1 ELSE 0 END) AS passing_students,
                       SUM(CASE WHEN ss.failed_subjects > 0 THEN 1 ELSE 0 END) AS failing_students
                FROM student_enrollments se
                LEFT JOIN semester_scores ss ON ss.student_id = se.student_id 
                    AND ss.academic_year_id = se.academic_year_id AND ss.semester_id = ?
                WHERE se.class_id = ? AND se.academic_year_id = ? 
                    AND se.status = 'enrolled' AND se.is_active = 1
            ");
            $stmt->execute([$semesterId, $classId, $academicYearId]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log('AcademicCalculator::getClassStatistics - ' . $e->getMessage());
            return null;
        }
    }
}
