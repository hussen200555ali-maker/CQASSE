<?php
/**
 * ============================================================
 * EQAS - رؤوس الأمان المركزية HTTP Security Headers
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * يُحمَّل في بداية كل طلب PHP عبر config.php
 * يوفر طبقة حماية إضافية ضد الهجمات الشائعة:
 * - Clickjacking (X-Frame-Options)
 * - XSS / Code Injection (Content-Security-Policy)
 * - MIME Sniffing (X-Content-Type-Options)
 * - Protocol Downgrade (HSTS)
 * - Permission Abuse (Permissions-Policy)
 * - Information Leakage (Referrer-Policy)
 * - Cache Sensitivity (Cache-Control)
 * ============================================================
 * [SEC-001] إضافة رؤوس HTTP الأمنية - v2.0.2
 * ============================================================
 */

// منع التنفيذ المباشر
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

// التحقق من أن الرؤوس لم تُرسل بعد (لا يوجد مخرجات سابقة)
if (headers_sent($file, $line)) {
    // إذا كانت الرؤوس أُرسلت بالفعل، نسجل تحذير ولا نحاول إرسالها مجدداً
    error_log("EQAS Security Headers: Cannot set headers - output started at {$file}:{$line}");
    return;
}

// ============================================================================
// 1. منع Clickjacking - حماية من تضمين الصفحة في إطارات خارجية
// ============================================================================
// SAMEORIGIN: يسمح بالتضمين فقط من نفس النطاق
header('X-Frame-Options: SAMEORIGIN');

// ============================================================================
// 2. سياسة أمان المحتوى CSP - حماية من XSS وحقن الأكواد
// ============================================================================
// [SEC-004] v2.0.3: تحسين CSP بإضافة nonce للسكربتات
// إنشاء nonce عشوائي لكل طلب - يُستخدم في الوسم <script nonce="...">
$cspNonce = base64_encode(random_bytes(16));
// تخزين nonce في GLOBALS ليستخدمه header.php والصفحات
$GLOBALS['CSP_NONCE'] = $cspNonce;

// ملاحظة: unsafe-inline مسموح به للتوافق مع الأكواد الداخلية
// unsafe-eval ضروري لـ Chart.js (eval() داخلي)
$csp = "default-src 'self'; " .
       "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
       "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
       "img-src 'self' data: blob:; " .
       "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
       "connect-src 'self' https://cdn.jsdelivr.net; " .
       "frame-ancestors 'self'; " .
       "base-uri 'self'; " .
       "form-action 'self';";
header("Content-Security-Policy: " . $csp);

// ============================================================================
// 3. منع MIME Sniffing - إجبار المتصفح على استخدام نوع المحتوى المُعلن
// ============================================================================
header('X-Content-Type-Options: nosniff');

// ============================================================================
// 4. حماية XSS - تفعيل فلتر XSS في المتصفحات القديمة
// ============================================================================
// ملاحظة: هذا الرأس مهمل في المتصفحات الحديثة لكن يُبقي للتوافق
header('X-XSS-Protection: 1; mode=block');

// ============================================================================
// 5. سياسة الإحالة - التحكم في معلومات الإحالة المُرسلة
// ============================================================================
// strict-origin-when-cross-origin: يرسل المصدر الكامل للطلبات نفس النطاق،
// والمصدر فقط للطلبات عبر النطاقات
header('Referrer-Policy: strict-origin-when-cross-origin');

// ============================================================================
// 6. سياسة الصلاحيات - تعطيل الصلاحيات غير المستخدمة
// ============================================================================
// تعطيل: الموقع الجغرافي، الكاميرا، الميكروفون (لا يحتاجها النظام)
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");

// ============================================================================
// 7. HSTS - إجبار استخدام HTTPS (للمحلات HTTPS فقط)
// ============================================================================
// max-age=31536000: سنة كاملة
// includeSubDomains: يشمل جميع النطاقات الفرعية
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ============================================================================
// 8. منع التخزين المؤقت - حماية البيانات الحساسة
// ============================================================================
// no-store: لا يخزن أي شيء
// no-cache: يتحقق من الخادم قبل استخدام النسخة المخزنة
// must-revalidate: يعيد التحقق دائماً
// max-age=0: تنتهي الصلاحية فوراً
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// Expires في الماضي - يمنع التخزين المؤقت في المتصفحات القديمة
header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
