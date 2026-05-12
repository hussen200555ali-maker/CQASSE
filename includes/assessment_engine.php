<?php
/**
 * ============================================================
 * EQAS - محرك التقييم الموحد
 * نظام التقييم الشامل لجودة التعليم المدرسي 
 * ============================================================
 * 
 * يحتوي هذا الملف على المحرك الأساسي للتقييم الذي يدعم 10 أنواع أدوات:
 * - مقياس ليكرت (5 مستويات)
 * - مقياس التكرار (5 مستويات)
 * - مقياس الرضا (5 مستويات)
 * - نعم / لا
 * - مقياس الشدة (4 مستويات) - عكسي
 * - عداد
 * - رقمي (0-100)
 * - رقمي (نطاق مخصص)
 * - روبريك (4 مستويات)
 * - نص حر
 */

/**
 * فئة محرك التقييم الأساسية
 * تتعامل مع جميع عمليات التقييم من الإدخال إلى الحساب والتخزين
 */
class AssessmentEngine {
    /** @var PDO اتصال قاعدة البيانات */
    private $db;
    
    // ثوابت أنواع الأدوات العشرة
    const TOOL_LIKERT_5 = 'likert_5';           // مقياس ليكرت (1-5)
    const TOOL_FREQUENCY_5 = 'frequency_5';      // مقياس التكرار (1=أبداً إلى 5=دائماً)
    const TOOL_SATISFACTION_5 = 'satisfaction_5'; // مقياس الرضا (1=غير راضٍ جداً إلى 5=راضٍ جداً)
    const TOOL_YES_NO = 'yes_no';                // نعم / لا (0/1)
    const TOOL_SEVERITY_4 = 'severity_4';        // مقياس الشدة (4=لا يوجد، 3=خفيف، 2=متوسط، 1=شديد) - عكسي!
    const TOOL_COUNTER = 'counter';              // عداد (رقم صحيح غير سالب)
    const TOOL_NUMERIC_100 = 'numeric_100';      // رقمي (0-100)
    const TOOL_NUMERIC_CUSTOM = 'numeric_custom'; // رقمي (نطاق مخصص)
    const TOOL_RUBRIC_4 = 'rubric_4';            // روبريك (4 مستويات)
    const TOOL_TEXT = 'text';                     // نص حر (بدون درجة)
    
    /**
     * إنشاء كائن محرك التقييم
     * @param PDO $db اتصال قاعدة البيانات
     */
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * عرض حقل إدخال أداة التقييم
     * يعيد نص HTML لعنصر الإدخال المناسب
     * 
     * @param array $item بيانات عنصر التقييم (text_ar, tool_type, max_value, is_inverse, weight)
     * @param mixed $value القيمة الحالية (اختياري)
     * @param string $prefix بادئة اسم الحقل (اختياري)
     * @return string كود HTML لعنصر الإدخال
     */
    public function renderTool($item, $value = null, $prefix = 'item_') {
        $toolType = $item['tool_type'];
        $itemId = $item['id'];
        $fieldName = $prefix . $itemId;
        $currentValue = $value !== null ? $value : '';
        $html = '';
        
        switch ($toolType) {
            case self::TOOL_LIKERT_5:
                $html = $this->renderLikert5($fieldName, $itemId, $item['text_ar'], $currentValue, $item['is_inverse']);
                break;
                
            case self::TOOL_FREQUENCY_5:
                $html = $this->renderFrequency5($fieldName, $itemId, $item['text_ar'], $currentValue);
                break;
                
            case self::TOOL_SATISFACTION_5:
                $html = $this->renderSatisfaction5($fieldName, $itemId, $item['text_ar'], $currentValue);
                break;
                
            case self::TOOL_YES_NO:
                $html = $this->renderYesNo($fieldName, $itemId, $item['text_ar'], $currentValue);
                break;
                
            case self::TOOL_SEVERITY_4:
                $html = $this->renderSeverity4($fieldName, $itemId, $item['text_ar'], $currentValue);
                break;
                
            case self::TOOL_COUNTER:
                $html = $this->renderCounter($fieldName, $itemId, $item['text_ar'], $currentValue, $item['max_value']);
                break;
                
            case self::TOOL_NUMERIC_100:
                $html = $this->renderNumeric100($fieldName, $itemId, $item['text_ar'], $currentValue);
                break;
                
            case self::TOOL_NUMERIC_CUSTOM:
                $html = $this->renderNumericCustom($fieldName, $itemId, $item['text_ar'], $currentValue, $item['max_value']);
                break;
                
            case self::TOOL_RUBRIC_4:
                $html = $this->renderRubric4($fieldName, $itemId, $item['text_ar'], $currentValue);
                break;
                
            case self::TOOL_TEXT:
                $html = $this->renderText($fieldName, $itemId, $item['text_ar'], $currentValue);
                break;
                
            default:
                $html = '<div class="form-group"><label class="form-label">' . e($item['text_ar']) . '</label>';
                $html .= '<input type="text" name="' . $fieldName . '" class="form-control" value="' . e($currentValue) . '">';
                $html .= '</div>';
        }
        
        return $html;
    }
    
    /**
     * عرض مقياس ليكرت 5 مستويات
     */
    private function renderLikert5($fieldName, $itemId, $text, $value, $isInverse = 0) {
        $options = [
            5 => 'موافق بشدة',
            4 => 'موافق',
            3 => 'محايد',
            2 => 'غير موافق',
            1 => 'غير موافق بشدة'
        ];
        $inverseBadge = $isInverse ? ' <span class="badge badge-warning" style="font-size:10px;">عكسي</span>' : '';
        
        $html = '<div class="form-group assessment-item" data-tool="likert_5" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . $inverseBadge . '</label>';
        $html .= '<div class="likert-scale flex items-center gap-4 flex-wrap">';
        foreach ($options as $val => $label) {
            $checked = ($value !== null && $value !== '' && $value == $val) ? 'checked' : '';
            $html .= '<label class="form-check" style="min-width:120px;">';
            $html .= '<input type="radio" name="' . $fieldName . '" value="' . $val . '" ' . $checked . ' class="form-check-input" data-tool-type="likert_5" data-weight="1">';
            $html .= '<span class="form-check-label">' . $val . ' - ' . $label . '</span>';
            $html .= '</label>';
        }
        $html .= '</div></div>';
        return $html;
    }
    
    /**
     * عرض مقياس التكرار 5 مستويات
     */
    private function renderFrequency5($fieldName, $itemId, $text, $value) {
        $options = [
            5 => 'دائماً',
            4 => 'غالباً',
            3 => 'أحياناً',
            2 => 'نادراً',
            1 => 'أبداً'
        ];
        
        $html = '<div class="form-group assessment-item" data-tool="frequency_5" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<div class="likert-scale flex items-center gap-4 flex-wrap">';
        foreach ($options as $val => $label) {
            $checked = ($value !== null && $value !== '' && $value == $val) ? 'checked' : '';
            $html .= '<label class="form-check" style="min-width:110px;">';
            $html .= '<input type="radio" name="' . $fieldName . '" value="' . $val . '" ' . $checked . ' class="form-check-input" data-tool-type="frequency_5" data-weight="1">';
            $html .= '<span class="form-check-label">' . $val . ' - ' . $label . '</span>';
            $html .= '</label>';
        }
        $html .= '</div></div>';
        return $html;
    }
    
    /**
     * عرض مقياس الرضا 5 مستويات
     */
    private function renderSatisfaction5($fieldName, $itemId, $text, $value) {
        $options = [
            5 => 'راضٍ جداً',
            4 => 'راضٍ',
            3 => 'محايد',
            2 => 'غير راضٍ',
            1 => 'غير راضٍ جداً'
        ];
        
        $html = '<div class="form-group assessment-item" data-tool="satisfaction_5" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<div class="likert-scale flex items-center gap-4 flex-wrap">';
        foreach ($options as $val => $label) {
            $checked = ($value !== null && $value !== '' && $value == $val) ? 'checked' : '';
            $html .= '<label class="form-check" style="min-width:120px;">';
            $html .= '<input type="radio" name="' . $fieldName . '" value="' . $val . '" ' . $checked . ' class="form-check-input" data-tool-type="satisfaction_5" data-weight="1">';
            $html .= '<span class="form-check-label">' . $val . ' - ' . $label . '</span>';
            $html .= '</label>';
        }
        $html .= '</div></div>';
        return $html;
    }
    
    /**
     * عرض خيار نعم/لا
     */
    private function renderYesNo($fieldName, $itemId, $text, $value) {
        $html = '<div class="form-group assessment-item" data-tool="yes_no" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<div class="flex items-center gap-6">';
        $checkedYes = ($value !== null && $value !== '' && $value == 1) ? 'checked' : '';
        $checkedNo = ($value !== null && $value !== '' && $value == 0) ? 'checked' : '';
        $html .= '<label class="form-check"><input type="radio" name="' . $fieldName . '" value="1" ' . $checkedYes . ' class="form-check-input" data-tool-type="yes_no" data-weight="1"><span class="form-check-label">نعم</span></label>';
        $html .= '<label class="form-check"><input type="radio" name="' . $fieldName . '" value="0" ' . $checkedNo . ' class="form-check-input" data-tool-type="yes_no" data-weight="1"><span class="form-check-label">لا</span></label>';
        $html .= '</div></div>';
        return $html;
    }
    
    /**
     * عرض مقياس الشدة 4 مستويات (عكسي!)
     * 4 = لا يوجد، 3 = خفيف، 2 = متوسط، 1 = شديد
     * كلما زادت القيمة الخام كان أفضل (أقل شدة)
     */
    private function renderSeverity4($fieldName, $itemId, $text, $value) {
        $options = [
            4 => 'لا يوجد',
            3 => 'خفيف',
            2 => 'متوسط',
            1 => 'شديد'
        ];
        
        $html = '<div class="form-group assessment-item" data-tool="severity_4" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . ' <span class="badge badge-warning" style="font-size:10px;">عكسي</span></label>';
        $html .= '<div class="likert-scale flex items-center gap-4 flex-wrap">';
        foreach ($options as $val => $label) {
            $checked = ($value !== null && $value !== '' && $value == $val) ? 'checked' : '';
            $html .= '<label class="form-check" style="min-width:100px;">';
            $html .= '<input type="radio" name="' . $fieldName . '" value="' . $val . '" ' . $checked . ' class="form-check-input" data-tool-type="severity_4" data-weight="1" data-inverse="1">';
            $html .= '<span class="form-check-label">' . $val . ' - ' . $label . '</span>';
            $html .= '</label>';
        }
        $html .= '</div></div>';
        return $html;
    }
    
    /**
     * عرض حقل العداد
     */
    private function renderCounter($fieldName, $itemId, $text, $value, $maxValue = null) {
        $currentValue = $value ?? '';
        $maxAttr = $maxValue ? 'max="' . (int)$maxValue . '"' : '';
        $helpText = $maxValue ? 'الحد الأقصى: ' . (int)$maxValue : '';
        
        $html = '<div class="form-group assessment-item" data-tool="counter" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<input type="number" name="' . $fieldName . '" value="' . e($currentValue) . '" min="0" ' . $maxAttr . ' class="form-control" style="max-width:200px;" data-tool-type="counter" data-weight="1">';
        if ($helpText) {
            $html .= '<span class="form-text">' . $helpText . '</span>';
        }
        $html .= '</div>';
        return $html;
    }
    
    /**
     * عرض حقل رقمي 0-100
     */
    private function renderNumeric100($fieldName, $itemId, $text, $value) {
        $html = '<div class="form-group assessment-item" data-tool="numeric_100" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<input type="number" name="' . $fieldName . '" value="' . e($value ?? '') . '" min="0" max="100" class="form-control" style="max-width:200px;" data-tool-type="numeric_100" data-weight="1">';
        $html .= '<span class="form-text">القيمة من 0 إلى 100</span>';
        $html .= '</div>';
        return $html;
    }
    
    /**
     * عرض حقل رقمي بنطاق مخصص
     */
    private function renderNumericCustom($fieldName, $itemId, $text, $value, $maxValue = null) {
        $max = $maxValue ? (int)$maxValue : 100;
        $html = '<div class="form-group assessment-item" data-tool="numeric_custom" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<input type="number" name="' . $fieldName . '" value="' . e($value ?? '') . '" min="0" max="' . $max . '" class="form-control" style="max-width:200px;" data-tool-type="numeric_custom" data-weight="1" data-max-value="' . $max . '">';
        $html .= '<span class="form-text">القيمة من 0 إلى ' . $max . '</span>';
        $html .= '</div>';
        return $html;
    }
    
    /**
     * عرض مقياس الروبريك 4 مستويات
     */
    private function renderRubric4($fieldName, $itemId, $text, $value) {
        $options = [
            4 => 'متميز',
            3 => 'جيد',
            2 => 'مقبول',
            1 => 'ضعيف'
        ];
        
        $html = '<div class="form-group assessment-item" data-tool="rubric_4" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<div class="likert-scale flex items-center gap-4 flex-wrap">';
        foreach ($options as $val => $label) {
            $checked = ($value !== null && $value !== '' && $value == $val) ? 'checked' : '';
            $html .= '<label class="form-check" style="min-width:100px;">';
            $html .= '<input type="radio" name="' . $fieldName . '" value="' . $val . '" ' . $checked . ' class="form-check-input" data-tool-type="rubric_4" data-weight="1">';
            $html .= '<span class="form-check-label">' . $val . ' - ' . $label . '</span>';
            $html .= '</label>';
        }
        $html .= '</div></div>';
        return $html;
    }
    
    /**
     * عرض حقل النص الحر
     */
    private function renderText($fieldName, $itemId, $text, $value) {
        $html = '<div class="form-group assessment-item" data-tool="text" data-item-id="' . $itemId . '">';
        $html .= '<label class="form-label">' . e($text) . '</label>';
        $html .= '<textarea name="' . $fieldName . '" class="form-control" rows="3" data-tool-type="text">' . e($value ?? '') . '</textarea>';
        $html .= '</div>';
        return $html;
    }
    
    /**
     * التحقق من صحة قيمة استجابة أداة التقييم
     * 
     * @param string $toolType نوع الأداة
     * @param mixed $value القيمة المدخلة
     * @param float|null $maxValue القيمة القصوى (للأدوات الرقمية المخصصة والعداد)
     * @return array ['valid' => bool, 'error' => string, 'value' => mixed]
     */
    public function validateTool($toolType, $value, $maxValue = null) {
        $result = ['valid' => false, 'error' => '', 'value' => null];
        
        // النص الحر دائماً صحيح
        if ($toolType === self::TOOL_TEXT) {
            $result['valid'] = true;
            $result['value'] = is_string($value) ? trim($value) : '';
            return $result;
        }
        
        // التحقق من أن القيمة غير فارغة
        if ($value === null || $value === '') {
            $result['error'] = 'هذا الحقل مطلوب';
            return $result;
        }
        
        switch ($toolType) {
            case self::TOOL_LIKERT_5:
            case self::TOOL_FREQUENCY_5:
            case self::TOOL_SATISFACTION_5:
                $val = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
                if ($val === false) {
                    $result['error'] = 'يرجى اختيار قيمة من 1 إلى 5';
                } else {
                    $result['valid'] = true;
                    $result['value'] = $val;
                }
                break;
                
            case self::TOOL_YES_NO:
                if ($value === '1' || $value === 1 || $value === true) {
                    $result['valid'] = true;
                    $result['value'] = 1;
                } elseif ($value === '0' || $value === 0 || $value === false) {
                    $result['valid'] = true;
                    $result['value'] = 0;
                } else {
                    $result['error'] = 'يرجى اختيار نعم أو لا';
                }
                break;
                
            case self::TOOL_SEVERITY_4:
                $val = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
                if ($val === false) {
                    $result['error'] = 'يرجى اختيار قيمة من 1 إلى 4';
                } else {
                    $result['valid'] = true;
                    $result['value'] = $val;
                }
                break;
                
            case self::TOOL_COUNTER:
                $val = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($val === false) {
                    $result['error'] = 'يرجى إدخال رقم صحيح غير سالب';
                } elseif ($maxValue !== null && $val > $maxValue) {
                    $result['error'] = 'القيمة لا يجب أن تتجاوز ' . $maxValue;
                } else {
                    $result['valid'] = true;
                    $result['value'] = $val;
                }
                break;
                
            case self::TOOL_NUMERIC_100:
                $val = filter_var($value, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
                if ($val === false) {
                    $result['error'] = 'يرجى إدخال رقم من 0 إلى 100';
                } else {
                    $result['valid'] = true;
                    $result['value'] = round($val, 2);
                }
                break;
                
            case self::TOOL_NUMERIC_CUSTOM:
                $max = $maxValue !== null ? (float)$maxValue : 100;
                $val = filter_var($value, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
                if ($val === false) {
                    $result['error'] = 'يرجى إدخال رقم من 0 إلى ' . $max;
                } else {
                    $result['valid'] = true;
                    $result['value'] = round($val, 2);
                }
                break;
                
            case self::TOOL_RUBRIC_4:
                $val = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
                if ($val === false) {
                    $result['error'] = 'يرجى اختيار مستوى من 1 إلى 4';
                } else {
                    $result['valid'] = true;
                    $result['value'] = $val;
                }
                break;
                
            default:
                $result['error'] = 'نوع أداة غير معروف: ' . $toolType;
        }
        
        return $result;
    }
    
    /**
     * تطبيع قيمة استجابة الأداة إلى مقياس 0-100
     * 
     * @param string $toolType نوع الأداة
     * @param mixed $value القيمة الخام
     * @param float|null $maxValue القيمة القصوى
     * @param bool $isInverse هل العنصر معكوس
     * @return float|null الدرجة المطبعة (0-100) أو null للنص الحر
     */
    public function normalizeTool($toolType, $value, $maxValue = null, $isInverse = false) {
        // النص الحر لا يُقيّم رقمياً
        if ($toolType === self::TOOL_TEXT) {
            return null;
        }
        
        // إذا كانت القيمة فارغة
        if ($value === null || $value === '') {
            return 0;
        }
        
        $normalized = 0;
        
        switch ($toolType) {
            case self::TOOL_LIKERT_5:
            case self::TOOL_FREQUENCY_5:
            case self::TOOL_SATISFACTION_5:
                // القيمة / 5 * 100
                $normalized = ((float)$value / 5) * 100;
                break;
                
            case self::TOOL_YES_NO:
                // 0 أو 100
                $normalized = (float)$value * 100;
                break;
                
            case self::TOOL_SEVERITY_4:
                // عكسي! (القيمة - 1) / 3 * 100
                // كلما زادت القيمة الخام كان أفضل (أقل شدة): 4=لايوجد→100%, 1=شديد→0%
                // [إصلاح 2026-05-11] كانت الصيغة ((4-value)/3)*100 معكوسة - تصحح إلى ((value-1)/3)*100
                $normalized = (((float)$value - 1) / 3) * 100;
                break;
                
            case self::TOOL_COUNTER:
                // إذا وجدت قيمة قصوى: min(value, max) / max * 100
                // وإلا: القيمة كما هي
                if ($maxValue !== null && $maxValue > 0) {
                    $normalized = (min((float)$value, (float)$maxValue) / (float)$maxValue) * 100;
                } else {
                    $normalized = (float)$value;
                }
                break;
                
            case self::TOOL_NUMERIC_100:
                // القيمة كما هي (0-100 بالفعل)
                $normalized = (float)$value;
                break;
                
            case self::TOOL_NUMERIC_CUSTOM:
                // القيمة / القيمة القصوى * 100
                $max = $maxValue !== null ? (float)$maxValue : 100;
                if ($max > 0) {
                    $normalized = ((float)$value / $max) * 100;
                } else {
                    $normalized = 0;
                }
                break;
                
            case self::TOOL_RUBRIC_4:
                // القيمة / 4 * 100
                $normalized = ((float)$value / 4) * 100;
                break;
                
            default:
                $normalized = 0;
        }
        
        // تطبيق الانعكاس إذا كان العنصر معكوساً
        // ملاحظة: severity_4 يعكس داخلياً بالفعل، لكن يمكن عكسه مرة أخرى إذا isInverse
        if ($isInverse && $toolType !== self::TOOL_SEVERITY_4) {
            $normalized = 100 - $normalized;
        }
        
        // التأكد من أن القيمة بين 0 و 100
        return max(0, min(100, round($normalized, 2)));
    }
    
    /**
     * حساب الدرجة الكلية لمجموعة استجابات
     * المتوسط المرجح للدرجات المطبعة
     * 
     * @param array $responses مصفوفة الاستجابات [['weight' => w, 'normalized' => n], ...]
     * @return float الدرجة الكلية (0-100)
     */
    public function calculateScore($responses) {
        if (empty($responses)) {
            return 0;
        }
        
        $totalWeight = 0;
        $weightedSum = 0;
        
        foreach ($responses as $response) {
            $weight = (float)($response['weight'] ?? 1);
            $normalized = (float)($response['normalized'] ?? 0);
            
            // تجاهل القيم الفارغة (مثل النص الحر)
            if ($response['normalized'] === null) {
                continue;
            }
            
            $weightedSum += $weight * $normalized;
            $totalWeight += $weight;
        }
        
        if ($totalWeight <= 0) {
            return 0;
        }
        
        return round(($weightedSum / $totalWeight), 2);
    }
    
    /**
     * الحصول على تسمية الأداة بالعربية
     * 
     * @param string $toolType نوع الأداة
     * @return string التسمية العربية
     */
    public function getToolLabel($toolType) {
        $labels = [
            'likert_5' => 'مقياس ليكرت (5 مستويات)',
            'frequency_5' => 'مقياس التكرار (5 مستويات)',
            'satisfaction_5' => 'مقياس الرضا (5 مستويات)',
            'yes_no' => 'نعم / لا',
            'severity_4' => 'مقياس الشدة (4 مستويات) - عكسي',
            'counter' => 'عداد',
            'numeric_100' => 'رقمي (0-100)',
            'numeric_custom' => 'رقمي (نطاق مخصص)',
            'rubric_4' => 'روبريك (4 مستويات)',
            'text' => 'نص حر'
        ];
        return $labels[$toolType] ?? $toolType;
    }
    
    /**
     * الحصول على خيارات الأدوات لقائمة منسدلة
     * 
     * @return array مصفوفة للقائمة المنسدلة HTML
     */
    public function getToolOptions() {
        return [
            'likert_5' => 'مقياس ليكرت (5 مستويات)',
            'frequency_5' => 'مقياس التكرار (5 مستويات)',
            'satisfaction_5' => 'مقياس الرضا (5 مستويات)',
            'yes_no' => 'نعم / لا',
            'severity_4' => 'مقياس الشدة (4 مستويات) - عكسي',
            'counter' => 'عداد',
            'numeric_100' => 'رقمي (0-100)',
            'numeric_custom' => 'رقمي (نطاق مخصص)',
            'rubric_4' => 'روبريك (4 مستويات)',
            'text' => 'نص حر'
        ];
    }
    
    /**
     * الحصول على خيارات أنواع النماذج لقائمة منسدلة
     * 
     * @return array مصفوفة أنواع النماذج
     */
    public function getFormTypeOptions() {
        return [
            'behavior_eval' => 'التقييم السلوكي',
            'skills_checklist' => 'قائمة المهارات',
            'teaching_self' => 'التقييم الذاتي للتدريس',
            'teaching_peer' => 'تقييم الأقران للتدريس',
            'teaching_admin' => 'التقييم الإشرافي للتدريس',
            'climate_survey' => 'استبيان المناخ المدرسي',
            'facility_checklist' => 'فحص المرافق المدرسية'
        ];
    }
    
    /**
     * الحصول على تسمية نوع النموذج
     * 
     * @param string $formType نوع النموذج
     * @return string التسمية العربية
     */
    public function getFormTypeLabel($formType) {
        $options = $this->getFormTypeOptions();
        return $options[$formType] ?? $formType;
    }
    
    /**
     * الحصول على أيقونة نوع النموذج
     * 
     * @param string $formType نوع النموذج
     * @return string الأيقونة
     */
    public function getFormTypeIcon($formType) {
        $icons = [
            'behavior_eval' => '🎯',
            'skills_checklist' => '✅',
            'teaching_self' => '🪞',
            'teaching_peer' => '👥',
            'teaching_admin' => '👔',
            'climate_survey' => '🌡️',
            'facility_checklist' => '🏫'
        ];
        return $icons[$formType] ?? '📋';
    }
    
    /** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
    /**
     * حفظ استجابة تقييم في الجدول الموحد
     * 
     * @param array $data بيانات الاستجابة
     * @return int|false معرف الاستجابة أو false
     */
    public function saveResponse($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO assessment_responses 
                (form_id, item_id, student_id, teacher_id, class_id, 
                 academic_year_id, semester_id, school_id, 
                 numeric_value, text_value, responded_by, response_date, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
            ");
            $stmt->execute([
                $data['form_id'],
                $data['item_id'],
                $data['student_id'] ?? null,
                $data['teacher_id'] ?? null,
                $data['class_id'] ?? null,
                $data['academic_year_id'],
                $data['semester_id'],
                $data['school_id'],
                $data['numeric_value'] ?? null,
                $data['text_value'] ?? null,
                $data['responded_by'] ?? null
            ]);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('خطأ في حفظ الاستجابة: ' . $e->getMessage());
            return false;
        }
    }
    
    /** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
    /**
     * الحصول على استجابات تقييم بناءً على فلاتر
     * 
     * @param array $filters الفلاتر (form_id, student_id, teacher_id, class_id, semester_id, etc.)
     * @return array مصفوفة الاستجابات
     */
    public function getResponses($filters) {
        $sql = "SELECT ar.*, ai.text_ar, ai.tool_type, ai.weight, ai.is_inverse, ai.max_value
                FROM assessment_responses ar
                JOIN assessment_items ai ON ar.item_id = ai.id
                WHERE ar.is_active = 1";
        $params = [];
        
        if (!empty($filters['form_id'])) {
            $sql .= " AND ar.form_id = ?";
            $params[] = $filters['form_id'];
        }
        if (!empty($filters['student_id'])) {
            $sql .= " AND ar.student_id = ?";
            $params[] = $filters['student_id'];
        }
        if (!empty($filters['teacher_id'])) {
            $sql .= " AND ar.teacher_id = ?";
            $params[] = $filters['teacher_id'];
        }
        if (!empty($filters['class_id'])) {
            $sql .= " AND ar.class_id = ?";
            $params[] = $filters['class_id'];
        }
        if (!empty($filters['semester_id'])) {
            $sql .= " AND ar.semester_id = ?";
            $params[] = $filters['semester_id'];
        }
        if (!empty($filters['academic_year_id'])) {
            $sql .= " AND ar.academic_year_id = ?";
            $params[] = $filters['academic_year_id'];
        }
        if (!empty($filters['school_id'])) {
            $sql .= " AND ar.school_id = ?";
            $params[] = $filters['school_id'];
        }
        if (!empty($filters['responded_by'])) {
            $sql .= " AND ar.responded_by = ?";
            $params[] = $filters['responded_by'];
        }
        
        $sql .= " ORDER BY ai.sort_order ASC, ar.response_date DESC";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('خطأ في جلب الاستجابات: ' . $e->getMessage());
            return [];
        }
    }
    
    /** @deprecated-v2.0.3 Candidate for removal — verify no callers before deleting */
    /**
     * حذف استجابة (حذف ناعم)
     * 
     * @param int $id معرف الاستجابة
     * @return bool نجاح أو فشل
     */
    public function deleteResponse($id) {
        try {
            $stmt = $this->db->prepare("UPDATE assessment_responses SET is_active = 0 WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (Exception $e) {
            error_log('خطأ في حذف الاستجابة: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * الحصول على نموذج تقييم مع فئاته وعناصره
     * 
     * @param int $formId معرف النموذج
     * @return array|false بيانات النموذج أو false
     */
    public function getFormWithItems($formId) {
        try {
            // جلب بيانات النموذج
            $stmt = $this->db->prepare("SELECT * FROM assessment_forms WHERE id = ? AND is_active = 1");
            $stmt->execute([$formId]);
            $form = $stmt->fetch();
            
            if (!$form) return false;
            
            // جلب المعايير (الفئات)
            // [إصلاح 2026-05-11] assessment_categories → assessment_criteria (تطابق schema)
            $stmt = $this->db->prepare("SELECT * FROM assessment_criteria WHERE form_id = ? AND is_active = 1 ORDER BY sort_order ASC");
            $stmt->execute([$formId]);
            $categories = $stmt->fetchAll();
            
            // جلب العناصر لكل فئة
            // [إصلاح 2026-05-11] category_id → criteria_id (تطابق schema)
            foreach ($categories as &$category) {
                $stmt = $this->db->prepare("SELECT * FROM assessment_items WHERE criteria_id = ? AND form_id = ? AND is_active = 1 ORDER BY sort_order ASC");
                $stmt->execute([$category['id'], $formId]);
                $category['items'] = $stmt->fetchAll();
            }
            
            $form['categories'] = $categories;
            return $form;
        } catch (Exception $e) {
            error_log('خطأ في جلب النموذج: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * الحصول على جميع النماذج النشطة
     * 
     * @param string|null $formType نوع النموذج (اختياري)
     * @return array مصفوفة النماذج
     */
    public function getForms($formType = null) {
        $sql = "SELECT af.*, 
                (SELECT COUNT(*) FROM assessment_criteria WHERE form_id = af.id AND is_active = 1) as category_count,
                (SELECT COUNT(*) FROM assessment_items WHERE form_id = af.id AND is_active = 1) as item_count
                FROM assessment_forms af 
                WHERE af.is_active = 1";
        $params = [];
        
        if ($formType) {
            // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
            $sql .= " AND af.form_key = ?";
            $params[] = $formType;
        }
        
        $sql .= " ORDER BY af.created_at DESC";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log('خطأ في جلب النماذج: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * الحصول على الدرجة التقديرية بالعربية
     * 
     * @param float $score الدرجة (0-100)
     * @return array ['label' => string, 'class' => string, 'color' => string]
     */
    public function getScoreRating($score) {
        if ($score >= 90) return ['label' => 'ممتاز', 'class' => 'excellent', 'color' => '#28a745'];
        if ($score >= 80) return ['label' => 'جيد جداً', 'class' => 'very-good', 'color' => '#17a2b8'];
        if ($score >= 70) return ['label' => 'جيد', 'class' => 'good', 'color' => '#1a5632'];
        if ($score >= 60) return ['label' => 'مقبول', 'class' => 'fair', 'color' => '#ffc107'];
        if ($score >= 50) return ['label' => 'ضعيف', 'class' => 'poor', 'color' => '#fd7e14'];
        return ['label' => 'ضعيف جداً', 'class' => 'very-poor', 'color' => '#dc3545'];
    }
    
    /**
     * عرض شريط درجة مرئي
     * 
     * @param float $score الدرجة (0-100)
     * @param string $label التسمية (اختياري)
     * @return string كود HTML
     */
    public function renderScoreBar($score, $label = '') {
        $rating = $this->getScoreRating($score);
        $scoreDisplay = number_format($score, 1);
        
        $html = '<div class="score-bar-container">';
        if ($label) {
            $html .= '<div class="flex justify-between items-center mb-1">';
            $html .= '<span class="text-sm">' . e($label) . '</span>';
            $html .= '<span class="text-sm font-semibold" style="color:' . $rating['color'] . ';">' . $scoreDisplay . '% - ' . $rating['label'] . '</span>';
            $html .= '</div>';
        }
        $html .= '<div style="background:#e9ecef; border-radius:8px; height:12px; overflow:hidden;">';
        $html .= '<div style="width:' . min(100, max(0, $score)) . '%; background:' . $rating['color'] . '; height:100%; border-radius:8px; transition:width 0.5s ease;"></div>';
        $html .= '</div></div>';
        
        return $html;
    }
    
    /**
     * إنشاء نموذج تقييم جديد
     * 
     * @param array $data بيانات النموذج
     * @return int|false معرف النموذج أو false
     */
    public function createForm($data) {
        try {
            // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
            $stmt = $this->db->prepare("
                INSERT INTO assessment_forms (name_ar, name_en, form_key, description, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $data['name_ar'],
                $data['name_en'] ?? null,
                $data['form_key'],  // [إصلاح 2026-05-11] مفتاح المصفوفة: form_type → form_key (تطابق اسم العمود)
                $data['description'] ?? null
            ]);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('خطأ في إنشاء النموذج: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * إنشاء فئة تقييم جديدة
     * 
     * @param array $data بيانات الفئة
     * @return int|false معرف الفئة أو false
     */
    public function createCategory($data) {
        try {
            // [إصلاح 2026-05-11] assessment_categories → assessment_criteria (تطابق schema)
            $stmt = $this->db->prepare("
                INSERT INTO assessment_criteria (form_id, name_ar, name_en, sort_order, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $data['form_id'],
                $data['name_ar'],
                $data['name_en'] ?? null,
                $data['sort_order'] ?? 0
            ]);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('خطأ في إنشاء الفئة: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * إنشاء عنصر تقييم جديد
     * 
     * @param array $data بيانات العنصر
     * @return int|false معرف العنصر أو false
     */
    public function createItem($data) {
        try {
            // [إصلاح 2026-05-11] category_id → criteria_id و form_type → form_key (تطابق schema)
            $stmt = $this->db->prepare("
                INSERT INTO assessment_items 
                (criteria_id, form_id, text_ar, text_en, tool_type, weight, is_inverse, max_value, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $data['criteria_id'],  // [إصلاح 2026-05-11] مفتاح المصفوفة: category_id → criteria_id (تطابق اسم العمود)
                $data['form_id'],
                $data['text_ar'],
                $data['text_en'] ?? null,
                $data['tool_type'],
                $data['weight'] ?? 1.00,
                $data['is_inverse'] ?? 0,
                $data['max_value'] ?? null,
                $data['sort_order'] ?? 0
            ]);
            return $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('خطأ في إنشاء العنصر: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * إحصائيات التقييمات
     * 
     * @return array إحصائيات عامة
     */
    public function getAssessmentStats() {
        $stats = [
            'total_forms' => 0,
            'total_responses' => 0,
            'by_type' => []
        ];
        
        try {
            // إجمالي النماذج
            $stmt = $this->db->query("SELECT COUNT(*) FROM assessment_forms WHERE is_active = 1");
            $stats['total_forms'] = (int)$stmt->fetchColumn();
            
            // إجمالي الاستجابات
            $stmt = $this->db->query("SELECT COUNT(*) FROM assessment_responses WHERE is_active = 1");
            $stats['total_responses'] = (int)$stmt->fetchColumn();
            
            // حسب نوع النموذج
            // [إصلاح 2026-05-11] form_type → form_key (تطابق schema)
            $stmt = $this->db->query("
                SELECT af.form_key, COUNT(DISTINCT af.id) as form_count, COUNT(ar.id) as response_count
                FROM assessment_forms af
                LEFT JOIN assessment_responses ar ON af.id = ar.form_id AND ar.is_active = 1
                WHERE af.is_active = 1
                GROUP BY af.form_key
            ");
            $byType = $stmt->fetchAll();
            foreach ($byType as $row) {
                $stats['by_type'][$row['form_key']] = [
                    'form_count' => (int)$row['form_count'],
                    'response_count' => (int)$row['response_count']
                ];
            }
        } catch (Exception $e) {
            error_log('خطأ في إحصائيات التقييم: ' . $e->getMessage());
        }
        
        return $stats;
    }
}
