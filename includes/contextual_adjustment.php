<?php
/**
 * ============================================================
 * EQAS - Contextual Adjustment Service
 * معامل التعديل السياقي لمؤشر جودة التعليم
 * ============================================================
 *
 * يحسب هذا المحرك تعديلاً سياقياً لمؤشر جودة التعليم (EQI)
 * بناءً على العوامل السياقية للمدرسة، مما يسمح بمقارنة عادلة
 * بين المدارس ذات الظروف المختلفة.
 *
 * معادلة التعديل:
 * adjusted_sqi = raw_sqi + (challenge_index / 100) × max_adjustment
 *
 * حيث:
 * - challenge_index (0-100): مؤشر التحدي السياقي (أعلى = تحديات أكبر)
 * - max_adjustment: أقصى تعديل مسموح (افتراضي 10 نقاط)
 * - adjusted_sqi: النتيجة المعدلة (مقصورة بين 0-100)
 */

class ContextualAdjustmentService {
    private $db;
    private $maxAdjustment;
    private $weights;

    public function __construct($db, $maxAdjustment = 10) {
        $this->db = $db;
        $this->maxAdjustment = $maxAdjustment;
        $this->weights = [
            'location_type'           => 0.20,
            'socioeconomic_index'     => 0.25,
            'student_teacher_ratio'   => 0.15,
            'infrastructure_score'    => 0.15,
            'parent_education_level'  => 0.10,
            'students_with_special_needs_pct' => 0.08,
            'student_mobility_rate'   => 0.07,
        ];
    }

    public function getMaxAdjustment() {
        return $this->maxAdjustment;
    }

    public function setMaxAdjustment($value) {
        $this->maxAdjustment = $value;
    }

    /**
     * يحسب adjusted_sqi لمدرسة معينة
     */
    public function calculateAdjustedSQI($schoolId, $academicYearId, $rawEqi) {
        if ($rawEqi === null) {
            return null;
        }

        $context = $this->loadContext($schoolId, $academicYearId);

        if ($context === null) {
            return $rawEqi;
        }

        $challengeIndex = $this->calculateChallengeIndex($context);
        $adjustment = ($challengeIndex / 100) * $this->maxAdjustment;
        $adjusted = $rawEqi + $adjustment;

        return round(max(0, min(100, $adjusted)), 1);
    }

    /**
     * يحسب adjusted_sqi مع معلومات التعديل كاملة
     */
    public function calculateAdjustedSQIWithDetails($schoolId, $academicYearId, $rawEqi) {
        if ($rawEqi === null) {
            return null;
        }

        $context = $this->loadContext($schoolId, $academicYearId);

        if ($context === null) {
            return [
                'adjusted_sqi' => $rawEqi,
                'raw_sqi' => $rawEqi,
                'challenge_index' => 0,
                'adjustment' => 0,
                'has_context' => false,
            ];
        }

        $challengeIndex = $this->calculateChallengeIndex($context);
        $adjustment = round(($challengeIndex / 100) * $this->maxAdjustment, 2);
        $adjusted = $rawEqi + $adjustment;
        $adjusted = round(max(0, min(100, $adjusted)), 1);

        return [
            'adjusted_sqi' => $adjusted,
            'raw_sqi' => $rawEqi,
            'challenge_index' => round($challengeIndex, 1),
            'adjustment' => $adjustment,
            'has_context' => true,
            'location_type' => $context['location_type'],
            'socioeconomic_index' => $context['socioeconomic_index'],
            'student_teacher_ratio' => $context['student_teacher_ratio'],
            'infrastructure_score' => $context['infrastructure_score'],
        ];
    }

    /**
     * يحسب adjusted_sqi لمجموعة مدارس دفعة واحدة
     */
    public function batchCalculateAdjustedSQI($schoolIds, $academicYearId, $rawEqiMap) {
        $results = [];
        foreach ($schoolIds as $schoolId) {
            $raw = isset($rawEqiMap[$schoolId]) ? $rawEqiMap[$schoolId] : null;
            $results[$schoolId] = $this->calculateAdjustedSQIWithDetails($schoolId, $academicYearId, $raw);
        }
        return $results;
    }

    /**
     * يحمل بيانات السياق المدرسي
     */
    private function loadContext($schoolId, $academicYearId) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM school_context
                WHERE school_id = ? AND academic_year_id = ? AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$schoolId, $academicYearId]);
            $context = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($context) {
                return $context;
            }

            // محاولة إيجاد أحدث سياق متاح للمدرسة
            $stmt = $this->db->prepare("
                SELECT * FROM school_context
                WHERE school_id = ? AND is_active = 1
                ORDER BY academic_year_id DESC
                LIMIT 1
            ");
            $stmt->execute([$schoolId]);
            $context = $stmt->fetch(PDO::FETCH_ASSOC);

            return $context ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * يحسب مؤشر التحدي السياقي (0-100)
     */
    public function calculateChallengeIndex($context) {
        $index = 0;
        $totalWeight = 0;

        // 1. نوع المنطقة
        if (!empty($context['location_type'])) {
            $locationScores = ['urban' => 0, 'suburban' => 30, 'rural' => 60];
            $locationScore = $locationScores[$context['location_type']] ?? 0;
            $index += $locationScore * $this->weights['location_type'];
            $totalWeight += $this->weights['location_type'];
        }

        // 2. المستوى الاقتصادي الاجتماعي (أقل = تحدٍ أكبر)
        if ($context['socioeconomic_index'] !== null) {
            $score = 100 - min(100, max(0, $context['socioeconomic_index']));
            $index += $score * $this->weights['socioeconomic_index'];
            $totalWeight += $this->weights['socioeconomic_index'];
        }

        // 3. نسبة الطلاب إلى المعلمين (أعلى = تحدٍ أكبر)
        if ($context['student_teacher_ratio'] !== null) {
            $score = min(100, max(0, ($context['student_teacher_ratio'] / 50) * 100));
            $index += $score * $this->weights['student_teacher_ratio'];
            $totalWeight += $this->weights['student_teacher_ratio'];
        }

        // 4. درجة البنية التحتية (أقل = تحدٍ أكبر)
        if ($context['infrastructure_score'] !== null) {
            $score = 100 - min(100, max(0, $context['infrastructure_score']));
            $index += $score * $this->weights['infrastructure_score'];
            $totalWeight += $this->weights['infrastructure_score'];
        }

        // 5. مستوى تعليم أولياء الأمور (أقل = تحدٍ أكبر)
        if ($context['parent_education_level'] !== null) {
            $score = 100 - min(100, max(0, $context['parent_education_level']));
            $index += $score * $this->weights['parent_education_level'];
            $totalWeight += $this->weights['parent_education_level'];
        }

        // 6. نسبة الطلاب ذوي الاحتياجات الخاصة (أعلى = تحدٍ أكبر)
        if ($context['students_with_special_needs_pct'] !== null) {
            $score = min(100, max(0, $context['students_with_special_needs_pct'] * 10));
            $index += $score * $this->weights['students_with_special_needs_pct'];
            $totalWeight += $this->weights['students_with_special_needs_pct'];
        }

        // 7. معدل تنقل الطلاب (أعلى = تحدٍ أكبر)
        if ($context['student_mobility_rate'] !== null) {
            $score = min(100, max(0, $context['student_mobility_rate']));
            $index += $score * $this->weights['student_mobility_rate'];
            $totalWeight += $this->weights['student_mobility_rate'];
        }

        // تطبيع في حال عدم توفر جميع العوامل
        if ($totalWeight > 0) {
            $index = $index / $totalWeight;
        }

        $index = round(max(0, min(100, $index)), 1);

        // تحديث challenge_index في قاعدة البيانات إذا كان مختلفاً
        if ($context['challenge_index'] != $index && isset($context['id'])) {
            try {
                $updateStmt = $this->db->prepare("UPDATE school_context SET challenge_index = ? WHERE id = ?");
                $updateStmt->execute([$index, $context['id']]);
            } catch (Exception $e) {}
        }

        return $index;
    }

    /**
     * يحفظ أو يحدث بيانات السياق لمدرسة
     */
    public function saveContext($schoolId, $academicYearId, $data) {
        $allowedFields = [
            'location_type', 'total_students', 'student_teacher_ratio',
            'socioeconomic_index', 'parent_education_level',
            'students_with_special_needs_pct', 'infrastructure_score',
            'student_mobility_rate', 'notes',
        ];

        $fields = [];
        $params = [':school_id' => $schoolId, ':academic_year_id' => $academicYearId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "`$field` = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fieldList = implode(', ', $fields);

        try {
            // التحقق من وجود السجل
            $stmt = $this->db->prepare("SELECT id FROM school_context WHERE school_id = :school_id AND academic_year_id = :academic_year_id");
            $stmt->execute([':school_id' => $schoolId, ':academic_year_id' => $academicYearId]);
            $existing = $stmt->fetchColumn();

            if ($existing) {
                $sql = "UPDATE school_context SET $fieldList WHERE id = :id";
                $params[':id'] = $existing;
            } else {
                $sql = "INSERT INTO school_context (school_id, academic_year_id, $fieldList) VALUES (:school_id, :academic_year_id, $fieldList)";
            }

            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * يحصل على جميع سياقات المدارس لعام دراسي
     */
    public function getAllContexts($academicYearId) {
        try {
            $stmt = $this->db->prepare("
                SELECT sc.*, s.name_ar as school_name
                FROM school_context sc
                JOIN schools s ON s.id = sc.school_id
                WHERE sc.academic_year_id = ? AND sc.is_active = 1
                ORDER BY s.name_ar
            ");
            $stmt->execute([$academicYearId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * يحصل على المدارس بدون سياق مسجل
     */
    public function getSchoolsWithoutContext($academicYearId) {
        try {
            $stmt = $this->db->prepare("
                SELECT s.id, s.name_ar
                FROM schools s
                WHERE s.is_active = 1
                AND s.id NOT IN (
                    SELECT school_id FROM school_context
                    WHERE academic_year_id = ? AND is_active = 1
                )
                ORDER BY s.name_ar
            ");
            $stmt->execute([$academicYearId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}
