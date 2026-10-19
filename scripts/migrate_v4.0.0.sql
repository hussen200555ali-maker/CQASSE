-- ============================================================
-- EQAS Migration v4.0.0 — تحديث بيانات الاستمارات الشامل
-- ============================================================
-- يضبط محتويات assessment_structure لجميع الاستمارات:
--  1. إصلاح parent_id الخاطئ (+18) في الاستمارات 1 و2 و4
--  2. إعادة بناء الاستمارات 3 (تقييم ذاتي) و5 (تقييم إشرافي)
--  3. توسيع الاستمارات 1 و2 و4 و7
--  4. ملء النصوص الفارغة في الاستمارات 6 و14 و17
--  5. تفعيل الاستمارتين 14 و17
-- ============================================================

-- ============================================================
-- الخطوة 0: إصلاح parent_id الخاطئ (إزاحة +18)
-- ============================================================
-- Form 1: items 19-20 → parent_id=1, items 21-22 → parent_id=2, items 23-24 → parent_id=3
UPDATE assessment_structure SET parent_id = 1 WHERE id IN (19,20) AND parent_id != 1;
UPDATE assessment_structure SET parent_id = 2 WHERE id IN (21,22) AND parent_id != 2;
UPDATE assessment_structure SET parent_id = 3 WHERE id IN (23,24) AND parent_id != 3;

-- Form 2: items 25-26 → parent_id=4, items 27-28 → parent_id=5, items 29-30 → parent_id=6
UPDATE assessment_structure SET parent_id = 4 WHERE id IN (25,26) AND parent_id != 4;
UPDATE assessment_structure SET parent_id = 5 WHERE id IN (27,28) AND parent_id != 5;
UPDATE assessment_structure SET parent_id = 6 WHERE id IN (29,30) AND parent_id != 6;

-- Form 4: items 35-36 → parent_id=9, items 37-38 → parent_id=10
UPDATE assessment_structure SET parent_id = 9 WHERE id IN (35,36) AND parent_id != 9;
UPDATE assessment_structure SET parent_id = 10 WHERE id IN (37,38) AND parent_id != 10;

-- ============================================================
-- الخطوة 1: تحديث بيانات الاستمارات (assessment_forms)
-- ============================================================
UPDATE assessment_forms SET
    description = 'تقييم سلوك الطالب في المدرسة من حيث الانضباط والتفاعل والمسؤولية',
    version = 'v2.0',
    is_default = 1
WHERE id = 1;

UPDATE assessment_forms SET
    description = 'قياس المهارات الأساسية للطالب: القرائية والحسابية والحركية والتكنولوجية',
    version = 'v2.0',
    is_default = 1
WHERE id = 2;

UPDATE assessment_forms SET
    description = 'تقييم المعلم لذاته في خمسة مجالات: التخطيط والتنفيذ وإدارة الصف والتقويم والتطور المهني',
    version = 'v2.0',
    is_default = 1
WHERE id = 3;

UPDATE assessment_forms SET
    description = 'تقييم المعلم من قبل زملائه في التعاون المهني والمساهمة المدرسية والممارسات المهنية والإبداع',
    version = 'v2.0',
    is_default = 1
WHERE id = 4;

UPDATE assessment_forms SET
    description = 'تقييم المشرف التربوي لأداء المعلم في جودة التعليم والإدارة الصفية والالتزام المهني والتطوير ونتائج التعلم',
    version = 'v2.0',
    is_default = 1
WHERE id = 5;

UPDATE assessment_forms SET
    description = 'استبيان قياس المناخ المدرسي عبر تسعة أبعاد: البيئة، العلاقات، الأمن، الخدمات، التعلم، الدعم، جودة الحياة، القيادة، التحفيز',
    version = 'v2.0',
    is_default = 1
WHERE id = 6;

UPDATE assessment_forms SET
    description = 'قائمة تفقد جودة المرافق المدرسية: المباني والخدمات والموارد التعليمية والأمن والسلامة',
    version = 'v2.0',
    is_default = 1
WHERE id = 7;

UPDATE assessment_forms SET
    name_ar = 'استبيان رضا المعلمين',
    description = 'استبيان قياس رضا المعلمين عن بيئة العمل والقيادة المدرسية وجودة الحياة الوظيفية وبيئة التعلم',
    version = 'v1.0',
    is_active = 1,
    is_default = 1
WHERE id = 14;

UPDATE assessment_forms SET
    name_ar = 'استبيان رضا أولياء الأمور',
    description = 'استبيان قياس رضا أولياء الأمور عن التواصل وجودة التعليم وبيئة المدرسة والأنشطة والخدمات',
    version = 'v1.0',
    is_active = 1,
    is_default = 1
WHERE id = 17;

-- ============================================================
-- الخطوة 2: إعادة بناء الاستمارة 3 — التقييم الذاتي للتدريس
-- ============================================================
-- Soft-delete old structure
UPDATE assessment_structure SET is_active = 0 WHERE form_id = 3;

-- Criteria (will get auto-increment IDs)
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, NULL, 'criteria', 'التخطيط والإعداد', NULL, NULL, 20.00, 1, 1);

-- Need to know the new ID for parent_id
SET @c1 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, @c1, 'item', 'أخطط لدروسي بشكل شامل ومتكامل', 'أخطط لدروسي بشكل شامل ومتكامل', 'likert_5', 1.00, 1, 1),
(3, @c1, 'item', 'أحدد أهدافاً تعليمية واضحة وقابلة للقياس', 'أحدد أهدافاً تعليمية واضحة وقابلة للقياس', 'likert_5', 1.00, 2, 1),
(3, @c1, 'item', 'أختار استراتيجيات تدريس مناسبة للمحتوى والطلاب', 'أختار استراتيجيات تدريس مناسبة للمحتوى والطلاب', 'likert_5', 1.00, 3, 1),
(3, @c1, 'item', 'أعد الوسائل والموارد التعليمية المناسبة', 'أعد الوسائل والموارد التعليمية المناسبة', 'likert_5', 1.00, 4, 1),
(3, @c1, 'item', 'أراعي الفروق الفردية بين الطلاب في تخطيطي', 'أراعي الفروق الفردية بين الطلاب في تخطيطي', 'likert_5', 1.00, 5, 1);

-- Criteria 2: تنفيذ التدريس
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, NULL, 'criteria', 'تنفيذ التدريس', NULL, NULL, 25.00, 2, 1);
SET @c2 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, @c2, 'item', 'أستخدم استراتيجيات تدريس متنوعة وفعالة', 'أستخدم استراتيجيات تدريس متنوعة وفعالة', 'likert_5', 1.00, 1, 1),
(3, @c2, 'item', 'أدير الوقت بشكل فعال داخل الحصة', 'أدير الوقت بشكل فعال داخل الحصة', 'likert_5', 1.00, 2, 1),
(3, @c2, 'item', 'أربط المحتوى التعليمي بالحياة الواقعية', 'أربط المحتوى التعليمي بالحياة الواقعية', 'likert_5', 1.00, 3, 1),
(3, @c2, 'item', 'أوظف التقنية في التدريس بشكل مناسب', 'أوظف التقنية في التدريس بشكل مناسب', 'likert_5', 1.00, 4, 1),
(3, @c2, 'item', 'أنوع أساليب التقويم أثناء التدريس', 'أنوع أساليب التقويم أثناء التدريس', 'likert_5', 1.00, 5, 1);

-- Criteria 3: إدارة الصف
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, NULL, 'criteria', 'إدارة الصف', NULL, NULL, 20.00, 3, 1);
SET @c3 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, @c3, 'item', 'أنظم بيئة الصف بما يجذب انتباه الطلاب', 'أنظم بيئة الصف بما يجذب انتباه الطلاب', 'likert_5', 1.00, 1, 1),
(3, @c3, 'item', 'أضع قواعد وإجراءات صفية واضحة', 'أضع قواعد وإجراءات صفية واضحة', 'likert_5', 1.00, 2, 1),
(3, @c3, 'item', 'أدير السلوك الصفي بطرق إيجابية', 'أدير السلوك الصفي بطرق إيجابية', 'likert_5', 1.00, 3, 1),
(3, @c3, 'item', 'أخلق مناخاً صفياً آمناً ومشجعاً على التعلم', 'أخلق مناخاً صفياً آمناً ومشجعاً على التعلم', 'likert_5', 1.00, 4, 1),
(3, @c3, 'item', 'أتعامل مع المشكلات الصفية بحكمة وهدوء', 'أتعامل مع المشكلات الصفية بحكمة وهدوء', 'likert_5', 1.00, 5, 1);

-- Criteria 4: التقويم والتقييم
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, NULL, 'criteria', 'التقويم والتقييم', NULL, NULL, 20.00, 4, 1);
SET @c4 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, @c4, 'item', 'أصمم أدوات تقويم متنوعة ومناسبة للأهداف', 'أصمم أدوات تقويم متنوعة ومناسبة للأهداف', 'likert_5', 1.00, 1, 1),
(3, @c4, 'item', 'أحلل نتائج التقييم لتحديد نقاط القوة والضعف', 'أحلل نتائج التقييم لتحديد نقاط القوة والضعف', 'likert_5', 1.00, 2, 1),
(3, @c4, 'item', 'أقدم تغذية راجعة بناءة للطلاب', 'أقدم تغذية راجعة بناءة للطلاب', 'likert_5', 1.00, 3, 1),
(3, @c4, 'item', 'أوثق وأتابع تقدم الطلاب بشكل منتظم', 'أوثق وأتابع تقدم الطلاب بشكل منتظم', 'likert_5', 1.00, 4, 1),
(3, @c4, 'item', 'أستخدم نتائج التقويم لتحسين ممارستي التدريسية', 'أستخدم نتائج التقويم لتحسين ممارستي التدريسية', 'likert_5', 1.00, 5, 1);

-- Criteria 5: التطور المهني
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, NULL, 'criteria', 'التطور المهني', NULL, NULL, 15.00, 5, 1);
SET @c5 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(3, @c5, 'item', 'أشارك في برامج التطوير المهني بانتظام', 'أشارك في برامج التطوير المهني بانتظام', 'likert_5', 1.00, 1, 1),
(3, @c5, 'item', 'أتعاون مع الزملاء وأتبادل الخبرات', 'أتعاون مع الزملاء وأتبادل الخبرات', 'likert_5', 1.00, 2, 1),
(3, @c5, 'item', 'أمارس التفكير التأملي في ممارساتي التدريسية', 'أمارس التفكير التأملي في ممارساتي التدريسية', 'likert_5', 1.00, 3, 1),
(3, @c5, 'item', 'أطلع على المستجدات في مجال التعليم والتعلم', 'أطلع على المستجدات في مجال التعليم والتعلم', 'likert_5', 1.00, 4, 1);

-- ============================================================
-- الخطوة 3: إعادة بناء الاستمارة 5 — التقييم الإشرافي للتدريس
-- ============================================================
UPDATE assessment_structure SET is_active = 0 WHERE form_id = 5;

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, NULL, 'criteria', 'جودة التعليم', NULL, NULL, 30.00, 1, 1);
SET @c5_1 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, @c5_1, 'item', 'يتقن المعلم المحتوى العلمي للمادة', 'يتقن المعلم المحتوى العلمي للمادة', 'likert_5', 1.00, 1, 1),
(5, @c5_1, 'item', 'يستخدم استراتيجيات تدريس فعالة ومتنوعة', 'يستخدم استراتيجيات تدريس فعالة ومتنوعة', 'likert_5', 1.00, 2, 1),
(5, @c5_1, 'item', 'يقدر على إثارة دافعية الطلاب للتعلم', 'يقدر على إثارة دافعية الطلاب للتعلم', 'likert_5', 1.00, 3, 1),
(5, @c5_1, 'item', 'يستخدم أساليب تقويم مناسبة ومتنوعة', 'يستخدم أساليب تقويم مناسبة ومتنوعة', 'likert_5', 1.00, 4, 1),
(5, @c5_1, 'item', 'يحلل نتائج الطلاب ويستثمرها في تحسين التدريس', 'يحلل نتائج الطلاب ويستثمرها في تحسين التدريس', 'likert_5', 1.00, 5, 1);

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, NULL, 'criteria', 'الإدارة الصفية', NULL, NULL, 20.00, 2, 1);
SET @c5_2 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, @c5_2, 'item', 'ينظم بيئة التعلم ويدير الوقت بكفاءة', 'ينظم بيئة التعلم ويدير الوقت بكفاءة', 'likert_5', 1.00, 1, 1),
(5, @c5_2, 'item', 'يضبط الصف بطرق إيجابية وفعالة', 'يضبط الصف بطرق إيجابية وفعالة', 'likert_5', 1.00, 2, 1),
(5, @c5_2, 'item', 'يوفر مناخاً صفياً داعماً للتعلم', 'يوفر مناخاً صفياً داعماً للتعلم', 'likert_5', 1.00, 3, 1),
(5, @c5_2, 'item', 'يراعي الفروق الفردية بين الطلاب', 'يراعي الفروق الفردية بين الطلاب', 'likert_5', 1.00, 4, 1),
(5, @c5_2, 'item', 'يدير المواقف الصعبة في الصف بحرفية', 'يدير المواقف الصعبة في الصف بحرفية', 'likert_5', 1.00, 5, 1);

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, NULL, 'criteria', 'الالتزام المهني', NULL, NULL, 20.00, 3, 1);
SET @c5_3 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, @c5_3, 'item', 'يلتزم بالمواعيد والحضور المنتظم', 'يلتزم بالمواعيد والحضور المنتظم', 'likert_5', 1.00, 1, 1),
(5, @c5_3, 'item', 'ينفذ الخطط الدراسية وفق الجدول الزمني', 'ينفذ الخطط الدراسية وفق الجدول الزمني', 'likert_5', 1.00, 2, 1),
(5, @c5_3, 'item', 'يلتزم بتوجيهات الإشراف التربوي', 'يلتزم بتوجيهات الإشراف التربوي', 'likert_5', 1.00, 3, 1),
(5, @c5_3, 'item', 'يتعاون مع إدارة المدرسة بفعالية', 'يتعاون مع إدارة المدرسة بفعالية', 'likert_5', 1.00, 4, 1),
(5, @c5_3, 'item', 'يتواصل مع أولياء الأمور بشكل دوري', 'يتواصل مع أولياء الأمور بشكل دوري', 'likert_5', 1.00, 5, 1);

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, NULL, 'criteria', 'التطوير والتحسين', NULL, NULL, 15.00, 4, 1);
SET @c5_4 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, @c5_4, 'item', 'يستجيب للتغذية الراجعة من المشرفين', 'يستجيب للتغذية الراجعة من المشرفين', 'likert_5', 1.00, 1, 1),
(5, @c5_4, 'item', 'يسعى للتطوير المهني المستمر', 'يسعى للتطوير المهني المستمر', 'likert_5', 1.00, 2, 1),
(5, @c5_4, 'item', 'يطبق استراتيجيات تدريس حديثة', 'يطبق استراتيجيات تدريس حديثة', 'likert_5', 1.00, 3, 1),
(5, @c5_4, 'item', 'يوثق مسيرته المهنية وإنجازاته', 'يوثق مسيرته المهنية وإنجازاته', 'likert_5', 1.00, 4, 1);

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, NULL, 'criteria', 'نتائج التعلم', NULL, NULL, 15.00, 5, 1);
SET @c5_5 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(5, @c5_5, 'item', 'يحقق الطلاب مخرجات التعلم المستهدفة', 'يحقق الطلاب مخرجات التعلم المستهدفة', 'likert_5', 1.00, 1, 1),
(5, @c5_5, 'item', 'يتحسن أداء الطلاب بمرور الوقت', 'يتحسن أداء الطلاب بمرور الوقت', 'likert_5', 1.00, 2, 1),
(5, @c5_5, 'item', 'يحقق الطلاب نتائج متميزة في الاختبارات', 'يحقق الطلاب نتائج متميزة في الاختبارات', 'likert_5', 1.00, 3, 1),
(5, @c5_5, 'item', 'يظهر الطلاب رضا عن جودة التدريس', 'يظهر الطلاب رضا عن جودة التدريس', 'likert_5', 1.00, 4, 1);

-- ============================================================
-- الخطوة 4: توسيع الاستمارة 1 — التقييم السلوكي
-- ============================================================
-- Update existing items with better text
UPDATE assessment_structure SET text_ar = name_ar WHERE form_id = 1 AND type = 'item' AND (text_ar IS NULL OR text_ar = '');

-- Add new items to existing criteria (1=الانضباط المدرسي, 2=التفاعل الصفي, 3=المسؤولية الشخصية)
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(1, 1, 'item', 'يلتزم بالزي المدرسي والمظهر اللائق', 'يلتزم بالزي المدرسي والمظهر اللائق', 'likert_5', 1.00, 3, 1),
(1, 1, 'item', 'يلتزم بتعليمات المدرسة وأنظمتها', 'يلتزم بتعليمات المدرسة وأنظمتها', 'likert_5', 1.00, 4, 1),
(1, 2, 'item', 'ينتبه ويتركّز أثناء شرح المعلم', 'ينتبه ويتركّز أثناء شرح المعلم', 'likert_5', 1.00, 3, 1),
(1, 2, 'item', 'يبادر بطرح الأسئلة والاستفسارات', 'يبادر بطرح الأسئلة والاستفسارات', 'likert_5', 1.00, 4, 1),
(1, 3, 'item', 'يحضر الواجبات المدرسية في الوقت المحدد', 'يحضر الواجبات المدرسية في الوقت المحدد', 'likert_5', 1.00, 3, 1),
(1, 3, 'item', 'يحضر الأدوات والكتب المدرسية يومياً', 'يحضر الأدوات والكتب المدرسية يومياً', 'likert_5', 1.00, 4, 1);

-- Update criteria weights to sum to 100
UPDATE assessment_structure SET weight = 30.00 WHERE id = 1; -- الانضباط المدرسي
UPDATE assessment_structure SET weight = 35.00 WHERE id = 2; -- التفاعل الصفي
UPDATE assessment_structure SET weight = 35.00 WHERE id = 3; -- المسؤولية الشخصية

-- ============================================================
-- الخطوة 5: توسيع الاستمارة 2 — قائمة المهارات الأساسية
-- ============================================================
UPDATE assessment_structure SET text_ar = name_ar WHERE form_id = 2 AND type = 'item' AND (text_ar IS NULL OR text_ar = '');

-- Add items to existing criteria
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(2, 4, 'item', 'يستخدم مهارات البحث والاستقصاء', 'يستخدم مهارات البحث والاستقصاء', 'likert_5', 1.00, 3, 1),
(2, 4, 'item', 'يلخص المعلومات وينظمها بشكل منطقي', 'يلخص المعلومات وينظمها بشكل منطقي', 'likert_5', 1.00, 4, 1),
(2, 5, 'item', 'يحل النزاعات مع زملائه بطرق سلمية', 'يحل النزاعات مع زملائه بطرق سلمية', 'likert_5', 1.00, 3, 1),
(2, 5, 'item', 'يظهر تعاطفاً وتفهّماً لمشاعر الآخرين', 'يظهر تعاطفاً وتفهّماً لمشاعر الآخرين', 'likert_5', 1.00, 4, 1),
(2, 6, 'item', 'يفكر بشكل إبداعي ويقدم أفكاراً جديدة', 'يفكر بشكل إبداعي ويقدم أفكاراً جديدة', 'likert_5', 1.00, 3, 1),
(2, 6, 'item', 'يتخذ قرارات مناسبة بعد التفكير', 'يتخذ قرارات مناسبة بعد التفكير', 'likert_5', 1.00, 4, 1);

-- Add new criteria: المهارات التكنولوجية
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(2, NULL, 'criteria', 'المهارات التكنولوجية', NULL, NULL, 25.00, 4, 1);
SET @c2_new = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(2, @c2_new, 'item', 'يستخدم الحاسوب في التعلم', 'يستخدم الحاسوب في التعلم', 'likert_5', 1.00, 1, 1),
(2, @c2_new, 'item', 'يبحث في الإنترنت بشكل فعال وآمن', 'يبحث في الإنترنت بشكل فعال وآمن', 'likert_5', 1.00, 2, 1),
(2, @c2_new, 'item', 'يستخدم التطبيقات التعليمية', 'يستخدم التطبيقات التعليمية', 'likert_5', 1.00, 3, 1),
(2, @c2_new, 'item', 'يلتزم بقواعد السلامة الرقمية', 'يلتزم بقواعد السلامة الرقمية', 'likert_5', 1.00, 4, 1);

-- Update existing criteria weights
UPDATE assessment_structure SET weight = 25.00 WHERE id = 4; -- المهارات القرائية
UPDATE assessment_structure SET weight = 25.00 WHERE id = 5; -- المهارات الحسابية
UPDATE assessment_structure SET weight = 25.00 WHERE id = 6; -- المهارات الحركية

-- ============================================================
-- الخطوة 6: توسيع الاستمارة 4 — تقييم الأقران
-- ============================================================
UPDATE assessment_structure SET text_ar = name_ar WHERE form_id = 4 AND type = 'item' AND (text_ar IS NULL OR text_ar = '');

-- Add items to existing criteria
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(4, 9, 'item', 'يشارك الموارد والخبرات التعليمية', 'يشارك الموارد والخبرات التعليمية', 'likert_5', 1.00, 3, 1),
(4, 9, 'item', 'يساهم في حل المشكلات الجماعية', 'يساهم في حل المشكلات الجماعية', 'likert_5', 1.00, 4, 1),
(4, 10, 'item', 'يساهم في تطوير البرامج والمناهج', 'يساهم في تطوير البرامج والمناهج', 'likert_5', 1.00, 3, 1),
(4, 10, 'item', 'يمثل المدرسة في المحافل الخارجية', 'يمثل المدرسة في المحافل الخارجية', 'likert_5', 1.00, 4, 1);

-- Add new criteria 1: الممارسات المهنية
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(4, NULL, 'criteria', 'الممارسات المهنية', NULL, NULL, 25.00, 3, 1);
SET @c4_new1 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(4, @c4_new1, 'item', 'يلتزم بالأنظمة والقوانين المدرسية', 'يلتزم بالأنظمة والقوانين المدرسية', 'likert_5', 1.00, 1, 1),
(4, @c4_new1, 'item', 'يتواصل مع إدارة المدرسة بفعالية', 'يتواصل مع إدارة المدرسة بفعالية', 'likert_5', 1.00, 2, 1),
(4, @c4_new1, 'item', 'يحترم خصوصية الطلاب والمعلومات', 'يحترم خصوصية الطلاب والمعلومات', 'likert_5', 1.00, 3, 1),
(4, @c4_new1, 'item', 'يظهر سلوكاً مهنياً قدوة للآخرين', 'يظهر سلوكاً مهنياً قدوة للآخرين', 'likert_5', 1.00, 4, 1);

-- Add new criteria 2: الإبداع والابتكار
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(4, NULL, 'criteria', 'الإبداع والابتكار', NULL, NULL, 20.00, 4, 1);
SET @c4_new2 = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(4, @c4_new2, 'item', 'يقدم أفكاراً إبداعية في التدريس', 'يقدم أفكاراً إبداعية في التدريس', 'likert_5', 1.00, 1, 1),
(4, @c4_new2, 'item', 'يستخدم أساليب مبتكرة في التقويم', 'يستخدم أساليب مبتكرة في التقويم', 'likert_5', 1.00, 2, 1),
(4, @c4_new2, 'item', 'يطبق مبادرات تطويرية في الصف', 'يطبق مبادرات تطويرية في الصف', 'likert_5', 1.00, 3, 1),
(4, @c4_new2, 'item', 'يشجع زملاءه على التجديد والتطوير', 'يشجع زملاءه على التجديد والتطوير', 'likert_5', 1.00, 4, 1);

-- Update existing criteria weights
UPDATE assessment_structure SET weight = 30.00 WHERE id = 9;  -- التعاون المهني
UPDATE assessment_structure SET weight = 25.00 WHERE id = 10; -- المساهمة في المدرسة

-- ============================================================
-- الخطوة 7: توسيع الاستمارة 7 — تفقد المرافق
-- ============================================================
UPDATE assessment_structure SET text_ar = name_ar WHERE form_id = 7 AND type = 'item' AND (text_ar IS NULL OR text_ar = '');

-- Add items to existing criteria
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(7, 16, 'item', 'كفاية الفصول الدراسية وسعتها للطلاب', 'كفاية الفصول الدراسية وسعتها للطلاب', 'yes_no', 1.00, 3, 1),
(7, 16, 'item', 'نظافة المبنى المدرسي بشكل عام', 'نظافة المبنى المدرسي بشكل عام', 'yes_no', 1.00, 4, 1),
(7, 17, 'item', 'توفر خدمة الإنترنت في المدرسة', 'توفر خدمة الإنترنت في المدرسة', 'yes_no', 1.00, 3, 1),
(7, 17, 'item', 'أنظمة التكييف والتهوية مناسبة', 'أنظمة التكييف والتهوية مناسبة', 'yes_no', 1.00, 4, 1),
(7, 18, 'item', 'توفر مصادر التعلم الرقمية', 'توفر مصادر التعلم الرقمية', 'yes_no', 1.00, 3, 1);

-- Add new criteria: الأمن والسلامة
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(7, NULL, 'criteria', 'الأمن والسلامة', NULL, NULL, 20.00, 4, 1);
SET @c7_new = LAST_INSERT_ID();

INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(7, @c7_new, 'item', 'وجود طفايات حريق ومخارج طوارئ مناسبة', 'وجود طفايات حريق ومخارج طوارئ مناسبة', 'yes_no', 1.00, 1, 1),
(7, @c7_new, 'item', 'وجود كاميرات مراقبة في الممرات والمداخل', 'وجود كاميرات مراقبة في الممرات والمداخل', 'yes_no', 1.00, 2, 1),
(7, @c7_new, 'item', 'وجود حارس أمن أو نظام حراسة للمدرسة', 'وجود حارس أمن أو نظام حراسة للمدرسة', 'yes_no', 1.00, 3, 1),
(7, @c7_new, 'item', 'توفر صندوق إسعافات أولية وممرض مقيم', 'توفر صندوق إسعافات أولية وممرض مقيم', 'yes_no', 1.00, 4, 1);

-- Update existing criteria weights
UPDATE assessment_structure SET weight = 30.00 WHERE id = 16; -- الفصول الدراسية
UPDATE assessment_structure SET weight = 25.00 WHERE id = 17; -- المرافق الصحية
UPDATE assessment_structure SET weight = 25.00 WHERE id = 18; -- المختبرات والمكتبة

-- ============================================================
-- الخطوة 8: ملء النصوص الفارغة في الاستمارة 6 — المناخ المدرسي
-- ============================================================
-- Update criteria names (they may show as ???? in non-UTF8 consoles)
UPDATE assessment_structure SET name_ar = 'الخدمات المدرسية' WHERE id = 55;
UPDATE assessment_structure SET name_ar = 'بيئة التعلم' WHERE id = 59;
UPDATE assessment_structure SET name_ar = 'الدعم التربوي' WHERE id = 63;
UPDATE assessment_structure SET name_ar = 'جودة الحياة' WHERE id = 67;
UPDATE assessment_structure SET name_ar = 'القيادة المدرسية' WHERE id = 71;
UPDATE assessment_structure SET name_ar = 'التحفيز المدرسي' WHERE id = 75;

-- Fill item texts for الخدمات المدرسية (criteria 55, items 56-58, tool=satisfaction_5)
UPDATE assessment_structure SET name_ar = 'الخدمات الصحية المدرسية مناسبة', text_ar = 'الخدمات الصحية المدرسية مناسبة' WHERE id = 56;
UPDATE assessment_structure SET name_ar = 'المقصف المدرسي يقدم خدمات جيدة', text_ar = 'المقصف المدرسي يقدم خدمات جيدة' WHERE id = 57;
UPDATE assessment_structure SET name_ar = 'خدمات المواصلات المدرسية مناسبة', text_ar = 'خدمات المواصلات المدرسية مناسبة' WHERE id = 58;

-- Fill item texts for بيئة التعلم (criteria 59, items 60-62, tool=frequency_5)
UPDATE assessment_structure SET name_ar = 'المعلمون يستخدمون أساليب تدريس متنوعة', text_ar = 'المعلمون يستخدمون أساليب تدريس متنوعة' WHERE id = 60;
UPDATE assessment_structure SET name_ar = 'المناهج الدراسية مناسبة لمستوى الطلاب', text_ar = 'المناهج الدراسية مناسبة لمستوى الطلاب' WHERE id = 61;
UPDATE assessment_structure SET name_ar = 'الأنشطة التعليمية متنوعة ومحفزة', text_ar = 'الأنشطة التعليمية متنوعة ومحفزة' WHERE id = 62;

-- Fill item texts for الدعم التربوي (criteria 63, items 64-66, tool=frequency_5)
UPDATE assessment_structure SET name_ar = 'المدرسة تقدم دعماً للطلاب ذوي الصعوبات', text_ar = 'المدرسة تقدم دعماً للطلاب ذوي الصعوبات' WHERE id = 64;
UPDATE assessment_structure SET name_ar = 'توجد برامج تقوية للطلاب الضعاف', text_ar = 'توجد برامج تقوية للطلاب الضعاف' WHERE id = 65;
UPDATE assessment_structure SET name_ar = 'المدرسة تهتم بالموهوبين والمتفوقين', text_ar = 'المدرسة تهتم بالموهوبين والمتفوقين' WHERE id = 66;

-- Fill item texts for جودة الحياة (criteria 67, items 68-70, tool=satisfaction_5)
UPDATE assessment_structure SET name_ar = 'أشعر بالرضا عن الحياة المدرسية بشكل عام', text_ar = 'أشعر بالرضا عن الحياة المدرسية بشكل عام' WHERE id = 68;
UPDATE assessment_structure SET name_ar = 'المدرسة تعزز الصحة النفسية للطلاب', text_ar = 'المدرسة تعزز الصحة النفسية للطلاب' WHERE id = 69;
UPDATE assessment_structure SET name_ar = 'توجد برامج للحد من التوتر والضغوط', text_ar = 'توجد برامج للحد من التوتر والضغوط' WHERE id = 70;

-- Fill item texts for القيادة المدرسية (criteria 71, items 72-74, tool=satisfaction_5)
UPDATE assessment_structure SET name_ar = 'إدارة المدرسة تتخذ قرارات عادلة', text_ar = 'إدارة المدرسة تتخذ قرارات عادلة' WHERE id = 72;
UPDATE assessment_structure SET name_ar = 'مدير المدرسة يتواصل مع الجميع بفعالية', text_ar = 'مدير المدرسة يتواصل مع الجميع بفعالية' WHERE id = 73;
UPDATE assessment_structure SET name_ar = 'الإدارة تشجع على المشاركة في اتخاذ القرارات', text_ar = 'الإدارة تشجع على المشاركة في اتخاذ القرارات' WHERE id = 74;

-- Fill item texts for التحفيز المدرسي (criteria 75, items 76-78, tool=frequency_5)
UPDATE assessment_structure SET name_ar = 'المدرسة تحفز الطلاب على الإبداع والتميز', text_ar = 'المدرسة تحفز الطلاب على الإبداع والتميز' WHERE id = 76;
UPDATE assessment_structure SET name_ar = 'توجد حوافز تشجع على السلوك الإيجابي', text_ar = 'توجد حوافز تشجع على السلوك الإيجابي' WHERE id = 77;
UPDATE assessment_structure SET name_ar = 'المعلمون يشجعون الطلاب ويدعمونهم', text_ar = 'المعلمون يشجعون الطلاب ويدعمونهم' WHERE id = 78;

-- Update climate criteria weights
UPDATE assessment_structure SET weight = 15.00 WHERE id = 13; -- البيئة المدرسية
UPDATE assessment_structure SET weight = 15.00 WHERE id = 14; -- العلاقات الاجتماعية
UPDATE assessment_structure SET weight = 15.00 WHERE id = 15; -- الأمن والسلامة
UPDATE assessment_structure SET weight = 10.00 WHERE id = 55; -- الخدمات المدرسية
UPDATE assessment_structure SET weight = 10.00 WHERE id = 59; -- بيئة التعلم
UPDATE assessment_structure SET weight = 10.00 WHERE id = 63; -- الدعم التربوي
UPDATE assessment_structure SET weight = 10.00 WHERE id = 67; -- جودة الحياة
UPDATE assessment_structure SET weight = 10.00 WHERE id = 71; -- القيادة المدرسية
UPDATE assessment_structure SET weight = 5.00 WHERE id = 75; -- التحفيز المدرسي

-- ============================================================
-- الخطوة 9: تفعيل وملء الاستمارة 14 — رضا المعلمين
-- ============================================================
-- Fill items for بيئة العمل (criteria 79, tool=satisfaction_5)
UPDATE assessment_structure SET name_ar = 'أنا راضٍ عن بيئة العمل المادية في المدرسة', text_ar = 'أنا راضٍ عن بيئة العمل المادية في المدرسة' WHERE id = 80;
UPDATE assessment_structure SET name_ar = 'العلاقات بين الزملاء تسودها الاحترام والتعاون', text_ar = 'العلاقات بين الزملاء تسودها الاحترام والتعاون' WHERE id = 81;
UPDATE assessment_structure SET name_ar = 'الإدارة تدعم المعلمين وتقدر جهودهم', text_ar = 'الإدارة تدعم المعلمين وتقدر جهودهم' WHERE id = 82;
UPDATE assessment_structure SET name_ar = 'عبء العمل مناسب ومتوازن', text_ar = 'عبء العمل مناسب ومتوازن' WHERE id = 83;

-- Fill items for القيادة المدرسية (criteria 84, tool=likert_5)
UPDATE assessment_structure SET name_ar = 'القرارات تتخذ بشفافية ووضوح', text_ar = 'القرارات تتخذ بشفافية ووضوح' WHERE id = 85;
UPDATE assessment_structure SET name_ar = 'الإدارة تقدر جهود المعلمين وتعترف بها', text_ar = 'الإدارة تقدر جهود المعلمين وتعترف بها' WHERE id = 86;
UPDATE assessment_structure SET name_ar = 'القيادة توفر الدعم والتوجيه اللازم', text_ar = 'القيادة توفر الدعم والتوجيه اللازم' WHERE id = 87;

-- Fill items for جودة الحياة الوظيفية (criteria 88, tool=frequency_5)
UPDATE assessment_structure SET name_ar = 'الراتب والحوافز مناسبان للجهد المبذول', text_ar = 'الراتب والحوافز مناسبان للجهد المبذول' WHERE id = 89;
UPDATE assessment_structure SET name_ar = 'توجد فرص عادلة للترقي والتطور الوظيفي', text_ar = 'توجد فرص عادلة للترقي والتطور الوظيفي' WHERE id = 90;
UPDATE assessment_structure SET name_ar = 'أحقق توازناً جيداً بين العمل والحياة الشخصية', text_ar = 'أحقق توازناً جيداً بين العمل والحياة الشخصية' WHERE id = 91;

-- Fill items for بيئة التعلم والطلاب (criteria 92, tool=likert_5)
UPDATE assessment_structure SET name_ar = 'مستوى الطلاب التحصيلي مناسب', text_ar = 'مستوى الطلاب التحصيلي مناسب' WHERE id = 93;
UPDATE assessment_structure SET name_ar = 'سلوك الطلاب وانضباطهم مقبول', text_ar = 'سلوك الطلاب وانضباطهم مقبول' WHERE id = 94;
UPDATE assessment_structure SET name_ar = 'أولياء الأمور يتفاعلون مع المدرسة بإيجابية', text_ar = 'أولياء الأمور يتفاعلون مع المدرسة بإيجابية' WHERE id = 95;

-- Update teacher_satisfaction criteria names
UPDATE assessment_structure SET name_ar = 'بيئة العمل' WHERE id = 79;
UPDATE assessment_structure SET name_ar = 'القيادة المدرسية' WHERE id = 84;
UPDATE assessment_structure SET name_ar = 'جودة الحياة الوظيفية' WHERE id = 88;
UPDATE assessment_structure SET name_ar = 'بيئة التعلم والطلاب' WHERE id = 92;

-- Add 1 more item to each criteria (expanding from 3-4 to 4-5 each)
-- بيئة العمل: +1 item (was 4, now 5)
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(14, 79, 'item', 'تتوفر فرص التطوير المهني بشكل مناسب', 'تتوفر فرص التطوير المهني بشكل مناسب', 'satisfaction_5', 1.00, 5, 1);
-- القيادة المدرسية: +2 items (was 3, now 5)
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(14, 84, 'item', 'العدالة والمساواة في التعامل مع جميع المعلمين', 'العدالة والمساواة في التعامل مع جميع المعلمين', 'likert_5', 1.00, 4, 1),
(14, 84, 'item', 'التواصل مع الإدارة فعال وسهل', 'التواصل مع الإدارة فعال وسهل', 'likert_5', 1.00, 5, 1);
-- جودة الحياة الوظيفية: +2 items (was 3, now 5)
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(14, 88, 'item', 'الأمان الوظيفي والاستقرار', 'الأمان الوظيفي والاستقرار', 'frequency_5', 1.00, 4, 1),
(14, 88, 'item', 'برامج التدريب والتطوير تلبي احتياجاتي المهنية', 'برامج التدريب والتطوير تلبي احتياجاتي المهنية', 'frequency_5', 1.00, 5, 1);
-- بيئة التعلم والطلاب: +2 items (was 3, now 5)
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(14, 92, 'item', 'الموارد التعليمية متوفرة وكافية', 'الموارد التعليمية متوفرة وكافية', 'likert_5', 1.00, 4, 1),
(14, 92, 'item', 'الدعم الفني متاح عند الحاجة', 'الدعم الفني متاح عند الحاجة', 'likert_5', 1.00, 5, 1);

-- Update criteria weights for teacher_satisfaction
UPDATE assessment_structure SET weight = 25.00 WHERE id = 79; -- بيئة العمل
UPDATE assessment_structure SET weight = 25.00 WHERE id = 84; -- القيادة المدرسية
UPDATE assessment_structure SET weight = 25.00 WHERE id = 88; -- جودة الحياة الوظيفية
UPDATE assessment_structure SET weight = 25.00 WHERE id = 92; -- بيئة التعلم والطلاب

-- ============================================================
-- الخطوة 10: تفعيل وملء الاستمارة 17 — رضا أولياء الأمور
-- ============================================================
-- Fill items for التواصل مع المدرسة (criteria 96, tool=frequency_5)
UPDATE assessment_structure SET name_ar = 'التواصل مع إدارة المدرسة سهل وميسر', text_ar = 'التواصل مع إدارة المدرسة سهل وميسر' WHERE id = 97;
UPDATE assessment_structure SET name_ar = 'المدرسة ترد على استفساراتي بشكل سريع', text_ar = 'المدرسة ترد على استفساراتي بشكل سريع' WHERE id = 98;
UPDATE assessment_structure SET name_ar = 'أتلقي إشعارات دورية عن مستوى أبنائي', text_ar = 'أتلقي إشعارات دورية عن مستوى أبنائي' WHERE id = 99;

-- Fill items for جودة التعليم (criteria 100, tool=satisfaction_5)
UPDATE assessment_structure SET name_ar = 'المناهج الدراسية ذات جودة عالية', text_ar = 'المناهج الدراسية ذات جودة عالية' WHERE id = 101;
UPDATE assessment_structure SET name_ar = 'مستوى المعلمين في التدريس جيد', text_ar = 'مستوى المعلمين في التدريس جيد' WHERE id = 102;
UPDATE assessment_structure SET name_ar = 'ألاحظ تقدماً في تحصيل أبنائي الدراسي', text_ar = 'ألاحظ تقدماً في تحصيل أبنائي الدراسي' WHERE id = 103;

-- Fill items for بيئة المدرسة (criteria 104, tool=satisfaction_5)
UPDATE assessment_structure SET name_ar = 'المدرسة نظيفة ومرافقها جيدة', text_ar = 'المدرسة نظيفة ومرافقها جيدة' WHERE id = 105;
UPDATE assessment_structure SET name_ar = 'أشعر بالاطمئنان على سلامة أبنائي في المدرسة', text_ar = 'أشعر بالاطمئنان على سلامة أبنائي في المدرسة' WHERE id = 106;
UPDATE assessment_structure SET name_ar = 'الخدمات الأساسية للمدرسة متوفرة', text_ar = 'الخدمات الأساسية للمدرسة متوفرة' WHERE id = 107;

-- Fill items for الأنشطة والخدمات (criteria 108, tool=likert_5)
UPDATE assessment_structure SET name_ar = 'الأنشطة اللاصفية متنوعة ومفيدة', text_ar = 'الأنشطة اللاصفية متنوعة ومفيدة' WHERE id = 109;
UPDATE assessment_structure SET name_ar = 'البرامج التعليمية المساندة ذات جودة', text_ar = 'البرامج التعليمية المساندة ذات جودة' WHERE id = 110;
UPDATE assessment_structure SET name_ar = 'المدرسة تهتم بالموهوبين وتنمي قدراتهم', text_ar = 'المدرسة تهتم بالموهوبين وتنمي قدراتهم' WHERE id = 111;

-- Update parent_satisfaction criteria names
UPDATE assessment_structure SET name_ar = 'التواصل مع المدرسة' WHERE id = 96;
UPDATE assessment_structure SET name_ar = 'جودة التعليم' WHERE id = 100;
UPDATE assessment_structure SET name_ar = 'بيئة المدرسة' WHERE id = 104;
UPDATE assessment_structure SET name_ar = 'الأنشطة والخدمات' WHERE id = 108;

-- Expand: add 2 items per criteria (3→5)
INSERT INTO assessment_structure (form_id, parent_id, type, name_ar, text_ar, tool_type, weight, sort_order, is_active) VALUES
(17, 96, 'item', 'المدرسة تنظم اجتماعات دورية مع أولياء الأمور', 'المدرسة تنظم اجتماعات دورية مع أولياء الأمور', 'frequency_5', 1.00, 4, 1),
(17, 96, 'item', 'قنوات التواصل المتاحة متنوعة ومناسبة', 'قنوات التواصل المتاحة متنوعة ومناسبة', 'frequency_5', 1.00, 5, 1),
(17, 100, 'item', 'المدرسة تنمي مهارات أبنائي بشكل متوازن', 'المدرسة تنمي مهارات أبنائي بشكل متوازن', 'satisfaction_5', 1.00, 4, 1),
(17, 100, 'item', 'الأنشطة التعليمية متنوعة ومفيدة', 'الأنشطة التعليمية متنوعة ومفيدة', 'satisfaction_5', 1.00, 5, 1),
(17, 104, 'item', 'المواصلات المدرسية آمنة ومنظمة', 'المواصلات المدرسية آمنة ومنظمة', 'satisfaction_5', 1.00, 4, 1),
(17, 104, 'item', 'المقصف المدرسي يقدم وجبات صحية', 'المقصف المدرسي يقدم وجبات صحية', 'satisfaction_5', 1.00, 5, 1),
(17, 108, 'item', 'المدرسة تقدم دعماً لذوي الاحتياجات الخاصة', 'المدرسة تقدم دعماً لذوي الاحتياجات الخاصة', 'likert_5', 1.00, 4, 1),
(17, 108, 'item', 'المدرسة تسهم في خدمة المجتمع المحلي', 'المدرسة تسهم في خدمة المجتمع المحلي', 'likert_5', 1.00, 5, 1);

-- Update criteria weights for parent_satisfaction
UPDATE assessment_structure SET weight = 25.00 WHERE id = 96;  -- التواصل مع المدرسة
UPDATE assessment_structure SET weight = 30.00 WHERE id = 100; -- جودة التعليم
UPDATE assessment_structure SET weight = 25.00 WHERE id = 104; -- بيئة المدرسة
UPDATE assessment_structure SET weight = 20.00 WHERE id = 108; -- الأنشطة والخدمات

-- ============================================================
-- الخطوة 11: إصلاح sort_order للاستمارة 6
-- (ترتيب الأسئلة حسب المعيار)
-- ============================================================
UPDATE assessment_structure SET sort_order = 3 WHERE id = 45 AND sort_order != 3;
UPDATE assessment_structure SET sort_order = 4 WHERE id = 46 AND sort_order != 4;
UPDATE assessment_structure SET sort_order = 5 WHERE id = 47 AND sort_order != 5;
UPDATE assessment_structure SET sort_order = 6 WHERE id = 48 AND sort_order != 6;

-- ============================================================
-- الخطوة 12: تنظيف بيانات الاختبار (form_id=22)
-- ============================================================
UPDATE assessment_structure SET is_active = 0 WHERE form_id = 22;

-- ============================================================
-- ملخص التغييرات
-- ============================================================
-- Form 1 (behavior_eval): 6 items → 12 items (6 new)
-- Form 2 (skills_checklist): 6 items → 14 items (8 new + new criteria)
-- Form 3 (teaching_self): 1 item → 24 items (full rebuild)
-- Form 4 (teaching_peer): 4 items → 16 items (12 new + 2 new criteria)
-- Form 5 (teaching_admin): 2 items → 23 items (full rebuild)
-- Form 6 (climate_survey): 18 empty texts → all filled
-- Form 7 (facility_checklist): 4 items → 13 items (9 new + new criteria)
-- Form 14 (teacher_satisfaction): activated, 13→19 items, all filled
-- Form 17 (parent_satisfaction): activated, 12→20 items, all filled
-- Parent_id bug: fixed in forms 1, 2, 4
