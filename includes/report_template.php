<?php
/**
 * ============================================================
 * EQAS - Report Template Engine
 * قالب التقارير الرسمية باستخدام TCPDF
 * ============================================================
 *
 * يوفر هذا الملف كلاسين:
 * 1. EQAS_PDF extends TCPDF — يعالج الترويسة والتذييل والخطوط
 * 2. ReportTemplate — واجهة عالية المستوى لبناء التقارير
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ============================================================
// 1. EQAS_PDF — TCPDF مخصص للنظام
// ============================================================

class EQAS_PDF extends TCPDF {
    protected $reportTitle = '';
    protected $orgName = 'EQAS - نظام التقييم الشامل لجودة التعليم المدرسي';

    public function setReportTitle($title) {
        $this->reportTitle = $title;
    }

    public function setOrgName($name) {
        $this->orgName = $name;
    }

    public function Header() {
        if ($this->getPage() == 1) return;

        $this->SetFont('dejavusans', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->SetY(5);
        $this->Cell(0, 8, $this->orgName, 0, false, 'R', 0, '', 0, false, 'T', 'M');

        $this->SetY(5);
        $this->SetFont('dejavusans', '', 8);
        $this->Cell(0, 8, $this->reportTitle, 0, false, 'L', 0, '', 0, false, 'T', 'M');

        $this->SetY(14);
        $this->SetDrawColor(26, 86, 50);
        $this->Line($this->getMargins()['left'], 14, $this->getPageWidth() - $this->getMargins()['right'], 14);
    }

    public function Footer() {
        if ($this->getPage() == 1) return;

        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(128, 128, 128);

        $pageNum = $this->getAliasNumPage();
        $totalPages = $this->getAliasNbPages();
        $footerText = "صفحة $pageNum من $totalPages";

        $this->Cell(0, 10, $footerText, 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// ============================================================
// 2. ReportTemplate — واجهة بناء التقارير
// ============================================================

class ReportTemplate {
    private $pdf;
    private $orientation;
    private $unit;
    private $pageSize;

    // ألوان المؤسسة
    private $primaryColor = [26, 86, 50];
    private $primaryLight = [45, 122, 74];
    private $accentColor = [200, 169, 81];
    private $darkColor = [30, 41, 59];
    private $grayLight = [248, 250, 252];
    private $grayBorder = [226, 232, 240];
    private $textColor = [51, 65, 85];
    private $whiteColor = [255, 255, 255];

    public function __construct($title = 'تقرير', $orientation = 'P', $pageSize = 'A4') {
        $this->orientation = $orientation;
        $this->unit = 'mm';
        $this->pageSize = $pageSize;

        $this->pdf = new EQAS_PDF($orientation, $this->unit, $pageSize, true, 'UTF-8', false);

        $this->pdf->setReportTitle($title);

        // إعدادات المستند
        $this->pdf->SetCreator('EQAS');
        $this->pdf->SetAuthor('نظام التقييم الشامل لجودة التعليم المدرسي');
        $this->pdf->SetTitle($title);

        // RTL
        $this->pdf->setRTL(true);

        // الهوامش: 2 سم من كل جانب
        $this->pdf->SetMargins(20, 25, 20);
        $this->pdf->SetHeaderMargin(10);
        $this->pdf->SetFooterMargin(15);

        // التحكم في فواصل الصفحات
        $this->pdf->SetAutoPageBreak(true, 20);

        // الخط الافتراضي
        $this->pdf->SetFont('dejavusans', '', 14);

        // الصورة الافتراضية للترويسة والرقم التسلسلي
        $this->pdf->setPrintHeader(true);
        $this->pdf->setPrintFooter(true);
    }

    /**
     * إضافة صفحة غلاف
     */
    public function addCoverPage($academicYear = '', $preparedBy = '', $logoPath = null) {
        $this->pdf->addPage();

        // شريط علوي بلون المؤسسة
        $this->pdf->SetFillColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
        $this->pdf->Rect(0, 0, $this->pdf->getPageWidth(), 8, 'F');

        // شريط سفلي
        $this->pdf->Rect(0, $this->pdf->getPageHeight() - 8, $this->pdf->getPageWidth(), 8, 'F');

        // شعار — في حالة وجوده
        if ($logoPath && file_exists($logoPath)) {
            $this->pdf->Image($logoPath, $this->pdf->getPageWidth() / 2 - 25, 35, 50, 0, '', '', '', false, 300, '', false, false, 0);
        }

        // تباعد
        $this->pdf->SetY(95);

        // عنوان التقرير
        $this->pdf->SetFont('dejavusans', 'B', 28);
        $this->pdf->SetTextColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
        $this->pdf->Cell(0, 20, 'التقرير السنوي', 0, 1, 'C', 0, '', 1);

        $this->pdf->SetFont('dejavusans', '', 18);
        $this->pdf->SetTextColor($this->darkColor[0], $this->darkColor[1], $this->darkColor[2]);
        $this->pdf->Cell(0, 14, 'لمؤشرات جودة التعليم', 0, 1, 'C', 0, '', 1);

        // خط فاصل
        $this->pdf->SetY($this->pdf->GetY() + 5);
        $this->pdf->SetDrawColor($this->accentColor[0], $this->accentColor[1], $this->accentColor[2]);
        $this->pdf->SetLineWidth(0.5);
        $this->pdf->Line(60, $this->pdf->GetY(), $this->pdf->getPageWidth() - 60, $this->pdf->GetY());

        // العام الدراسي
        $this->pdf->SetY($this->pdf->GetY() + 15);
        $this->pdf->SetFont('dejavusans', '', 16);
        $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);
        $this->pdf->Cell(0, 12, $academicYear, 0, 1, 'C', 0, '', 1);

        // التفاصيل
        $this->pdf->SetY($this->pdf->GetY() + 20);
        $this->pdf->SetFont('dejavusans', '', 12);
        $this->pdf->SetTextColor(100, 100, 100);

        $dateStr = date('Y/m/d');
        if ($preparedBy) {
            $this->pdf->Cell(0, 9, "إعداد: $preparedBy", 0, 1, 'C', 0, '', 1);
        }
        $this->pdf->Cell(0, 9, "تاريخ الإصدار: $dateStr", 0, 1, 'C', 0, '', 1);

        // شعار EQAS
        $this->pdf->SetY($this->pdf->GetY() + 25);
        $this->pdf->SetFont('dejavusans', 'B', 10);
        $this->pdf->SetTextColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
        $this->pdf->Cell(0, 8, 'Education Quality Assessment System', 0, 1, 'C', 0, '', 1);
        $this->pdf->Cell(0, 8, 'نظام التقييم الشامل لجودة التعليم المدرسي', 0, 1, 'C', 0, '', 1);

        // إخفاء الترويسة والتذييل في صفحة الغلاف
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
    }

    /**
     * بداية صفحة جديدة مع إظهار الترويسة والتذييل
     */
    public function startContentPages() {
        $this->pdf->setPrintHeader(true);
        $this->pdf->setPrintFooter(true);
    }

    /**
     * إضافة قسم بعنوان
     */
    public function addSectionTitle($title) {
        $this->pdf->addPage();

        // شريط عنوان القسم
        $this->pdf->SetFillColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
        $this->pdf->SetTextColor($this->whiteColor[0], $this->whiteColor[1], $this->whiteColor[2]);
        $this->pdf->SetFont('dejavusans', 'B', 18);
        $this->pdf->Cell(0, 14, $title, 0, 1, 'R', true, '', 1);

        $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);
        $this->pdf->SetFont('dejavusans', '', 14);
        $this->pdf->Ln(4);
    }

    /**
     * إضافة قسم بدون بداية صفحة جديدة
     */
    public function addSubTitle($title) {
        $this->pdf->SetFont('dejavusans', 'B', 16);
        $this->pdf->SetTextColor($this->primaryLight[0], $this->primaryLight[1], $this->primaryLight[2]);

        $this->pdf->Cell(0, 10, $title, 0, 1, 'R', 0, '', 1);
        $this->pdf->SetDrawColor($this->grayBorder[0], $this->grayBorder[1], $this->grayBorder[2]);
        $this->pdf->Line($this->pdf->getMargins()['left'], $this->pdf->GetY(), $this->pdf->getPageWidth() - $this->pdf->getMargins()['right'], $this->pdf->GetY());
        $this->pdf->Ln(3);

        $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);
        $this->pdf->SetFont('dejavusans', '', 14);
    }

    /**
     * إضافة فقرة نصية
     */
    public function addParagraph($text) {
        $this->pdf->SetFont('dejavusans', '', 12);
        $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);
        $this->pdf->MultiCell(0, 7, $text, 0, 'R', 0, 1, '', '', true, 0, false, true, 0, 'T', false);
        $this->pdf->Ln(2);
    }

    /**
     * إضافة جدول منسق
     * $headers: [['text'=>'...', 'width'=>...], ...]
     * $rows: [[{text: '...', color: '...', align: '...'}, ...], ...]
     */
    public function addTable($headers, $rows) {
        // حساب العرض الإجمالي
        $totalWidth = $this->pdf->getPageWidth() - $this->pdf->getMargins()['left'] - $this->pdf->getMargins()['right'];

        // عرض الرؤوس
        $this->pdf->SetFillColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
        $this->pdf->SetTextColor($this->whiteColor[0], $this->whiteColor[1], $this->whiteColor[2]);
        $this->pdf->SetFont('dejavusans', 'B', 11);
        $this->pdf->SetDrawColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);

        foreach ($headers as $h) {
            $w = isset($h['width']) ? ($h['width'] / 100) * $totalWidth : $totalWidth / count($headers);
            $align = isset($h['align']) ? $h['align'] : 'C';
            $this->pdf->Cell($w, 9, $h['text'], 1, 0, $align, true, '', 1);
        }
        $this->pdf->Ln();

        // عرض البيانات
        $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);
        $this->pdf->SetFont('dejavusans', '', 10);

        $fill = false;
        foreach ($rows as $row) {
            $i = 0;
            foreach ($headers as $h) {
                $w = isset($h['width']) ? ($h['width'] / 100) * $totalWidth : $totalWidth / count($headers);
                $cellData = isset($row[$i]) ? $row[$i] : '';
                $align = 'C';

                if (is_array($cellData)) {
                    $cellText = isset($cellData['text']) ? $cellData['text'] : '';
                    $align = isset($cellData['align']) ? $cellData['align'] : 'C';
                    if (isset($cellData['color'])) {
                        $this->pdf->SetTextColor($cellData['color'][0], $cellData['color'][1], $cellData['color'][2]);
                    }
                } else {
                    $cellText = (string)$cellData;
                }

                $bgColor = $fill ? $this->grayLight : $this->whiteColor;
                $this->pdf->SetFillColor($bgColor[0], $bgColor[1], $bgColor[2]);
                $this->pdf->Cell($w, 7, $cellText, 1, 0, $align, true, '', 1);

                $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);

                $i++;
            }
            $this->pdf->Ln();
            $fill = !$fill;
        }
        $this->pdf->Ln(4);
    }

    /**
     * إضافة بطاقات KPI — عرض 3-4 بطاقات في صف واحد
     */
    public function addKPICards($cards) {
        $totalWidth = $this->pdf->getPageWidth() - $this->pdf->getMargins()['left'] - $this->pdf->getMargins()['right'];
        $cols = count($cards);
        $colWidth = ($totalWidth - ($cols - 1) * 3) / $cols;

        foreach ($cards as $i => $card) {
            if ($i > 0) $this->pdf->SetX($this->pdf->GetX() + 3);

            $startX = $this->pdf->GetX();
            $startY = $this->pdf->GetY();

            // خلفية البطاقة
            if (isset($card['color'])) {
                $this->pdf->SetFillColor($card['color'][0], $card['color'][1], $card['color'][2]);
            } else {
                $this->pdf->SetFillColor($this->grayLight[0], $this->grayLight[1], $this->grayLight[2]);
            }
            $this->pdf->Rect($startX, $startY, $colWidth, 24, 'F');

            // حد
            $this->pdf->SetDrawColor($this->grayBorder[0], $this->grayBorder[1], $this->grayBorder[2]);
            $this->pdf->Rect($startX, $startY, $colWidth, 24, 'D');

            // القيمة
            $this->pdf->SetFont('dejavusans', 'B', 18);
            $this->pdf->SetTextColor($this->darkColor[0], $this->darkColor[1], $this->darkColor[2]);
            $this->pdf->SetXY($startX, $startY + 2);
            $this->pdf->Cell($colWidth, 10, $card['value'] ?? '', 0, 1, 'C', 0, '', 1);

            // التسمية
            $this->pdf->SetFont('dejavusans', '', 8);
            $this->pdf->SetTextColor(100, 100, 100);
            $this->pdf->SetX($startX);
            $this->pdf->Cell($colWidth, 6, $card['label'] ?? '', 0, 1, 'C', 0, '', 1);
        }

        $maxY = $this->pdf->GetY();
        foreach ($cards as $i => $card) {
            if ($i == 0) continue;
            $this->pdf->SetX($this->pdf->GetX() + $colWidth + 3);
        }
        $this->pdf->SetY($maxY + 6);
    }

    /**
     * إضافة شريط تقدم أفقي (لمؤشر EQI مثلًا)
     */
    public function addProgressBar($label, $value, $maxValue = 100, $barColor = null) {
        $totalWidth = $this->pdf->getPageWidth() - $this->pdf->getMargins()['left'] - $this->pdf->getMargins()['right'];
        $labelWidth = 50;
        $barWidth = $totalWidth - $labelWidth - 20;

        $this->pdf->SetFont('dejavusans', '', 10);
        $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);
        $this->pdf->Cell($labelWidth, 7, $label, 0, 0, 'R', 0, '', 1);

        $barX = $this->pdf->GetX();
        $barY = $this->pdf->GetY();

        // خلفية الشريط
        $this->pdf->SetFillColor(226, 232, 240);
        $this->pdf->Rect($barX, $barY + 1, $barWidth, 5, 'F');

        // الشريط الملون
        $pct = min(100, max(0, ($value / $maxValue) * 100));
        $fillW = ($pct / 100) * $barWidth;

        if ($barColor === null) {
            if ($pct >= 85) $barColor = [40, 167, 69];
            elseif ($pct >= 70) $barColor = [23, 162, 184];
            elseif ($pct >= 50) $barColor = [255, 193, 7];
            else $barColor = [220, 53, 69];
        }
        $this->pdf->SetFillColor($barColor[0], $barColor[1], $barColor[2]);
        $this->pdf->Rect($barX, $barY + 1, $fillW, 5, 'F');

        // القيمة نصياً
        $this->pdf->SetFont('dejavusans', 'B', 10);
        $this->pdf->SetXY($barX + $barWidth + 2, $barY);
        $this->pdf->Cell(18, 7, number_format($value, 1) . '%', 0, 1, 'L', 0, '', 1);

        $this->pdf->SetFont('dejavusans', '', 14);
        $this->pdf->Ln(2);
    }

    /**
     * إضافة قائمة توصيات
     */
    public function addRecommendations($items) {
        $this->pdf->SetFont('dejavusans', '', 12);
        $this->pdf->SetTextColor($this->textColor[0], $this->textColor[1], $this->textColor[2]);

        foreach ($items as $item) {
            $bullet = "\u{2022} ";
            $text = $bullet . $item;
            $this->pdf->MultiCell(0, 7, $text, 0, 'R', 0, 1, '', '', true, 0, false, true, 0, 'T', false);
            $this->pdf->Ln(1);
        }
        $this->pdf->Ln(3);
    }

    /**
     * إضافة تذييل للقسم (border-bottom فاصل)
     */
    public function addSectionBreak() {
        $this->pdf->SetDrawColor($this->grayBorder[0], $this->grayBorder[1], $this->grayBorder[2]);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line($this->pdf->getMargins()['left'], $this->pdf->GetY(), $this->pdf->getPageWidth() - $this->pdf->getMargins()['right'], $this->pdf->GetY());
        $this->pdf->Ln(6);
    }

    /**
     * حفظ وعرض ملف PDF
     */
    public function output($filename, $mode = 'D') {
        $this->pdf->Output($filename, $mode);
    }
}
