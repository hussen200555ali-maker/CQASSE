<?php
/**
 * ============================================================
 * EQAS - Installation Engine (محرّك التثبيت)
 * نظام التقييم الشامل لجودة التعليم المدرسي
 * ============================================================
 * منطق التثبيت الفعلي، مفصول عن معالج التثبيت (setup.php)
 * ليصبح قابلاً لإعادة الاستخدام والاختبار الآلي.
 *
 * الاستخدام:
 *   require_once __DIR__ . '/installer.php';
 *   $result = eqas_perform_installation([
 *       'db'     => ['host' => ..., 'name' => ..., 'user' => ..., 'pass' => ...],
 *       'admin'  => ['username' => ..., 'password' => ..., 'email' => ..., 'full_name' => ...],
 *       'school' => ['name' => ..., 'type' => ..., 'address' => ..., 'governorate' => ..., 'directorate' => ...],
 *       'year'   => ['name' => ..., 'start' => ..., 'end' => ...],
 *   ]); // true على النجاح، أو نص خطأ
 */

// كلمات المرور المحظورة (افتراضية/ضعيفة/معروفة)
function eqas_weak_passwords(): array {
    return [
        'admin', 'admin123', 'Admin@123', 'password', 'password1', 'Password@123',
        '12345678', '123456789', 'qwerty123', 'eqas123', 'eqas@2026', 'letmein',
        'welcome1', 'root1234', 'school123',
    ];
}

/**
 * التحقق من قوة كلمة مرور مدير النظام.
 * @return string|null نص الخطأ، أو null إذا كانت مقبولة.
 */
function eqas_validate_admin_password(string $password): ?string {
    if (strlen($password) < 8) {
        return 'يجب أن تكون كلمة المرور 8 أحرف على الأقل.';
    }
    if (in_array(strtolower($password), array_map('strtolower', eqas_weak_passwords()), true)) {
        return 'كلمة المرور ضعيفة أو معروفة. يرجى اختيار كلمة مرور قوية.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'يجب أن تحتوي كلمة المرور على حرف واحد ورقم واحد على الأقل.';
    }
    return null;
}

/**
 * تقسيم نص SQL إلى استعلامات منفصلة، مع إزالة التعليقات السطرية والكتلية
 * واحترام النصوص المقتبسة.
 */
function eqas_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $inLineComment = false;
    $inBlockComment = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }
        if ($inString) {
            $current .= $ch;
            if ($ch === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $inString = true;
            $stringChar = $ch;
            $current .= $ch;
            continue;
        }
        if ($ch === '-' && $next === '-') {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($ch === '#') {
            $inLineComment = true;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }
        if ($ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    $stmt = trim($current);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }
    return $statements;
}

/**
 * تنفيذ ملف SQL استعلاماً باستعلام.
 * @param bool $tolerant تجاهل أخطاء "already exists"/"Duplicate" (مفيد لإعادة التثبيت).
 * @return array{executed:int, skipped:int, errors:array<string>}
 */
function eqas_import_sql_file(PDO $pdo, string $path, bool $tolerant = true): array {
    $result = ['executed' => 0, 'skipped' => 0, 'errors' => []];
    if (!is_file($path)) {
        $result['errors'][] = "الملف غير موجود: {$path}";
        return $result;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        $result['errors'][] = "فشل في قراءة الملف: {$path}";
        return $result;
    }
    foreach (eqas_sql_statements($sql) as $statement) {
        $trimmed = trim($statement);
        if ($trimmed === '' || $trimmed[0] === '-' || $trimmed[0] === '#') {
            continue;
        }
        try {
            $pdo->exec($trimmed);
            $result['executed']++;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if ($tolerant && (strpos($msg, 'already exists') !== false || strpos($msg, 'Duplicate') !== false)) {
                $result['skipped']++;
                continue;
            }
            $result['errors'][] = $msg . ' | ' . mb_substr($trimmed, 0, 160);
            if (!$tolerant) {
                return $result;
            }
        }
    }
    return $result;
}

/**
 * بناء محتوى ملف config.php بعد نجاح التثبيت.
 */
function eqas_build_config(array $dbConfig, ?string $sessionSalt = null): string {
    $sessionSalt = $sessionSalt ?: bin2hex(random_bytes(32));
    $c  = "<?php\n";
    $c .= "/**\n * EQAS - Configuration File\n * نظام التقييم الشامل - ملف الإعدادات\n * Auto-generated by setup wizard v2.0.3\n */\n\n";
    $c .= "define('EQAS_ENV', 'production');\n";
    $c .= "define('EQAS_VERSION', '2.0.3');\n\n";
    $c .= "require_once __DIR__ . '/includes/security_headers.php';\n\n";
    $c .= "define('DB_HOST', '" . addslashes($dbConfig['host']) . "');\n";
    $c .= "define('DB_NAME', '" . addslashes($dbConfig['name']) . "');\n";
    $c .= "define('DB_USER', '" . addslashes($dbConfig['user']) . "');\n";
    $c .= "define('DB_PASS', '" . addslashes($dbConfig['pass']) . "');\n";
    $c .= "define('DB_CHARSET', 'utf8mb4');\n\n";
    $c .= "define('EQAS_ROOT', __DIR__);\n";
    $c .= "define('EQAS_INCLUDES', EQAS_ROOT . '/includes');\n";
    $c .= "define('EQAS_PAGES', EQAS_ROOT . '/pages');\n";
    $c .= "define('EQAS_ASSETS', EQAS_ROOT . '/assets');\n";
    $c .= "define('EQAS_BACKUPS', EQAS_ROOT . '/backups');\n";
    $c .= "define('EQAS_TEMP', EQAS_ROOT . '/temp');\n";
    $c .= "define('EQAS_UPLOADS', EQAS_ROOT . '/uploads');\n\n";
    $c .= "define('EQAS_URL', 'http://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost') . '/cqasse');\n";
    $c .= "define('EQAS_ASSETS_URL', EQAS_URL . '/assets');\n\n";
    $c .= "define('CSRF_ENABLED', true);\n";
    $c .= "define('MAX_LOGIN_ATTEMPTS', 5);\n";
    $c .= "define('LOCKOUT_DURATION', 900);\n";
    $c .= "define('SESSION_TIMEOUT', 3600);\n";
    $c .= "define('PASSWORD_MIN_LENGTH', 8);\n";
    $c .= "define('SESSION_FINGERPRINT_SALT', '" . $sessionSalt . "');\n\n";
    $c .= "define('BACKUP_MAX_SIZE_MB', 50);\n";
    $c .= "define('BACKUP_GZIP_ENABLED', true);\n\n";
    $c .= "define('EQI_REDISTRIBUTION', 'hybrid');\n";
    $c .= "define('ACADEMIC_PASS_RATE', 50);\n";
    $c .= "define('BEHAVIOR_PASS_RATE', 60);\n";
    $c .= "define('ATTENDANCE_PASS_RATE', 75);\n\n";
    $c .= "error_reporting(0);\n";
    $c .= "ini_set('display_errors', 0);\n\n";
    $c .= "date_default_timezone_set('Asia/Aden');\n\n";
    $c .= "define('EQAS_INSTALLED', true);\n";
    return $c;
}

/**
 * تنفيذ التثبيت الكامل.
 *
 * @param array $cfg إعدادات التثبيت:
 *   - db:     [host, name, user, pass]
 *   - admin:  [username, password, email, full_name]
 *   - school: [name, type, address, governorate, directorate]
 *   - year:   [name, start, end]
 *   - write_config (bool, افتراضي true)
 *   - config_path  (مسار config.php، افتراضي جذر المشروع)
 *   - lock_path    (مسار قفل التثبيت، افتراضي temp/installed.lock)
 *   - force_admin_password_change (bool, افتراضي true)
 *   - allow_existing_db (bool, افتراضي false) — للسماح بالكتابة فوق قاعدة بيانات غير فارغة
 *
 * @return true|string  true عند النجاح أو نص الخطأ.
 */
function eqas_perform_installation(array $cfg) {
    $dbConfig     = $cfg['db']     ?? null;
    $adminConfig  = $cfg['admin']  ?? null;
    $schoolConfig = $cfg['school'] ?? null;
    $yearConfig   = $cfg['year']   ?? null;

    if (!$dbConfig || !$adminConfig || !$schoolConfig || !$yearConfig) {
        return 'بيانات التثبيت غير مكتملة. يرجى البدء من جديد.';
    }

    // التحقق من قوة كلمة مرور المدير (R-03)
    $pwError = eqas_validate_admin_password((string)$adminConfig['password']);
    if ($pwError !== null) {
        return $pwError;
    }

    $writeConfig = $cfg['write_config'] ?? true;
    $configPath  = $cfg['config_path'] ?? (__DIR__ . '/../config.php');
    $lockPath    = $cfg['lock_path']   ?? (__DIR__ . '/../temp/installed.lock');
    $forceChange = $cfg['force_admin_password_change'] ?? true;

    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4",
            $dbConfig['user'],
            $dbConfig['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // حماية (R-01): schema.sql يحتوي على DROP TABLE، لذا نمنع الكتابة فوق قاعدة بيانات غير فارغة
        if (empty($cfg['allow_existing_db'])) {
            $existingTables = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            )->fetchColumn();
            if ($existingTables > 0) {
                return 'قاعدة البيانات المحددة تحتوي على ' . $existingTables . ' جدولاً موجوداً مسبقاً. لحماية بياناتك، اختر قاعدة بيانات فارغة أو أفرغ محتواها قبل التثبيت.';
            }
        }

        // 1) استيراد مخطط قاعدة البيانات (schema.sql)
        $schemaFile = dirname(__DIR__) . '/database/schema.sql';
        if (!is_file($schemaFile)) {
            return 'ملف schema.sql غير موجود في مجلد database/. يرجى التأكد من رفع جميع ملفات النظام.';
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        $schemaImport = eqas_import_sql_file($pdo, $schemaFile, true);
        if (!empty($schemaImport['errors'])) {
            return 'خطأ في استيراد schema.sql: ' . $schemaImport['errors'][0];
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // 2) استيراد البيانات المرجعية (seed.sql) ثم إعدادات النظام (settings_seed.sql)
        $seedFile = dirname(__DIR__) . '/database/seed.sql';
        if (is_file($seedFile)) {
            $seedImport = eqas_import_sql_file($pdo, $seedFile, true);
            if (!empty($seedImport['errors'])) {
                return 'خطأ في استيراد seed.sql: ' . $seedImport['errors'][0];
            }
        }
        $settingsSeedFile = dirname(__DIR__) . '/database/settings_seed.sql';
        if (is_file($settingsSeedFile)) {
            eqas_import_sql_file($pdo, $settingsSeedFile, true);
        }

        // دور مدير النظام من البيانات المرجعية
        $adminRoleId = $pdo->query("SELECT id FROM roles WHERE name = 'group_admin'")->fetchColumn();
        if (!$adminRoleId) {
            return 'تعذّر العثور على دور مدير النظام (group_admin) في البيانات المرجعية (seed.sql).';
        }

        // 3) المدرسة
        $typeMap = ['primary' => 'basic', 'secondary' => 'secondary', 'mixed' => 'combined'];
        $schoolTypeDb = $typeMap[$schoolConfig['type']] ?? 'combined';
        $refCode = str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT INTO schools (name_ar, name_en, reference_code, address, governorate, district, school_type, is_active)
                               VALUES (?, NULL, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $schoolConfig['name'],
            $refCode,
            $schoolConfig['address'],
            $schoolConfig['governorate'],
            $schoolConfig['directorate'],
            $schoolTypeDb,
        ]);
        $schoolId = (int)$pdo->lastInsertId();

        // 4) مدير النظام (بكلمة المرور التي اختارها المستخدم)
        $hashedPassword = password_hash($adminConfig['password'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name_ar, full_name_en, email, role_id, school_id, is_active, login_attempts, must_change_password, created_at)
                               VALUES (?, ?, ?, NULL, ?, ?, ?, 1, 0, 0, NOW())");
        $stmt->execute([
            $adminConfig['username'],
            $hashedPassword,
            $adminConfig['full_name'],
            $adminConfig['email'],
            $adminRoleId,
            $schoolId,
        ]);
        $adminUserId = (int)$pdo->lastInsertId();

        // 5) العام الأكاديمي + الفصل الأول
        $stmt = $pdo->prepare("INSERT INTO academic_years (name, start_date, end_date, is_current, status, is_active)
                               VALUES (?, ?, ?, 1, 'active', 1)");
        $stmt->execute([$yearConfig['name'], $yearConfig['start'], $yearConfig['end']]);
        $academicYearId = (int)$pdo->lastInsertId();

        $semStart = $yearConfig['start'];
        $semEnd = date('Y-m-d', strtotime($yearConfig['start'] . ' + 4 months'));
        $stmt = $pdo->prepare("INSERT INTO semesters (academic_year_id, name_ar, start_date, end_date, is_current, lock_level, is_active)
                               VALUES (?, 'الفصل الدراسي الأول', ?, ?, 1, 'none', 1)");
        $stmt->execute([$academicYearId, $semStart, $semEnd]);

        // 6) إعدادات النظام الافتراضية
        $defaultSettings = [
            ['system_name', 'نظام التقييم الشامل لجودة التعليم المدرسي'],
            ['system_short_name', 'EQAS'],
            ['system_version', '2.0.3'],
            ['default_language', 'ar'],
            ['timezone', 'Asia/Aden'],
        ];
        $settingsStmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaultSettings as $setting) {
            $settingsStmt->execute($setting);
        }

        // 7) إعدادات التنبيهات الافتراضية للمدرسة الجديدة
        $alertDefaults = [
            ['low_grade', 1, 50.00, 'warning'],
            ['failing_subject', 1, 50.00, 'danger'],
            ['high_absence', 1, 25.00, 'danger'],
            ['consecutive_absence', 1, 3.00, 'warning'],
            ['poor_behavior', 1, 60.00, 'warning'],
            ['eqi_decline', 0, 50.00, 'danger'],
            ['promotion_risk', 1, 50.00, 'critical'],
            ['attendance_below_threshold', 1, 15.00, 'info'],
            ['behavior_below_threshold', 0, 50.00, 'warning'],
            ['custom', 0, null, 'info'],
        ];
        $alertStmt = $pdo->prepare("INSERT INTO alert_settings (school_id, alert_type, is_enabled, threshold_value, severity, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        foreach ($alertDefaults as $a) {
            $alertStmt->execute([$schoolId, $a[0], $a[1], $a[2], $a[3]]);
        }

        // ملاحظة: جدول school_settings أُلغي في الترحيل v3.3.0 (نُقل إلى old_school_settings
        // وأصبحت الإعدادات تُدار عبر system_settings). لذا لا يتم إدخال أي صفوف هنا.

        // 8) [R-03] إبطال أي حساب admin افتراضي قادم من ملف البذور
        if ($forceChange) {
            $pdo->prepare("UPDATE users SET must_change_password = 1 WHERE username = 'admin' AND id <> ?")
                ->execute([$adminUserId]);
        }

        // 9) كتابة config.php + قفل التثبيت
        if ($writeConfig) {
            $configContent = eqas_build_config($dbConfig);
            if (@file_put_contents($configPath, $configContent) === false) {
                return 'تم التثبيت في قاعدة البيانات، لكن تعذّر كتابة ملف config.php. تحقق من صلاحيات الكتابة في جذر المشروع.';
            }
        }
        if ($lockPath) {
            @file_put_contents($lockPath, "installed_at=" . date('c') . "\n");
        }

        return true;
    } catch (Exception $e) {
        return 'حدث خطأ أثناء التثبيت: ' . $e->getMessage();
    }
}
