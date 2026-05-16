<?php
/**
 * ============================================================
 * EQAS - Filter Helpers
 * دوال مساعدة لمعالجة فلاتر العام الدراسي والفصل الدراسي
 * ============================================================
 * السلوك:
 *   - قائمة السنوات: "جميع السنوات" + كل السنوات غير تخطيط
 *   - قائمة الفصول: "جميع الفصول" + كل الفصول من كل السنوات
 *   - لا ربط بين القوائم — كل قائمة مستقلة
 *   - القيمة 0 = "الكل" (جميع السنوات / جميع الفصول)
 */

/**
 * معالجة فلاتر العام والفصل الدراسي
 * @param PDO $db
 * @param int $selectedAcademicYearId العام المحدد (0 = جميع السنوات)
 * @param int $selectedSemesterId الفصل المحدد (0 = جميع الفصول)
 * @param bool $includeSemesterName هل يتم جلب اسم الفصل؟
 * @return array [academicYearId, semesterId, allAcademicYears, allSemesters, semesterName]
 */
function processAcademicFilters($db, $selectedAcademicYearId = 0, $selectedSemesterId = 0, $includeSemesterName = true) {
    $selectedAcademicYearId = (int)$selectedAcademicYearId;
    $selectedSemesterId = (int)$selectedSemesterId;

    // === قائمة السنوات: "جميع السنوات" + كل السنوات غير تخطيط ===
    $allAcademicYears = [['id' => 0, 'name' => 'جميع السنوات']];
    $realYears = $db->query(
        "SELECT id, name FROM academic_years WHERE status != 'planning' ORDER BY start_date DESC"
    )->fetchAll();
    foreach ($realYears as $ay) {
        $allAcademicYears[] = $ay;
    }

    // التحقق من صحة السنة المختارة
    if ($selectedAcademicYearId > 0) {
        $found = false;
        foreach ($allAcademicYears as $ay) {
            if ((int)$ay['id'] === $selectedAcademicYearId) { $found = true; break; }
        }
        if (!$found) $selectedAcademicYearId = 0;
    }

    // === قائمة الفصول: "جميع الفصول" + كل الفصول من كل السنوات ===
    $allSemesters = [['id' => 0, 'name' => 'جميع الفصول']];
    $realSemesters = $db->query(
        "SELECT id, name_ar AS name FROM semesters WHERE is_active = 1 ORDER BY start_date ASC"
    )->fetchAll();
    foreach ($realSemesters as $s) {
        $allSemesters[] = $s;
    }

    // التحقق من صحة الفصل المختار
    if ($selectedSemesterId > 0) {
        $found = false;
        foreach ($allSemesters as $s) {
            if ((int)$s['id'] === $selectedSemesterId) { $found = true; break; }
        }
        if (!$found) $selectedSemesterId = 0;
    }

    // === اسم الفترة للعرض ===
    $semesterName = '';
    if ($includeSemesterName) {
        if ($selectedSemesterId > 0) {
            $stmt = $db->prepare("SELECT name_ar FROM semesters WHERE id = ?");
            $stmt->execute([$selectedSemesterId]);
            $semesterName = (string)$stmt->fetchColumn();
        } elseif ($selectedAcademicYearId > 0) {
            foreach ($allAcademicYears as $ay) {
                if ((int)$ay['id'] === $selectedAcademicYearId) {
                    $semesterName = 'جميع فصول ' . $ay['name'];
                    break;
                }
            }
            if (!$semesterName) $semesterName = 'جميع الفصول';
        } else {
            $semesterName = 'جميع السنوات والفصول';
        }
    }

    return [$selectedAcademicYearId, $selectedSemesterId, $allAcademicYears, $allSemesters, $semesterName];
}

/**
 * الحصول على اسم العام الدراسي من المصفوفة
 */
function getAcademicYearName($allAcademicYears, $yearId) {
    foreach ($allAcademicYears as $ay) {
        if ((int)$ay['id'] === (int)$yearId) return $ay['name'];
    }
    return '';
}
