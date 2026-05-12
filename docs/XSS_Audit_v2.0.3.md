# XSS Audit - EQAS v2.0.3

**Date**: 2026-05-11
**Auditor**: Sub Agent (Phase 2)
**Scope**: All PHP files under `/pages/` and `/includes/`

## Methodology

1. Searched for `<?= $` patterns (direct variable output in HTML) excluding safe patterns (`<?= e(`, `<?= htmlspecialchars(`)
2. Searched for `echo $` patterns in HTML context
3. Searched for `value="<?= $` in form inputs without htmlspecialchars
4. Verified source of variables (user input vs server-generated)
5. Applied risk levels based on whether the variable originates from `$_GET`/`$_POST` (High), database IDs (Low), or server-calculated values (Info)

## Risk Levels

- **High**: Variable originates from `$_GET`/`$_POST` and is output without escaping — directly exploitable
- **Medium**: Variable is a database ID used in HTML attribute — theoretically exploitable if DB is compromised
- **Low**: Variable is server-generated (calculations, config) — not directly exploitable but defense-in-depth recommended
- **Info**: Variable is clearly an integer or boolean — no practical XSS risk

## Findings

### Fixed in Phase 2

| # | File | Line | Vulnerable Code | Variable | Risk Level | Fix Applied |
|---|------|------|----------------|----------|------------|-------------|
| 1 | `pages/settings/school_settings.php` | 180 | `value="<?php echo $selectedSchool; ?>"` | `$selectedSchool` ← `$_GET['school_id']` | **High** | Changed to `htmlspecialchars($selectedSchool, ENT_QUOTES, 'UTF-8')` |
| 2 | `pages/settings/school_settings.php` | 54 | `redirect('...?school_id=' . $selectedSchool)` | `$selectedSchool` ← `$_GET['school_id']` | **High** | Added `(int)` cast |
| 3 | `pages/settings/school_settings.php` | 105 | `redirect('...&category=' . $selectedCategory)` | `$selectedCategory` ← `$_GET['category']` | **Medium** | Added `urlencode()` |
| 4 | `pages/academic_assessment/grade_entry.php` | 306 | `($_GET['semester_id'] ?? '') == $sem['id']` | `$_GET['semester_id']` | **Medium** | Changed to `(int)($_GET['semester_id'] ?? 0)` |
| 5 | `pages/academic_assessment/grade_entry.php` | 427 | `value="<?= $_GET['semester_id'] ?? ... ?>"` | `$_GET['semester_id']` | **High** | Changed to `(int)($_GET['semester_id'] ?? ...)` |
| 6 | `pages/promotion/evaluate.php` | 143 | `value="<?= $selectedYear ?>"` | `$selectedYear` ← `$_GET['academic_year_id']` | **High** | Changed to `htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8')` + added `(int)` cast at source |
| 7 | `pages/promotion/evaluate.php` | 144 | `value="<?= $selectedSemester ?>"` | `$selectedSemester` ← `$_GET['semester_id']` | **High** | Changed to `htmlspecialchars($selectedSemester, ENT_QUOTES, 'UTF-8')` + added `(int)` cast at source |
| 8 | `pages/eqi/calculate.php` | 43-45 | `$selectedSchool = $_GET['school_id'] ?? ...` | All three from `$_GET` | **High** | Added `(int)` cast at source |
| 9 | `pages/eqi/index.php` | 43-45 | `$selectedSchool = $_GET['school_id'] ?? ...` | All three from `$_GET` | **High** | Added `(int)` cast at source |
| 10 | `pages/eqi/compare.php` | 37-38 | `$selectedYear = $_GET['academic_year_id'] ?? ...` | Two from `$_GET` | **High** | Added `(int)` cast at source |
| 11 | `pages/eqi/history.php` | 35 | `$selectedSchool = $_GET['school_id'] ?? ...` | From `$_GET` | **High** | Added `(int)` cast at source |

### Remaining Findings (Not Fixed — Low/Info Risk)

| # | File | Line(s) | Vulnerable Code | Variable | Risk Level | Recommendation |
|---|------|---------|----------------|----------|------------|----------------|
| 12 | `pages/promotion/evaluate.php` | 172-179 | `value="<?= $ev['student_id'] ?>`" and similar hidden inputs | `$ev['student_id']`, `$ev['decision']`, etc. | **Low** | Server-generated from PromotionEngine; add `e()` for defense-in-depth |
| 13 | `pages/promotion/conditional.php` | 133-135 | `value="<?= $student['student_id'] ?>"` etc. | `$student` from DB | **Low** | Server-generated; add `e()` for defense-in-depth |
| 14 | `pages/notes/add.php` | 170 | `value="<?= $draft['id'] ?>"` | `$draft['id']` from DB | **Low** | Integer ID from DB; low risk |
| 15 | `pages/notes/index.php` | 295-306 | `value="<?= $note['id'] ?>"`, `value="<?= $note['is_pinned'] ?? 0 }}"` | `$note` from DB | **Low** | Integer values from DB; low risk |
| 16 | `pages/supervision/improvement_plan.php` | 250-263 | `value="<?= $plan['id'] ?>"`, `value="<?= $plan['progress'] ?>"` | `$plan` from DB | **Low** | Server-generated; add `e()` for defense-in-depth |
| 17 | `pages/academic/lock.php` | 220 | `value="<?= $sem['id'] ?>"` | `$sem['id']` from DB | **Info** | Integer ID from DB |
| 18 | `pages/academic/semesters.php` | 198, 216 | `value="<?= $sem['id'] ?>"` | `$sem['id']` from DB | **Info** | Integer ID from DB |
| 19 | `pages/behavior/scores.php` | 171 | `value="<?= $cls['id'] ?>"` | `$cls['id']` from DB | **Info** | Integer ID from DB |
| 20 | `pages/behavior/history.php` | 132, 143 | `value="<?= $st['id'] ?>"`, `value="<?= $cls['id'] ?>"` | DB IDs | **Info** | Integer IDs from DB |
| 21 | `pages/behavior/incident.php` | 232, 245, 280, 287 | `value="<?= $cls['id'] ?>"`, `value="<?= $st['id'] ?>"`, `data-points="<?= $cat['points'] ?>"` | DB IDs/scores | **Info** | Integer values from DB |
| 22 | `pages/setup/sections.php` | 258 | `value="<?= $cl['id'] ?>"` | `$cl['id']` from DB | **Info** | Integer ID from DB |
| 23 | `pages/setup/school_info.php` | 103 | `value="<?= $val ?>"` | `$val` from static array | **Info** | Static value |
| 24 | `pages/setup/teachers_assign.php` | 256 | `value="<?= $a['id'] ?>"` | `$a['id']` from DB | **Info** | Integer ID from DB |
| 25 | `pages/setup/weight_setup.php` | 186, 195 | `value="<?= $sg['id'] ?>"`, `value="<?= $currentVal ?>"` | DB/calculated | **Low** | Server-generated; add `e()` for defense-in-depth |
| 26 | `pages/academic_assessment/summary.php` | 291 | `value="<?= $sem['id'] ?>"` selected comparison | `$selectedSemesterId` vs DB ID | **Info** | Integer comparison |
| 27 | `pages/academic_assessment/semester_calc.php` | 298 | `value="<?= $sem['id'] ?>"` selected comparison | DB ID | **Info** | Integer comparison |
| 28 | `pages/academic_assessment/tests.php` | 400, 408 | `value="<?= $tst['id'] ?>"` | `$tst['id']` from DB | **Info** | Integer ID from DB |
| 29 | `pages/notifications/preferences.php` | 164, 213, 295 | `value="<?= $freqKey ?>"`, `value="<?= $roleId ?>"` | Server-generated keys | **Info** | Static/server values |
| 30 | `pages/supervision/improvement_plan.php` | 154 | `value="<?= $val ?>"` | Static option value | **Info** | Static value |
| 31 | `pages/promotion/approve.php` | 68 | `<?= ($_GET['decision'] ?? '') === $k ?>` | `$_GET['decision']` in comparison | **Info** | Used in `===` comparison only, not output |
| 32 | `pages/alerts/bulk_resolve.php` | 86, 92 | `<?= ($_GET['alert_type'] ?? '') === $k ?>` | `$_GET` in comparison | **Info** | Used in `===` comparison only, not output |
| 33 | `pages/teaching/*.php` | Various | `echo $rating['color']`, `echo $eval['id']` | Server-generated | **Low** | Values from evaluation engine; add `e()` for defense-in-depth |
| 34 | `pages/eqi/dimensions.php` | 78 | `href="?dimension=<?= $key ?>"` | `$key` from `$dimensions` array | **Info** | Server-defined array key |

### Summary Statistics

| Metric | Count |
|--------|-------|
| Total findings | 34 |
| Fixed in Phase 2 | 11 |
| High risk (fixed) | 8 |
| Medium risk (fixed) | 2 |
| Low risk (remaining) | 6 |
| Info risk (remaining) | 15 |
| Low/Info risk recommended for defense-in-depth | 8 |

### Recommendations for Future Phases

1. **Defense-in-depth**: Apply `e()` to all `<?= $var ?>` output in HTML context, even for DB-sourced values. If the database is ever compromised, stored XSS becomes possible.
2. **Centralized input sanitization**: Create a `sanitizeInt()` helper and use it consistently at the point where `$_GET`/`$_POST` values are first assigned.
3. **Template engine**: Consider adopting a template engine (Twig, Blade) that auto-escapes by default.
4. **Content Security Policy**: Add CSP headers as an additional XSS mitigation layer.
