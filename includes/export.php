<?php
/**
 * ============================================================
 * EQAS - Custom XLSX/CSV Export Library
 * نظام التقييم الشامل - مكتبة التصدير المخصصة
 * ============================================================
 * مكتبة تصدير XLSX مبنية من الصفر بدون مكتبات خارجية
 * تعتمد على تنسيق Office Open XML (ZIP + XML)
 * ============================================================
 */

/**
 * صنف كاتب ملفات XLSX المخصص
 */
class EqasXlsxWriter {
    /** @var array أوراق العمل */
    private $sheets = [];

    /** @var string اسم الورقة الحالية */
    private $currentSheet = '';

    /** @var array عناوين الأعمدة للورقة الحالية */
    private $headers = [];

    /** @var array صفوف البيانات للورقة الحالية */
    private $rows = [];

    /** @var array الأنماط المطبقة */
    private $styles = [];

    /** @var array دمج الخلايا */
    private $merges = [];

    /** @var array عرض الأعمدة */
    private $columnWidths = [];

    /** @var array الجداول المشتركة للنصوص */
    private $sharedStrings = [];

    /** @var int عداد الأنماط */
    private $styleIndex = 0;

    /** @var array خريطة الأنماط */
    private $styleMap = [];

    /**
     * المُنشئ
     */
    public function __construct() {
        $this->addSheet('Sheet1');
    }

    /**
     * إضافة ورقة عمل جديدة
     * @param string $name اسم الورقة
     * @return self
     */
    public function addSheet($name) {
        $name = mb_substr($name, 0, 31, 'UTF-8'); // حد Excel 31 حرف
        $this->sheets[$name] = [
            'headers' => [],
            'rows' => [],
            'styles' => [],
            'merges' => [],
            'columnWidths' => [],
            'headerStyle' => []
        ];
        $this->currentSheet = $name;
        $this->headers = &$this->sheets[$name]['headers'];
        $this->rows = &$this->sheets[$name]['rows'];
        $this->merges = &$this->sheets[$name]['merges'];
        $this->columnWidths = &$this->sheets[$name]['columnWidths'];
        return $this;
    }

    /**
     * تعيين الورقة الحالية
     * @param string $name اسم الورقة
     * @return self
     */
    public function setSheet($name) {
        if (!isset($this->sheets[$name])) {
            $this->addSheet($name);
        }
        $this->currentSheet = $name;
        $this->headers = &$this->sheets[$name]['headers'];
        $this->rows = &$this->sheets[$name]['rows'];
        $this->merges = &$this->sheets[$name]['merges'];
        $this->columnWidths = &$this->sheets[$name]['columnWidths'];
        return $this;
    }

    /**
     * تعيين عناوين الأعمدة مع نمط اختياري
     * @param array $headers عناوين الأعمدة
     * @param array $style النمط الاختياري
     * @return self
     */
    public function setHeaders($headers, $style = []) {
        $this->sheets[$this->currentSheet]['headers'] = $headers;
        $this->sheets[$this->currentSheet]['headerStyle'] = $style;
        // حساب عرض الأعمدة تلقائياً
        $col = 0;
        foreach ($headers as $header) {
            $width = mb_strlen((string)$header, 'UTF-8') * 2 + 4;
            if (!isset($this->columnWidths[$col]) || $this->columnWidths[$col] < $width) {
                $this->columnWidths[$col] = min($width, 50);
            }
            $col++;
        }
        return $this;
    }

    /**
     * إضافة صف بيانات
     * @param array $data بيانات الصف
     * @param array $style النمط الاختياري
     * @return self
     */
    public function addRow($data, $style = []) {
        $rowIndex = count($this->rows) + 2; // +2 للعناوين وبدء من 1
        $rowData = [];
        $col = 0;
        foreach ($data as $value) {
            $cellData = [
                'value' => $value,
                'style' => $style
            ];
            // إذا كان رقمياً، احفظه كرقم
            if (is_numeric($value) && !is_string($value)) {
                $cellData['type'] = 'number';
            } else {
                $cellData['type'] = 'string';
                // إضافة للنصوص المشتركة
                $strKey = (string)$value;
                if (!isset($this->sharedStrings[$strKey])) {
                    $this->sharedStrings[$strKey] = count($this->sharedStrings);
                }
            }
            $rowData[] = $cellData;

            // حساب عرض العمود
            $width = mb_strlen((string)$value, 'UTF-8') * 1.5 + 2;
            if (!isset($this->columnWidths[$col]) || $this->columnWidths[$col] < $width) {
                $this->columnWidths[$col] = min($width, 50);
            }
            $col++;
        }
        $this->rows[] = $rowData;
        return $this;
    }

    /**
     * دمج خلايا
     * @param string $range نطاق الدمج (مثال: A1:D1)
     * @return self
     */
    public function mergeCells($range) {
        $this->sheets[$this->currentSheet]['merges'][] = $range;
        return $this;
    }

    /**
     * تعيين عرض العمود
     * @param int $col رقم العمود (يبدأ من 0)
     * @param float $width العرض
     * @return self
     */
    public function setColumnWidth($col, $width) {
        $this->sheets[$this->currentSheet]['columnWidths'][$col] = $width;
        return $this;
    }

    /**
     * تطبيق نمط على نطاق
     * @param string $range النطاق
     * @param array $style النمط (bold, bg_color, alignment, font_color, font_size)
     * @return self
     */
    public function setStyle($range, $style) {
        $styleIdx = $this->registerStyle($style);
        $this->sheets[$this->currentSheet]['styles'][] = [
            'range' => $range,
            'style_index' => $styleIdx,
            'style' => $style
        ];
        return $this;
    }

    /**
     * تسجيل نمط جديد
     * @param array $style خصائص النمط
     * @return int فهرس النمط
     */
    private function registerStyle($style) {
        $key = md5(json_encode($style));
        if (isset($this->styleMap[$key])) {
            return $this->styleMap[$key];
        }
        $idx = $this->styleIndex++;
        $this->styleMap[$key] = $idx;
        return $idx;
    }

    /**
     * حفظ الملف في المسار المحدد
     * @param string $filePath مسار الملف
     * @return bool نجاح العملية
     */
    public function save($filePath) {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();
        $result = $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            return false;
        }

        // إضافة الملفات الأساسية للأرشيف
        $zip->addFromString('[Content_Types].xml', $this->generateContentTypes());
        $zip->addFromString('_rels/.rels', $this->generateRels());

        // ملفات المصنف
        $zip->addFromString('xl/workbook.xml', $this->generateWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->generateWorkbookRels());
        $zip->addFromString('xl/styles.xml', $this->generateStyles());
        $zip->addFromString('xl/sharedStrings.xml', $this->generateSharedStrings());

        // أوراق العمل
        $sheetIndex = 1;
        foreach ($this->sheets as $sheetName => $sheetData) {
            $zip->addFromString('xl/worksheets/sheet' . $sheetIndex . '.xml', $this->generateWorksheet($sheetName));
            $sheetIndex++;
        }

        $zip->close();
        return file_exists($filePath);
    }

    /**
     * تنزيل الملف مباشرة
     * @param string $filename اسم الملف للتنزيل
     * @return void
     */
    public function download($filename) {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir . '/eqas_export_' . uniqid() . '.xlsx';

        if (!$this->save($tempFile)) {
            die('خطأ في إنشاء ملف التصدير');
        }

        // تعيين رؤوس HTTP
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tempFile));
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        // إرسال الملف
        readfile($tempFile);

        // حذف الملف المؤقت
        @unlink($tempFile);
        exit;
    }

    /**
     * توليد ملف [Content_Types].xml
     */
    private function generateContentTypes() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';

        $sheetIndex = 1;
        foreach ($this->sheets as $sheetName => $sheetData) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $sheetIndex . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetIndex++;
        }
        $xml .= '</Types>';
        return $xml;
    }

    /**
     * توليد ملف _rels/.rels
     */
    private function generateRels() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $xml .= '</Relationships>';
        return $xml;
    }

    /**
     * توليد ملف workbook.xml
     */
    private function generateWorkbook() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        $sheetIndex = 1;
        foreach ($this->sheets as $sheetName => $sheetData) {
            $xml .= '<sheet name="' . htmlspecialchars($sheetName, ENT_XML1, 'UTF-8') . '" sheetId="' . $sheetIndex . '" r:id="rId' . ($sheetIndex + 1) . '"/>';
            $sheetIndex++;
        }
        $xml .= '</sheets>';
        $xml .= '</workbook>';
        return $xml;
    }

    /**
     * توليد ملف workbook.xml.rels
     */
    private function generateWorkbookRels() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        $sheetIndex = 1;
        foreach ($this->sheets as $sheetName => $sheetData) {
            $xml .= '<Relationship Id="rId' . ($sheetIndex + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetIndex . '.xml"/>';
            $sheetIndex++;
        }
        $xml .= '</Relationships>';
        return $xml;
    }

    /**
     * توليد ملف styles.xml
     */
    private function generateStyles() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // عدد تنسيقات الأرقام
        $xml .= '<numFmts count="0"/>';

        // الخطوط - الخط 0: الافتراضي، الخط 1: عريض
        $xml .= '<fonts count="2">';
        $xml .= '<font><sz val="11"/><name val="Arial"/></font>';
        $xml .= '<font><b/><sz val="11"/><name val="Arial"/></font>';
        $xml .= '</fonts>';

        // التعبئات - 0: لا شيء، 1: رمادي للعناوين، 2: أصفر فاتح
        $xml .= '<fills count="3">';
        $xml .= '<fill><patternFill patternType="none"/></fill>';
        $xml .= '<fill><patternFill patternType="lightGray"/></fill>';
        $xml .= '<fill><patternFill patternType="solid"><fgColor rgb="FF4472C4"/></patternFill></patternFill></fill>';
        $xml .= '</fills>';

        // الحدود - 0: بلا حدود، 1: حدود رفيعة
        $xml .= '<borders count="2">';
        $xml .= '<border><left/><right/><top/><bottom/><diagonal/></border>';
        $xml .= '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>';
        $xml .= '</borders>';

        // أنماط الخلايا - 0: الافتراضي، 1: العناوين
        $xml .= '<cellStyleXfs count="2">';
        $xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>';
        $xml .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
        $xml .= '</cellStyleXfs>';

        $xml .= '<cellXfs count="3">';
        // xf 0: الافتراضي
        $xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        // xf 1: العناوين (عريض، وسط، خلفية زرقاء)
        $xml .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
        // xf 2: حدود رفيعة فقط
        $xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>';
        $xml .= '</cellXfs>';

        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $xml .= '</styleSheet>';
        return $xml;
    }

    /**
     * تحويل رقم العمود إلى حرف (0=A, 1=B, ...)
     * @param int $col رقم العمود
     * @return string حرف العمود
     */
    private function columnLetter($col) {
        $letter = '';
        $col++; // البدء من 1
        while ($col > 0) {
            $col--;
            $letter = chr(65 + ($col % 26)) . $letter;
            $col = (int)($col / 26);
        }
        return $letter;
    }

    /**
     * توليد ملف ورقة العمل XML
     * @param string $sheetName اسم الورقة
     * @return string محتوى XML
     */
    private function generateWorksheet($sheetName) {
        $sheetData = $this->sheets[$sheetName];
        $headers = $sheetData['headers'];
        $rows = $sheetData['rows'];
        $merges = $sheetData['merges'];
        $columnWidths = $sheetData['columnWidths'];
        $headerStyle = $sheetData['headerStyle'];

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        // عرض الأعمدة
        if (!empty($columnWidths)) {
            $xml .= '<cols>';
            foreach ($columnWidths as $col => $width) {
                $xml .= '<col min="' . ($col + 1) . '" max="' . ($col + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        // صف العناوين
        if (!empty($headers)) {
            $xml .= '<row r="1">';
            $col = 0;
            foreach ($headers as $header) {
                $colLetter = $this->columnLetter($col);
                $cellRef = $colLetter . '1';
                $strIndex = isset($this->sharedStrings[(string)$header]) ? $this->sharedStrings[(string)$header] : 0;
                $xml .= '<c r="' . $cellRef . '" s="1" t="s"><v>' . $strIndex . '</v></c>';
                $col++;
            }
            $xml .= '</row>';
        }

        // صفوف البيانات
        $rowNum = 2;
        foreach ($rows as $row) {
            $xml .= '<row r="' . $rowNum . '">';
            $col = 0;
            foreach ($row as $cell) {
                $colLetter = $this->columnLetter($col);
                $cellRef = $colLetter . $rowNum;

                if ($cell['type'] === 'number') {
                    $xml .= '<c r="' . $cellRef . '" s="2"><v>' . $cell['value'] . '</v></c>';
                } else {
                    $strKey = (string)$cell['value'];
                    $strIndex = isset($this->sharedStrings[$strKey]) ? $this->sharedStrings[$strKey] : 0;
                    $xml .= '<c r="' . $cellRef . '" s="2" t="s"><v>' . $strIndex . '</v></c>';
                }
                $col++;
            }
            $rowNum++;
        }
        $xml .= '</sheetData>';

        // دمج الخلايا
        if (!empty($merges)) {
            $xml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $merge) {
                $xml .= '<mergeCell ref="' . $merge . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    /**
     * توليد ملف sharedStrings.xml
     */
    private function generateSharedStrings() {
        $strings = array_keys($this->sharedStrings);
        $count = count($strings);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';

        foreach ($strings as $str) {
            $xml .= '<si><t>' . htmlspecialchars($str, ENT_XML1, 'UTF-8') . '</t></si>';
        }

        $xml .= '</sst>';
        return $xml;
    }
}

// ============================================================================
// دوال التصدير المساعدة
// ============================================================================

/**
 * تصدير البيانات كملف CSV مع دعم العربية
 * @param array $data صفوف البيانات
 * @param array $headers عناوين الأعمدة
 * @param string $filename اسم الملف
 * @return void
 */
function exportCSV($data, $headers, $filename) {
    // تعيين رؤوس HTTP
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    // فتح مخرج الإخراج
    $output = fopen('php://output', 'w');

    // إضافة BOM لدعم العربية في Excel
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // إضافة العناوين
    if (!empty($headers)) {
        fputcsv($output, $headers);
    }

    // إضافة البيانات
    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

/**
 * تصدير متعدد الصيغ
 * @param array $data صفوف البيانات
 * @param array $headers عناوين الأعمدة
 * @param string $baseFilename الاسم الأساسي للملف (بدون امتداد)
 * @param array $formats الصيغ المطلوبة ['xlsx', 'csv']
 * @param string $sheetName اسم ورقة العمل
 * @return void
 */
function exportAllFormats($data, $headers, $baseFilename, $formats = ['xlsx', 'csv'], $sheetName = 'بيانات') {
    $format = $formats[0] ?? 'xlsx'; // استخدام الصيغة الأولى

    switch ($format) {
        case 'xlsx':
            $writer = new EqasXlsxWriter();
            $writer->setSheet($sheetName);
            $writer->setHeaders($headers, ['bold' => true, 'bg_color' => '4472C4', 'font_color' => 'FFFFFF']);

            foreach ($data as $row) {
                $writer->addRow($row);
            }

            $writer->download($baseFilename . '.xlsx');
            break;

        case 'csv':
        default:
            exportCSV($data, $headers, $baseFilename . '.csv');
            break;
    }
}

/**
 * إنشاء ملف تصدير وحفظ سجل التصدير
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $userId رقم المستخدم
 * @param string $exportType نوع التصدير
 * @param string $format الصيغة
 * @param array $data البيانات
 * @param array $headers العناوين
 * @param string $baseFilename الاسم الأساسي
 * @param array $filters عوامل التصفية
 * @return array معلومات التصدير
 */
function createExportFile($db, $userId, $exportType, $format, $data, $headers, $baseFilename, $filters = []) {
    // التأكد من وجود مجلد التصدير
    $exportDir = EQAS_TEMP . '/exports';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $timestamp = date('Ymd_His');
    $fileName = $baseFilename . '_' . $timestamp . '.' . $format;
    $filePath = $exportDir . '/' . $fileName;

    if ($format === 'xlsx') {
        $writer = new EqasXlsxWriter();
        $writer->setSheet('بيانات');
        $writer->setHeaders($headers);
        foreach ($data as $row) {
            $writer->addRow($row);
        }
        $writer->save($filePath);
    } else {
        // CSV
        $output = fopen($filePath, 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        if (!empty($headers)) {
            fputcsv($output, $headers);
        }
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
    }

    // حفظ سجل التصدير
    $stmt = $db->prepare("INSERT INTO export_history (user_id, export_type, file_name, file_path, file_size, row_count, filters, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', NOW())");
    $stmt->execute([
        $userId,
        $exportType,
        $fileName,
        $filePath,
        filesize($filePath),
        count($data),
        json_encode($filters, JSON_UNESCAPED_UNICODE)
    ]);

    return [
        'file_path' => $filePath,
        'file_name' => $fileName,
        'file_size' => filesize($filePath),
        'row_count' => count($data)
    ];
}

/**
 * تنزيل ملف تصدير موجود
 * @param string $filePath مسار الملف
 * @param string $fileName اسم الملف
 * @return void
 */
function downloadExportFile($filePath, $fileName) {
    if (!file_exists($filePath)) {
        die('الملف غير موجود');
    }

    $ext = pathinfo($fileName, PATHINFO_EXTENSION);
    $contentTypes = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv; charset=utf-8',
        'pdf' => 'application/pdf'
    ];

    $contentType = $contentTypes[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: max-age=0');
    readfile($filePath);
    exit;
}

/**
 * تنظيف ملفات التصدير القديمة
 * @param int $daysToKeep عدد أيام الاحتفاظ
 * @return int عدد الملفات المحذوفة
 */
function cleanupOldExports($daysToKeep = 7) {
    $exportDir = EQAS_TEMP . '/exports';
    if (!is_dir($exportDir)) {
        return 0;
    }

    $deleted = 0;
    $cutoffTime = time() - ($daysToKeep * 86400);

    $files = glob($exportDir . '/*');
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoffTime) {
            @unlink($file);
            $deleted++;
        }
    }

    return $deleted;
}

// ============================================================================
// تصدير PDF باستخدام TCPDF (إن توفر) أو HTML-to-Print
// ============================================================================

/**
 * إنشاء تقرير PDF بسيط باستخدام HTML
 * يعتمد على CSS @media print لإنشاء نسخة قابلة للطباعة
 * إذا كان TCPDF متاحاً، يستخدمه؛ وإلا يولّد HTML للطباعة
 *
 * @param string $title عنوان التقرير
 * @param string $html محتوى التقرير بتنسيق HTML
 * @param string $filename اسم الملف
 * @param array $options خيارات إضافية [orientation, paper_size, header_text, footer_text]
 * @return array معلومات التصدير
 */
function exportPDF($title, $html, $filename, $options = []) {
    $orientation = $options['orientation'] ?? 'P'; // P=Portrait, L=Landscape
    $paperSize = $options['paper_size'] ?? 'A4';
    $headerText = $options['header_text'] ?? 'نظام التقييم الشامل لجودة التعليم';
    $footerText = $options['footer_text'] ?? 'EQAS - تقرير مولّد آلياً';

    // محاولة استخدام TCPDF إن كان متاحاً
    $tcpdfPath = dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdfPath)) {
        require_once $tcpdfPath;

        $pdf = new TCPDF($orientation, 'mm', $paperSize, true, 'UTF-8', false);

        // إعدادات المستند
        $pdf->SetCreator('EQAS');
        $pdf->SetAuthor('نظام التقييم الشامل');
        $pdf->SetTitle($title);
        $pdf->SetSubject($title);

        // إعدادات الخط - دعم العربية
        $pdf->setRTL(true);
        $pdf->setTempDir(sys_get_temp_dir());

        // الهوامش
        $pdf->SetMargins(15, 20, 15);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(10);

        // خط عربي
        $pdf->SetFont('dejavusans', '', 10);

        // إضافة صفحة
        $pdf->AddPage();

        // محتوى HTML
        $pdf->writeHTML($html, true, false, true, false, '');

        // حفظ أو تنزيل
        $exportDir = defined('EQAS_TEMP') ? EQAS_TEMP . '/exports' : sys_get_temp_dir() . '/eqas_exports';
        if (!is_dir($exportDir)) mkdir($exportDir, 0755, true);

        $filePath = $exportDir . '/' . $filename;
        $pdf->Output($filePath, 'F');

        return [
            'success' => true,
            'file_path' => $filePath,
            'file_name' => $filename,
            'file_size' => filesize($filePath),
            'method' => 'tcpdf'
        ];
    }

    // TCPDF غير متاح - إنشاء HTML قابل للطباعة
    $printableHtml = generatePrintableHTML($title, $html, $headerText, $footerText);

    $exportDir = defined('EQAS_TEMP') ? EQAS_TEMP . '/exports' : sys_get_temp_dir() . '/eqas_exports';
    if (!is_dir($exportDir)) mkdir($exportDir, 0755, true);

    $htmlFilename = str_replace('.pdf', '_print.html', $filename);
    $filePath = $exportDir . '/' . $htmlFilename;
    file_put_contents($filePath, $printableHtml);

    return [
        'success' => true,
        'file_path' => $filePath,
        'file_name' => $htmlFilename,
        'file_size' => filesize($filePath),
        'method' => 'html_print',
        'note' => 'TCPDF غير متاح. تم إنشاء نسخة HTML قابلة للطباعة.'
    ];
}

/**
 * إنشاء صفحة HTML قابلة للطباعة
 *
 * @param string $title العنوان
 * @param string $content المحتوى
 * @param string $headerText نص الرأس
 * @param string $footerText نص التذييل
 * @return string كود HTML الكامل
 */
function generatePrintableHTML($title, $content, $headerText = '', $footerText = '') {
    $dir = dirname(__DIR__);
    $cssFile = $dir . '/assets/css/print.css';
    $printCSS = file_exists($cssFile) ? file_get_contents($cssFile) : '';

    return '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($title) . '</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Tajawal", "Noto Sans SC", "DejaVu Sans", Arial, sans-serif;
            direction: rtl; text-align: right;
            color: #1a1a2e; background: #fff;
            font-size: 12pt; line-height: 1.7;
            padding: 20mm;
        }
        ' . $printCSS . '
        .report-header {
            text-align: center; border-bottom: 3px double #1a5632;
            padding-bottom: 15px; margin-bottom: 20px;
        }
        .report-header h1 { font-size: 18pt; color: #1a5632; margin-bottom: 5px; }
        .report-header .subtitle { font-size: 11pt; color: #6c757d; }
        .report-footer {
            text-align: center; border-top: 1px solid #dee2e6;
            padding-top: 10px; margin-top: 30px; font-size: 9pt; color: #9ca3af;
        }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #dee2e6; padding: 8px 10px; text-align: right; font-size: 10pt; }
        th { background: #1a5632; color: white; font-weight: bold; }
        tr:nth-child(even) { background: #f8f9fa; }
        .text-center { text-align: center; }
        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
        .text-warning { color: #ffc107; }
        @media print {
            body { padding: 0; }
            .no-print { display: none !important; }
            .report-header { border-bottom-width: 2px; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align:center;margin-bottom:20px;">
        <button onclick="window.print()" style="padding:10px 30px;font-size:14pt;background:#1a5632;color:white;border:none;border-radius:8px;cursor:pointer;">
            🖨️ طباعة التقرير
        </button>
        <button onclick="window.close()" style="padding:10px 30px;font-size:14pt;background:#6c757d;color:white;border:none;border-radius:8px;cursor:pointer;margin-right:10px;">
            ✕ إغلاق
        </button>
    </div>
    <div class="report-header">
        <h1>' . e($headerText ?: 'نظام التقييم الشامل لجودة التعليم') . '</h1>
        <div class="subtitle">' . e($title) . ' — ' . date('Y/m/d H:i') . '</div>
    </div>
    ' . $content . '
    <div class="report-footer">' . e($footerText ?: 'EQAS - تقرير مولّد آلياً') . '</div>
</body>
</html>';
}

/**
 * تصدير كشوف الدرجات بصيغة PDF
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param array $gradeData بيانات كشف الدرجات
 * @param string $filename اسم الملف
 * @return array معلومات التصدير
 */
function exportGradeSheetPDF($db, $gradeData, $filename = '') {
    if (empty($gradeData) || empty($gradeData['students'])) {
        return ['success' => false, 'message' => 'لا توجد بيانات للتصدير'];
    }

    $filename = $filename ?: 'كشف_درجات_' . ($gradeData['class_info']['class_name'] ?? '') . '_' . date('Ymd') . '.pdf';

    // بناء HTML للكشف
    $html = '<h2 style="text-align:center;color:#1a5632;margin-bottom:5px;">كشف درجات</h2>';
    $html .= '<p style="text-align:center;color:#6c757d;margin-bottom:15px;">';
    $html .= e($gradeData['class_info']['school_name'] ?? '') . ' — ';
    $html .= e($gradeData['class_info']['grade_name'] ?? '') . ' — ';
    $html .= e($gradeData['class_info']['class_name'] ?? '');
    $html .= '</p>';

    $html .= '<table><thead><tr>';
    $html .= '<th>#</th><th>رقم الطالب</th><th>اسم الطالب</th>';
    foreach ($gradeData['subjects'] as $subjectName) {
        $html .= '<th>' . e($subjectName) . '</th>';
    }
    $html .= '<th>المعدل</th><th>الترتيب</th><th>الحالة</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($gradeData['students'] as $rank => $student) {
        $html .= '<tr>';
        $html .= '<td class="text-center">' . ($rank + 1) . '</td>';
        $html .= '<td>' . e($student['student_number'] ?? '') . '</td>';
        $html .= '<td><strong>' . e($student['full_name_ar'] ?? '') . '</strong></td>';
        foreach ($gradeData['subject_ids'] as $sid) {
            $grade = $student['grades'][$sid] ?? '-';
            $class = '';
            if (is_numeric($grade)) {
                $class = $grade < ($gradeData['passing_grade'] ?? 50) ? ' class="text-danger"' : '';
            }
            $html .= '<td' . $class . ' class="text-center">' . $grade . '</td>';
        }
        $avgClass = $student['average'] >= ($gradeData['passing_grade'] ?? 50) ? 'text-success' : 'text-danger';
        $html .= '<td class="text-center ' . $avgClass . '"><strong>' . $student['average'] . '%</strong></td>';
        $html .= '<td class="text-center">' . ($rank + 1) . '</td>';
        $html .= '<td class="text-center ' . $avgClass . '">' . $student['status'] . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    return exportPDF('كشف درجات - ' . ($gradeData['class_info']['school_name'] ?? ''), $html, $filename, [
        'orientation' => 'L', // عرضي لكشوف الدرجات
        'header_text' => $gradeData['class_info']['school_name'] ?? ''
    ]);
}

// ============================================================================
// تحسين دالة exportAllFormats لدعم صيغ متعددة
// ============================================================================

/**
 * تصدير متعدد الصيغ محسّن
 * يدعم: xlsx, csv, pdf, html
 * يمكن تصدير صيغة واحدة فقط (تنزيل مباشر) أو حفظ الكل
 *
 * @param array $data صفوف البيانات
 * @param array $headers عناوين الأعمدة
 * @param string $baseFilename الاسم الأساسي للملف (بدون امتداد)
 * @param string $format الصيغة المطلوبة (xlsx, csv, pdf, html)
 * @param string $sheetName اسم ورقة العمل
 * @param array $options خيارات إضافية [title, orientation, header_text]
 * @return array|string معلومات التصدير (للحفظ) أو تنزيل مباشر
 */
function exportAllFormatsEnhanced($data, $headers, $baseFilename, $format = 'xlsx', $sheetName = 'بيانات', $options = []) {
    switch ($format) {
        case 'xlsx':
            $writer = new EqasXlsxWriter();
            $writer->setSheet($sheetName);
            $writer->setHeaders($headers, ['bold' => true, 'bg_color' => '4472C4', 'font_color' => 'FFFFFF']);

            foreach ($data as $row) {
                $writer->addRow($row);
            }

            $filename = exportFileName($baseFilename, 'xlsx');
            $writer->download($filename);
            break;

        case 'csv':
            $filename = exportFileName($baseFilename, 'csv');
            exportRowsCSV($data, $headers, $filename);
            break;

        case 'pdf':
            $title = $options['title'] ?? $baseFilename;
            $html = buildTableHTML($headers, $data, $title);
            $filename = exportFileName($baseFilename, 'pdf');

            $result = exportPDF($title, $html, $filename, [
                'orientation' => $options['orientation'] ?? 'P',
                'header_text' => $options['header_text'] ?? ''
            ]);

            if ($result['method'] === 'html_print') {
                // تنزيل ملف HTML للطباعة
                header('Content-Type: text/html; charset=utf-8');
                readfile($result['file_path']);
                @unlink($result['file_path']);
                exit;
            }

            // تنزيل PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($result['file_path']));
            readfile($result['file_path']);
            @unlink($result['file_path']);
            exit;
            break;

        case 'html':
            $title = $options['title'] ?? $baseFilename;
            $html = buildTableHTML($headers, $data, $title);
            $filename = exportFileName($baseFilename, 'html');

            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $html;
            exit;
            break;

        default:
            exportAllFormats($data, $headers, $baseFilename, [$format], $sheetName);
            break;
    }
}

/**
 * بناء جدول HTML من البيانات والعناوين
 *
 * @param array $headers عناوين الأعمدة
 * @param array $data صفوف البيانات
 * @param string $title العنوان
 * @return string كود HTML للجدول
 */
function buildTableHTML($headers, $data, $title = '') {
    $html = '';
    if ($title) {
        $html .= '<h2 style="text-align:center;color:#1a5632;margin-bottom:15px;">' . e($title) . '</h2>';
    }

    $html .= '<table><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . e($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . e($cell ?? '') . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

/**
 * تصدير تقرير شامل بصيغ متعددة
 *
 * @param PDO $db اتصال قاعدة البيانات
 * @param int $userId معرف المستخدم
 * @param string $reportType نوع التقرير
 * @param string $format الصيغة
 * @param array $filters عوامل التصفية
 * @return array معلومات التصدير
 */
function exportReport($db, $userId, $reportType, $format = 'xlsx', $filters = []) {
    $schoolId = $filters['school_id'] ?? null;
    $academicYearId = $filters['academic_year_id'] ?? getCurrentAcademicYearId($db);
    $semesterId = $filters['semester_id'] ?? null;

    switch ($reportType) {
        case 'grade_sheet':
            $data = buildGradeSheetReport($db, $schoolId, $academicYearId, $semesterId);
            break;
        case 'attendance':
            $data = buildAttendanceReport($db, $schoolId, $academicYearId, $semesterId);
            break;
        case 'behavior':
            $data = buildBehaviorReport($db, $schoolId, $academicYearId);
            break;
        case 'eqi':
            $data = buildEqiReport($db, $schoolId, $academicYearId);
            break;
        default:
            return ['success' => false, 'message' => 'نوع تقرير غير معروف'];
    }

    if (empty($data['rows'])) {
        return ['success' => false, 'message' => 'لا توجد بيانات للتصدير'];
    }

    $filename = $reportType . '_report';
    exportAllFormatsEnhanced($data['rows'], $data['headers'], $filename, $format, 'بيانات', [
        'title' => $data['title'] ?? $reportType,
        'orientation' => $reportType === 'grade_sheet' ? 'L' : 'P'
    ]);

    // تسجيل التصدير
    logActivity($db, $userId, 'export_report', "تصدير تقرير: {$reportType} بصيغة {$format}", 'export', null);

    return ['success' => true];
}

/**
 * بناء تقرير كشف الدرجات
 */
function buildGradeSheetReport($db, $schoolId, $academicYearId, $semesterId) {
    $headers = ['الترتيب', 'رقم الطالب', 'اسم الطالب', 'المعدل', 'الحالة'];
    $rows = [];

    try {
        $sql = "SELECT s.student_number, s.full_name_ar,
                       COALESCE(ss.gpa, 0) as gpa,
                       COALESCE(ss.status, 'نشط') as status
                FROM students s
                LEFT JOIN student_subject_summary ss ON s.id = ss.student_id AND ss.academic_year_id = ?
                WHERE s.is_active = 1";
        $params = [$academicYearId];
        if ($schoolId) {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " ORDER BY gpa DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rank = 1;
        while ($row = $stmt->fetch()) {
            $rows[] = [
                $rank++,
                $row['student_number'],
                $row['full_name_ar'],
                $row['gpa'] . '%',
                $row['gpa'] >= 50 ? 'ناجح' : 'راسب'
            ];
        }
    } catch (Exception $e) { }

    return ['title' => 'تقرير كشف الدرجات', 'headers' => $headers, 'rows' => $rows];
}

/**
 * بناء تقرير الحضور
 */
function buildAttendanceReport($db, $schoolId, $academicYearId, $semesterId) {
    $headers = ['رقم الطالب', 'اسم الطالب', 'إجمالي الأيام', 'أيام الحضور', 'أيام الغياب', 'نسبة الحضور'];
    $rows = [];

    try {
        $sql = "SELECT s.student_number, s.full_name_ar,
                       COALESCE(SUM(CASE WHEN ad.status = 'present' THEN 1 ELSE 0 END), 0) as present_days,
                       COUNT(ad.id) as total_days
                FROM students s
                LEFT JOIN attendance_daily ad ON s.id = ad.student_id AND ad.academic_year_id = ?
                WHERE s.is_active = 1";
        $params = [$academicYearId];
        if ($schoolId) {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " GROUP BY s.id ORDER BY present_days DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch()) {
            $absent = $row['total_days'] - $row['present_days'];
            $rate = $row['total_days'] > 0 ? round(($row['present_days'] / $row['total_days']) * 100, 1) : 0;
            $rows[] = [
                $row['student_number'],
                $row['full_name_ar'],
                $row['total_days'],
                $row['present_days'],
                $absent,
                $rate . '%'
            ];
        }
    } catch (Exception $e) { }

    return ['title' => 'تقرير الحضور', 'headers' => $headers, 'rows' => $rows];
}

/**
 * بناء تقرير السلوك
 */
function buildBehaviorReport($db, $schoolId, $academicYearId) {
    $headers = ['رقم الطالب', 'اسم الطالب', 'درجة السلوك', 'عدد المخالفات', 'عدد الإيجابيات'];
    $rows = [];

    try {
        $sql = "SELECT s.student_number, s.full_name_ar,
                       COALESCE(bs.current_score, 100) as behavior_score,
                       COALESCE(SUM(CASE WHEN bi.direction = 'negative' THEN 1 ELSE 0 END), 0) as violations,
                       COALESCE(SUM(CASE WHEN bi.direction = 'positive' THEN 1 ELSE 0 END), 0) as positives
                FROM students s
                LEFT JOIN behavior_scores bs ON s.id = bs.student_id AND bs.academic_year_id = ?
                LEFT JOIN behavior_incidents bi ON s.id = bi.student_id AND bi.is_active = 1
                WHERE s.is_active = 1";
        $params = [$academicYearId];
        if ($schoolId) {
            $sql .= " AND s.school_id = ?";
            $params[] = $schoolId;
        }
        $sql .= " GROUP BY s.id ORDER BY behavior_score ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch()) {
            $rows[] = [
                $row['student_number'],
                $row['full_name_ar'],
                $row['behavior_score'],
                $row['violations'],
                $row['positives']
            ];
        }
    } catch (Exception $e) { }

    return ['title' => 'تقرير السلوك', 'headers' => $headers, 'rows' => $rows];
}

/**
 * بناء تقرير مؤشر جودة التعليم
 */
function buildEqiReport($db, $schoolId, $academicYearId) {
    $headers = ['المدرسة', 'مؤشر الجودة', 'التقييم', 'أكاديمي', 'تدريس', 'مناخ', 'سلوك', 'مهارات', 'حضور'];
    $rows = [];

    try {
        $sql = "SELECT s.name_ar, COALESCE(eq.overall_eqi, 0) as eqi_score,
                       eq.academic_score, eq.teaching_score, eq.climate_score,
                       eq.behavior_score, eq.skills_score, eq.attendance_score
                FROM schools s
                LEFT JOIN equi_results eq ON s.id = eq.school_id AND eq.is_active = 1
                WHERE s.is_active = 1";
        $params = [];
        if ($schoolId) {
            $sql .= " AND s.id = ?";
            $params[] = $schoolId;
        }
        $sql .= " ORDER BY eqi_score DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch()) {
            $rating = getEqiRating($row['eqi_score']);
            $rows[] = [
                $row['name_ar'],
                $row['eqi_score'] . '%',
                $rating[0],
                ($row['academic_score'] ?? '-') . '%',
                ($row['teaching_score'] ?? '-') . '%',
                ($row['climate_score'] ?? '-') . '%',
                ($row['behavior_score'] ?? '-') . '%',
                ($row['skills_score'] ?? '-') . '%',
                ($row['attendance_score'] ?? '-') . '%'
            ];
        }
    } catch (Exception $e) { }

    return ['title' => 'تقرير مؤشر جودة التعليم', 'headers' => $headers, 'rows' => $rows];
}
