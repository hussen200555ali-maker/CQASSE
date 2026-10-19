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
 * Note: Uses activity_logs table with action='backup'
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

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * تحويل صف من activity_logs إلى تنسيق backup_logs
     */
    private function normalizeBackupRow($row) {
        if (!$row) return null;
        $details = [];
        if (!empty($row['details_json'])) {
            $d = json_decode($row['details_json'], true);
            if (is_array($d)) $details = $d;
        }
        $row['backup_type'] = $row['entity_type'] ?? '';
        $row['school_id'] = $details['school_id'] ?? null;
        $row['file_path'] = $details['file_path'] ?? $row['description'] ?? '';
        $row['file_size'] = $details['file_size'] ?? 0;
        $row['tables_count'] = $details['tables_count'] ?? 0;
        $row['rows_count'] = $details['rows_count'] ?? 0;
        $row['error_message'] = $details['error_message'] ?? null;
        $row['created_by'] = $row['user_id'];
        return $row;
    }

    /**
     * المستوى 1: إنشاء نسخة احتياطية كاملة
     */
    public function createFullBackup($createdBy, $schoolId = null) {
        $timestamp = date('Ymd_His');
        $fileName = "full_{$timestamp}.sql.gz";
        $filePath = $this->backupDir . '/' . $fileName;

        $today = date('Y-m-d');
        $stmt = $this->db->prepare("SELECT id FROM activity_logs WHERE action = 'backup' AND entity_type = 'full' AND DATE(created_at) = ? AND status = 'completed' AND is_active = 1");
        $stmt->execute([$today]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'تم إنشاء نسخة احتياطية كاملة اليوم بالفعل'];
        }

        $tables = $this->getAllTables();
        $schoolIdVal = $schoolId ?? $_SESSION['user']['school_id'] ?? null;

        $detailsJson = json_encode([
            'school_id' => $schoolIdVal,
            'file_path' => $filePath,
            'file_size' => 0,
            'tables_count' => count($tables),
            'rows_count' => 0
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("INSERT INTO activity_logs (user_id, action, entity_type, description, details_json, status, is_active, created_at) VALUES (?, 'backup', 'full', ?, ?, 'pending', 1, NOW())");
        $stmt->execute([$createdBy, $filePath, $detailsJson]);
        $backupId = $this->db->lastInsertId();

        try {
            $sqlDump = $this->generateSQLDump($tables);
            $gzipResult = $this->writeGzipFile($filePath, $sqlDump);

            $fileSize = filesize($filePath);
            $rowsCount = $this->countRowsInDump($sqlDump);

            $stmt = $this->db->prepare("UPDATE activity_logs SET status = 'completed', details_json = JSON_SET(details_json, '$.file_size', ?, '$.rows_count', ?) WHERE id = ?");
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
            $stmt = $this->db->prepare("UPDATE activity_logs SET status = 'failed', details_json = JSON_SET(COALESCE(details_json, '{}'), '$.error_message', ?) WHERE id = ?");
            $stmt->execute([$e->getMessage(), $backupId]);

            return ['success' => false, 'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()];
        }
    }

    /**
     * المستوى 2: إنشاء نسخة احتياطية قبل عملية حساسة
     */
    public function createPreOperationBackup($operation, $createdBy, $tables = [], $schoolId = null) {
        $timestamp = date('Ymd_His');
        $safeOpName = preg_replace('/[^a-zA-Z0-9_]/', '', $operation);
        $fileName = "preop_{$timestamp}_{$safeOpName}.sql.gz";
        $filePath = $this->backupDir . '/' . $fileName;

        if (empty($tables)) {
            $tables = $this->getOperationTables($operation);
        }

        $schoolIdVal = $schoolId ?? $_SESSION['user']['school_id'] ?? null;

        $detailsJson = json_encode([
            'school_id' => $schoolIdVal,
            'file_path' => $filePath,
            'file_size' => 0,
            'tables_count' => count($tables),
            'rows_count' => 0,
            'operation' => $operation
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("INSERT INTO activity_logs (user_id, action, entity_type, description, details_json, status, is_active, created_at) VALUES (?, 'backup', 'pre_operation', ?, ?, 'pending', 1, NOW())");
        $stmt->execute([$createdBy, $filePath, $detailsJson]);
        $backupId = $this->db->lastInsertId();

        try {
            $sqlDump = $this->generateSQLDump($tables);
            $this->writeGzipFile($filePath, $sqlDump);

            $fileSize = filesize($filePath);
            $rowsCount = $this->countRowsInDump($sqlDump);

            $stmt = $this->db->prepare("UPDATE activity_logs SET status = 'completed', details_json = JSON_SET(details_json, '$.file_size', ?, '$.rows_count', ?) WHERE id = ?");
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
            $stmt = $this->db->prepare("UPDATE activity_logs SET status = 'failed', details_json = JSON_SET(COALESCE(details_json, '{}'), '$.error_message', ?) WHERE id = ?");
            $stmt->execute([$e->getMessage(), $backupId]);

            return ['success' => false, 'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()];
        }
    }

    /**
     * المستوى 3: إنشاء نسخة احتياطية يدوية
     */
    public function createManualBackup($tables, $createdBy, $schoolId = null) {
        $timestamp = date('Ymd_His');
        $fileName = "manual_{$timestamp}.sql.gz";
        $filePath = $this->backupDir . '/' . $fileName;

        $availableTables = $this->getAllTables();
        $tables = array_intersect($tables, $availableTables);

        if (empty($tables)) {
            return ['success' => false, 'message' => 'لم يتم تحديد جداول صالحة'];
        }

        $schoolIdVal = $schoolId ?? $_SESSION['user']['school_id'] ?? null;

        $detailsJson = json_encode([
            'school_id' => $schoolIdVal,
            'file_path' => $filePath,
            'file_size' => 0,
            'tables_count' => count($tables),
            'rows_count' => 0
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("INSERT INTO activity_logs (user_id, action, entity_type, description, details_json, status, is_active, created_at) VALUES (?, 'backup', 'manual', ?, ?, 'pending', 1, NOW())");
        $stmt->execute([$createdBy, $filePath, $detailsJson]);
        $backupId = $this->db->lastInsertId();

        try {
            $sqlDump = $this->generateSQLDump($tables);
            $this->writeGzipFile($filePath, $sqlDump);

            $fileSize = filesize($filePath);
            $rowsCount = $this->countRowsInDump($sqlDump);

            $stmt = $this->db->prepare("UPDATE activity_logs SET status = 'completed', details_json = JSON_SET(details_json, '$.file_size', ?, '$.rows_count', ?) WHERE id = ?");
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
            $stmt = $this->db->prepare("UPDATE activity_logs SET status = 'failed', details_json = JSON_SET(COALESCE(details_json, '{}'), '$.error_message', ?) WHERE id = ?");
            $stmt->execute([$e->getMessage(), $backupId]);

            return ['success' => false, 'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()];
        }
    }

    /**
     * استعادة من نسخة احتياطية
     */
    public function restoreBackup($filePath, $restoredBy) {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'ملف النسخة الاحتياطية غير موجود'];
        }

        $fileSizeMB = filesize($filePath) / (1024 * 1024);
        if ($fileSizeMB > $this->largeBackupThreshold) {
        }

        $preRestoreResult = $this->createPreOperationBackup('restore', $restoredBy);
        if (!$preRestoreResult['success']) {
            return ['success' => false, 'message' => 'فشل إنشاء نسخة احتياطية قبل الاستعادة: ' . $preRestoreResult['message']];
        }

        try {
            $result = $this->executeSQLFile($filePath);

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
     */
    public function listBackups($filters = []) {
        $sql = "SELECT al.*, u.full_name_ar as creator_name
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.action = 'backup' AND al.is_active = 1";

        $params = [];

        if (!empty($filters['backup_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['backup_type'];
        }

        if (!empty($filters['status'])) {
            $sql .= " AND al.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $sql .= " ORDER BY al.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map([$this, 'normalizeBackupRow'], $rows);
    }

    /**
     * حذف نسخة احتياطية
     */
    public function deleteBackup($backupId) {
        $stmt = $this->db->prepare("SELECT * FROM activity_logs WHERE id = ? AND action = 'backup' AND is_active = 1");
        $stmt->execute([$backupId]);
        $backup = $this->normalizeBackupRow($stmt->fetch());

        if (!$backup) {
            return ['success' => false, 'message' => 'النسخة الاحتياطية غير موجودة'];
        }

        if (file_exists($backup['file_path'])) {
            @unlink($backup['file_path']);
        }

        $stmt = $this->db->prepare("UPDATE activity_logs SET is_active = 0 WHERE id = ?");
        $stmt->execute([$backupId]);

        return ['success' => true, 'message' => 'تم حذف النسخة الاحتياطية بنجاح'];
    }

    /**
     * الحصول على نسخة احتياطية واحدة (للاستخدام من الصفحات)
     */
    public function getBackupById($backupId) {
        $stmt = $this->db->prepare("SELECT al.*, u.full_name_ar as creator_name
                                    FROM activity_logs al
                                    LEFT JOIN users u ON al.user_id = u.id
                                    WHERE al.id = ? AND al.action = 'backup' AND al.is_active = 1");
        $stmt->execute([$backupId]);
        return $this->normalizeBackupRow($stmt->fetch());
    }

    /**
     * الحصول على معلومات النسخة الاحتياطية
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

        try {
            $gz = gzopen($filePath, 'r');
            if ($gz) {
                $content = '';
                $maxRead = 1024 * 1024;
                while (!gzeof($gz) && strlen($content) < $maxRead) {
                    $content .= gzread($gz, 8192);
                }
                gzclose($gz);

                preg_match_all('/CREATE TABLE.*?`(\w+)`/si', $content, $tableMatches);
                $info['tables'] = $tableMatches[1] ?? [];
                $info['tables_count'] = count($info['tables']);

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
     */
    public function cleanupOldBackups($daysToKeep = 30) {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));

        $stmt = $this->db->prepare("SELECT * FROM activity_logs WHERE action = 'backup' AND created_at < ? AND is_active = 1 ORDER BY created_at ASC");
        $stmt->execute([$cutoffDate]);
        $oldBackups = array_map([$this, 'normalizeBackupRow'], $stmt->fetchAll());

        $deleted = 0;
        $lastFullBackup = null;

        foreach ($oldBackups as $backup) {
            if ($backup['backup_type'] === 'full' && $backup['status'] === 'completed') {
                if ($lastFullBackup !== null) {
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
     */
    private function getOperationTables($operation) {
        $operationTables = [
            'promotion' => ['promotions', 'students', 'student_enrollments', 'grades', 'behavior_scores', 'v_attendance_summary'],
            'restore' => $this->getAllTables(),
            'semester_close' => ['semesters', 'grades', 'assessment_responses', 'behavior_scores', 'attendance', 'v_attendance_summary'],
            'eqi_calculate' => ['equi_results', 'eqi_dimension_weights'],
            'bulk_grades' => ['grades'],
            'bulk_attendance' => ['attendance', 'v_attendance_summary'],
            'settings_change' => ['system_settings', 'school_settings'],
            'user_management' => ['users', 'roles', 'role_permissions', 'permissions'],
        ];

        return $operationTables[$operation] ?? $this->getAllTables();
    }

    /**
     * توليد ملف SQL كامل
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
     */
    private function writeGzipFile($filePath, $content) {
        $gz = gzopen($filePath, 'w9');
        if (!$gz) {
            throw new Exception('فشل فتح ملف gzip للكتابة');
        }

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
     */
    private function executeSQLFile($filePath) {
        $gz = gzopen($filePath, 'r');
        if (!$gz) {
            throw new Exception('فشل فتح ملف النسخة الاحتياطية');
        }

        $content = '';
        while (!gzeof($gz)) {
            $content .= gzread($gz, 8192);
        }
        gzclose($gz);

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
     */
    private function splitSQL($sql) {
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

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
     */
    private function countRowsInDump($sqlDump) {
        preg_match_all('/^INSERT INTO/sim', $sqlDump, $matches);
        return count($matches[0] ?? []);
    }

    /**
     * الحصول على إحصائيات النسخ الاحتياطي
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

        $stmt = $this->db->query("SELECT COUNT(*) as total, COALESCE(CAST(JSON_EXTRACT(details_json, '$.file_size') AS UNSIGNED), 0) as total_size FROM activity_logs WHERE action = 'backup' AND is_active = 1 AND status = 'completed'");
        $row = $stmt->fetch();
        $stats['total_backups'] = $row['total'];
        $stats['total_size'] = $row['total_size'];

        $stmt = $this->db->query("SELECT entity_type, COUNT(*) as cnt FROM activity_logs WHERE action = 'backup' AND is_active = 1 AND status = 'completed' GROUP BY entity_type");
        while ($row = $stmt->fetch()) {
            switch ($row['entity_type']) {
                case 'full': $stats['full_count'] = $row['cnt']; break;
                case 'pre_operation': $stats['preop_count'] = $row['cnt']; break;
                case 'manual': $stats['manual_count'] = $row['cnt']; break;
            }
        }

        $stmt = $this->db->query("SELECT created_at FROM activity_logs WHERE action = 'backup' AND entity_type = 'full' AND is_active = 1 AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
        $row = $stmt->fetch();
        $stats['last_full_date'] = $row ? $row['created_at'] : null;

        $stmt = $this->db->query("SELECT created_at FROM activity_logs WHERE action = 'backup' AND is_active = 1 AND status = 'completed' ORDER BY created_at DESC LIMIT 1");
        $row = $stmt->fetch();
        $stats['last_backup_date'] = $row ? $row['created_at'] : null;

        return $stats;
    }

    /**
     * الحصول على قائمة الجداول مع عدد الصفوف
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
