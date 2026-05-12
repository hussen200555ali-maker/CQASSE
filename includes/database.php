<?php
/**
 * ============================================================
 * EQAS - اتصال قاعدة البيانات (نمط Singleton)
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * طبقة قاعدة البيانات الرئيسية باستخدام PDO
 * يدعم نمط Singleton لضمان اتصال واحد فقط
 * يتضمن دوال مساعدة لتنفيذ الاستعلامات بسهولة وأمان
 * ============================================================
 */

class Database {
    /** @var Database|null نسخة واحدة من الكلاس (Singleton) */
    private static $instance = null;

    /** @var PDO كائن اتصال PDO */
    private $pdo;

    /** @var int عدد الاستعلامات المنفذة (للتتبع) */
    private $queryCount = 0;

    /** @var float وقت بدء الاتصال (للتتبع) */
    private $startTime;

    /**
     * المُنشئ الخاص - يمنع إنشاء كائن مباشر من خارج الكلاس
     * يقوم بتحميل الإعدادات وإنشاء اتصال PDO آمن
     *
     * @throws Exception في حالة فشل الاتصال بقاعدة البيانات
     */
    private function __construct() {
        // تحميل ملف الإعدادات إن لم يتم تحميله مسبقاً
        if (!defined('DB_HOST')) {
            $configFile = __DIR__ . '/../config.php';
            if (file_exists($configFile)) {
                require_once $configFile;
            }
        }

        // التحقق من توفر إعدادات قاعدة البيانات
        $host = defined('DB_HOST') ? DB_HOST : 'localhost';
        $name = defined('DB_NAME') ? DB_NAME : '';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

        if (empty($name)) {
            error_log('EQAS: اسم قاعدة البيانات غير محدد');
            throw new Exception('اسم قاعدة البيانات غير محدد في الإعدادات');
        }

        $this->startTime = microtime(true);

        try {
            // بناء سلسلة الاتصال DSN
            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

            // خيارات PDO المتقدمة
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,       // رمي استثناءات عند الأخطاء
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,            // جلب كمصفوفة ترابطية
                PDO::ATTR_EMULATE_PREPARES   => false,                        // استخدام الاستعلامات المجهزة الحقيقية
                PDO::ATTR_PERSISTENT         => false,                        // عدم استخدام الاتصالات المستمرة
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,                         // دعم ROW_COUNT حتى مع عدم التغيير
            ];

            $this->pdo = new PDO($dsn, $user, $pass, $options);

            // ضبط المنطقة الزمنية لجلسة MySQL
            $this->pdo->exec("SET time_zone = '+03:00'");

        } catch (PDOException $e) {
            // تسجيل الخطأ دون كشف بيانات حساسة
            error_log('EQAS Database Error: ' . $e->getMessage());
            throw new Exception('خطأ في الاتصال بقاعدة البيانات - يرجى التحقق من الإعدادات');
        }
    }

    /**
     * منع استنساخ الكائن (Singleton)
     */
    private function __clone() {}

    /**
     * منع إلغاء التسلسل (Singleton)
     *
     * @throws Exception دائماً
     */
    public function __wakeup() {
        throw new Exception('لا يمكن إلغاء تسلسل كائن Database');
    }

    /**
     * الحصول على النسخة الوحيدة من الكلاس (Singleton)
     * يقوم بإنشاء النسخة عند أول استدعاء فقط
     *
     * @return Database نسخة قاعدة البيانات
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * الحصول على كائن اتصال PDO المباشر
     *
     * @return PDO كائن الاتصال
     */
    public function getConnection() {
        return $this->pdo;
    }

    /**
     * تنفيذ استعلام مع معلمات اختيارية
     * يعيد كائن PDOStatement
     *
     * @param string $sql استعلام SQL
     * @param array $params المعلمات المرتبطة
     * @return PDOStatement
     */
    public function query($sql, $params = []) {
        $this->queryCount++;
        if (empty($params)) {
            return $this->pdo->query($sql);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * تجهيز استعلام للتنفيذ اللاحق
     *
     * @param string $sql استعلام SQL
     * @return PDOStatement
     */
    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }

    /**
     * جلب صف واحد من نتيجة الاستعلام
     *
     * @param string $sql استعلام SQL
     * @param array $params المعلمات المرتبطة
     * @return array|false صف واحد كمصفوفة ترابطية أو false
     */
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * جلب جميع الصفوف من نتيجة الاستعلام
     *
     * @param string $sql استعلام SQL
     * @param array $params المعلمات المرتبطة
     * @return array مصفوفة من الصفوف
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * جلب عمود واحد من أول صف
     *
     * @param string $sql استعلام SQL
     * @param array $params المعلمات المرتبطة
     * @return mixed قيمة العمود أو false
     */
    public function fetchColumn($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * تنفيذ استعلام تعديل (INSERT/UPDATE/DELETE)
     * يعيد عدد الصفوف المتأثرة
     *
     * @param string $sql استعلام SQL
     * @param array $params المعلمات المرتبطة
     * @return int عدد الصفوف المتأثرة
     */
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * إدراج صف جديد في جدول
     * يقوم ببناء استعلام INSERT تلقائياً من المصفوفة
     *
     * @param string $table اسم الجدول
     * @param array $data مصفوفة ترابطية [عمود => قيمة]
     * @return int معرف الصف الجديد
     * @throws Exception في حالة فشل الإدراج
     */
    public function insert($table, $data) {
        if (empty($data)) {
            throw new Exception('لا يمكن إدراج بيانات فارغة');
        }

        // تنظيف أسماء الأعمدة (حماية من حقن SQL)
        $columns = array_map(function($col) {
            return '`' . str_replace('`', '', $col) . '`';
        }, array_keys($data));

        // إنشاء علامات الاستبدال
        $placeholders = array_fill(0, count($data), '?');
        $values = array_values($data);

        $sql = "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $this->query($sql, $values);
        return $this->pdo->lastInsertId();
    }

    /**
     * تحديث صفوف في جدول
     *
     * @param string $table اسم الجدول
     * @param array $data مصفوفة ترابطية [عمود => قيمة] للتحديث
     * @param string $where شرط التحديث (مثل: id = ?)
     * @param array $whereParams معلمات شرط التحديث
     * @return int عدد الصفوف المتأثرة
     */
    public function update($table, $data, $where, $whereParams = []) {
        if (empty($data)) {
            throw new Exception('لا يمكن تحديث بيانات فارغة');
        }

        // بناء أزواج العمود = القيمة
        $setParts = [];
        $values = [];
        foreach ($data as $column => $value) {
            $col = '`' . str_replace('`', '', $column) . '`';
            $setParts[] = "{$col} = ?";
            $values[] = $value;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE {$where}";
        $values = array_merge($values, (array)$whereParams);

        return $this->execute($sql, $values);
    }

    /**
     * حذف صفوف من جدول (حذف ناعم - تعيين is_deleted = 1)
     * إذا كان الجدول يحتوي على عمود is_deleted يتم الحذف الناعم
     * وإلا يتم الحذف الفعلي
     *
     * @param string $table اسم الجدول
     * @param string $where شرط الحذف
     * @param array $whereParams معلمات الشرط
     * @return int عدد الصفوف المتأثرة
     */
    public function delete($table, $where, $whereParams = []) {
        // التحقق من وجود عمود is_deleted للقيام بالحذف الناعم
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'is_deleted'");
            $hasSoftDelete = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $hasSoftDelete = false;
        }

        if ($hasSoftDelete) {
            // حذف ناعم - تعليم الصفوف كمحذوفة
            $sql = "UPDATE `{$table}` SET is_deleted = 1, deleted_at = NOW() WHERE {$where}";
        } else {
            // حذف فعلي
            $sql = "DELETE FROM `{$table}` WHERE {$where}";
        }

        return $this->execute($sql, (array)$whereParams);
    }

    /**
     * عد الصفوف في جدول
     *
     * @param string $table اسم الجدول
     * @param string $where شرط العد (اختياري)
     * @param array $params معلمات الشرط
     * @return int عدد الصفوف
     */
    public function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) FROM `{$table}`";
        if (!empty($where)) {
            $sql .= " WHERE {$where}";
        }
        return (int)$this->fetchColumn($sql, $params);
    }

    /**
     * التحقق من وجود صفوف تطابق الشرط
     *
     * @param string $table اسم الجدول
     * @param string $where شرط البحث
     * @param array $params معلمات الشرط
     * @return bool هل يوجد تطابق
     */
    public function exists($table, $where, $params = []) {
        return $this->count($table, $where, $params) > 0;
    }

    /**
     * الحصول على آخر معرف تم إدراجه
     *
     * @return string آخر معرف
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    /**
     * بدء معاملة قاعدة بيانات (Transaction)
     */
    public function beginTransaction() {
        $this->pdo->beginTransaction();
    }

    /**
     * تأكيد المعاملة (Commit)
     */
    public function commit() {
        $this->pdo->commit();
    }

    /**
     * التراجع عن المعاملة (Rollback)
     */
    public function rollback() {
        $this->pdo->rollBack();
    }

    /**
     * التحقق من وجود معاملة نشطة
     *
     * @return bool هل توجد معاملة نشطة
     */
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }

    /**
     * تنفيذ معاملة في كتلة واحدة مع تراجع تلقائي عند الفشل
     *
     * @param callable $callback الدالة المراد تنفيذها داخل المعاملة
     * @return mixed نتيجة الدالة
     * @throws Exception عند فشل المعاملة
     */
    public function transaction($callback) {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Exception $e) {
            if ($this->inTransaction()) {
                $this->rollback();
            }
            error_log('EQAS Transaction Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * الحصول على عدد الاستعلامات المنفذة
     *
     * @return int عدد الاستعلامات
     */
    public function getQueryCount() {
        return $this->queryCount;
    }

    /**
     * الحصول على وقت التشغيل منذ إنشاء الاتصال
     *
     * @return float الوقت بالثواني
     */
    public function getElapsedTime() {
        return round(microtime(true) - $this->startTime, 4);
    }

    /**
     * إعادة ضبط النسخة (للاختبار فقط)
     * يحذف النسخة الحالية ويسمح بإنشاء اتصال جديد
     */
    public static function resetInstance() {
        self::$instance = null;
    }
}

// ============================================================================
// إنشاء متغير $db العام للتوافق مع الكود القديم
// ============================================================================
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    // في حالة فشل الاتصال (مثل صفحة الإعداد) - تعيين القيمة null
    $db = null;
}
