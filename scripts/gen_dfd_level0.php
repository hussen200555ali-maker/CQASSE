<?php
/**
 * Generate DFD Level 0 — CQASSE
 * 6 processes, 4 external entities, 6 data stores
 * Canvas: 2200 × 1600
 */

$canvasW = 2200;
$canvasH = 1600;

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ─── Helper: create a vertex cell ─────────────────────────
function vtx($id, $label, $style, $x, $y, $w, $h) {
    global $canvasW, $canvasH;
    return "        <mxCell id=\"{$id}\" value=\"" . h($label) . "\" style=\"{$style}\" parent=\"1\" vertex=\"1\">\n" .
           "          <mxGeometry x=\"{$x}\" y=\"{$y}\" width=\"{$w}\" height=\"{$h}\" as=\"geometry\" />\n" .
           "        </mxCell>\n";
}

function edge($id, $src, $tgt, $label, $exitX, $exitY, $entryX, $entryY, $dashed = '') {
    $style = "edgeStyle=orthogonalEdgeStyle;exitX={$exitX};exitY={$exitY};entryX={$entryX};entryY={$entryY};";
    $style .= "rounded=1;orthogonalLoop=1;jettySize=auto;html=1;";
    $style .= "endArrow=blockThin;endFill=1;strokeWidth=1.5;fontSize=9;";
    $style .= "labelBackgroundColor=#ffffff;curved=0;{$dashed}";
    return "        <mxCell id=\"{$id}\" value=\"" . h($label) . "\" style=\"{$style}\" edge=\"1\" parent=\"1\" source=\"{$src}\" target=\"{$tgt}\">\n" .
           "          <mxGeometry relative=\"1\" as=\"geometry\" />\n" .
           "        </mxCell>\n";
}

// ─── IDs ──────────────────────────────────────────────────
$eid = 100; // edge counter

// Entities (same as context, slightly repositioned)
$GA = 2;  // x=50,  y=240
$SA = 3;  // x=1970, y=240
$TE = 4;  // x=1970, y=900
$PA = 5;  // x=50,  y=900

// Processes (2 rows x 3 cols)
// Row 1: P1(admin) P2(enroll) P6(comm) — top
// Row 2: P3(att/beh) P4(eval) P5(reports) — bottom
$P1 = 10; $P1x=340;  $P1y=120;  $P1w=220; $P1h=80;   // إدارة المستخدمين
$P2 = 11; $P2x=920;  $P2y=120;  $P2w=220; $P2h=80;   // البيانات الأساسية
$P6 = 15; $P6x=1540; $P6y=120;  $P6w=220; $P6h=80;   // التواصل
$P3 = 12; $P3x=340;  $P3y=460;  $P3w=220; $P3h=80;   // الحضور والسلوك
$P4 = 13; $P4x=920;  $P4y=460;  $P4w=220; $P4h=80;   // التقييم
$P5 = 14; $P5x=1540; $P5y=460;  $P5w=220; $P5h=80;   // التقارير

// Data Stores (6, bottom)
$DS1 = 20; $DS1x=100; $DS1y=840; $DS1w=250; $DS1h=60;  // النظام والمستخدمين
$DS2 = 21; $DS2x=420; $DS2y=840; $DS2w=250; $DS2h=60;  // الأكاديمية والتسجيل
$DS3 = 22; $DS3x=740; $DS3y=840; $DS3w=250; $DS3h=60;  // سجلات الأداء
$DS4 = 23; $DS4x=1060;$DS4y=840; $DS4w=250; $DS4h=60;  // التقييم والجودة
$DS5 = 24; $DS5x=1380;$DS5y=840; $DS5w=250; $DS5h=60;  // المناخ والاستبيانات
$DS6 = 25; $DS6x=1700;$DS6y=840; $DS6w=250; $DS6h=60;  // الإشعارات والتحسين

// Context boundary box (optional, large dashed rect)
$BOUNDARY = 30;

// ─── XML start ───────────────────────────────────────────
$xml = '<?xml version="1.0" encoding="UTF-8"?>
<mxfile host="app.diagrams.net" modified="2026-05-19T15:00:00Z" agent="CQASSE" version="24.2.5" type="device">
  <diagram id="CQASSE-DFD-L0" name="DFD Level 0 - CQASSE">
    <mxGraphModel dx="0" dy="0" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="' . $canvasW . '" pageHeight="' . $canvasH . '" math="0" shadow="0">
      <root>
        <mxCell id="0" />
        <mxCell id="1" parent="0" />
';

// ─── External Entities ──────────────────────────────────
$xml .= vtx($GA, '<b>مدير النظام</b><br><i>Group Admin</i>',
    'rounded=1;fillColor=#e74c3c;fontColor=#ffffff;fontSize=12;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;',
    50, 240, 190, 80);

$xml .= vtx($SA, '<b>مدير المدرسة</b><br><i>School Admin</i>',
    'rounded=1;fillColor=#3498db;fontColor=#ffffff;fontSize=12;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;',
    1970, 240, 190, 80);

$xml .= vtx($TE, '<b>معلم</b><br><i>Teacher</i>',
    'rounded=1;fillColor=#2ecc71;fontColor=#ffffff;fontSize=12;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;',
    1970, 900, 190, 80);

$xml .= vtx($PA, '<b>ولي أمر</b><br><i>Parent</i>',
    'rounded=1;fillColor=#f39c12;fontColor=#ffffff;fontSize=12;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;',
    50, 900, 190, 80);

// ─── Processes (rounded rects, distinctive DFD style) ──
$procStyle = 'rounded=1;fillColor=#e8f4fd;strokeColor=#2980b9;strokeWidth=2;fontSize=11;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;';

$xml .= vtx($P1, '<b>P1</b><br>إدارة المستخدمين<br>والصلاحيات والنظام', $procStyle, $P1x, $P1y, $P1w, $P1h);
$xml .= vtx($P2, '<b>P2</b><br>البيانات الأساسية<br>والتسجيل', $procStyle, $P2x, $P2y, $P2w, $P2h);
$xml .= vtx($P3, '<b>P3</b><br>إدارة الحضور<br>والسلوك والمهارات', $procStyle, $P3x, $P3y, $P3w, $P3h);
$xml .= vtx($P4, '<b>P4</b><br>التقييم الشامل<br>وجودة التعليم', $procStyle, $P4x, $P4y, $P4w, $P4h);
$xml .= vtx($P5, '<b>P5</b><br>التقارير<br>والتحليلات', $procStyle, $P5x, $P5y, $P5w, $P5h);
$xml .= vtx($P6, '<b>P6</b><br>التواصل وبوابة<br>المستفيدين', $procStyle, $P6x, $P6y, $P6w, $P6h);

// ─── Data Stores (open-ended rect DFD style) ───────────
$dsStyle = 'shape=cylinder3;fillColor=#fef9e7;strokeColor=#f39c12;strokeWidth=1.5;fontSize=10;verticalAlign=middle;align=center;whiteSpace=wrap;html=1;';

$xml .= vtx($DS1, '<b>DS1</b><br>بيانات النظام<br>والمستخدمين', $dsStyle, $DS1x, $DS1y, $DS1w, $DS1h);
$xml .= vtx($DS2, '<b>DS2</b><br>البيانات الأكاديمية<br>والتسجيل', $dsStyle, $DS2x, $DS2y, $DS2w, $DS2h);
$xml .= vtx($DS3, '<b>DS3</b><br>سجلات الأداء<br>(حضور، سلوك، مهارات)', $dsStyle, $DS3x, $DS3y, $DS3w, $DS3h);
$xml .= vtx($DS4, '<b>DS4</b><br>التقييمات الإشرافية<br>ومؤشرات EQI', $dsStyle, $DS4x, $DS4y, $DS4w, $DS4h);
$xml .= vtx($DS5, '<b>DS5</b><br>المناخ المدرسي<br>والاستبيانات', $dsStyle, $DS5x, $DS5y, $DS5w, $DS5h);
$xml .= vtx($DS6, '<b>DS6</b><br>الإشعارات والتنبيهات<br>وخطط التحسين', $dsStyle, $DS6x, $DS6y, $DS6w, $DS6h);

// ─── Flows: Entity → Process ───────────────────────────

// GA → P1 (3)
$xml .= edge($eid++, $GA, $P1, "بيانات المستخدمين\nوالصلاحيات", 1, 0.5, 0, 0.3);
$xml .= edge($eid++, $GA, $P1, "إعدادات النظام", 1, 0.5, 0, 0.5);
$xml .= edge($eid++, $GA, $P1, "سياسات المجموعة", 1, 0.5, 0, 0.7);

// GA → P5 (3)
$xml .= edge($eid++, $GA, $P5, "طلبات التقارير\nوالتحليلات", 1, 0.5, 0, 0.3);
$xml .= edge($eid++, $GA, $P5, "طلبات التصدير", 1, 0.5, 0, 0.7);

// SA → P2 (6)
$xml .= edge($eid++, $SA, $P2, "بيانات الطلاب\nوأولياء الأمور", 0, 0.5, 1, 0.2);
$xml .= edge($eid++, $SA, $P2, "بيانات الشعب والمواد", 0, 0.5, 1, 0.35);
$xml .= edge($eid++, $SA, $P2, "تعيينات المعلمين", 0, 0.5, 1, 0.5);
$xml .= edge($eid++, $SA, $P2, "تسجيل الطلاب\nفي الشعب", 0, 0.5, 1, 0.65);
$xml .= edge($eid++, $SA, $P2, "الفصول والسنوات\nالدراسية", 0, 0.5, 1, 0.8);

// SA → P3 (2)
$xml .= edge($eid++, $SA, $P3, "إعدادات الحضور", 0, 0.5, 1, 0.3);
$xml .= edge($eid++, $SA, $P3, "معايير السلوك", 0, 0.5, 1, 0.7);

// SA → P4 (4)
$xml .= edge($eid++, $SA, $P4, "نتائج التقييم\nالإشرافي", 0, 0.5, 1, 0.2);
$xml .= edge($eid++, $SA, $P4, "ردود استبيانات\nالمناخ", 0, 0.5, 1, 0.4);
$xml .= edge($eid++, $SA, $P4, "نتائج تقييم\nالمرافق", 0, 0.5, 1, 0.6);
$xml .= edge($eid++, $SA, $P4, "قرارات الترفيع", 0, 0.5, 1, 0.8);

// TE → P3 (4)
$xml .= edge($eid++, $TE, $P3, "سجلات الحضور", 0, 0.5, 1, 0.2);
$xml .= edge($eid++, $TE, $P3, "سجلات السلوك", 0, 0.5, 1, 0.4);
$xml .= edge($eid++, $TE, $P3, "تقارير المهارات", 0, 0.5, 1, 0.6);
$xml .= edge($eid++, $TE, $P3, "بيانات الاختبارات\nوالدرجات", 0, 0.5, 1, 0.8);

// TE → P4 (3)
$xml .= edge($eid++, $TE, $P4, "التقييم الذاتي", 0, 0.5, 1, 0.2);
$xml .= edge($eid++, $TE, $P4, "تقييم الأقران", 0, 0.5, 1, 0.5);
$xml .= edge($eid++, $TE, $P4, "تقييم المعلم\nللمدير", 0, 0.5, 1, 0.8);

// PA → P6 (2)
$xml .= edge($eid++, $PA, $P6, "استعلامات ولي\nالأمر", 1, 0.5, 0, 0.3);
$xml .= edge($eid++, $PA, $P6, "إقرارات ولي الأمر", 1, 0.5, 0, 0.7);

// ─── Flows: Process → Entity ───────────────────────────

// P1 → GA (4)
$xml .= edge($eid++, $P1, $GA, "سجل النشاطات", 0, 0.3, 1, 0.2);
$xml .= edge($eid++, $P1, $GA, "حالة النظام", 0, 0.5, 1, 0.4);
$xml .= edge($eid++, $P1, $GA, "سجل عمليات\nالنسخ الاحتياطي", 0, 0.7, 1, 0.6);

// P5 → GA (4) — exit from P5 right side up to GA
$xml .= edge($eid++, $P5, $GA, "التقارير السنوية", 0, 0.3, 1, 0.2);
$xml .= edge($eid++, $P5, $GA, "مؤشرات EQI\nوKPIs", 0, 0.5, 1, 0.4);
$xml .= edge($eid++, $P5, $GA, "التنبيهات الذكية", 0, 0.7, 1, 0.6);
$xml .= edge($eid++, $P5, $GA, "نتائج التحليلات\nوالمقارنات", 0, 0.85, 1, 0.8);

// P2 → SA (3)
$xml .= edge($eid++, $P2, $SA, "تأكيد التسجيل", 1, 0.3, 0, 0.2);
$xml .= edge($eid++, $P2, $SA, "إحصائيات المدرسة", 1, 0.5, 0, 0.4);
$xml .= edge($eid++, $P2, $SA, "تقارير الصف", 1, 0.7, 0, 0.6);

// P4 → SA (3)
$xml .= edge($eid++, $P4, $SA, "مؤشرات EQI\nللمدرسة", 1, 0.3, 0, 0.2);
$xml .= edge($eid++, $P4, $SA, "نتائج التقييمات", 1, 0.5, 0, 0.4);
$xml .= edge($eid++, $P4, $SA, "تقرير المدرسة PDF", 1, 0.7, 0, 0.6);

// P3 → TE (3)
$xml .= edge($eid++, $P3, $TE, "بيانات الطلاب\nللصف", 1, 0.25, 0, 0.2);
$xml .= edge($eid++, $P3, $TE, "إشعارات الغياب", 1, 0.5, 0, 0.5);
$xml .= edge($eid++, $P3, $TE, "خطط تطوير\nالمعلم", 1, 0.75, 0, 0.8);

// P6 → PA (3)
$xml .= edge($eid++, $P6, $PA, "بيانات الأبناء\n(درجات، سلوك، حضور)", 1, 0.25, 0, 0.2);
$xml .= edge($eid++, $P6, $PA, "نتائج الترفيع", 1, 0.5, 0, 0.5);
$xml .= edge($eid++, $P6, $PA, "الإشعارات", 1, 0.75, 0, 0.8);

// ─── Inter-Process Flows ───────────────────────────────

// P2 → P1: user validation
$edgeSt = "edgeStyle=orthogonalEdgeStyle;rounded=1;orthogonalLoop=1;jettySize=auto;html=1;endArrow=blockThin;endFill=1;strokeWidth=1.5;fontSize=9;labelBackgroundColor=#ffffff;curved=0;strokeColor=#7f8c8d;dashed=1;";
$xml .= "        <mxCell id=\"200\" value=\"طلبات التحقق من الصلاحيات\" style=\"{$edgeSt}exitX=0;exitY=0.5;entryX=1;entryY=0.5;\" edge=\"1\" parent=\"1\" source=\"11\" target=\"10\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P1 → P2, P3, P4, P5, P6: user roles & permissions validation
foreach ([['P2',11,0.5], ['P3',12,0.5], ['P4',13,0.5], ['P5',14,0.5], ['P6',15,0.5]] as $i => $p) {
    $label = $i == 0 ? "صلاحيات المستخدمين\nوأدوارهم" : "";
    $etgt = $p[1]; $eentryY = $p[2];
    $xml .= "        <mxCell id=\"" . (201+$i) . "\" value=\"" . h($label) . "\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=0;entryY={$eentryY};\" edge=\"1\" parent=\"1\" source=\"{$P1}\" target=\"{$etgt}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";
}

// P2 → P3: enrollment data
$xml .= "        <mxCell id=\"206\" value=\"بيانات تسجيل الطلاب\nوالشعب\" style=\"{$edgeSt}exitX=0;exitY=0.5;entryX=1;entryY=0.3;\" edge=\"1\" parent=\"1\" source=\"11\" target=\"12\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P2 → P4: academic structure
$xml .= "        <mxCell id=\"207\" value=\"الهيكل الأكاديمي\n(مواد، شعب، معلمين)\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=1;entryY=0.15;\" edge=\"1\" parent=\"1\" source=\"11\" target=\"13\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P3 → P4: daily performance for EQI
$xml .= "        <mxCell id=\"208\" value=\"بيانات الأداء\nاليومي (حضور، سلوك، مهارات، درجات)\" style=\"{$edgeSt}exitX=0.5;exitY=0;entryX=0;entryY=0.5;\" edge=\"1\" parent=\"1\" source=\"12\" target=\"13\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P4 → P5: EQI results for reporting
$xml .= "        <mxCell id=\"209\" value=\"نتائج EQI\nومؤشرات الجودة\" style=\"{$edgeSt}exitX=0.5;exitY=0;entryX=0;entryY=0.5;\" edge=\"1\" parent=\"1\" source=\"13\" target=\"14\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P4 → P6: results for parent portal
$xml .= "        <mxCell id=\"210\" value=\"نتائج الطلاب\nللمستفيدين\" style=\"{$edgeSt}exitX=1;exitY=0.5;entryX=0;entryY=0.5;\" edge=\"1\" parent=\"1\" source=\"13\" target=\"15\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P5 → P6: notifications
$xml .= "        <mxCell id=\"211\" value=\"التنبيهات والإشعارات\nللإرسال\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=1;entryY=0.5;\" edge=\"1\" parent=\"1\" source=\"14\" target=\"15\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P5 → P2: reports need
// P5 → P3: reports need
$xml .= "        <mxCell id=\"212\" value=\"طلب بيانات للتقارير\" style=\"{$edgeSt}exitX=0;exitY=0.3;entryX=1;entryY=0.5;\" edge=\"1\" parent=\"1\" source=\"14\" target=\"13\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";
$xml .= "        <mxCell id=\"213\" value=\"طلب بيانات للتقارير\" style=\"{$edgeSt}exitX=0;exitY=0.7;entryX=1;entryY=0.7;\" edge=\"1\" parent=\"1\" source=\"14\" target=\"12\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P6 → SA: parent communication status
$xml .= "        <mxCell id=\"214\" value=\"مراسلات أولياء\nالأمور\" style=\"{$edgeSt}exitX=0.5;exitY=0;entryX=1;entryY=0.5;\" edge=\"1\" parent=\"1\" source=\"15\" target=\"14\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// ─── Process ↔ Data Store flows ─────────────────────────

// P1 ↔ DS1
$xml .= "        <mxCell id=\"300\" value=\"\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P1}\" target=\"{$DS1}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P2 ↔ DS2
$xml .= "        <mxCell id=\"301\" value=\"\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P2}\" target=\"{$DS2}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P3 ↔ DS3
$xml .= "        <mxCell id=\"302\" value=\"\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P3}\" target=\"{$DS3}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P4 ↔ DS4
$xml .= "        <mxCell id=\"303\" value=\"\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P4}\" target=\"{$DS4}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P4 ↔ DS5 (climate surveys)
$xml .= "        <mxCell id=\"304\" value=\"\" style=\"{$edgeSt}exitX=0.15;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P4}\" target=\"{$DS5}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P5 ↔ DS4 (read EQI data)
$xml .= "        <mxCell id=\"305\" value=\"\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P5}\" target=\"{$DS4}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P5 ↔ DS2 (read academic data)
$xml .= "        <mxCell id=\"306\" value=\"\" style=\"{$edgeSt}exitX=0.2;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P5}\" target=\"{$DS2}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P5 ↔ DS3 (read performance data)
$xml .= "        <mxCell id=\"307\" value=\"\" style=\"{$edgeSt}exitX=0.8;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P5}\" target=\"{$DS3}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P6 ↔ DS6
$xml .= "        <mxCell id=\"308\" value=\"\" style=\"{$edgeSt}exitX=0.5;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P6}\" target=\"{$DS6}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// P1 ↔ DS6 (audit logs, alerts)
$xml .= "        <mxCell id=\"309\" value=\"\" style=\"{$edgeSt}exitX=0.15;exitY=1;entryX=0.5;entryY=0;\" edge=\"1\" parent=\"1\" source=\"{$P1}\" target=\"{$DS6}\">\n          <mxGeometry relative=\"1\" as=\"geometry\" />\n        </mxCell>\n";

// ─── Boundary box (dashed, represents system boundary) ──
$bStyle = 'rounded=1;dashed=1;fillColor=none;strokeColor=#bdc3c7;strokeWidth=2;fontSize=14;fontColor=#7f8c8d;verticalAlign=top;align=left;whiteSpace=wrap;html=1;';
$xml .= vtx($BOUNDARY, '<b>حدود النظام — CQASSE</b>', $bStyle, 260, 60, 1940, 920);

// ─── XML end ─────────────────────────────────────────────
$xml .= '      </root>
    </mxGraphModel>
  </diagram>
</mxfile>';

file_put_contents(__DIR__ . '/../docs/CQASSE_DFD_Level0.drawio', $xml);
echo "DONE: " . __DIR__ . "/../docs/CQASSE_DFD_Level0.drawio\n";
echo "Edges: " . ($eid - 100 + 25) . "\n";
echo "Size: " . strlen($xml) . " bytes\n";

// Validate
try {
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    echo "XML valid: YES\n";
} catch (Exception $ex) {
    echo "XML valid: NO - " . $ex->getMessage() . "\n";
}
