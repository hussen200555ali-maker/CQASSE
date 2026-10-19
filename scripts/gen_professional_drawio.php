<?php
/**
 * Generate a professional CQASSE Context Diagram (.drawio)
 * Uses orthogonal edge routing, proper label backgrounds, and clean spacing
 */

// ─── Configuration ───────────────────────────────────────────
$canvasW = 1600;
$canvasH = 1000;

// Entities
$entities = [
    'GA' => ['x' => 30,  'y' => 180, 'w' => 190, 'h' => 80,  'fill' => '#e74c3c', 'label' => '<b>مدير النظام</b><br><i>Group Admin</i>'],
    'SA' => ['x' => 1380,'y' => 180, 'w' => 190, 'h' => 80,  'fill' => '#3498db', 'label' => '<b>مدير المدرسة</b><br><i>School Admin</i>'],
    'TE' => ['x' => 1380,'y' => 700, 'w' => 190, 'h' => 80,  'fill' => '#2ecc71', 'label' => '<b>معلم</b><br><i>Teacher</i>'],
    'PA' => ['x' => 30,  'y' => 700, 'w' => 190, 'h' => 80,  'fill' => '#f39c12', 'label' => '<b>ولي أمر</b><br><i>Parent</i>'],
];

// System box
$sysX = 330;
$sysY = 150;
$sysW = 940;
$sysH = 680;

// SYS connection points (entry/exit as fractions of width/height)
// Layout: We reserve bands on each side for specific entity connections
// Left side (entryX=0): GA top band (y: 0.15-0.40), PA bottom band (y: 0.60-0.85)  
// Right side (entryX=1): SA top band (y: 0.15-0.40), TE bottom band (y: 0.60-0.85)
// Top (entryY=0): SYS→GA left half (x: 0.10-0.40), SYS→SA right half (x: 0.60-0.90)
// Bottom (entryY=1): SYS→TE right half (x: 0.65-0.90), SYS→PA left half (x: 0.10-0.35)

// ─── Edge definitions ────────────────────────────────────────
// format: [id, src, tgt, label, exitX, exitY, entryX, entryY]
$edges = [];

// Function to add edges with even spacing
function addEdges(&$edges, &$id, $src, $tgt, $labels, $exitX, $exitY, $entryX, $entryStart, $entryStep) {
    foreach ($labels as $i => $label) {
        $entryY = $entryStart + ($i * $entryStep);
        $edges[] = [$id++, $src, $tgt, $label, $exitX, $exitY, $entryX, $entryY];
    }
}

$eid = 100;

// ─── GA → SYS (3 flows, left side) ───
addEdges($edges, $eid, 'GA', 'SYS', [
    "بيانات المستخدمين\nوالصلاحيات والسياسات",
    "إعدادات النظام",
    "طلبات النسخ الاحتياطي\nوالاستعادة"
], 1, 0.5, 0, 0.18, 0.07);

// ─── SYS → GA (6 flows, top edge of SYS curving to GA) ───
addEdges($edges, $eid, 'SYS', 'GA', [
    "سجل النشاطات",
    "مؤشرات EQI\nونتائج الجودة",
    "التنبيهات الذكية",
    "تقارير الأداء\nوKPIs",
    "نتائج التحليلات\nوالمقارنات",
    "التقارير السنوية\nوبيانات التصدير"
], 0, 0, 1, 0.15, 0.10);
// Override: these go from SYS top to GA right side
// entry points on GA: right side (entryX=1) at various y positions

// ─── SA → SYS (9 flows, right side) ───
addEdges($edges, $eid, 'SA', 'SYS', [
    "بيانات الطلاب\nوأولياء الأمور",
    "بيانات الشعب والمواد\nوتعيينات المعلمين",
    "بيانات تسجيل الطلاب\nفي الشعب",
    "إعدادات الحضور\nومعايير السلوك",
    "نتائج التقييم الإشرافي",
    "ردود استبيانات المناخ",
    "نتائج تقييم المرافق",
    "قرارات الترفيع",
    "خطط التحسين"
], 0, 0.5, 1, 0.14, 0.07);

// ─── SYS → SA (6 flows, top edge of SYS) ───
addEdges($edges, $eid, 'SYS', 'SA', [
    "مؤشرات EQI للمدرسة",
    "التنبيهات الذكية",
    "إحصائيات المدرسة",
    "كشوف الدرجات\nوتقارير الصف",
    "تقرير المدرسة PDF",
    "الإنذارات\nوإشعارات الغياب"
], 0, 0, 1, 0.15, 0.10);
// These go from SYS top to SA left side

// ─── TE → SYS (5 flows, right-bottom side) ───
addEdges($edges, $eid, 'TE', 'SYS', [
    "سجلات الحضور",
    "سجلات السلوك",
    "نتائج تقييم المهارات",
    "بيانات الاختبارات\nوالدرجات",
    "نتائج التقييم الذاتي\nوتقييم الأقران"
], 0, 0.5, 1, 0.58, 0.06);

// ─── SYS → TE (3 flows, bottom edge) ───
addEdges($edges, $eid, 'SYS', 'TE', [
    "بيانات الطلاب والصف",
    "خطط تطوير المعلم\nوالتوصيات",
    "إشعارات الغياب"
], 0, 1, 1, 0.55, 0.12);

// ─── PA → SYS (2 flows, left-bottom side) ───
addEdges($edges, $eid, 'PA', 'SYS', [
    "استعلامات ولي الأمر",
    "إقرارات ولي الأمر"
], 1, 0.5, 0, 0.62, 0.08);

// ─── SYS → PA (3 flows, bottom edge left side) ───
addEdges($edges, $eid, 'SYS', 'PA', [
    "بيانات الأبناء\n(درجات، سلوك، حضور، مهارات)",
    "نتائج الترفيع",
    "الإشعارات"
], 0, 1, 1, 0.50, 0.12);

// ─── XML generation ──────────────────────────────────────────
function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$xml = '<?xml version="1.0" encoding="UTF-8"?>
<mxfile host="app.diagrams.net" modified="2026-05-19T12:00:00Z" agent="CQASSE" version="24.2.5" type="device">
  <diagram id="CQASSE-Context" name="مخطط السياق - Context Diagram">
    <mxGraphModel dx="0" dy="0" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' . $canvasW . '" pageHeight="' . $canvasH . '" math="0" shadow="0">
      <root>
        <mxCell id="0" />
        <mxCell id="1" parent="0" />
';

// Add entities (vertices)
$entityIds = [];
$entityNum = 2;
foreach ($entities as $key => $e) {
    $eid_cell = $entityNum++;
    $entityIds[$key] = $eid_cell;
    $encoded = h($e['label']);
    $xml .= "        <mxCell id=\"{$eid_cell}\" value=\"{$encoded}\" style=\"rounded=1;fillColor={$e['fill']};fontColor=#ffffff;fontSize=12;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;\" parent=\"1\" vertex=\"1\">\n";
    $xml .= "          <mxGeometry x=\"{$e['x']}\" y=\"{$e['y']}\" width=\"{$e['w']}\" height=\"{$e['h']}\" as=\"geometry\" />\n";
    $xml .= "        </mxCell>\n";
}

// System box
$sysId = $entityNum;
$sysLabel = '<b style="font-size:18px;">CQASSE</b><hr size="1"><span style="font-size:13px;">نظام التقييم الشامل<br>لجودة التعليم المدرسي</span>';
$xml .= "        <mxCell id=\"{$sysId}\" value=\"" . h($sysLabel) . "\" style=\"rounded=1;fillColor=#f5f6fa;strokeColor=#2c3e50;strokeWidth=2.5;fontSize=13;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;\" parent=\"1\" vertex=\"1\">\n";
$xml .= "          <mxGeometry x=\"{$sysX}\" y=\"{$sysY}\" width=\"{$sysW}\" height=\"{$sysH}\" as=\"geometry\" />\n";
$xml .= "        </mxCell>\n";

// Add edges
// Edge style template: use orthogonal routing with exit/entry points
// NOTE: exitX/exitY/entryX/entryY go in the style string for drawio to use as connection points
foreach ($edges as $e) {
    list($edgeId, $srcKey, $tgtKey, $label, $exitX, $exitY, $entryX, $entryY) = $e;
    $srcId = $entityIds[$srcKey];
    $tgtId = $entityIds[$tgtKey];
    $encoded = h($label);
    
    // Determine edge routing style based on direction
    $edgeStyle = "edgeStyle=orthogonalEdgeStyle;";
    $edgeStyle .= "exitX={$exitX};exitY={$exitY};";
    $edgeStyle .= "entryX={$entryX};entryY={$entryY};";
    $edgeStyle .= "rounded=1;orthogonalLoop=1;jettySize=auto;html=1;";
    $edgeStyle .= "endArrow=blockThin;endFill=1;";
    $edgeStyle .= "strokeWidth=1.5;fontSize=9;";
    $edgeStyle .= "labelBackgroundColor=#ffffff;";
    $edgeStyle .= "curved=0;";
    
    $xml .= "        <mxCell id=\"{$edgeId}\" value=\"{$encoded}\" style=\"{$edgeStyle}\" edge=\"1\" parent=\"1\" source=\"{$srcId}\" target=\"{$tgtId}\">\n";
    $xml .= "          <mxGeometry relative=\"1\" as=\"geometry\" />\n";
    $xml .= "        </mxCell>\n";
}

$xml .= '      </root>
    </mxGraphModel>
  </diagram>
</mxfile>';

file_put_contents(__DIR__ . '/CQASSE_Context_Diagram_v2.drawio', $xml);
echo "DONE: Created " . __DIR__ . "/CQASSE_Context_Diagram_v2.drawio\n";
echo "Entities: " . count($entities) . "\n";
echo "Edges: " . count($edges) . "\n";
echo "File size: " . strlen($xml) . " bytes\n";
