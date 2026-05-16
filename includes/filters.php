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
