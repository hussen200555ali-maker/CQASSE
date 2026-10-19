<?php
/**
 * دوال مساعدة للفلاتر الموحّدة (السنة الدراسية، الفصل، المدرسة)
 */

function semesterValueToNameAr($value) {
    if ($value == 1) return 'الفصل الدراسي الأول';
    if ($value == 2) return 'الفصل الدراسي الثاني';
    return null;
}

function buildSemesterSubquery($semesterValue, $alias = 'cs') {
    $nameAr = semesterValueToNameAr($semesterValue);
    if (!$nameAr) return ['sql' => '', 'params' => []];
    return [
        'sql' => " AND {$alias}.semester_id IN (SELECT id FROM semesters WHERE name_ar = ? AND is_active = 1)",
        'params' => [$nameAr]
    ];
}

function buildYearFilter($ayId, $alias = 'cs') {
    if ($ayId <= 0) return ['sql' => '', 'params' => []];
    return [
        'sql' => " AND {$alias}.academic_year_id = ?",
        'params' => [$ayId]
    ];
}

function buildSchoolFilter($schoolId, $alias = 'cs') {
    if ($schoolId <= 0) return ['sql' => '', 'params' => []];
    return [
        'sql' => " AND {$alias}.school_id = ?",
        'params' => [$schoolId]
    ];
}

/**
 * بناء شرط SQL لعزل البيانات حسب دور المستخدم
 * group_admin:  لا شرط (يرى الكل)
 * school_admin: AND s.school_id = ?  (يفترض وجود alias s للطلاب)
 * teacher:      AND <column> IN (?,?,...)  (معرفات الفصول المسندة)
 */
function buildRoleFilter($db, $userRole, $userId, $schoolId, $teacherColumn = 'class_id') {
    if ($userRole === 'group_admin') {
        return ['sql' => '', 'params' => []];
    }

    if ($userRole === 'school_admin' && $schoolId) {
        return [
            'sql' => " AND s.school_id = ?",
            'params' => [$schoolId]
        ];
    }

    if ($userRole === 'teacher' && $schoolId) {
        $classIds = getTeacherAssignedClassIds($db, $userId, $schoolId);
        if (!empty($classIds)) {
            $ph = implode(',', array_fill(0, count($classIds), '?'));
            return [
                'sql' => " AND {$teacherColumn} IN ({$ph})",
                'params' => $classIds
            ];
        }
        return ['sql' => " AND 1=0", 'params' => []];
    }

    return ['sql' => '', 'params' => []];
}
