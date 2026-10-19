<?php
/**
 * Professional CQASSE Context Diagram v2
 * Proper orthogonal routing with correct exit/entry points
 */

$canvasW = 1600;
$canvasH = 1000;

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ─── Vertex definitions ──────────────────────────────────
// [id, label, fill, x, y, w, h]
$vertices = [
    [2, '<b>مدير النظام</b><br><i>Group Admin</i>', '#e74c3c', 30, 180, 190, 80],
    [3, '<b>مدير المدرسة</b><br><i>School Admin</i>', '#3498db', 1380, 180, 190, 80],
    [4, '<b>معلم</b><br><i>Teacher</i>', '#2ecc71', 1380, 700, 190, 80],
    [5, '<b>ولي أمر</b><br><i>Parent</i>', '#f39c12', 30, 700, 190, 80],
];

// System: id=6
$sys = [6, 300, 130, 1000, 720];

// ─── Edge definitions ────────────────────────────────────
// [id, src, tgt, label, style_exitX, style_exitY, style_entryX, style_entryY]
$edges = [];
$eid = 100;

// ==================== GA → SYS (3) ====================
// Enter SYS left side at various Y positions
$labels = [
    "بيانات المستخدمين\nوالصلاحيات والسياسات",
    "إعدادات النظام",
    "طلبات النسخ الاحتياطي\nوالاستعادة"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 2, 6, $l, 1, 0.5, 0, 0.20 + $i * 0.07];
}

// ==================== SYS → GA (6) ====================
// Exit SYS top, curve left to GA right side
$labels = [
    "سجل النشاطات",
    "مؤشرات EQI\nونتائج الجودة",
    "التنبيهات الذكية",
    "تقارير الأداء\nوKPIs",
    "نتائج التحليلات\nوالمقارنات",
    "التقارير السنوية\nوبيانات التصدير"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 6, 2, $l, 0.07 + $i * 0.055, 0, 1, 0.18 + $i * 0.11];
}

// ==================== SA → SYS (9) ====================
// Enter SYS right side at various Y positions
$labels = [
    "بيانات الطلاب\nوأولياء الأمور",
    "بيانات الشعب والمواد\nوتعيينات المعلمين",
    "بيانات تسجيل الطلاب\nفي الشعب",
    "إعدادات الحضور\nومعايير السلوك",
    "نتائج التقييم الإشرافي",
    "ردود استبيانات المناخ",
    "نتائج تقييم المرافق",
    "قرارات الترفيع",
    "خطط التحسين"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 3, 6, $l, 0, 0.5, 1, 0.14 + $i * 0.07];
}

// ==================== SYS → SA (6) ====================
// Exit SYS top, curve right to SA left side
$labels = [
    "مؤشرات EQI للمدرسة",
    "التنبيهات الذكية",
    "إحصائيات المدرسة",
    "كشوف الدرجات\nوتقارير الصف",
    "تقرير المدرسة PDF",
    "الإنذارات\nوإشعارات الغياب"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 6, 3, $l, 0.66 + $i * 0.053, 0, 0, 0.18 + $i * 0.11];
}

// ==================== TE → SYS (5) ====================
// Enter SYS right side at lower Y positions
$labels = [
    "سجلات الحضور",
    "سجلات السلوك",
    "نتائج تقييم المهارات",
    "بيانات الاختبارات\nوالدرجات",
    "نتائج التقييم الذاتي\nوتقييم الأقران"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 4, 6, $l, 0, 0.5, 1, 0.59 + $i * 0.07];
}

// ==================== SYS → TE (3) ====================
// Exit SYS bottom, go to TE left side
$labels = [
    "بيانات الطلاب والصف",
    "خطط تطوير المعلم\nوالتوصيات",
    "إشعارات الغياب"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 6, 4, $l, 0.72 + $i * 0.08, 1, 0, 0.5];
}

// ==================== PA → SYS (2) ====================
// Enter SYS left side at lower Y positions
$labels = [
    "استعلامات ولي الأمر",
    "إقرارات ولي الأمر"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 5, 6, $l, 1, 0.5, 0, 0.63 + $i * 0.08];
}

// ==================== SYS → PA (3) ====================
// Exit SYS bottom, go to PA right side
$labels = [
    "بيانات الأبناء\n(درجات، سلوك، حضور، مهارات)",
    "نتائج الترفيع",
    "الإشعارات"
];
foreach ($labels as $i => $l) {
    $edges[] = [$eid++, 6, 5, $l, 0.08 + $i * 0.08, 1, 1, 0.5];
}

// ─── Generate XML ───────────────────────────────────────
$xml = '<?xml version="1.0" encoding="UTF-8"?>
<mxfile host="app.diagrams.net" modified="2026-05-19T14:00:00Z" agent="CQASSE" version="24.2.5" type="device">
  <diagram id="CQASSE-Context" name="مخطط السياق - CQASSE">
    <mxGraphModel dx="0" dy="0" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' . $canvasW . '" pageHeight="' . $canvasH . '" math="0" shadow="0">
      <root>
        <mxCell id="0" />
        <mxCell id="1" parent="0" />
';

// Vertices
foreach ($vertices as $v) {
    list($vid, $label, $fill, $x, $y, $w, $h) = $v;
    $xml .= "        <mxCell id=\"{$vid}\" value=\"" . h($label) . "\" style=\"rounded=1;fillColor={$fill};fontColor=#ffffff;fontSize=12;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;\" parent=\"1\" vertex=\"1\">\n";
    $xml .= "          <mxGeometry x=\"{$x}\" y=\"{$y}\" width=\"{$w}\" height=\"{$h}\" as=\"geometry\" />\n";
    $xml .= "        </mxCell>\n";
}

// System
list($sysId, $sx, $sy, $sw, $sh) = $sys;
$sysLabel = '<b style="font-size:20px;color:#2c3e50;">CQASSE</b><hr size="1" noshade="noshade" style="border-color:#bdc3c7;"><span style="font-size:13px;color:#34495e;">نظام التقييم الشامل<br>لجودة التعليم المدرسي</span>';
$xml .= "        <mxCell id=\"{$sysId}\" value=\"" . h($sysLabel) . "\" style=\"rounded=1;fillColor=#f5f6fa;strokeColor=#2c3e50;strokeWidth=2.5;fontSize=13;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;\" parent=\"1\" vertex=\"1\">\n";
$xml .= "          <mxGeometry x=\"{$sx}\" y=\"{$sy}\" width=\"{$sw}\" height=\"{$sh}\" as=\"geometry\" />\n";
$xml .= "        </mxCell>\n";

// Edges
foreach ($edges as $e) {
    list($edgeId, $src, $tgt, $label, $exitX, $exitY, $entryX, $entryY) = $e;
    $style = "edgeStyle=orthogonalEdgeStyle;exitX={$exitX};exitY={$exitY};entryX={$entryX};entryY={$entryY};";
    $style .= "rounded=1;orthogonalLoop=1;jettySize=auto;html=1;";
    $style .= "endArrow=blockThin;endFill=1;strokeWidth=1.5;fontSize=9;";
    $style .= "labelBackgroundColor=#ffffff;curved=0;";
    $xml .= "        <mxCell id=\"{$edgeId}\" value=\"" . h($label) . "\" style=\"{$style}\" edge=\"1\" parent=\"1\" source=\"{$src}\" target=\"{$tgt}\">\n";
    $xml .= "          <mxGeometry relative=\"1\" as=\"geometry\" />\n";
    $xml .= "        </mxCell>\n";
}

$xml .= '      </root>
    </mxGraphModel>
  </diagram>
</mxfile>';

file_put_contents(__DIR__ . '/../docs/CQASSE_Context_Diagram.drawio', $xml);
echo "DONE\n";
echo "Edges: " . count($edges) . "\n";
echo "File: docs/CQASSE_Context_Diagram.drawio\n";

// Validate XML
try {
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    echo "XML valid: YES\n";
} catch (Exception $ex) {
    echo "XML valid: NO - " . $ex->getMessage() . "\n";
}
