import re
import sys

sql_path = r"C:\xamppp\htdocs\eqas\docs\eqas_db (8).sql"

with open(sql_path, 'r', encoding='utf-8') as f:
    sql = f.read()

# Find all CREATE TABLE statements
table_pattern = re.compile(r'CREATE TABLE\s+`(\w+)`\s*\((.*?)\);\s*', re.IGNORECASE | re.DOTALL)

tables = {}
for match in table_pattern.finditer(sql):
    tname = match.group(1)
    body = match.group(2)
    cols = []
    for line in body.split('\n'):
        line = line.strip().rstrip(',')
        if not line or line.startswith('--'):
            continue
        # column definition: `col` type [constraints] ...
        # Use regex to capture column name, type, and the rest
        m = re.match(r'`(\w+)`\s+([^\s,]+)(.*)', line)
        if m:
            cname = m.group(1)
            ctype = m.group(2).upper()
            rest = m.group(3).upper()
            nullable = 'NOT NULL' not in rest
            pk = 'PRIMARY KEY' in rest
            cols.append((cname, ctype, {'nullable': nullable, 'pk': pk}))
    tables[tname] = cols

# Find FOREIGN KEY constraints
fk_pattern = re.compile(r'FOREIGN KEY\s*\(\s*`(\w+)`\s*\)\s*REFERENCES\s*`(\w+)`\s*\(\s*`(\w+)`\s*\)', re.IGNORECASE)
foreign_keys = []
for match in fk_pattern.finditer(sql):
    child_col, parent_table, parent_col = match.groups()
    child_table = None
    for tname, cols in tables.items():
        if any(c[0] == child_col for c in cols):
            child_table = tname
            break
    if child_table:
        foreign_keys.append((child_table, child_col, parent_table, parent_col))

# Also check for CONSTRAINT ... FOREIGN KEY ... (sometimes after column list)
constraint_pattern = re.compile(r'CONSTRAINT\s+`\w+`\s+FOREIGN KEY\s*\(\s*`(\w+)`\s*\)\s*REFERENCES\s*`(\w+)`\s*\(\s*`(\w+)`\s*\)', re.IGNORECASE)
for match in constraint_pattern.finditer(sql):
    child_col, parent_table, parent_col = match.groups()
    child_table = None
    for tname, cols in tables.items():
        if any(c[0] == child_col for c in cols):
            child_table = tname
            break
    if child_table:
        foreign_keys.append((child_table, child_col, parent_table, parent_col))

# Remove duplicates
foreign_keys = list(dict.fromkeys(foreign_keys))

# Now filter to only the tables we need
needed = {
    'users', 'roles', 'schools',
    'academic_years', 'semesters', 'grade_levels', 'classes', 'subjects',
    'students', 'student_enrollments',
    'tests', 'grades', 'subject_weight_distribution',
    'assessment_forms', 'assessment_responses', 'behavior_incidents', 'attendance_daily', 'eqi_results'
}

filtered_tables = {t: cols for t, cols in tables.items() if t in needed}
filtered_fks = [fk for fk in foreign_keys if fk[0] in needed and fk[2] in needed]

# Generate mermaid
lines = ['erDiagram']
# Define table groups for ordering maybe
groups = [
    ('المستخدمون والصلاحيات', ['users', 'roles', 'schools']),
    ('الهيكل الأكاديمي', ['academic_years', 'semesters', 'grade_levels', 'classes', 'subjects']),
    ('الطلاب والتسجيل', ['students', 'student_enrollments']),
    ('التقييم الأكاديمي', ['tests', 'grades', 'subject_weight_distribution']),
    ('التقييم الشامل (الأبعاد الأخرى)', ['assessment_forms', 'assessment_responses', 'behavior_incidents', 'attendance_daily', 'eqi_results'])
]

# Add tables with comments
for group_name, tbls in groups:
    lines.append(f'    %% {group_name}')
    for t in tbls:
        if t in filtered_tables:
            lines.append(f'    {t} {{')
            for cname, ctype, constr in filtered_tables[t]:
                # Determine if PK or FK
                flags = []
                if constr['pk']:
                    flags.append('PK')
                # Check if this column appears as FK in any foreign key (as child)
                if any(fk[0]==t and fk[1]==cname for fk in filtered_fks):
                    flags.append('FK')
                flag_str = ' '.join(flags)
                line = f'        {ctype} {cname}'
                if flag_str:
                    line += f' {flag_str}'
                lines.append(line)
            lines.append('    }')
            lines.append('')
# Relationships
lines.append('    %% العلاقات')
for child_t, child_c, parent_t, parent_c in filtered_fks:
    lines.append(f'    {child_t} ||--o{{ {parent_t} : "{child_c}"')

output = '\n'.join(lines)
# Write to file
with open(r'C:\xamppp\htdocs\eqas\docs\erd\academic_erd.mmd', 'w', encoding='utf-8') as f:
    f.write(output)
print('Written to academic_erd.mmd')
# Also print first 20 lines for debugging
print('\n--- First 20 lines ---')
for i, line in enumerate(output.split('\n')[:20]):
    print(f'{i:2}: {line}')