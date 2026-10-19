<?php
/**
 * ============================================================
 * EQAS - معالج الأخطاء والاستثناءات المركزي
 * نظام التقييم الشامل لجودة التعليم المدرسي
 * ============================================================
 * - يسجّل الأخطاء في logs/app.log
 * - يمنع تسريب التفاصيل في الإنتاج
 * - يعرض صفحة خطأ عربية موحّدة مع رقم مرجعي
 * - يحوّل E_USER_ERROR إلى استثناء (حتى لا يستمر التنفيذ)
 *
 * التحميل: في بداية كل نقطة دخول (قبل config.php إن أمكن).
 * ============================================================
 */

if (defined('EQAS_ERROR_HANDLER')) {
    return;
}
define('EQAS_ERROR_HANDLER', true);

// ---------------------------------------------------------------------------
// تسجيل الأخطاء في ملف خاص بالمشروع
// ---------------------------------------------------------------------------
$eqasLogDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($eqasLogDir)) {
    @mkdir($eqasLogDir, 0775, true);
}
if (is_dir($eqasLogDir) && is_writable($eqasLogDir)) {
    @ini_set('log_errors', '1');
    @ini_set('error_log', $eqasLogDir . DIRECTORY_SEPARATOR . 'app.log');
}

/**
 * توليد رقم مرجعي قصير للخطأ لعرضه للمستخدم وربطه بالسجل.
 */
$eqasErrorId = static function () {
    try {
        return 'EQAS-' . strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable $e) {
        return 'EQAS-' . strtoupper(dechex(mt_rand()));
    }
};

/**
 * بناء رابط العودة الآمن حتى قبل تحميل config.php.
 */
$eqasHomeUrl = static function () {
    if (defined('EQAS_URL') && EQAS_URL !== '') {
        return rtrim(EQAS_URL, '/') . '/login.php';
    }
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $projRoot = str_replace('\\', '/', dirname(__DIR__));
    $basePath = ($docRoot !== '' && strpos($projRoot, $docRoot) === 0)
        ? rtrim(substr($projRoot, strlen($docRoot)), '/')
        : '';
    return $basePath . '/login.php';
};

$eqasIsDev = static function () {
    return defined('EQAS_ENV') && EQAS_ENV === 'development';
};

$eqasErrorLabel = static function ($severity) {
    $labels = [
        E_ERROR             => 'ERROR',
        E_WARNING           => 'WARNING',
        E_PARSE             => 'PARSE',
        E_NOTICE            => 'NOTICE',
        E_CORE_ERROR        => 'CORE_ERROR',
        E_CORE_WARNING      => 'CORE_WARNING',
        E_COMPILE_ERROR     => 'COMPILE_ERROR',
        E_COMPILE_WARNING   => 'COMPILE_WARNING',
        E_USER_ERROR        => 'USER_ERROR',
        E_USER_WARNING      => 'USER_WARNING',
        E_USER_NOTICE       => 'USER_NOTICE',
        E_RECOVERABLE_ERROR => 'RECOVERABLE',
        E_DEPRECATED        => 'DEPRECATED',
        E_USER_DEPRECATED   => 'USER_DEPRECATED',
    ];
    return $labels[$severity] ?? ('E_' . $severity);
};

/**
 * تحويل الأخطاء إلى سجل موحّد.
 * ملاحظة: لا نرمي استثناءً من الأخطاء العادية (Warnings/Notices) لتجنّب
 * تعطيل صفحات تعتمد على سلوك PHP الافتراضي؛ نكتفي بالسجل.
 */
set_error_handler(static function ($severity, $message, $file = '', $line = 0) use ($eqasErrorLabel, $eqasIsDev) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    error_log(sprintf(
        '[EQAS][%s] %s in %s:%d',
        $eqasErrorLabel($severity),
        $message,
        $file,
        $line
    ));

    // الأخطاء الجسيمة يجب أن توقف التنفيذ (مثل trigger_error في config.production.php)
    if ($severity === E_USER_ERROR || $severity === E_RECOVERABLE_ERROR) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    // في التطوير: اترك PHP يعرض الخطأ. في الإنتاج: امنع العرض.
    return !$eqasIsDev();
});

/**
 * صفحة خطأ موحّدة (تُستخدم للاستثناءات والأخطاء القاتلة).
 */
$eqasRenderErrorPage = static function ($id, $detail = '') use ($eqasHomeUrl) {
    $home = htmlspecialchars($eqasHomeUrl(), ENT_QUOTES, 'UTF-8');
    $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    $safeDetail = $detail !== ''
        ? '<pre style="text-align:left;background:#0f172a;padding:12px;border-radius:8px;overflow:auto;color:#fca5a5;white-space:pre-wrap;">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>'
        : '';

    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>خطأ في النظام</title><style>'
        . 'body{font-family:"Segoe UI",Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}'
        . '.box{background:#1e293b;border:1px solid #334155;border-radius:14px;padding:32px;max-width:620px;width:100%;text-align:center}'
        . 'h1{margin:0 0 12px;font-size:22px}p{color:#94a3b8;line-height:1.8}'
        . '.id{display:inline-block;margin-top:14px;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px 14px;font-family:monospace;color:#38bdf8}'
        . 'a{display:inline-block;margin-top:18px;color:#38bdf8;text-decoration:none}'
        . '</style></head><body><div class="box">'
        . '<h1>حدث خطأ غير متوقع</h1>'
        . '<p>تم تسجيل الخطأ لدى إدارة النظام. يرجى المحاولة لاحقاً أو التواصل مع الدعم مع ذكر الرقم المرجعي أدناه.</p>';

    if (defined('EQAS_ENV') && EQAS_ENV === 'development' && $detail !== '') {
        echo $safeDetail;
    }

    echo '<div class="id">' . $safeId . '</div><br>'
        . '<a href="' . $home . '">العودة لتسجيل الدخول</a>'
        . '</div></body></html>';
};

set_exception_handler(static function (Throwable $e) use ($eqasErrorId, $eqasRenderErrorPage, $eqasIsDev) {
    $id = $eqasErrorId();
    error_log(sprintf(
        "[EQAS][%s] Uncaught %s: %s in %s:%d\n%s",
        $id,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    $detail = '';
    if ($eqasIsDev()) {
        $detail = get_class($e) . ': ' . $e->getMessage()
            . "\n" . $e->getFile() . ':' . $e->getLine();
    }

    $eqasRenderErrorPage($id, $detail);
    exit;
});

register_shutdown_function(static function () use ($eqasErrorId, $eqasRenderErrorPage) {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!$error || !in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $id = $eqasErrorId();
    error_log(sprintf(
        '[EQAS][%s] Fatal: %s in %s:%d',
        $id,
        $error['message'],
        $error['file'],
        $error['line']
    ));

    if (headers_sent()) {
        return;
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $eqasRenderErrorPage($id);
});
