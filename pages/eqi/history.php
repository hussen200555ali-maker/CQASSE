<?php
/**
 * ============================================================
 * EQAS - تاريخ حسابات المؤشر (محوّل إلى view.php)
 * نظام التقييم الشامل - EQI History Redirect
 * ============================================================
 * تم دمج السجل التاريخي مع صفحة view.php.
 * هذا الملف يحوّل إلى view.php للتوافق مع الروابط القديمة.
 */

require_once __DIR__ . '/../../includes/session.php';

$schoolId = (int)($_GET['school_id'] ?? 0);
$query = $schoolId > 0 ? 'school_id=' . $schoolId : '';
$query .= '&year_id=' . ((int)($_GET['year_id'] ?? 0));
$query .= '&semester_id=' . ((int)($_GET['semester_id'] ?? 0));

$redirectUrl = 'view.php?' . ltrim($query, '&');
header('Location: ' . $redirectUrl);
exit;
