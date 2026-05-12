<?php
/**
 * ============================================================
 * EQAS - 3-Level Backup System
 * نظام التقييم الشامل - نظام النسخ الاحتياطي ثلاثي المستويات
 * ============================================================
 * المستوى 1: نسخة احتياطية كاملة (يومياً عند تسجيل دخول المدير)
 * المستوى 2: نسخة قبل العمليات الحساسة
 * المستوى 3: تصدير يدوي (المستخدم يختار الجداول)
 * ============================================================
 */

class BackupManager {
    /** @var PDO اتصال قاعدة البيانات */
    private $db;

    /** @var string مسار مجلد النسخ الاحتياطية */
    private $backupDir;

    /** @var int حجم النسخة الكبيرة (MB) */
    private $largeBackupThreshold = 50;

    /**
     * المُنشئ
     * @param PDO $db اتصال قاعدة البيانات
     */
    public function __construct($db) {
        $this->db = $db;
        $this->backupDir = defined('EQAS_BACKUPS') ? EQAS_BACKUPS : __DIR__ . '/../backups';

        // التأكد من وجود المجلد
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * المستوى 1: إنشاء نسخة احتياطية كاملة
     * تُنفذ يومياً عند تسجيل دخول المدير
     * @param int $createdBy رقم المستخدم المنشئ
     * @param int|null $schoolId رقم المدرسة (اختياري)
     * @return array معلومات النسخة الاحتياطية
     */
    public function createFullBackup($createdBy, $schoolId = null) {
        $timestamp = date('Ymd_His');
        $fileName = "full_{$timestamp}.sql.gz";
        $filePath = $this->backupDir . '/' . $fileName;

        // التحقق من عدم وجود نسخة كاملة اليوم
        $today = date('Y-m-d');
        $stmt = $this->db->prepare("SELECT id FROM backup_logs WHERE backup_type = 'full' AND DATE(created_at) = ? AND status = 'completed'");
        $stmt->execute([$today]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'تم إنشاء نسخة احتياطية كاملة اليوم بالفعل'];
        }

        // الحصول على جميع الجداول
        $tables = $this->getAllTables();
        $schoolIdVal = $schoolId ?? $_SESSION['user']['school_id'] ?? null;

        // تسجيل بداية النسخ الاحتياطي
        $stmt = $this->db->prepare("INSERT INTO backup_logs (school_id, backup_type, file_path, file_size, tables_count, rows_count, status, created_by, created_at) VALUES (?, 'full', ?, 0, ?, 0, 'pending', ?, NOW())");
        $stmt->execute([$schoolIdVal, $filePath, count($tables), $createdBy]);
        $backupId = $this->db->lastInsertId();

        try {
            // إنشاء النسخة
            $sqlDump = $this->generateSQLDump($tables);
            $gzipResult = $this->writeGzipFile($filePath, $sqlDump);

            $fileSize = filesize($filePath);
            $rowsCount = $this->countRowsInDump($sqlDump);

            // تحديث السجل
            $stmt = $this->db->prepare("UPDATE backup_logs SET file_size = ?, rows_count = ?, status = 'completed' WHERE id = ?");
            $stmt->execute([$fileSize, $rowsCount, $backupId]);

            return [
                'success' => true,
                'backup_id' => $backupId,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'tables_count' => count($tables),
                'rows_count' => $rowsCount
            ];
        } catch (Exception $e) {
            // تحديث السجل بالفشل
            $stmt = $this->db->prepare("UPDATE backup_logs SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->execute([$e->getMessage(), $backupId]);

            return ['success' => false, 'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()];
        }
    }

    /**
     * المستوى 2: إنشاء نسخة احتياطية قبل عملية حساسة
     * @param string $operation اسم العملية
     * @param int $createdBy رقم المستخدم
     * @param array $tables الجداول المتأثرة
     * @param int|null $schoolId رقم المدرسة
     * @return array معلومات النسخة
     */
    public function createPreOperationBackup($operation, $createdBy, $tables = [], $schoolId = null) {
        $timestamp = date('Ymd_His');
        $safeOpName = preg_replace('/[^a-zA-Z0-9_]/', '', $operation);
        $fileName = "preop_{$timestamp}_{$safeOpName}.sql.gz";
        $filePath = $this->backupDir . '/' . $fileName;

        // إذا لم تحدد الجداول، استخدم الجداول المتأثرة حسب العملية
        if (empty($tables)) {
            $tables = $this->getOperationTables($operation);
        }

        $schoolIdVal = $schoolId ?? $_SESSION['user']['school_id'] ?? null;

        // تسجيل بداية النسخ
        $stmt = $this->db->prepare("INSERT INTO backup_logs (school_id, backup_type, file_path, file_size, tables_count, rows_count, status, created_by, created_at) VALUES (?, 'pre_operation', ?, 0, ?, 0, 'pending', ?, NOW())");
        $stmt->execute([$schoolIdVal, $filePath, count($tables), $createdBy]);
        $backupId = $this->db->lastInsertId();

        try {
            $sqlDump = $this->generateSQLDump($tables);
            $this->writeGzipFile($filePath, $sqlDump);

            $fileSize = filesize($filePath);
            $rowsCount = $this->countRowsInDump($sqlDump);

            $stmt = $this->db->prepare("UPDATE backup_logs SET file_size = ?, rows_count = ?, status = 'completed' WHERE id = ?");
            $stmt->execute([$fileSize, $rowsCount, $backupId]);

            return [
                'success' => true,
                'backup_id' => $backupId,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'tables_count' => count($tables),
                'rows_count' => $rowsCount
            ];
        } catch (Exception $e) {
            $stmt = $this->db->prepare("UPDATE backup_logs SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->execute([$e->getMessage(), $backupId]);

            return ['success' => false, 'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()];
        }
    }

    /**
     * المستوى 3: إنشاء نسخة احتياطية يدوية
     * @param array $tables الجداول المحددة من المستخدم
     * @param int $createdBy رقم المستخدم
     * @param int|null $schoolId رقم المدرسة
     * @return array معلومات النسخة
     */
    public function createManualBackup($tables, $createdBy, $schoolId = null) {
        $timestamp = date('Ymd_His');
        $fileName = "manual_{$timestamp}.sql.gz";
        $filePath = $this->backupDir . '/' . $fileName;

        // التحقق من الجداول
        $availableTables = $this->getAllTables();
        $tables = array_intersect($tables, $availableTables);

        if (empty($tables)) {
            return ['success' => false, 'message' => 'لم يتم تحديد جداول صالحة'];
        }

        $schoolIdVal = $schoolId ?? $_SESSION['user']['school_id'] ?? null;

        // تسجيل بداية النسخ
        $stmt = $this->db->prepare("INSERT INTO backup_logs (school_id, backup_type, file_path, file_size, tables_count, rows_count, status, created_by, created_at) VALUES (?, 'manual', ?, 0, ?, 0, 'pending', ?, NOW())");
        $stmt->execute([$schoolIdVal, $filePath, count($tables), $createdBy]);
        $backupId = $this->db->lastInsertId();

        try {
            $sqlDump = $this->generateSQLDump($tables);
            $this->writeGzipFile($filePath, $sqlDump);

            $fileSize = filesize($filePath);
            $rowsCount = $this->countRowsInDump($sqlDump);

            $stmt = $this->db->prepare("UPDATE backup_logs SET file_size = ?, rows_count = ?, status = 'completed' WHERE id = ?");
            $stmt->execute([$fileSize, $rowsCount, $backupId]);

            return [
                'success' => true,
                'backup_id' => $backupId,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'tables_count' => count($tables),
                'rows_count' => $rowsCount
            ];
        } catch (Exception $e) {
            $stmt = $this->db->prepare("UPDATE backup_logs SET status = 'failed', error_message = ? WHERE id = ?");
            $stmt->execute([$e->getMessage(), $backupId]);

            return ['success' => false, 'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()];
        }
    }

    /**
     * استعادة من نسخة احتياطية
     * @param string $filePath مسار ملف النسخة
     * @param int $restoredBy رقم المستخدم
     * @return array نتيجة الاستعادة
     */
    public function restoreBackup($filePath, $restoredBy) {
        // التحقق من وجود الملف
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'ملف النسخة الاحتياطية غير موجود'];
        }

        // تحذير للنسخ الكبيرة
        $fileSizeMB = filesize($filePath) / (1024 * 1024);
        if ($fileSizeMB > $this->largeBackupThreshold) {
            // تم التحقق مسبقاً من تأكيد المستخدم
        }

        // إنشاء نسخة احتياطية قبل الاستعادة
        $preRestoreResult = $this->createPreOperationBackup('restore', $restoredBy);
        if (!$preRestoreResult['success']) {
            return ['success' => false, 'message' => 'فشل إنشاء نسخة احتياطية قبل الاستعادة: ' . $preRestoreResult['message']];
        }

        try {
            // تنفيذ ملف SQL
            $result = $this->executeSQLFile($filePath);

            // تسجيل النشاط
            if (function_exists('logActivity')) {
                logActivity($this->db, $restoredBy, 'backup_restore', 'استعادة من نسخة احتياطية: ' . basename($filePath), 'backup', null);
            }

            return [
                'success' => true,
                'message' => 'تمت الاستعادة بنجاح',
                'queries_executed' => $result['queries_executed'],
                'errors' => $result['errors'],
                'pre_restore_backup' => $preRestoreResult
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'فشل الاستعادة: ' . $e->getMessage()];
        }
    }

    /**
     * قائمة النسخ الاحتياطية
     * @param array $filters عوامل التصفية
     * @return array قائمة النسخ
     */
    public function listBackups($filters = []) {
        $sql = "SELECT bl.*, u.full_name_ar as creator_name
                FROM backup_logs bl
                LEFT JOIN users u ON bl.created_by = u.id
                WHERE bl.is_active = 1";

        $params = [];

        // تصفية حسب النوع
        if (!empty($filters['backup_type'])) {
            $sql .= " AND bl.backup_type = ?";
            $params[] = $filters['backup_type'];
        }

        // تصفية حسب الحالة
        if (!empty($filters['status'])) {
            $sql .= " AND bl.status = ?";
            $params[] = $filters['status'];
        }

        // تصفية حسب التاريخ
        if (!empty($filters['date_from'])) {
            $sql .= " AND bl.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND bl.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql .= " ORDER BY bl.created_at DESC";

        // حد النتائج
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * حذف نسخة احتياطية
     * @param int $backupId رقم النسخة
     * @return array نتيجة الحذف
     */
    public function deleteBackup($backupId) {
        // الحصول على معلومات النسخة
        $stmt = $this->db->prepare("SELECT * FROM backup_logs WHERE id = ? AND is_active = 1");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();

        if (!$backup) {
            return ['success' => false, 'message' => 'النسخة الاحتياطية غير موجودة'];
        }

        // حذف الملف
        if (file_exists($backup['file_path'])) {
            @unlink($backup['file_path']);
        }

        // حذف السجل (حذف ناعم)
        $stmt = $this->db->prepare("UPDATE backup_logs SET is_active = 0 WHERE id = ?");
        $stmt->execute([$backupId]);

        return ['success' => true, 'message' => 'تم حذف النسخة الاحتياطية بنجاح'];
    }

    /**
     * الحصول على معلومات النسخة الاحتياطية
     * @param string $filePath مسار الملف
     * @return array معلومات النسخة
     */
    public function getBackupInfo($filePath) {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'الملف غير موجود'];
        }

        $info = [
            'file_path' => $filePath,
            'file_name' => basename($filePath),
            'file_size' => filesize($filePath),
            'file_size_mb' => round(filesize($filePath) / (1024 * 1024), 2),
            'created_at' => date('Y-m-d H:i:s', filemtime($filePath))
        ];

        // محاولة قراءة معلومات من الملف المضغوط
        try {
            $gz = gzopen($filePath, 'r');
            if ($gz) {
                $content = '';
                $maxRead = 1024 * 1024; // قراءة أول 1MB فقط
                while (!gzeof($gz) && strlen($content) < $maxRead) {
                    $content .= gzread($gz, 8192);
                }
                gzclose($gz);

                // عد الجداول
                preg_match_all('/CREATE TABLE.*?`(\w+)`/si', $content, $tableMatches);
                $info['tables'] = $tableMatches[1] ?? [];
                $info['tables_count'] = count($info['tables']);

                // عد الصفوف تقريبياً
                preg_match_all('/^INSERT INTO/sim', $content, $insertMatches);
                $info['estimated_rows'] = count($insertMatches[0] ?? []);
            }
        } catch (Exception $e) {
            $info['read_error'] = $e->getMessage();
        }

        return ['success' => true, 'info' => $info];
    }

    /**
     * تنظيف النسخ القديمة تلقائياً
     * @param int $daysToKeep عدد أيام الاحتفاظ
     * @return int عدد النسخ المحذوفة
     */
    public function cleanupOldBackups($daysToKeep = 30) {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));

        // الحصول على النسخ القديمة (لا تحذف آخر نسخة كاملة)
        $stmt = $this->db->prepare("SELECT * FROM backup_logs WHERE created_at < ? AND is_active = 1 ORDER BY created_at ASC");
        $stmt->execute([$cutoffDate]);
        $oldBackups = $stmt->fetchAll();

        $deleted = 0;
        $lastFullBackup = null;

        // الاحتفاظ بآخر نسخة كاملة (الأحدث - لأن الترتيب ASC)
        foreach ($oldBackups as $backup) {
            if ($backup['backup_type'] === 'full' && $backup['status'] === 'completed') {
                if ($lastFullBackup !== null) {
                    // حذف النسخة الكاملة السابقة (الأقدم) والاحتفاظ بالأحدث
                    $this->deleteBackup($lastFullBackup);
                    $deleted++;
                }
                $lastFullBackup = $backup['id'];
            } else {
                $this->deleteBackup($backup['id']);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * الحصول على جميع الجداول
     * @return array أسماء الجداول
     */
    private function getAllTables() {
        $stmt = $this->db->query("SHOW TABLES");
        $tables = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        return $tables;
    }

    /**
     * الحصول على الجداول المتأثرة بعملية معينة
     * @param string $operation اسم العملية
     * @return array أسماء الجداول
     */
    private function getOperationTables($operation) {
        $operationTables = [
            'promotion' => ['promotion_decisions', 'students', 'student_enrollments', 'grades', 'behavior_scores', 'attendance_summaries'],
            'restore' => $this->getAllTables(),
            'semester_close' => ['semesters', 'grades', 'assessment_responses', 'behavior_scores', 'attendance', 'attendance_summaries'],
            'eqi_calculate' => ['equi_results', 'eqi_dimension_weights'],
            'bulk_grades' => ['grades'],
            'bulk_attendance' => ['attendance', 'attendance_summaries'],
            'settings_change' => ['system_settings', 'school_settings'],
            'user_management' => ['users', 'roles', 'role_permissions', 'permissions'],
        ];

        return $operationTables[$operation] ?? $this->getAllTables();
    }

    /**
     * توليد ملف SQL كامل
     * @param array $tables الجداول المطلوبة
     * @return string محتوى SQL
     */
    private function generateSQLDump($tables) {
        $sql = "-- ============================================================\n";
        $sql .= "-- EQAS النسخ الاحتياطي\n";
        $sql .= "-- تاريخ الإنشاء: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- عدد الجداول: " . count($tables) . "\n";
        $sql .= "-- ============================================================\n\n";

        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $sql .= "SET AUTOCOMMIT = 0;\n";
        $sql .= "START TRANSACTION;\n\n";

        foreach ($tables as $table) {
            $table = trim($table, '`');

            // CREATE TABLE
            $sql .= "-- -----------------------------------------------------------\n";
            $sql .= "-- جدول: `{$table}`\n";
            $sql .= "-- -----------------------------------------------------------\n";

            try {
                $stmt = $this->db->query("SHOW CREATE TABLE `{$table}`");
                $createRow = $stmt->fetch(PDO::FETCH_NUM);
                if ($createRow && isset($createRow[1])) {
                    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    $sql .= $createRow[1] . ";\n\n";
                }
            } catch (Exception $e) {
                $sql .= "-- خطأ في قراءة هيكل الجدول {$table}: " . $e->getMessage() . "\n";
                continue;
            }

            // INSERT statements
            try {
                $stmt = $this->db->query("SELECT * FROM `{$table}`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $columnList = '`' . implode('`, `', $columns) . '`';

                    $sql .= "INSERT INTO `{$table}` ({$columnList}) VALUES\n";

                    $valuesList = [];
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = $this->db->quote($value);
                            }
                        }
                        $valuesList[] = '(' . implode(', ', $values) . ')';
                    }

                    // تقسيم لإدراجات صغيرة (100 صف لكل INSERT)
                    $chunks = array_chunk($valuesList, 100);
                    foreach ($chunks as $i => $chunk) {
                        $sql .= implode(",\n", $chunk);
                        $sql .= ";\n\n";
                        if ($i < count($chunks) - 1) {
                            $sql .= "INSERT INTO `{$table}` ({$columnList}) VALUES\n";
                        }
                    }
                }
            } catch (Exception $e) {
                $sql .= "-- خطأ في قراءة بيانات الجدول {$table}: " . $e->getMessage() . "\n";
            }

            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $sql .= "COMMIT;\n";

        return $sql;
    }

    /**
     * كتابة ملف gzip
     * @param string $filePath مسار الملف
     * @param string $content المحتوى
     * @return bool نجاح الكتابة
     */
    private function writeGzipFile($filePath, $content) {
        $gz = gzopen($filePath, 'w9');
        if (!$gz) {
            throw new Exception('فشل فتح ملف gzip للكتابة');
        }

        // كتابة المحتوى على أجزاء
        $offset = 0;
        $chunkSize = 8192;
        $length = strlen($content);

        while ($offset < $length) {
            $chunk = substr($content, $offset, $chunkSize);
            gzwrite($gz, $chunk);
            $offset += $chunkSize;
        }

        gzclose($gz);
        return true;
    }

    /**
     * تنفيذ ملف SQL من نسخة احتياطية
     * @param string $filePath مسار ملف النسخة
     * @return array نتيجة التنفيذ
     */
    private function executeSQLFile($filePath) {
        // قراءة المحتوى المضغوط
        $gz = gzopen($filePath, 'r');
        if (!$gz) {
            throw new Exception('فشل فتح ملف النسخة الاحتياطية');
        }

        $content = '';
        while (!gzeof($gz)) {
            $content .= gzread($gz, 8192);
        }
        gzclose($gz);

        // تقسيم الاستعلامات
        $queries = $this->splitSQL($content);

        $executed = 0;
        $errors = [];

        $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

        foreach ($queries as $query) {
            $query = trim($query);
            if (empty($query) || preg_match('/^--/', $query)) {
                continue;
            }

            try {
                $this->db->exec($query);
                $executed++;
            } catch (PDOException $e) {
                $errors[] = [
                    'query' => mb_substr($query, 0, 200),
                    'error' => $e->getMessage()
                ];
                // لا نتوقف عند الأخطاء، نستمر
            }
        }

        $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");

        return [
            'queries_executed' => $executed,
            'errors' => $errors
        ];
    }

    /**
     * تقسيم نص SQL إلى استعلامات منفصلة
     * @param string $sql نص SQL
     * @return array الاستعلامات
     */
    private function splitSQL($sql) {
        // إزالة التعليقات متعددة الأسطر
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // تقسيم بفواصل المنقوطة (مع تجاهل تلك داخل النصوص)
        $queries = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (!$inString) {
                if ($char === "'" || $char === '"') {
                    $inString = true;
                    $stringChar = $char;
                    $current .= $char;
                } elseif ($char === ';') {
                    $queries[] = $current;
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
                if ($char === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
            }
        }

        if (trim($current)) {
            $queries[] = $current;
        }

        return $queries;
    }

    /**
     * عد الصفوف في ملف SQL
     * @param string $sqlDump محتوى SQL
     * @return int عدد الصفوف
     */
    private function countRowsInDump($sqlDump) {
        preg_match_all('/^INSERT INTO/sim', $sqlDump, $matches);
        return count($matches[0] ?? []);
    }

    /**
     * الحصول على إحصائيات النسخ الاحتياطي
     * @return array الإحصائيات
     */
    public function getBackupStats() {
        $stats = [
            'total_backups' => 0,
            'total_size' => 0,
            'full_count' => 0,
            'preop_count' => 0,
            'manual_count' => 0,
            'last_full_date' => null,
            'last_backup_date' => null
        ];

        // إجمالي النسخ
        $stmt = $this->db->query("SELECT COUNT(*) as total, COALESCE(SUM(file_size), 0) as total_size FROM backup_logs WHERE is_active = 1 AND status = 'completed'");
        $row = $stmt->fetch();
        $stats['total_backups'] = $row['total'];
        $stats['total_size'] = $row['total_size'];

        // عدد حسب النوع
        $stmt = $this->db->query("SELECT backup_type, COUNT(*) as cnt FROM backup_logs WHERE is_active = 1 AND status = 'completed' GROUP BY backup_type");
        while ($row = $stmt->fetch()) {
            switch ($row['backup_type']) {
                case 'full': $stats['full_count'] = $row['cnt']; break;
                case 'pre_operation': $stats['preop_count'] = $row['cnt']; break;
                case 'manual': $stats['manual_count'] = $row['cnt']; break;
            }
        }

        // آخر نسخة كاملة
        $stmt = $this->db->query("SELECT created_at FROM backup_logs WHERE backup_type = 'full' AND is_active = 1 AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
        $row = $stmt->fetch();
        $stats['last_full_date'] = $row ? $row['created_at'] : null;

        // آخر نسخة
        $stmt = $this->db->query("SELECT created_at FROM backup_logs WHERE is_active = 1 AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
        $row = $stmt->fetch();
        $stats['last_backup_date'] = $row ? $row['created_at'] : null;

        return $stats;
    }

    /**
     * الحصول على قائمة الجداول مع عدد الصفوف
     * @return array معلومات الجداول
     */
    public function getTablesWithRowCounts() {
        $tables = $this->getAllTables();
        $result = [];

        foreach ($tables as $table) {
            try {
                $stmt = $this->db->query("SELECT COUNT(*) as cnt FROM `{$table}`");
                $row = $stmt->fetch();
                $result[] = [
                    'name' => $table,
                    'rows' => $row['cnt']
                ];
            } catch (Exception $e) {
                $result[] = [
                    'name' => $table,
                    'rows' => 0,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $result;
    }
}
