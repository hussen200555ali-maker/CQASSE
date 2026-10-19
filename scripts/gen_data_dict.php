<?php
/**
 * Generate complete Data Dictionary HTML for CQASSE
 * Reads all tables/views from eqas_db and groups by BFD
 */

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'eqas_db';

$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_error) die("Connection failed: " . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

// ─── BFD Grouping ───────────────────────────────
$bfdGroups = [
    1 => [
        'name' => 'إدارة المستخدمين والصلاحيات والنظام',
        'color' => '#e74c3c',
        'tables' => ['users','roles','permissions','role_permissions','activity_logs','system_settings','user_preferences','export_history']
    ],
    2 => [
        'name' => 'البيانات الأساسية والتسجيل',
        'color' => '#3498db',
        'tables' => ['academic_years','semesters','governorates','schools','grade_levels','classes','subjects','subject_grade_years','subject_teachers','teacher_assignments','subject_weight_distribution','students','student_enrollments','student_notes','attachments']
    ],
    3 => [
        'name' => 'إدارة الحضور والسلوك والمهارات',
        'color' => '#2ecc71',
        'tables' => ['attendance_daily','behavior_categories','behavior_incidents','behavior_scores']
    ],
    4 => [
        'name' => 'التقييم الشامل وجودة التعليم',
        'color' => '#9b59b6',
        'tables' => ['assessment_types','assessment_forms','assessment_structure','assessment_responses','tests','grades','learning_outcomes','evaluations','surveys','survey_items','climate_items','facility_items','eqi_results','improvement_plans','school_improvement_plans','promotions','promotion_overrides']
    ],
    5 => [
        'name' => 'التقارير والتحليلات (المشاهد)',
        'color' => '#f39c12',
        'tables' => ['v_student_current','v_student_subject_summary','student_subject_summary','v_semester_scores','v_attendance_summary']
    ],
    6 => [
        'name' => 'التواصل وبوابة المستفيدين',
        'color' => '#1abc9c',
        'tables' => ['alerts','notifications','parent_students','parent_acknowledgments']
    ]
];

// ─── Fetch table data from INFORMATION_SCHEMA ──
function getTableMeta($mysqli, $tableName) {
    $sql = "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, 
                   COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT, CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row;
    }
    $stmt->close();

    // Get table comment
    $sql2 = "SELECT TABLE_COMMENT, TABLE_TYPE, ENGINE, TABLE_ROWS
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $stmt2 = $mysqli->prepare($sql2);
    $stmt2->bind_param('s', $tableName);
    $stmt2->execute();
    $meta = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    return ['columns' => $columns, 'meta' => $meta];
}

// ─── Build FK info ──────────────────────────────
function getFKs($mysqli, $tableName) {
    $sql = "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $fks = [];
    foreach ($stmt->get_result() as $row) {
        $fks[$row['COLUMN_NAME']] = $row['REFERENCED_TABLE_NAME'] . '.' . $row['REFERENCED_COLUMN_NAME'];
    }
    $stmt->close();
    return $fks;
}

// ─── Get UNIQUE constraints ─────────────────────
function getUniques($mysqli, $tableName) {
    $sql = "SELECT COLUMN_NAME, CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NULL
              AND CONSTRAINT_NAME LIKE 'uk_%' OR CONSTRAINT_NAME LIKE 'unique_%' OR CONSTRAINT_NAME LIKE '%_unique'";
    // Simpler: just get all non-PK unique indexes
    $sql2 = "SELECT DISTINCT INDEX_NAME, COLUMN_NAME
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND INDEX_NAME != 'PRIMARY'
               AND NON_UNIQUE = 0
             ORDER BY INDEX_NAME, SEQ_IN_INDEX";
    $stmt = $mysqli->prepare($sql2);
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $uniques = [];
    foreach ($stmt->get_result() as $row) {
        $name = $row['INDEX_NAME'];
        if (!isset($uniques[$name])) $uniques[$name] = [];
        $uniques[$name][] = $row['COLUMN_NAME'];
    }
    $stmt->close();
    return $uniques;
}

// ─── Generate Column Type Display ────────────────
function colType($col) {
    $t = $col['COLUMN_TYPE'];
    if ($col['CHARACTER_MAXIMUM_LENGTH'] && $col['DATA_TYPE'] == 'varchar') {
        return $t;
    }
    return $t;
}

function isPK($colName, $tableName, $mysqli) {
    $sql = "SELECT COUNT(*) cnt FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              AND COLUMN_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ss', $tableName, $colName);
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
    return $cnt > 0;
}

// ─── HTML Generation ─────────────────────────────
$html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قائمة بيانات نظام CQASSE</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "Traditional Arabic", "Arial", sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background: #f0f2f5;
            color: #333;
            direction: rtl;
        }
        h1 { text-align: center; color: #2c3e50; font-size: 2em; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 1.1em; }
        .container { max-width: 1300px; margin: 0 auto; }
        .bfd-section {
            background: #fff;
            border-radius: 12px;
            margin-bottom: 40px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .bfd-header {
            padding: 15px 20px;
            margin: -20px -20px 25px -20px;
            color: #fff;
            font-size: 1.4em;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .bfd-count { font-size: 0.7em; opacity: 0.9; }
        .tbl-card {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 25px;
            overflow: hidden;
        }
        .tbl-header {
            background: #f8f9fa;
            padding: 12px 15px;
            font-weight: bold;
            font-size: 1.1em;
            border-bottom: 2px solid #3498db;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .tbl-meta { font-size: 0.8em; color: #7f8c8d; font-weight: normal; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95em;
        }
        th {
            background: #f8f9fa;
            padding: 10px 12px;
            text-align: right;
            font-weight: bold;
            border-bottom: 2px solid #ddd;
            color: #555;
        }
        td {
            padding: 8px 12px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        tr:hover { background: #f5f6fa; }
        .col-pk { background: #fff3cd; font-weight: bold; }
        .col-fk { background: #d4edda; }
        .col-uniq { background: #cce5ff; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            margin-left: 4px;
        }
        .badge-pk { background: #ffc107; color: #333; }
        .badge-fk { background: #28a745; color: #fff; }
        .badge-uniq { background: #17a2b8; color: #fff; }
        .badge-notnull { background: #dc3545; color: #fff; }
        .badge-nullable { background: #6c757d; color: #fff; }
        code { background: #e9ecef; padding: 1px 5px; border-radius: 3px; font-size: 0.9em; }
        .unique-info {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            padding: 8px 12px;
            margin: 10px;
            border-radius: 6px;
            font-size: 0.9em;
            color: #004085;
        }
        .view-note {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 8px 12px;
            margin: 10px;
            border-radius: 6px;
            font-size: 0.9em;
            color: #856404;
        }
        .index-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
        }
        .index-item {
            font-size: 0.85em;
            background: #fff;
            border: 1px solid #ddd;
            padding: 4px 10px;
            border-radius: 4px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .bfd-section { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📋 قائمة بيانات نظام CQASSE</h1>
    <p class="subtitle">نظام التقييم الشامل لجودة التعليم المدرسي — قاموس البيانات الكامل</p>
    <p style="text-align:center;color:#7f8c8d;margin-bottom:30px;">
        تم التوليد تلقائياً من قاعدة البيانات بتاريخ ' . date('Y-m-d H:i') . '
    </p>
';

// Summary stats
$totalTables = 0;
foreach ($bfdGroups as $g) $totalTables += count($g['tables']);
$html .= '<div style="background:#fff;border-radius:12px;padding:20px;margin-bottom:30px;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
    <h2 style="margin:0 0 15px 0;">📊 ملخص عام</h2>
    <p>إجمالي الجداول والمشاهد: <strong>' . $totalTables . '</strong></p>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">';
foreach ($bfdGroups as $bid => $g) {
    $html .= '<div style="background:' . $g['color'] . ';color:#fff;padding:8px 15px;border-radius:8px;font-size:0.9em;">
        BFD ' . $bid . ': ' . count($g['tables']) . ' جداول
    </div>';
}
$html .= '    </div></div>';

// ─── Process each BFD group ──────────────────────
$coloredBorders = ['#e74c3c','#3498db','#2ecc71','#9b59b6','#f39c12','#1abc9c'];
$tableNum = 0;

foreach ($bfdGroups as $bid => $group) {
    $html .= '<div class="bfd-section">
        <div class="bfd-header" style="background:' . $group['color'] . ';">
            BFD ' . $bid . ': ' . $group['name'] . '
            <span class="bfd-count">' . count($group['tables']) . ' جدولاً</span>
        </div>';

    $tblIdx = 0;
    foreach ($group['tables'] as $tableName) {
        $tblIdx++;
        $tableNum++;
        $data = getTableMeta($mysqli, $tableName);
        $fks = getFKs($mysqli, $tableName);
        $uniques = getUniques($mysqli, $tableName);
        $meta = $data['meta'];
        $columns = $data['columns'];

        $isView = ($meta && $meta['TABLE_TYPE'] == 'VIEW');
        $rowCount = $meta ? (int)$meta['TABLE_ROWS'] : 0;
        $engine = $meta ? ($meta['ENGINE'] ?: 'VIEW') : 'VIEW';
        $comment = $meta ? $meta['TABLE_COMMENT'] : '';

        $borderColor = $coloredBorders[($bid-1) % 6];

        $html .= '<div class="tbl-card">
            <div class="tbl-header" style="border-bottom-color:' . $borderColor . ';">
                <span>' . $tblIdx . '. ' . $tableName . ' 
                    <span class="tbl-meta">(' . number_format($rowCount) . ' صف — ' . $engine . ')</span>
                </span>
                <span style="font-size:0.9em;color:#555;">' . count($columns) . ' عموداً</span>
            </div>';

        if ($isView) {
            $viewDef = '';
            $res = $mysqli->query("SHOW CREATE VIEW `$tableName`");
            if ($res && $row = $res->fetch_row()) {
                $viewDef = substr($row[1], 0, 300) . '...';
            }
            $html .= '<div class="view-note">🔍 هذا كائن VIEW (مشهد) مبني على استعلام SQL وليس جدولاً فعلياً.</div>';
        }

        if ($comment) {
            $html .= '<div style="padding:8px 12px;color:#555;font-size:0.95em;background:#fef9e7;border-bottom:1px solid #eee;">
                📝 ' . htmlspecialchars($comment) . '
            </div>';
        }

        $html .= '<table>
            <thead>
                <tr>
                    <th style="width:25%;">العمود</th>
                    <th style="width:25%;">نوع البيانات</th>
                    <th style="width:20%;">القيود</th>
                    <th style="width:30%;">الوصف</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($columns as $col) {
            $colName = $col['COLUMN_NAME'];
            $colTypeStr = colType($col);
            $nullable = ($col['IS_NULLABLE'] == 'YES');
            $default = $col['COLUMN_DEFAULT'];
            $extra = $col['EXTRA'];
            $desc = $col['COLUMN_COMMENT'];

            // Determine badges
            $badges = [];
            $rowClass = '';

            // PK check
            $isPk = ($extra == 'auto_increment' && stripos($extra, 'auto_increment') !== false);
            if (!$isPk) {
                $pkCheck = $mysqli->query("SHOW KEYS FROM `$tableName` WHERE Key_name = 'PRIMARY' AND Column_name = '$colName'");
                $isPk = $pkCheck && $pkCheck->num_rows > 0;
            }

            if ($isPk) {
                $badges[] = '<span class="badge badge-pk">PK</span>';
                $rowClass = 'col-pk';
            }

            if (isset($fks[$colName])) {
                $badges[] = '<span class="badge badge-fk">FK → ' . $fks[$colName] . '</span>';
                $rowClass = 'col-fk';
            }

            if (!$isPk && !$nullable) {
                $badges[] = '<span class="badge badge-notnull">NOT NULL</span>';
            }

            if ($nullable && $colName != 'id') {
                $badges[] = '<span class="badge badge-nullable">NULL</span>';
            }

            if ($extra == 'auto_increment') {
                $badges[] = '<span class="badge" style="background:#e67e22;color:#fff;">AUTO_INCREMENT</span>';
            }

            if ($default !== null && $default !== '') {
                $badges[] = '<span class="badge" style="background:#6f42c1;color:#fff;">DEFAULT: ' . htmlspecialchars($default) . '</span>';
            }

            $badgeHtml = implode(' ', $badges);

            $html .= '<tr class="' . $rowClass . '">
                <td><code>' . htmlspecialchars($colName) . '</code></td>
                <td>' . htmlspecialchars($colTypeStr) . '</td>
                <td>' . $badgeHtml . '</td>
                <td>' . htmlspecialchars($desc ?: '') . '</td>
            </tr>';
        }

        $html .= '</tbody></table>';

        // UNIQUE constraints
        if (!empty($uniques)) {
            $html .= '<div class="unique-info">🔑 <strong>القيود الفريدة (UNIQUE):</strong><br>';
            foreach ($uniques as $idxName => $cols) {
                $html .= '🔹 <code>' . $idxName . '</code>: (' . implode(', ', $cols) . ')<br>';
            }
            $html .= '</div>';
        }

        $html .= '</div>'; // tbl-card
    }

    $html .= '</div>'; // bfd-section
}

$mysqli->close();

$html .= '<div style="text-align:center;padding:20px;color:#7f8c8d;font-size:0.9em;">
    <hr style="margin:20px 0;">
    تم إنشاء قاموس البيانات هذا تلقائياً — نظام CQASSE v4.0.0
</div>
</div>
</body>
</html>';

file_put_contents(__DIR__ . '/../docs/data_dictionary.html', $html);
echo "DONE: " . __DIR__ . "/../docs/data_dictionary.html\n";
echo "Generated: " . strlen($html) . " bytes\n";
echo "Tables/Views: " . $totalTables . "\n";
