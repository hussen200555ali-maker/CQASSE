<?php
/**
 * ============================================================
 * EQAS - الدوال المساعدة المحسّنة
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * مجموعة شاملة من الدوال المساعدة المستخدمة في جميع أنحاء النظام:
 * - الأمان (تنظيف المدخلات، CSRF، XSS)
 * - التنسيق (التواريخ، الأرقام، الحالات)
 * - واجهة المستخدم (شارات، أشرطة تقدم، ترقيم صفحات)
 * - النظام (الإعدادات، السجلات، الروابط)
 * - كلمات المرور (توليد، التحقق من القوة)
 * ============================================================
 */

// ============================================================================
// دوال الأمان والحماية
// ============================================================================

/**
 * تنظيف النص للعرض في HTML (منع XSS)
 *
 * @param string|null $string النص المراد تنظيفه
 * @return string النص المنظف
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * تنظيف المدخلات من XSS وHTML
 * تدعم المصفوفات المتداخلة
 *
 * @param string|array $input المدخل المراد تنظيفه
 * @return string|array المدخل المنظف
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * توليد حقل CSRF مخفي للنماذج
 *
 * @return string حقل إدخال HTML مخفي
 */
function csrfField() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

/**
 * التحقق من رمز CSRF المرسل
 *
 * @return bool هل الرمز صحيح
 */
function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================================================
// دوال الرسائل الفلاشية والتوجيه
// ============================================================================

/**
 * تعيين رسالة فلاشية (تظهر لمرة واحدة)
 *
 * @param string $message نص الرسالة
 * @param string $type نوع الرسالة (success, danger, warning, info)
 * @return void
 */
function setFlash($message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

/**
 * إعادة توجيه إلى عنوان URL
 *
 * @param string $url العنوان المستهدف
 * @return void
 */
function redirect($url) {
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>window.location.href="' . htmlspecialchars($url) . '";</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '"></noscript>';
    }
    exit;
}

// ============================================================================
// دوال طلبات HTTP
// ============================================================================

/**
 * التحقق مما إذا كان الطلب الحالي AJAX
 *
 * @return bool هل الطلب AJAX
 */
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * إرسال استجابة JSON وإنهاء التنفيذ
 *
 * @param mixed $data البيانات المراد إرسالها
 * @param int $statusCode رمز حالة HTTP
 * @return void
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// دوال تنسيق التواريخ والأرقام
// ============================================================================

/**
 * تنسيق التاريخ بالعربية
 *
 * @param string $date التاريخ بتنسيق قاعدة البيانات
 * @param string $format نوع التنسيق (short, medium, full)
 * @return string التاريخ المنسق
 */
function formatDateAr($date, $format = 'short') {
    if (empty($date)) return '';

    $d = new DateTime($date);
    $months = [
        1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
        5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];

    $days = [
        0 => 'الأحد', 1 => 'الإثنين', 2 => 'الثلاثاء',
        3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'
    ];

    switch ($format) {
        case 'full':
            return $days[(int)$d->format('w')] . ' ' . $d->format('j') . ' ' . $months[(int)$d->format('n')] . ' ' . $d->format('Y');
        case 'medium':
            return $d->format('j') . ' ' . $months[(int)$d->format('n')] . ' ' . $d->format('Y');
        case 'short':
        default:
            return $d->format('Y/m/d');
    }
}

/**
 * تنسيق الأرقام بالأسلوب العربي
 *
 * @param mixed $num الرقم المراد تنسيقه
 * @return string الرقم المنسق
 */
function formatNumberAr($num) {
    if ($num === null || $num === false) return '٠';
    return number_format((float)$num, 0, '.', ',');
}

// ============================================================================
// دوال مؤشر جودة التعليم (EQI)
// ============================================================================

/**
 * تقييم درجة مؤشر الجودة
 *
 * @param float $score درجة المؤشر
 * @return array [التقييم، صنف CSS، اللون]
 */
function getEqiRating($score) {
    static $thresholds = null;
    if ($thresholds === null) {
        $thresholds = [
            'excellent' => 85,
            'very_good' => 70,
            'fair'      => 50,
        ];
        global $db;
        if (isset($db)) {
            try {
                $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('policy_color_green_from','policy_color_yellow_from','policy_color_red_to')");
                $rows = $stmt->fetchAll();
                $settings = [];
                foreach ($rows as $row) {
                    $settings[$row['setting_key']] = (int)$row['setting_value'];
                }
                if (isset($settings['policy_color_green_from'])) {
                    $thresholds['excellent'] = $settings['policy_color_green_from'];
                }
                if (isset($settings['policy_color_yellow_from'])) {
                    $thresholds['very_good'] = $settings['policy_color_yellow_from'];
                }
                if (isset($settings['policy_color_red_to'])) {
                    $thresholds['fair'] = $settings['policy_color_red_to'] + 1;
                }
            } catch (Exception $e) {}
        }
    }

    if ($score >= $thresholds['excellent']) return ['ممتاز', 'eqi-rating-excellent', 'success'];
    if ($score >= $thresholds['very_good']) return ['جيد جداً', 'eqi-rating-good', 'primary'];
    if ($score >= $thresholds['fair']) return ['مقبول', 'eqi-rating-fair', 'warning'];
    return ['ضعيف', 'eqi-rating-poor', 'danger'];
}

// ============================================================================
// دوال ترجمة الأدوار والأنواع
// ============================================================================

/**
 * ترجمة اسم الدور إلى العربية
 *
 * @param string $role اسم الدور بالإنجليزية
 * @return string الاسم بالعربية
 */
function getRoleLabel($role) {
    $labels = [
        'group_admin'  => 'مدير النظام',
        'school_admin' => 'مدير المدرسة',
        'teacher'      => 'معلم',
        'parent'       => 'ولي أمر'
    ];
    return $labels[$role] ?? $role;
}

/**
 * ترجمة نوع المدرسة إلى العربية
 *
 * @param string $type نوع المدرسة
 * @return string النوع بالعربية
 */
function getSchoolTypeLabel($type) {
    $labels = [
        'primary'   => 'ابتدائية',
        'secondary' => 'ثانوية',
        'mixed'     => 'مختلطة'
    ];
    return $labels[$type] ?? $type;
}

/**
 * ترجمة الحالة إلى عربية
 *
 * @param string $status الحالة بالإنجليزية
 * @return string الحالة بالعربية
 */
function getStatusLabel($status) {
    $labels = [
        'active'      => 'نشط',
        'inactive'    => 'غير نشط',
        'pending'     => 'معلق',
        'completed'   => 'مكتمل',
        'draft'       => 'مسودة',
        'published'   => 'منشور',
        'locked'      => 'مقفل',
        'enrolled'    => 'مسجل',
        'transferred' => 'منقول',
        'dropped'     => 'منسحب',
        'graduated'   => 'متخرج',
        'promoted'    => 'مرفع',
        'repeated'    => 'معيد',
        'conditional' => 'مشروط',
        'proposed'    => 'مقترح',
        'approved'    => 'معتمد',
        'rejected'    => 'مرفوض',
        'planning'    => 'تخطيط',
        'evaluation'  => 'تقييم',
        'closed'      => 'مغلق'
    ];
    return $labels[$status] ?? $status;
}

/**
 * ترجمة نوع التنبيه إلى العربية
 *
 * @param string $type نوع التنبيه
 * @return string النوع بالعربية
 */
function getAlertTypeLabel($type) {
    $labels = [
        'low_grade'                 => 'درجة منخفضة',
        'failing_subject'           => 'رسوب مادة',
        'high_absence'              => 'غياب مرتفع',
        'consecutive_absence'       => 'غياب متتالي',
        'poor_behavior'             => 'سلوك سيء',
        'promotion_risk'            => 'خطر عدم الترفيع',
        'eqi_decline'               => 'انخفاض مؤشر الجودة',
        'attendance_below_threshold' => 'حضور دون الحد',
        'behavior_below_threshold'  => 'سلوك دون الحد',
        'custom'                    => 'مخصص'
    ];
    return $labels[$type] ?? $type;
}

/**
 * ترجمة مستوى الخطورة إلى العربية
 *
 * @param string $severity مستوى الخطورة
 * @return string المستوى بالعربية
 */
function getSeverityLabel($severity) {
    $labels = [
        'info'     => 'معلومات',
        'warning'  => 'تحذير',
        'danger'   => 'خطر',
        'critical' => 'حرج'
    ];
    return $labels[$severity] ?? $severity;
}

/**
 * ترجمة أداة التقييم إلى العربية
 *
 * @param string $toolType نوع الأداة
 * @return string الاسم بالعربية
 */
function getToolTypeLabel($toolType) {
    $labels = [
        'likert_5'       => 'مقياس ليكرت (5 مستويات)',
        'frequency_5'    => 'مقياس التكرار (5 مستويات)',
        'satisfaction_5' => 'مقياس الرضا (5 مستويات)',
        'yes_no'         => 'نعم / لا',
        'severity_4'     => 'مقياس الشدة (عكسي)',
        'counter'        => 'عداد',
        'numeric_100'    => 'رقمي (0-100)',
        'numeric_custom' => 'رقمي (مخصص)',
        'rubric_4'       => 'روبريك (4 مستويات)',
        'text'           => 'نص حر'
    ];
    return $labels[$toolType] ?? $toolType;
}

// ============================================================================
// دوال النصوص
// ============================================================================

/**
 * اقتطاع النص إلى طول محدد
 *
 * @param string $text النص الأصلي
 * @param int $length الطول الأقصى
 * @param string $suffix اللاحقة عند الاقتطاع
 * @return string النص المقتطع
 */
function truncate($text, $length = 100, $suffix = '...') {
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . $suffix;
}

// ============================================================================
// دوال الصلاحيات
// ============================================================================

/**
 * التحقق من صلاحية المستخدم بناءً على التسلسل الهرمي للأدوار
 *
 * @param string $requiredRole الدور المطلوب
 * @return bool هل لدى المستخدم الصلاحية
 */
function hasPermission($requiredRole) {
    $userRole = $_SESSION['user']['role'] ?? 'guest';
    $roleHierarchy = [
        'group_admin'  => 100,
        'school_admin' => 80,
        'teacher'      => 50,
        'parent'       => 30,
        'guest'        => 0
    ];

    $userLevel = $roleHierarchy[$userRole] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;

    return $userLevel >= $requiredLevel;
}

// ============================================================================
// دوال توليد السلاسل العشوائية
// ============================================================================

/**
 * توليد سلسلة عشوائية سداسية عشرية
 *
 * @param int $length طول السلسلة (يجب أن يكون زوجياً)
 * @return string السلسلة العشوائية
 */
function randomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * توليد رمز مرجعي عشوائي (5 أحرف)
 * يستخدم أحرف واضحة بدون تشابه (بدون O/0, I/1/L)
 *
 * @param string $prefix بادئة الرمز (اختياري)
 * @return string الرمز المرجعي
 */
function referenceCode($prefix = '') {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // أحرف بدون لبس
    $code = '';
    for ($i = 0; $i < 5; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $prefix . $code;
}

/**
 * توليد كلمة مرور عشوائية آمنة
 *
 * @param int $length طول كلمة المرور
 * @return string كلمة المرور المولّدة
 */
function generatePassword($length = 12) {
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $numbers   = '0123456789';
    $symbols   = '!@#$%^&*';
    $all       = $lowercase . $uppercase . $numbers . $symbols;

    // ضمان وجود حرف واحد على الأقل من كل نوع
    $password = '';
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];

    // إكمال الطول المطلوب بأحرف عشوائية
    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }

    // خلط الأحرف عشوائياً
    return str_shuffle($password);
}

/**
 * التحقق من قوة كلمة المرور
 * يعيد درجة من 0 إلى 6 مع وصف ولون
 *
 * @param string $password كلمة المرور المراد فحصها
 * @return array [score, label, color]
 */
function passwordStrength($password) {
    $score = 0;

    // فحص الطول
    if (strlen($password) >= 8)  $score++;
    if (strlen($password) >= 12) $score++;

    // فحص تنوع الأحرف
    if (preg_match('/[a-z]/', $password))     $score++; // أحرف صغيرة
    if (preg_match('/[A-Z]/', $password))     $score++; // أحرف كبيرة
    if (preg_match('/[0-9]/', $password))     $score++; // أرقام
    if (preg_match('/[^a-zA-Z0-9]/', $password)) $score++; // رموز خاصة

    $labels = ['ضعيفة جداً', 'ضعيفة', 'متوسطة', 'جيدة', 'قوية', 'قوية جداً', 'ممتازة'];
    $colors = ['danger', 'danger', 'warning', 'info', 'success', 'success', 'success'];

    return [
        'score' => $score,
        'label' => $labels[$score] ?? 'ضعيفة',
        'color' => $colors[$score] ?? 'danger'
    ];
}

// ============================================================================
// دوال العام الدراسي
// ============================================================================

/**
 * الحصول على معرف العام الدراسي الحالي
 *
 * @param PDO $db كائن اتصال قاعدة البيانات
 * @return int|null معرف العام الدراسي أو null
 */
function getCurrentAcademicYearId($db) {
    try {
        $stmt = $db->query("SELECT id FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        return $stmt ? $stmt->fetchColumn() : null;
    } catch (Exception $e) {
        return null;
    }
}

// ============================================================================
// دوال سجل النشاط
// ============================================================================

/**
 * تسجيل نشاط في سجل النظام
 *
 * @param PDO $db كائن اتصال قاعدة البيانات
 * @param int|null $userId معرف المستخدم
 * @param string $action نوع الإجراء
 * @param string $description وصف الإجراء
 * @param string $entityType نوع الكيان المتأثر
 * @param int|null $entityId معرف الكيان المتأثر
 * @return void
 */
function logActivity($db, $userId, $action, $description = '', $entityType = '', $entityId = null) {
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, description, entity_type, entity_id, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId,
            $action,
            $description,
            $entityType,
            $entityId,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log('EQAS Activity Log Error: ' . $e->getMessage());
    }
}

// ============================================================================
// دوال إعدادات النظام
// ============================================================================

/**
 * الحصول على إعداد النظام (مع تجاوز المدرسة)
 * يبحث أولاً في تجاوزات المدرسة ثم في الإعدادات العامة
 *
 * @param string $key مفتاح الإعداد
 * @param int|null $schoolId معرف المدرسة (للتجاوز)
 * @return mixed قيمة الإعداد أو null
 */
function getSetting($key, $schoolId = null) {
    global $db;

    try {
        // 1. البحث في تجاوزات المدرسة أولاً
        if ($schoolId) {
            $stmt = $db->prepare("
                SELECT ss.override_value
                FROM school_settings ss
                JOIN system_settings sst ON ss.setting_id = sst.id
                WHERE sst.setting_key = ? AND ss.school_id = ? AND ss.is_active = 1
            ");
            $stmt->execute([$key, $schoolId]);
            $override = $stmt->fetch();
            if ($override) {
                return $override['override_value'];
            }
        }

        // 2. الرجوع للإعداد العام
        $stmt = $db->prepare("
            SELECT setting_value, value_type
            FROM system_settings
            WHERE setting_key = ? AND is_active = 1
        ");
        $stmt->execute([$key]);
        $setting = $stmt->fetch();

        if (!$setting) {
            return null;
        }

        // تحويل القيمة حسب النوع
        switch ($setting['value_type']) {
            case 'number':
                return (float)$setting['setting_value'];
            case 'boolean':
                return filter_var($setting['setting_value'], FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($setting['setting_value'], true);
            default:
                return $setting['setting_value'];
        }
    } catch (Exception $e) {
        error_log('EQAS Get Setting Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * تعيين إعداد النظام (عام أو تجاوز مدرسة)
 *
 * @param string $key مفتاح الإعداد
 * @param mixed $value القيمة الجديدة
 * @param int|null $schoolId معرف المدرسة (للتجاوز)
 * @return bool هل تم التحديث بنجاح
 */
function setSetting($key, $value, $schoolId = null) {
    global $db;

    try {
        if ($schoolId) {
            // تجاوز على مستوى المدرسة
            $stmt = $db->prepare("
                SELECT id FROM system_settings WHERE setting_key = ? AND is_active = 1
            ");
            $stmt->execute([$key]);
            $setting = $stmt->fetch();

            if (!$setting) {
                return false;
            }

            $stmt = $db->prepare("
                INSERT INTO school_settings (school_id, setting_id, override_value, updated_by, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    override_value = VALUES(override_value),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            return $stmt->execute([
                $schoolId,
                $setting['id'],
                $value,
                $_SESSION['user']['id'] ?? null
            ]);
        } else {
            // تحديث الإعداد العام
            $stmt = $db->prepare("
                UPDATE system_settings
                SET setting_value = ?, updated_at = NOW()
                WHERE setting_key = ?
            ");
            return $stmt->execute([$value, $key]);
        }
    } catch (Exception $e) {
        error_log('EQAS Set Setting Error: ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// دوال عناوين URL والروابط
// ============================================================================

/**
 * إنشاء رابط URL كامل من المسار النسبي
 *
 * @param string $path المسار النسبي
 * @return string الرابط الكامل
 */
function baseUrl($path = '') {
    $base = defined('EQAS_URL') ? EQAS_URL : 'http://localhost/eqas';
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// ============================================================================
// دوال واجهة المستخدم (HTML)
// ============================================================================

/**
 * إنشاء شريط التنقل (breadcrumb)
 *
 * @param array $items عناصر التنقل [العنوان => الرابط] آخر عنصر بدون رابط
 * @return string كود HTML لشريط التنقل
 */
function breadcrumb($items) {
    $html = '<nav class="breadcrumb" aria-label="مسار التنقل">';
    $html .= '<a href="' . baseUrl('index.php') . '">الرئيسية</a>';

    foreach ($items as $label => $url) {
        $html .= ' <span class="breadcrumb-separator">/</span> ';
        if ($url) {
            $html .= '<a href="' . $url . '">' . e($label) . '</a>';
        } else {
            $html .= '<span class="breadcrumb-current">' . e($label) . '</span>';
        }
    }

    $html .= '</nav>';
    return $html;
}

/**
 * إنشاء ترقيم الصفحات
 *
 * @param int $total إجمالي السجلات
 * @param int $page الصفحة الحالية
 * @param int $perPage عدد السجلات في كل صفحة
 * @param string $baseUrl الرابط الأساسي (مع & في النهاية)
 * @return string كود HTML لترقيم الصفحات
 */
function paginate($total, $page, $perPage = 20, $baseUrl = '') {
    $totalPages = ceil($total / $perPage);
    if ($totalPages <= 1) return '';

    $currentPage = max(1, min($page, $totalPages));

    $html = '<div class="pagination" role="navigation" aria-label="ترقيم الصفحات">';

    // زر السابق
    if ($currentPage > 1) {
        $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage - 1) . '" class="btn btn-sm" aria-label="الصفحة السابقة">&laquo; السابق</a>';
    }

    // أرقام الصفحات
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);

    // إظهار الصفحة الأولى دائماً
    if ($startPage > 1) {
        $html .= '<a href="' . $baseUrl . '&page=1" class="btn btn-sm">1</a>';
        if ($startPage > 2) {
            $html .= '<span class="pagination-dots">...</span>';
        }
    }

    for ($i = $startPage; $i <= $endPage; $i++) {
        $active = ($i == $currentPage) ? ' active' : '';
        $html .= '<a href="' . $baseUrl . '&page=' . $i . '" class="btn btn-sm' . $active . '">' . $i . '</a>';
    }

    // إظهار الصفحة الأخيرة دائماً
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<span class="pagination-dots">...</span>';
        }
        $html .= '<a href="' . $baseUrl . '&page=' . $totalPages . '" class="btn btn-sm">' . $totalPages . '</a>';
    }

    // زر التالي
    if ($currentPage < $totalPages) {
        $html .= '<a href="' . $baseUrl . '&page=' . ($currentPage + 1) . '" class="btn btn-sm" aria-label="الصفحة التالية">التالي &raquo;</a>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * إنشاء شارة حالة HTML
 *
 * @param string $status الحالة
 * @param string $label النص البديل (اختياري)
 * @return string كود HTML للشارة
 */
function statusBadge($status, $label = '') {
    $colors = [
        'active'      => 'success',
        'inactive'    => 'secondary',
        'pending'     => 'warning',
        'approved'    => 'success',
        'rejected'    => 'danger',
        'proposed'    => 'info',
        'promoted'    => 'success',
        'repeated'    => 'danger',
        'conditional' => 'warning',
        'critical'    => 'danger',
        'danger'      => 'danger',
        'warning'     => 'warning',
        'info'        => 'info',
        'completed'   => 'success',
        'draft'       => 'secondary'
    ];

    $color = $colors[$status] ?? 'secondary';
    $text = $label ?: getStatusLabel($status);

    return '<span class="badge badge-' . $color . '">' . e($text) . '</span>';
}

/**
 * إنشاء شريط نسبة مئوية HTML
 *
 * @param float $value القيمة الحالية
 * @param float $max القيمة القصوى
 * @param string $label النص البديل (اختياري)
 * @return string كود HTML لشريط التقدم
 */
function percentageBar($value, $max = 100, $label = '') {
    $percentage = min(100, max(0, ($value / $max) * 100));

    // تحديد اللون حسب النسبة
    if ($percentage >= 70) {
        $color = 'success';
    } elseif ($percentage >= 50) {
        $color = 'warning';
    } else {
        $color = 'danger';
    }

    $displayLabel = $label ?: round($percentage, 1) . '%';

    return '<div class="progress-bar-wrapper" role="progressbar" aria-valuenow="' . round($percentage, 1) . '" aria-valuemin="0" aria-valuemax="100">'
         . '<div class="progress-bar bg-' . $color . '" style="width:' . $percentage . '%">'
         . e($displayLabel)
         . '</div></div>';
}

// ============================================================================
// دوال التوثيق والملفات
// ============================================================================

/**
 * إنشاء ترويسة ملف توثيقية (10 أسطر)
 * تستخدم في بداية ملفات PHP لضمان التوثيق الموحد
 *
 * @param string $title عنوان الملف
 * @param string $description وصف الملف
 * @param string $author المؤلف
 * @param string $version رقم الإصدار
 * @return string ترويسة التوثيق كتعليق
 */
function fileHeader($title, $description, $author = 'EQAS Team', $version = '1.0') {
    return "/**
 * ============================================================
 * EQAS - {$title}
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * {$description}
 *
 * الملف: " . basename(__FILE__) . "
 * الإصدار: {$version}
 * التاريخ: " . date('Y-m-d') . "
 * المؤلف: {$author}
 * ============================================================
 */";
}

// ============================================================================
// دوال الجلسة والسياق
// ============================================================================



/**
 * الحصول على معرف العام الدراسي الحالي من الجلسة أو قاعدة البيانات
 *
 * @return int|null معرف العام الدراسي أو null
 */
function currentAcademicYearId() {
    if (isset($_SESSION['academic_year_id'])) {
        return $_SESSION['academic_year_id'];
    }
    global $db;
    try {
        $stmt = $db->query("SELECT id FROM academic_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $id = $stmt ? $stmt->fetchColumn() : null;
        if ($id) {
            $_SESSION['academic_year_id'] = $id;
        }
        return $id;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * الحصول على معرف الفصل الدراسي الحالي
 *
 * @param int $academicYearId معرف العام الدراسي
 * @return int|null معرف الفصل الدراسي أو null
 */
function currentSemesterId($academicYearId = null) {
    if (isset($_SESSION['current_semester_id'])) {
        return $_SESSION['current_semester_id'];
    }
    global $db;
    try {
        $ayId = $academicYearId ?? currentAcademicYearId();
        if (!$ayId) return null;
        $stmt = $db->prepare("SELECT id FROM semesters WHERE academic_year_id = ? AND is_active = 1 AND is_current = 1 LIMIT 1");
        $stmt->execute([$ayId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $_SESSION['current_semester_id'] = $id;
        }
        return $id;
    } catch (Exception $e) {
        return null;
    }
}

// ============================================================================
// دوال الفصول الدراسية والأقفال
// ============================================================================

/**
 * التحقق مما إذا كان الفصل الدراسي مقفلاً
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $semesterId معرف الفصل الدراسي
 * @return bool هل الفصل مقفل
 */
function isSemesterLocked($db, $semesterId) {
    try {
        // [إصلاح 2026-05-11] عمود status → lock_level والقيم soft_locked/hard_locked → soft/hard
        $stmt = $db->prepare("SELECT lock_level FROM semesters WHERE id = ?");
        $stmt->execute([$semesterId]);
        $lockLevel = $stmt->fetchColumn();
        return in_array($lockLevel, ['soft', 'hard', 'admin_handled']);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * التحقق من إمكانية تعديل البيانات في فصل دراسي
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $semesterId معرف الفصل الدراسي
 * @param string $role دور المستخدم
 * @return bool هل يمكن التعديل
 */
function canEditSemester($db, $semesterId, $role = '') {
    if (empty($role)) {
        $role = $_SESSION['user']['role'] ?? '';
    }
    try {
        // [إصلاح 2026-05-11] عمود status → lock_level والقيم open→none, soft_locked→soft, hard_locked→hard
        $stmt = $db->prepare("SELECT lock_level FROM semesters WHERE id = ?");
        $stmt->execute([$semesterId]);
        $lockLevel = $stmt->fetchColumn();

        if ($lockLevel === 'none') return true;
        if ($lockLevel === 'soft' && in_array($role, ['group_admin', 'school_admin'])) return true;
        if ($lockLevel === 'hard' && $role === 'group_admin') return true;
        if ($lockLevel === 'admin_handled') return false;
        return false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * ترجمة حالة الفصل الدراسي إلى العربية
 *
 * @param string $status حالة الفصل
 * @return string الحالة بالعربية
 */
function getSemesterStatusLabel($status) {
    // [إصلاح 2026-05-11] القيم open→none, soft_locked→soft, hard_locked→hard (تطابق ENUM في schema)
    $labels = [
        'none'          => 'مفتوح',
        'soft'          => 'قفل ناعم',
        'hard'          => 'قفل صلب',
        'admin_handled' => 'معالجة إدارية',
        'closed'        => 'مغلق'
    ];
    return $labels[$status] ?? $status;
}

/**
 * ترجمة مستوى الصف الدراسي إلى العربية
 *
 * @param int $level رقم المستوى
 * @param string $type نوع المرحلة (basic/secondary)
 * @return string اسم الصف بالعربية
 */
function getGradeLevelLabel($level, $type = 'basic') {
    $basicLabels = [
        1 => 'الأول', 2 => 'الثاني', 3 => 'الثالث',
        4 => 'الرابع', 5 => 'الخامس', 6 => 'السادس',
        7 => 'السابع', 8 => 'الثامن', 9 => 'التاسع'
    ];
    $secondaryLabels = [
        10 => 'العاشر', 11 => 'الحادي عشر', 12 => 'الثاني عشر'
    ];

    if ($type === 'secondary' || $level >= 10) {
        return ($secondaryLabels[$level] ?? $level) . ' ثانوي';
    }
    return ($basicLabels[$level] ?? $level) . ' أساسي';
}

// ============================================================================
// دوال تنسيق الملفات والأحجام
// ============================================================================

/**
 * تنسيق حجم الملف بالوحدة المناسبة
 *
 * @param int $bytes حجم الملف بالبايت
 * @param int $precision عدد المنازل العشرية
 * @return string الحجم المنسق
 */
function formatFileSize($bytes, $precision = 2) {
    if ($bytes === 0 || $bytes === null) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * تنسيق الوقت المنقضي (منذ متى)
 *
 * @param string $datetime التاريخ والوقت
 * @return string الوقت المنقضي بالعربية
 */
function timeAgo($datetime) {
    if (empty($datetime)) return '';
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . ' سنة';
    if ($diff->m > 0) return $diff->m . ' شهر';
    if ($diff->d > 0) return $diff->d . ' يوم';
    if ($diff->h > 0) return $diff->h . ' ساعة';
    if ($diff->i > 0) return $diff->i . ' دقيقة';
    return 'الآن';
}

// ============================================================================
// دوال التحقق من البيانات
// ============================================================================

/**
 * التحقق من صلاحية تاريخ
 *
 * @param string $date التاريخ المراد التحقق منه
 * @param string $format التنسيق المتوقع
 * @return bool هل التاريخ صالح
 */
function isValidDate($date, $format = 'Y-m-d') {
    if (empty($date)) return false;
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * التحقق من أن القيمة ضمن نطاق
 *
 * @param mixed $value القيمة
 * @param mixed $min الحد الأدنى
 * @param mixed $max الحد الأقصى
 * @return bool هل القيمة ضمن النطاق
 */
function inRange($value, $min, $max) {
    return is_numeric($value) && $value >= $min && $value <= $max;
}

/**
 * تنظيف رقمي للتأكد من أنه ضمن نطاق
 *
 * @param mixed $value القيمة
 * @param mixed $min الحد الأدنى
 * @param mixed $max الحد الأقصى
 * @param mixed $default القيمة الافتراضية
 * @return mixed القيمة المنظفة
 */
function clamp($value, $min, $max, $default = null) {
    if (!is_numeric($value)) return $default ?? $min;
    return max($min, min($max, $value));
}

// ============================================================================
// دوال الإعدادات المتقدمة
// ============================================================================

/**
 * الحصول على جميع إعدادات فئة معينة
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param string $category فئة الإعدادات
 * @param int|null $schoolId معرف المدرسة
 * @return array الإعدادات
 */
function getSettingsByCategory($db, $category, $schoolId = null) {
    try {
        $stmt = $db->prepare("
            SELECT ss.setting_key, ss.setting_value, ss.value_type, ss.description_ar,
                   COALESCE(sc.override_value, ss.setting_value) as effective_value
            FROM system_settings ss
            LEFT JOIN school_settings sc ON sc.setting_id = ss.id AND sc.school_id = ? AND sc.is_active = 1
            WHERE ss.category = ? AND ss.is_active = 1
            ORDER BY ss.sort_order, ss.setting_key
        ");
        $stmt->execute([$schoolId, $category]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * الحصول على فئات الإعدادات
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @return array الفئات
 */
function getSettingCategories($db) {
    try {
        $stmt = $db->query("
            SELECT category, COUNT(*) as count,
                   MIN(description_ar) as description
            FROM system_settings
            WHERE is_active = 1
            GROUP BY category
            ORDER BY MIN(sort_order), category
        ");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ============================================================================
// دوال الترقيم والترتيب
// ============================================================================

/**
 * تحويل رقم إلى حرف عمود Excel (1=A, 2=B, ..., 27=AA)
 *
 * @param int $number الرقم
 * @return string حرف العمود
 */
function numberToLetter($number) {
    $letter = '';
    while ($number > 0) {
        $number--;
        $letter = chr(65 + ($number % 26)) . $letter;
        $number = (int)($number / 26);
    }
    return $letter;
}

/**
 * حساب المتوسط المرجح
 *
 * @param array $values القيم مع أوزانها [['value' => x, 'weight' => y], ...]
 * @return float|null المتوسط المرجح
 */
function weightedAverage($values) {
    if (empty($values)) return null;
    $totalWeight = 0;
    $weightedSum = 0;
    foreach ($values as $item) {
        $val = $item['value'] ?? 0;
        $weight = $item['weight'] ?? 0;
        if ($val !== null && $weight > 0) {
            $weightedSum += $val * $weight;
            $totalWeight += $weight;
        }
    }
    return $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null;
}

/**
 * حساب الرتبة (percentile)
 *
 * @param float $value القيمة
 * @param array $dataset مجموعة البيانات
 * @return int الرتبة (0-100)
 */
function percentile($value, $dataset) {
    if (empty($dataset)) return 0;
    $sorted = $dataset;
    sort($sorted);
    $count = count($sorted);
    $rank = 0;
    foreach ($sorted as $v) {
        if ($v <= $value) $rank++;
        else break;
    }
    return (int)(($rank / $count) * 100);
}

// ============================================================================
// دوال التصدير والطباعة
// ============================================================================

/**
 * تنسيق اسم ملف التصدير
 *
 * @param string $baseName الاسم الأساسي
 * @param string $extension الامتداد
 * @return string اسم الملف المنسق
 */
function exportFileName($baseName, $extension = 'xlsx') {
    $date = date('Y-m-d_His');
    $safeName = preg_replace('/[^a-zA-Z0-9_\-\x{0600}-\x{06FF}]/u', '_', $baseName);
    return $safeName . '_' . $date . '.' . $extension;
}

/**
 * تحويل صفوف قاعدة البيانات إلى CSV مباشرة
 *
 * @param array $rows صفوف البيانات
 * @param array $headers عناوين الأعمدة
 * @param string $filename اسم الملف
 * @return void
 */
function exportRowsCSV($rows, $headers, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Arabic

    if (!empty($headers)) {
        fputcsv($output, $headers);
    }

    foreach ($rows as $row) {
        if (is_object($row)) $row = (array)$row;
        fputcsv($output, array_values($row));
    }

    fclose($output);
    exit;
}

// ============================================================================
// دوال الألوان والتصنيفات البصرية
// ============================================================================

/**
 * الحصول على لون حسب النسبة المئوية
 *
 * @param float $percentage النسبة المئوية
 * @return array [hex_color, css_class, label]
 */
function getScoreColor($percentage) {
    if ($percentage >= 85) return ['#28a745', 'success', 'ممتاز'];
    if ($percentage >= 70) return ['#1a5632', 'primary', 'جيد جداً'];
    if ($percentage >= 50) return ['#ffc107', 'warning', 'مقبول'];
    return ['#dc3545', 'danger', 'ضعيف'];
}

/**
 * الحصول على لون مستوى الخطورة
 *
 * @param string $severity مستوى الخطورة
 * @return array [hex_color, bg_color, icon]
 */
function getSeverityColors($severity) {
    $map = [
        'info'     => ['#17a2b8', 'rgba(23,162,184,0.1)', 'ℹ️'],
        'warning'  => ['#ffc107', 'rgba(255,193,7,0.1)', '⚠️'],
        'danger'   => ['#dc3545', 'rgba(220,53,69,0.1)', '🔴'],
        'critical' => ['#721c24', 'rgba(114,28,36,0.15)', '🚨'],
    ];
    return $map[$severity] ?? $map['info'];
}

/**
 * الحصول على تصنيف الترفيع
 *
 * @param float $academic الدرجة الأكاديمية
 * @param float $behavior درجة السلوك
 * @param float $attendance نسبة الحضور
 * @return array [decision, label, css_class, icon]
 */
function getPromotionClassification($academic, $behavior, $attendance) {
    $passAcademic = $academic >= 50;
    $passBehavior = $behavior >= 60;
    $passAttendance = $attendance >= 75;

    if ($passAcademic && $passBehavior && $passAttendance) {
        return ['promoted', 'مرفع', 'success', '✅'];
    }
    if (!$passAcademic && $passBehavior && $passAttendance) {
        return ['repeated', 'معيد (أكاديمي)', 'danger', '🔄'];
    }
    if ($passAcademic && (!$passBehavior || !$passAttendance)) {
        return ['conditional', 'مشروط', 'warning', '⚠️'];
    }
    return ['repeated', 'معيد', 'danger', '❌'];
}

// ============================================================================
// دوال مساعدة لجلب البيانات الشائعة
// ============================================================================

/**
 * جلب قائمة المدارس المتاحة للمستخدم
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param string $role دور المستخدم
 * @param int|null $schoolId معرف مدرسة المستخدم
 * @return array قائمة المدارس
 */
function getAvailableSchools($db, $role, $schoolId = null) {
    try {
        if ($role === 'group_admin') {
            $stmt = $db->query("SELECT id, name_ar, school_type FROM schools WHERE is_active = 1 ORDER BY name_ar");
        } else {
            $stmt = $db->prepare("SELECT id, name_ar, school_type FROM schools WHERE id = ? AND is_active = 1");
            $stmt->execute([$schoolId]);
        }
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * جلب قائمة الفصول الدراسية
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $academicYearId معرف العام الدراسي
 * @param int|null $schoolId معرف المدرسة
 * @return array قائمة الفصول
 */
function getAvailableSemesters($db, $academicYearId, $schoolId = null) {
    try {
        $stmt = $db->prepare("SELECT id, name_ar, lock_level, start_date, end_date FROM semesters WHERE academic_year_id = ? AND is_active = 1 ORDER BY start_date");
        $stmt->execute([$academicYearId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * جلب إحصائيات سريعة للمدرسة
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $schoolId معرف المدرسة
 * @param int|null $academicYearId معرف العام الدراسي
 * @return array الإحصائيات
 */
function getSchoolQuickStats($db, $schoolId, $academicYearId = null) {
    $stats = ['students' => 0, 'teachers' => 0, 'classes' => 0, 'subjects' => 0];
    try {
        $ayId = $academicYearId ?? currentAcademicYearId();

        $stmt = $db->prepare("SELECT COUNT(*) FROM students s JOIN student_enrollments se ON s.id = se.student_id JOIN classes c ON se.class_id = c.id WHERE c.school_id = ? AND s.is_active = 1 AND se.status = 'enrolled'");
        $stmt->execute([$schoolId]);
        $stats['students'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'teacher' AND u.school_id = ? AND u.is_active = 1");
        $stmt->execute([$schoolId]);
        $stats['teachers'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND is_active = 1 AND academic_year_id = ?");
        $stmt->execute([$schoolId, $ayId]);
        $stats['classes'] = (int)$stmt->fetchColumn();

        $stmt = $db->prepare("SELECT COUNT(DISTINCT sub.id) FROM subjects sub WHERE sub.school_id = ? AND sub.is_active = 1");
        $stmt->execute([$schoolId]);
        $stats['subjects'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) { }
    return $stats;
}
