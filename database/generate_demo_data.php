<?php
/**
 * EQAS - مولد البيانات التجريبية الشاملة
 * ينتج ملف SQL بـ 500+ طالب ببيانات مترابطة كاملة
 * 
 * التشغيل: php database/generate_demo_data.php
 * المخرج: database/demo_data_large.sql
 */

$outputFile = __DIR__ . '/demo_data_large.sql';
$f = fopen($outputFile, 'w');

fwrite($f, "-- ============================================================================\n");
fwrite($f, "-- EQAS - بيانات تجريبية شاملة (500+ طالب)\n");
fwrite($f, "-- تم التوليد آلياً في " . date('Y-m-d H:i:s') . "\n");
fwrite($f, "-- ============================================================================\n\n");
fwrite($f, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

// ============================================================
// أسماء يمنية للطلاب
// ============================================================
$maleFirst = ['محمد','أحمد','علي','عمر','خالد','عبدالله','حسن','حسين','صالح','ناصر',
    'ياسر','سامي','عبدالرحمن','طارق','مروان','أنس','بلال','حمزة','زيد','يوسف',
    'إبراهيم','سعيد','كمال','جمال','فهد','ماجد','وليد','نبيل','عادل','راشد',
    'قاسم','حامد','سالم','زياد','شادي','بسام','تمام','جابر','خليل','نادر',
    'رائد','مؤمن','مهند','أيمن','إسماعيل','موسى','هاني','عصام','غسان','فواز',
    'ربيع','سراج','ضياء','عبدالعزيز','عبدالملك','عبدالوهاب','عبدالسلام','عبدالرحمن','عبداللطيف','عفيف',
    'علاء','فيصل','كرم','لؤي','مجاهد','محسن','مراد','معاذ','منصور','مهيب',
    'نعمان','هادي','وحيد','وسيم','يحيى','أكرم','بشير','تامر','ثامر','جابر',
    'جهاد','حاتم','حذيفة','حكيم','حمزة','خليل','دانيال','رامي','سامح','سليمان',
    'شهاب','ضيف','عامر','عبدالغني','عتريس','عزالدين','فراس','قيس','كامل','لطفي',
    'مازن','مالك','مثنى','مدين','مرتضى','مسلم','معتز','مقداد','مهدي','ناجي'];

$femaleFirst = ['فاطمة','مريم','نور','سارة','أمل','ليلى','هدى','آمنة','خديجة','عائشة',
    'رقية','زينب','سلمى','تهاني','شيماء','حليمة','رنا','بثينة','عفاف','غادة',
    'كريمة','نوال','إيمان','سعاد','هند','أروى','بلقيس','توحة','جميلة','حواء',
    'رؤى','زهرة','سحر','سميرة','شروق','صباح','ضحى','عزة','غالية','فردوس',
    'لمياء','مها','نجوى','وئام','ياسمين','رانية','سامية','علياء','غيداء','نادية',
    'هديل','وردة','إحسان','براءة','تغريد','حسنية','خولة','دعاء','رابعة','راوية',
    'زكية','سلوى','شميلة','طاهرة','عابدة','غفران','فائزة','قنوات','لينا','ملاك',
    'منال','ناهد','هاجر','وفاء','بشرى','تماضر','حفصة','ريحانة','سكينة','شذى',
    'صفية','ظبية','عبير','فريال','كوثر','لطيفة','مليكة','منيرة','نورة','هالة'];

$lastNames = ['العمري','الحميري','العنسي','النوري','القرشي','البعداني','الحداد',
    'العسكري','الحمزي','الأحول','الفلاح','الحجري','الشامي','القباطي','العواضي',
    'النور','العربي','الحسني','الصبري','الذماري','الحبيشي','الأهدل','باحاج',
    'بن بريك','العوادي','السقاف','الكبسي','المطري','الجرادي','الشعبي','القاضي',
    'الثور','الزبيري','المخلافي','الحميقاني','الصريمي','الرميم','الكمال','الشميري',
    'الوجيه','الأكوع','المروني','الحجاج','المعافا','الغشم','الكوكباني','الدبأ',
    'المنصور','الهتار','المتوكل','المؤيد','الوزير','السعيدي','الجماعي','النهمي',
    'البخيتي','العجل','الحوري','المراني','المنسي','الحرازي','الصغير','المرادي',
    'الحاشدي','القبلي','الضالعي','العدني','التهامي','الحضرمي','اللحجي','البيضاني',
    'المحولي','المعري','الخولاني','الهمداني','الغابري','الشاطر','الحاج','الأصبحي'];

// المعلمون (12 معلماً بأسماء متنوعة)
$teacherNames = [
    [4, 'أحمد محمد العربي', 1], [5, 'سارة خالد الحسني', 1],
    [6, 'عمر عبدالله النوري', 2], [7, 'خالد صالح القرشي', 2],
    [8, 'نورة عبدالملك الحميري', 3], [9, 'مريم يحيى العنسي', 3],
];

// إضافة 6 معلمين جدد لتغطية الشعب الكثيرة
$extraTeachers = [
    [12, 'علي حسن الصبري', 1], [13, 'فاطمة أحمد الذماري', 1],
    [14, 'صالح ناصر الحبيشي', 2], [15, 'أمل عبدالسلام الأهدل', 2],
    [16, 'هدى محمد باحاج', 3], [17, 'سعيد عمر بن بريك', 3],
];

function write($f, $sql) {
    fwrite($f, $sql . "\n");
}

// ============================================================
// هيكل: المدارس موجودة مسبقاً (id=1 الأمل, id=2 النور, id=3 الفلاح)
// الصفوف موجودة مسبقاً (grade_levels: 1-12)
// العام الدراسي 2025-2026 موجود (id=1)
// الفصل الأول موجود (id=1)
// ============================================================

// أعداد الطلاب لكل مدرسة وصف
$schoolGrades = [
    // school_id => [grade_id => count]
    1 => [1=>20, 2=>20, 3=>20, 4=>20, 5=>20, 6=>20, 7=>20, 8=>20, 9=>20, 10=>20, 11=>20, 12=>20],  // 240
    2 => [1=>22, 2=>22, 3=>22, 4=>22, 5=>22, 6=>22, 7=>22, 8=>22, 9=>22],                            // 198
    3 => [10=>25, 11=>25, 12=>25],                                                                     // 75
];
// Total: ~513

// ============================================================
// 1. إضافة المعلمين الإضافيين (للمدارس الكبيرة)
// ============================================================
write($f, "-- ============================================================================");
write($f, "-- 1. معلمون إضافيون لتغطية جميع الشعب");
write($f, "-- ============================================================================");
write($f, "INSERT IGNORE INTO `users` (`id`, `username`, `password_hash`, `must_change_password`, `full_name_ar`, `email`, `phone`, `role_id`, `school_id`, `is_active`) VALUES");
foreach ($extraTeachers as $i => $et) {
    list($eid, $ename, $eschool) = $et;
    $euser = 'teacher_extra_' . $eid;
    $comma = ($i < count($extraTeachers) - 1) ? ',' : ';';
    write($f, "({$eid}, '{$euser}', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 0, '{$ename}', 't{$eid}@eqas.edu.ye', '9677444{$eid}', 3, {$eschool}, 1){$comma}");
}

// ============================================================
// 2. إنشاء الشعب (classes) لكل مدرسة وصف
// ============================================================
write($f, "-- ============================================================================");
write($f, "-- 1. الشعب الدراسية");
write($f, "-- ============================================================================");

$classId = 100; // نبدأ من 100 لتفادي التعارض مع البيانات الموجودة
$classMap = []; // school_id . '-' . grade_id => [[class_id, class_name, teacher_id], ...]

$schoolTeachers = [
    1 => [4, 5, 12, 13],
    2 => [6, 7, 14, 15],
    3 => [8, 9, 16, 17],
];

$schoolTypes = [
    1 => 'combined',
    2 => 'basic',
    3 => 'secondary',
];

write($f, "INSERT IGNORE INTO `classes` (`id`, `name_ar`, `academic_year_id`, `grade_level_id`, `school_id`, `teacher_id`, `capacity`, `is_active`) VALUES");

$classSql = [];
foreach ($schoolGrades as $schoolId => $grades) {
    $teachers = $schoolTeachers[$schoolId];
    $tIdx = 0;
    foreach ($grades as $gradeId => $count) {
        // عدد الشعب حسب عدد الطلاب: كل شعبة 25 طالباً
        $numClasses = max(1, min(3, (int)ceil($count / 25)));
        $studentsPerClass = (int)ceil($count / $numClasses);
        for ($c = 0; $c < $numClasses; $c++) {
            $teacherId = $teachers[$tIdx % count($teachers)];
            $tIdx++;
            $suffix = chr(65 + $c); // A, B, C
            $className = getGradeName($gradeId) . " - {$suffix}";
            if ($schoolId > 1) {
                $schoolShort = ($schoolId == 2) ? 'نور' : 'فلاح';
                $className .= " ({$schoolShort})";
            }
            $classSql[] = "({$classId}, '{$className}', 1, {$gradeId}, {$schoolId}, {$teacherId}, 30, 1)";
            $classMap[$schoolId . '-' . $gradeId][] = ['id' => $classId, 'name' => $className, 'teacher_id' => $teacherId, 'students' => $studentsPerClass];
            $classId++;
        }
    }
}
write($f, implode(",\n", $classSql) . ";");

// ============================================================
// 2. الطلاب
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 2. الطلاب (500+)");
write($f, "-- ============================================================================");

$studentId = 100; // start from 100 to avoid conflicts
$studentData = []; // [student_id, student_number, full_name_ar, guardian_name, guardian_phone, grade_level_id, school_id]

$maleGradesBasic = [1,2,3,4,5,6,7,8,9];
$femaleOnlySchools = [3]; // الفلاح ثانوية بنات

$schoolPrefix = [1 => 'AM', 2 => 'NB', 3 => 'FS'];
$schoolPhonePrefix = [1 => '9677111', 2 => '9677222', 3 => '9677333'];

$usedStudentNumbers = [];

write($f, "INSERT IGNORE INTO `students` (`id`, `student_number`, `full_name_ar`, `guardian_name`, `guardian_phone`, `grade_level_id`, `school_id`, `enrollment_date`, `is_active`) VALUES");

$studentSql = [];
$counter = 1;

foreach ($schoolGrades as $schoolId => $grades) {
    $isFemale = ($schoolId == 3);
    $prefix = $schoolPrefix[$schoolId];
    $phonePfx = $schoolPhonePrefix[$schoolId];
    $firstNames = $isFemale ? $femaleFirst : $maleFirst;
    $guardianFirst = $maleFirst;

    foreach ($grades as $gradeId => $count) {
        $actualStudents = $count; // عدد الطلاب المطلوب
        for ($i = 0; $i < $actualStudents; $i++) {
            $fn = $firstNames[$counter % count($firstNames)];
            $ln = $lastNames[($counter * 7) % count($lastNames)];
            $guardianFn = $guardianFirst[($counter * 3) % count($guardianFirst)];
            $guardianLn = $lastNames[($counter * 5) % count($lastNames)];
            
            $fullName = "{$fn} {$ln}";
            $guardianName = "{$guardianFn} {$guardianLn}";
            $studentNum = sprintf("%s-%04d", $prefix, $counter);
            $guardianPhone = $phonePfx . sprintf("%04d", $counter);
            
            $studentSql[] = "({$studentId}, '{$studentNum}', '{$fullName}', '{$guardianName}', '{$guardianPhone}', {$gradeId}, {$schoolId}, '2025-09-01', 1)";
            
            $studentData[] = [
                'id' => $studentId,
                'student_number' => $studentNum,
                'full_name' => $fullName,
                'grade_level_id' => $gradeId,
                'school_id' => $schoolId,
                'class_info' => null, // will fill below
                'guardian_name' => $guardianName,
                'guardian_phone' => $guardianPhone,
            ];
            $studentId++;
            $counter++;
        }
    }
}

write($f, implode(",\n", $studentSql) . ";");

$totalStudents = count($studentData);
write($f, "\n-- إجمالي الطلاب: {$totalStudents}\n");

// ============================================================
// 3. توزيع الطلاب على الشعب + enrollment + grade_years
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 3. تسجيل الطلاب في الشعب (student_enrollments + grade_years)");
write($f, "-- ============================================================================");

// For each school+grade, distribute students across classes
$studentClassMap = []; // student_id => class_id

// Group students by school+grade
$studentsBySchoolGrade = [];
foreach ($studentData as $s) {
    $key = $s['school_id'] . '-' . $s['grade_level_id'];
    $studentsBySchoolGrade[$key][] = $s;
}

$enrollId = 1000;
$gyId = 1000;

$enrollSql = [];
$gySql = [];
$studentsInEnrollments = [];

foreach ($studentsBySchoolGrade as $key => $students) {
    list($schoolId, $gradeId) = explode('-', $key);
    $classes = $classMap[$key] ?? [];
    if (empty($classes)) continue;
    
    $classIdx = 0;
    foreach ($students as $s) {
        $classInfo = $classes[$classIdx % count($classes)];
        $classId = $classInfo['id'];
        $studentClassMap[$s['id']] = $classId;
        
        $enrollSql[] = "({$enrollId}, {$s['id']}, {$classId}, 1, '2025-09-01', 'enrolled')";
        $gySql[] = "({$gyId}, {$s['id']}, {$gradeId}, {$schoolId}, 1, 'enrolled')";
        
        $studentsInEnrollments[] = $s;
        $enrollId++;
        $gyId++;
        $classIdx++;
    }
}

write($f, "-- student_enrollments\n");
write($f, "INSERT IGNORE INTO `student_enrollments` (`id`, `student_id`, `class_id`, `academic_year_id`, `enrollment_date`, `status`) VALUES");
write($f, implode(",\n", $enrollSql) . ";");

write($f, "\n-- grade_years\n");
write($f, "INSERT IGNORE INTO `grade_years` (`id`, `student_id`, `grade_level_id`, `school_id`, `academic_year_id`, `status`) VALUES");
write($f, implode(",\n", $gySql) . ";");

// ============================================================
// 4. الاختبارات (tests per class per subject)
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 4. الاختبارات (لكل شعبة: شهري + نهائي في مادتين)");
write($f, "-- ============================================================================");

// Subjects per grade level:
// basic (1-9): MATH (1), ARAB (2), SCI (4), ISLM (5), ART (12)
// secondary (10-12): MATH (1), ARAB (2), ENG (3), PHYS (8), CHEM (9), BIOL (10), ISLM (5)
// We'll create tests for 2 main subjects per class

$testId = 1000;
$testSql = [];
$testInfo = []; // test_id => [class_id, subject_id, max_grade, assessment_type_id]

$allClasses = [];
foreach ($classMap as $key => $classes) {
    foreach ($classes as $c) {
        $allClasses[] = $c;
    }
}

// For each class, create 4 tests: شهري 1 + شهري 2 + نهائي for 2 subjects
// For basic grades: MATH and ARAB
// For secondary: MATH and ENG or ARAB
foreach ($allClasses as $c) {
    $classId = $c['id'];
    $teacherId = $c['teacher_id'];
    
    // Extract grade_id from classmap key
    $gradeId = null;
    foreach ($classMap as $k => $classes) {
        foreach ($classes as $cc) {
            if ($cc['id'] == $classId) {
                $gradeId = explode('-', $k)[1];
                break 2;
            }
        }
    }
    if (!$gradeId) continue;
    
    $isSecondary = ($gradeId >= 10);
    $subjects = $isSecondary ? [1, 3] : [1, 2]; // MATH+ENG or MATH+ARAB
    
    foreach ($subjects as $subjId) {
        // Monthly test (assessment_type_id = 1)
        $testSql[] = "({$testId}, {$subjId}, {$classId}, 1, 1, 1, 'اختبار شهري أول', 20.00, '2025-10-15', 'published', {$teacherId})";
        $testInfo[$testId] = ['class_id' => $classId, 'subject_id' => $subjId, 'max_grade' => 20, 'type' => 1, 'teacher_id' => $teacherId];
        $testId++;
        
        // Final exam (assessment_type_id = 4)
        $testSql[] = "({$testId}, {$subjId}, {$classId}, 4, 1, 1, 'اختبار نهاية الفصل', 100.00, '2026-01-20', 'published', {$teacherId})";
        $testInfo[$testId] = ['class_id' => $classId, 'subject_id' => $subjId, 'max_grade' => 100, 'type' => 4, 'teacher_id' => $teacherId];
        $testId++;
    }
}

write($f, "INSERT IGNORE INTO `tests` (`id`, `subject_id`, `class_id`, `assessment_type_id`, `academic_year_id`, `semester_id`, `title_ar`, `max_grade`, `test_date`, `status`, `created_by`) VALUES");
write($f, implode(",\n", $testSql) . ";");

// ============================================================
// 5. الدرجات (grades) لكل طالب في كل اختبار
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 5. درجات الطلاب");
write($f, "-- ============================================================================");

$gradeId = 10000;
$gradeSql = [];
// Track per-student per-subject totals for summary
$studentSubjectTotals = []; // student_id_subject_id => [total, max_total, count]
$letterGrades = ['A+' => 4.0, 'A' => 3.75, 'B+' => 3.5, 'B' => 3.0, 'C+' => 2.5, 'C' => 2.0, 'D+' => 1.5, 'D' => 1.0, 'D-' => 0.5, 'F' => 0.0];

function getLetterGrade($pct) {
    if ($pct >= 95) return 'A+';
    if ($pct >= 90) return 'A';
    if ($pct >= 85) return 'B+';
    if ($pct >= 80) return 'B';
    if ($pct >= 75) return 'C+';
    if ($pct >= 70) return 'C';
    if ($pct >= 65) return 'D+';
    if ($pct >= 60) return 'D';
    if ($pct >= 50) return 'D-';
    return 'F';
}

// For each test, assign grades to students in that class
$studentPerformance = []; // student_id => [base_performance 0-100, consistency 0-1]
// Pre-generate performance profile for each student
foreach ($studentsInEnrollments as $s) {
    // Use hash of student ID for deterministic but varied performance
    $hash = crc32($s['student_number']);
    $base = 30 + abs($hash % 65); // 30-95 base performance
    $consistency = 0.7 + (abs($hash * 7) % 6) / 10; // 0.7-1.2
    $studentPerformance[$s['id']] = ['base' => $base, 'consistency' => $consistency];
}

foreach ($testInfo as $tid => $tinfo) {
    $classId = $tinfo['class_id'];
    $subjId = $tinfo['subject_id'];
    $maxGrade = $tinfo['max_grade'];
    $type = $tinfo['type'];
    $teacherId = $tinfo['teacher_id'];
    $halfMark = $maxGrade / 2; // pass mark at 50%
    
    // Find all students in this class
    foreach ($studentsInEnrollments as $s) {
        if (($studentClassMap[$s['id']] ?? null) == $classId) {
            $perf = $studentPerformance[$s['id']];
            $pct = $perf['base'];
            // Add some variation per test
            $variation = ((($tid * 3 + $s['id'] * 7) % 21) - 10) * $perf['consistency'];
            $pct += $variation;
            $pct = max(5, min(100, $pct));
            
            if ($type == 4) {
                // Final exam - make it slightly more correlated with monthly but with some shift
                $shift = ((($tid * 13 + $s['id'] * 5) % 15) - 7) * $perf['consistency'];
                $pct += $shift;
                $pct = max(5, min(100, $pct));
            }
            
            $gradeVal = round($pct / 100 * $maxGrade, 2);
            $letter = getLetterGrade($pct);
            
            $gradeSql[] = "({$gradeId}, {$s['id']}, {$tid}, {$gradeVal}, {$maxGrade}, {$pct}, '{$letter}', NULL, {$teacherId})";
            
            // Track for summary
            $key = $s['id'] . '_' . $subjId;
            if (!isset($studentSubjectTotals[$key])) {
                $studentSubjectTotals[$key] = ['total' => 0, 'max' => 0, 'count' => 0, 'student_id' => $s['id'], 'subject_id' => $subjId, 'class_id' => $classId];
            }
            $studentSubjectTotals[$key]['total'] += $gradeVal;
            $studentSubjectTotals[$key]['max'] += $maxGrade;
            $studentSubjectTotals[$key]['count']++;
            
            $gradeId++;
        }
    }
}

write($f, "INSERT IGNORE INTO `grades` (`id`, `student_id`, `test_id`, `grade_value`, `max_value`, `percentage`, `letter_grade`, `notes`, `graded_by`) VALUES");
write($f, implode(",\n", $gradeSql) . ";");

// ============================================================
// 6. ملخصات درجات الطلاب (student_subject_summary)
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 6. ملخص درجات الطلاب لكل مادة");
write($f, "-- ============================================================================");

$summaryId = 10000;
$summarySql = [];

foreach ($studentSubjectTotals as $key => $data) {
    $pct = $data['max'] > 0 ? round($data['total'] / $data['max'] * 100, 2) : 0;
    $letter = getLetterGrade($pct);
    $gp = $letterGrades[$letter] ?? 0;
    
    $summarySql[] = "({$summaryId}, {$data['student_id']}, {$data['subject_id']}, {$data['class_id']}, 1, 1, {$data['total']}, {$data['max']}, {$pct}, '{$letter}', {$gp}, NULL)";
    $summaryId++;
}

write($f, "INSERT IGNORE INTO `student_subject_summary` (`id`, `student_id`, `subject_id`, `class_id`, `academic_year_id`, `semester_id`, `total_grade`, `max_grade`, `percentage`, `letter_grade`, `grade_point`, `rank_in_class`) VALUES");
write($f, implode(",\n", $summarySql) . ";");

// ============================================================
// 7. درجات الفصل الدراسي (semester_scores) + GPA
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 7. درجات الفصل والمعدل التراكمي");
write($f, "-- ============================================================================");

$ssId = 5000;
$ssSql = [];
$studentsProcessed = [];

foreach ($studentSubjectTotals as $key => $data) {
    $sid = $data['student_id'];
    if (isset($studentsProcessed[$sid])) continue;
    $studentsProcessed[$sid] = true;
    
    // Calculate GPA from all subjects
    $totalPct = 0;
    $totalGp = 0;
    $subjCount = 0;
    $carryOver = [];
    
    foreach ($studentSubjectTotals as $k => $d) {
        if ($d['student_id'] == $sid) {
            $p = $d['max'] > 0 ? $d['total'] / $d['max'] * 100 : 0;
            $l = getLetterGrade($p);
            $gp = $letterGrades[$l] ?? 0;
            $totalPct += $p;
            $totalGp += $gp;
            $subjCount++;
            
            if ($p < 50) {
                $carryOver[] = 'مادة ' . $d['subject_id'];
            }
        }
    }
    
    if ($subjCount == 0) continue;
    
    $avgPct = round($totalPct / $subjCount, 2);
    $avgGp = round($totalGp / $subjCount, 2);
    $carryJson = !empty($carryOver) ? "'[\"" . implode('\", \"', $carryOver) . "\"]'" : 'NULL';
    
    $ssSql[] = "({$ssId}, {$sid}, 1, 1, {$avgGp}, {$avgPct}, NULL, {$carryJson})";
    $ssId++;
}

write($f, "INSERT IGNORE INTO `semester_scores` (`id`, `student_id`, `academic_year_id`, `semester_id`, `gpa`, `total_percentage`, `rank_in_grade`, `carry_over_subjects`) VALUES");
write($f, implode(",\n", $ssSql) . ";");

// ============================================================
// 8. السلوك (behavior_scores + behavior_incidents)
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 8. السلوك (الدرجات + الحوادث)");
write($f, "-- ============================================================================");

$bsId = 5000;
$bsSql = [];

foreach ($studentsInEnrollments as $s) {
    $sid = $s['id'];
    $cid = $studentClassMap[$sid];
    $hash = abs(crc32($s['student_number']));
    
    $base = 100;
    $positive = $hash % 15; // 0-14 points positive
    $negative = ($hash * 7) % 10; // 0-9 points negative
    $current = $base + $positive - $negative;
    $pct = $current;
    $isPassing = $pct >= 60 ? 1 : 0;
    
    $bsSql[] = "({$bsId}, {$sid}, {$cid}, 1, 1, {$base}, {$positive}, {$negative}, {$pct}, {$isPassing})";
    $bsId++;
}

write($f, "INSERT IGNORE INTO `behavior_scores` (`id`, `student_id`, `class_id`, `academic_year_id`, `semester_id`, `starting_points`, `earned_points`, `deducted_points`, `current_percentage`, `is_passing`) VALUES");
write($f, implode(",\n", $bsSql) . ";");

// ============================================================
// 9. الحوادث السلوكية (subset of students - 20%)
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 9. حوادث سلوكية (لـ 20% من الطلاب)");
write($f, "-- ============================================================================");

$biId = 5000;
$biSql = [];
$behaviorCategories = [1,2,3,4,5,6,7,8,9,10,11,12,13]; // from seed.sql

foreach ($studentsInEnrollments as $idx => $s) {
    // Only 20% of students have incidents
    if ($idx % 5 != 0) continue;
    
    $sid = $s['id'];
    $cid = $studentClassMap[$sid];
    $hash = abs(crc32($s['student_number']));
    
    // 1-3 incidents per selected student
    $numIncidents = 1 + ($hash % 3);
    for ($i = 0; $i < $numIncidents; $i++) {
        $catId = $behaviorCategories[(($hash + $i * 7) % count($behaviorCategories))];
        $isPositive = $catId <= 6;
        $points = $isPositive ? (1 + ($hash + $i) % 5) : (-1 - (($hash + $i * 3) % 10));
        $days = 10 + (($hash + $i * 11) % 80);
        $month = 10 + intdiv($days, 30);
        $day = ($days % 28) + 1;
        $date = "2025-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($day, 2, '0', STR_PAD_LEFT);
        
        $teacherId = 4 + ($hash % 6);
        
        $desc = $isPositive ? 'سلوك إيجابي - تميز في الانضباط' : 'سلوك سلبي - مخالفة لوائح المدرسة';
        $biSql[] = "({$biId}, {$sid}, {$catId}, {$cid}, 1, 1, '{$date}', '{$desc}', {$points}, {$teacherId})";
        $biId++;
    }
}

write($f, "INSERT IGNORE INTO `behavior_incidents` (`id`, `student_id`, `category_id`, `class_id`, `academic_year_id`, `semester_id`, `incident_date`, `description`, `points`, `reported_by`) VALUES");
$biChunks = array_chunk($biSql, 200);
foreach ($biChunks as $i => $chunk) {
    if ($i == 0) {
        write($f, implode(",\n", $chunk) . ";");
    }
}

// ============================================================
// 10. الحضور (attendance_daily - أسبوع واحد لكل طالب)
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 10. الحضور اليومي (أسبوع واحد لكل طالب)");
write($f, "-- ============================================================================");

$adId = 50000;
$adSql = [];
$attendanceStatuses = ['present', 'present', 'present', 'present', 'present', 'absent', 'late', 'excused'];
$attendanceDates = [];
for ($d = 0; $d < 20; $d++) {
    $day = $d + 1;
    $attendanceDates[] = "2025-10-" . str_pad($day, 2, '0', STR_PAD_LEFT);
}

foreach ($studentsInEnrollments as $idx => $s) {
    $sid = $s['id'];
    $cid = $studentClassMap[$sid];
    $hash = abs(crc32($s['student_number']));
    
    // Each student has 20 days of attendance
    foreach ($attendanceDates as $dIdx => $date) {
        // 85% present rate overall
        $statusIdx = ($hash + $dIdx * 3) % 100;
        $status = $statusIdx < 85 ? 'present' : $attendanceStatuses[5 + ($statusIdx % 3)];
        
        $teacherId = 4 + (($hash + $dIdx) % 6);
        
        $adSql[] = "({$adId}, {$sid}, {$cid}, 1, 1, '{$date}', '{$status}', NULL, {$teacherId})";
        $adId++;
    }
}

write($f, "-- حضور كل طالب لمدة 20 يوماً");
$adChunks = array_chunk($adSql, 500);
foreach ($adChunks as $chunk) {
    write($f, "INSERT IGNORE INTO `attendance_daily` (`id`, `student_id`, `class_id`, `academic_year_id`, `semester_id`, `date`, `status`, `notes`, `recorded_by`) VALUES");
    write($f, implode(",\n", $chunk) . ";");
}

// ============================================================
// 11. تقييمات السلوك (نماذج) - لـ 10% من الطلاب
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 11. تقييمات السلوك (نماذج)");
write($f, "-- ============================================================================");

$baId = 5000;
$baSql = [];

$baId = 5000;
$baSql = [];
$count = 0;
foreach ($studentsInEnrollments as $idx => $s) {
    if ($idx % 10 != 0) continue;
    if ($count >= 50) break;
    
    $sid = $s['id'];
    $cid = $studentClassMap[$sid];
    $hash = abs(crc32($s['student_number']));
    $score = 50 + ($hash % 51); // 50-100
    $teacherId = 4 + ($hash % 6);
    $count++;
    
    $baSql[] = "({$baId}, {$sid}, {$cid}, 1, 1, 1, {$score}, 'تقييم سلوك شامل', {$teacherId}, '2026-01-15')";
    $baId++;
}

if (!empty($baSql)) {
    write($f, "INSERT IGNORE INTO `behavior_assessments` (`id`, `student_id`, `class_id`, `academic_year_id`, `semester_id`, `form_id`, `overall_score`, `notes`, `evaluated_by`, `evaluation_date`) VALUES");
    write($f, implode(",\n", $baSql) . ";");
}

// ============================================================
// 12. EQI لكل مدرسة
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 12. مؤشر جودة التعليم (EQI) لكل مدرسة");
write($f, "-- ============================================================================");

write($f, "INSERT IGNORE INTO `eqi_calculations` (`id`, `school_id`, `academic_year_id`, `semester_id`, `academic_score`, `teaching_score`, `climate_score`, `behavior_score`, `skills_score`, `attendance_score`, `overall_eqi`, `calculated_by`) VALUES");
write($f, "(100, 1, 1, 1, 78.50, 82.00, 75.00, 88.00, 70.00, 85.00, 78.98, 1),");
write($f, "(101, 2, 1, 1, 65.00, 70.00, 72.00, 80.00, 60.00, 90.00, 70.50, 1),");
write($f, "(102, 3, 1, 1, 85.00, 78.00, 80.00, 90.00, 75.00, 82.00, 81.70, 1);");
// Note: إذا كان لديك demo_seed.sql فسيتم تخطي هذه الصفوف بسبب UNIQUE KEY
// للفصل الثاني (semester_id=2) - لا يتعارض مع demo_seed
write($f, "INSERT IGNORE INTO `eqi_calculations` (`id`, `school_id`, `academic_year_id`, `semester_id`, `academic_score`, `teaching_score`, `climate_score`, `behavior_score`, `skills_score`, `attendance_score`, `overall_eqi`, `calculated_by`) VALUES");
write($f, "(103, 1, 1, 2, 80.00, 84.00, 77.00, 90.00, 72.00, 88.00, 80.50, 1),");
write($f, "(104, 2, 1, 2, 68.00, 72.00, 74.00, 82.00, 62.00, 92.00, 72.80, 1),");
write($f, "(105, 3, 1, 2, 87.00, 80.00, 82.00, 92.00, 78.00, 85.00, 83.20, 1);");

// ============================================================
// 13. قرارات الترقية
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 13. قرارات الترقية");
write($f, "-- ============================================================================");

$pdId = 5000;
$pdSql = [];

foreach ($studentsInEnrollments as $idx => $s) {
    $sid = $s['id'];
    $hash = abs(crc32($s['student_number']));
    $academicPct = 70 + (($hash * 3) % 31) - 15; // 55-100
    $behaviorPct = 80 + ($hash % 21); // 80-100
    $attendanceRate = 75 + ($hash % 26); // 75-100
    
    $academicPass = $academicPct >= 50 ? 1 : 0;
    $behaviorPass = $behaviorPct >= 60 ? 1 : 0;
    $attendancePass = $attendanceRate >= 75 ? 1 : 0;
    
    $totalPass = $academicPass + $behaviorPass + $attendancePass;
    if ($totalPass == 3) {
        $decision = 'promoted';
        $status = 'approved';
    } elseif ($totalPass >= 1 && $academicPass) {
        $decision = 'conditional';
        $status = 'proposed';
    } else {
        $decision = 'repeated';
        $status = 'approved';
    }
    
    $schoolAdminId = ($s['school_id'] == 1) ? 2 : (($s['school_id'] == 2) ? 3 : 5);
    // Fix: use correct school admin IDs
    $schoolAdminMap = [1 => 2, 2 => 3, 3 => 5]; // 5 is actually teacher, but let me check
    // Actually, let's use a simpler approach
    $decidedBy = [1 => 2, 2 => 3, 3 => 4][$s['school_id']] ?? 2;
    
    $pdSql[] = "({$pdId}, {$sid}, 1, 1, {$academicPct}, {$behaviorPct}, {$attendanceRate}, {$academicPass}, {$behaviorPass}, {$attendancePass}, '{$decision}', '{$status}', " . ($status == 'approved' ? $decidedBy : 'NULL') . ", NULL, {$decidedBy})";
    $pdId++;
}

write($f, "INSERT IGNORE INTO `promotion_decisions` (`id`, `student_id`, `academic_year_id`, `semester_id`, `academic_score`, `behavior_score`, `attendance_score`, `academic_pass`, `behavior_pass`, `attendance_pass`, `decision`, `status`, `approved_by`, `notes`, `decided_by`) VALUES");
write($f, implode(",\n", $pdSql) . ";");

// ============================================================
// 14. التنبيهات (لـ 15% من الطلاب)
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 14. التنبيهات");
write($f, "-- ============================================================================");

$alertId = 5000;
$alertSql = [];

$alertTypes = ['low_grade','failing_subject','high_absence','consecutive_absence','poor_behavior','promotion_risk'];
$severities = ['info','warning','danger','critical'];

foreach ($studentsInEnrollments as $idx => $s) {
    if ($idx % 7 != 0) continue; // ~15%
    if (count($alertSql) >= 80) break;
    
    $sid = $s['id'];
    $hash = abs(crc32($s['student_number']));
    $type = $alertTypes[$hash % count($alertTypes)];
    $severity = $severities[$hash % count($severities)];
    
    $titles = [
        'low_grade' => 'انخفاض في الدرجات',
        'failing_subject' => 'رسوب في مادة',
        'high_absence' => 'ارتفاع نسبة الغياب',
        'consecutive_absence' => 'غياب متتالي',
        'poor_behavior' => 'سوء السلوك',
        'promotion_risk' => 'خطر عدم الترقية',
    ];
    $messages = [
        'low_grade' => 'الطالب ' . $s['full_name'] . ' يعاني من انخفاض في التحصيل الدراسي',
        'failing_subject' => 'الطالب ' . $s['full_name'] . ' رسب في مادة دراسية',
        'high_absence' => 'نسبة غياب الطالب ' . $s['full_name'] . ' تجاوزت الحد المسموح',
        'consecutive_absence' => 'الطالب ' . $s['full_name'] . ' تغيب لمدة 3 أيام متتالية',
        'poor_behavior' => 'الطالب ' . $s['full_name'] . ' سجل سلوكاً سيئاً',
        'promotion_risk' => 'الطالب ' . $s['full_name'] . ' مهدد بعدم الترقية',
    ];
    
    $isRead = $hash % 3 == 0 ? 1 : 0;
    $isResolved = $hash % 5 == 0 ? 1 : 0;
    
    $alertSql[] = "({$alertId}, {$s['school_id']}, {$sid}, '{$type}', '{$severity}', '{$titles[$type]}', '{$messages[$type]}', {$isRead}, NULL, {$isResolved}, NULL, NULL)";
    $alertId++;
}

if (!empty($alertSql)) {
    write($f, "INSERT IGNORE INTO `alerts` (`id`, `school_id`, `student_id`, `alert_type`, `severity`, `title_ar`, `message_ar`, `is_read`, `read_at`, `is_resolved`, `resolved_by`, `resolved_at`) VALUES");
    write($f, implode(",\n", $alertSql) . ";");
}

// ============================================================
// 15. معلمو المواد (subject_teachers)
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 15. تعيين معلمي المواد للشعب");
write($f, "-- ============================================================================");

$stId = 5000;
$stSql = [];
$stTrack = [];

foreach ($allClasses as $c) {
    $classId = $c['id'];
    $teacherId = $c['teacher_id'];
    $gradeId = null;
    foreach ($classMap as $k => $classes) {
        foreach ($classes as $cc) {
            if ($cc['id'] == $classId) {
                $gradeId = explode('-', $k)[1];
                break 2;
            }
        }
    }
    if (!$gradeId) continue;
    
    $subjects = ($gradeId >= 10) ? [1, 3, 8, 9] : [1, 2, 4, 5];
    
    foreach ($subjects as $subjId) {
        $key = $subjId . '-' . $teacherId . '-' . $classId . '-1';
        if (isset($stTrack[$key])) continue;
        $stTrack[$key] = true;
        $stSql[] = "({$stId}, {$subjId}, {$teacherId}, {$classId}, 1)";
        $stId++;
    }
}

write($f, "INSERT IGNORE INTO `subject_teachers` (`id`, `subject_id`, `teacher_id`, `classroom_id`, `semester_id`) VALUES");
write($f, implode(",\n", $stSql) . ";");

// ============================================================
// 16. تقييمات التدريس
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 16. تقييمات التدريس للمعلمين");
write($f, "-- ============================================================================");

// جمع كل المعلمين المستخدمين
$allUsedTeachers = [];
foreach ($allClasses as $c) {
    $tid = $c['teacher_id'];
    if (!isset($allUsedTeachers[$tid])) {
        $allUsedTeachers[$tid] = ['id' => $tid, 'name' => ''];
    }
}

$teId = 5000;
$teSql = [];
foreach ($allUsedTeachers as $tid => $info) {
    if ($tid == 0) continue;
    $hash = abs($tid * 17);
    $self = 60 + ($hash % 41);
    $peer = 60 + (($hash * 3) % 41);
    $admin = 60 + (($hash * 7) % 41);
    $combined = round($self * 0.2 + $peer * 0.3 + $admin * 0.5, 2);
    $teSql[] = "({$teId}, {$tid}, 1, 1, {$self}, {$peer}, {$admin}, {$combined})";
    $teId++;
}

write($f, "INSERT IGNORE INTO `teaching_evaluation_summary` (`id`, `teacher_id`, `academic_year_id`, `semester_id`, `self_score`, `peer_score`, `admin_score`, `combined_score`) VALUES");
write($f, implode(",\n", $teSql) . ";");

// ============================================================
// 17. إعدادات الحضور للمدارس
// ============================================================
write($f, "\n-- ============================================================================");
write($f, "-- 17. إعدادات الحضور");
write($f, "-- ============================================================================");

write($f, "INSERT IGNORE INTO `attendance_settings` (`id`, `school_id`, `min_attendance_rate`, `late_count_as_absent`, `consecutive_absence_alert`) VALUES");
write($f, "(100, 1, 75.00, 0, 3),");
write($f, "(101, 2, 75.00, 1, 3),");
write($f, "(102, 3, 80.00, 1, 3);");

// ============================================================
// 18. الحساب الختامي
// ============================================================
fwrite($f, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
fwrite($f, "\n-- ============================================================================\n");
fwrite($f, "-- انتهى ملف البيانات التجريبية\n");
fwrite($f, "-- إجمالي الطلاب: {$totalStudents}\n");
fwrite($f, "-- عدد الشعب: " . count($allClasses) . "\n");
fwrite($f, "-- ============================================================================\n");

fclose($f);

echo "تم توليد ملف SQL: {$outputFile}\n";
echo "إجمالي الطلاب: {$totalStudents}\n";
echo "عدد الشعب: " . count($allClasses) . "\n";
echo "عدد الاختبارات: " . count($testInfo) . "\n";
echo "عدد الدرجات: " . count($gradeSql) . "\n";

// Helper function
function getGradeName($gradeId) {
    $names = [
        1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث', 4 => 'الرابع',
        5 => 'الخامس', 6 => 'السادس', 7 => 'السابع', 8 => 'الثامن',
        9 => 'التاسع', 10 => 'العاشر', 11 => 'الحادي عشر', 12 => 'الثاني عشر'
    ];
    return 'الصف ' . ($names[$gradeId] ?? $gradeId);
}
