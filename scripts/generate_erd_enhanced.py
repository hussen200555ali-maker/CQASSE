#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
EQAS ERD Enhanced Generator - Creates improved Draw.io ERD with relationships
"""

import re
import os

SQL_FILE = r"C:\xamppp\htdocs\eqas\docs\eqas_db (8).sql"
OUTPUT_DIR = r"C:\xamppp\htdocs\eqas\docs\erd"

SECTIONS = {
    "01-Academic": {
        "title": "المجال الأكاديمي - Academic Domain",
        "tables": ["academic_years", "semesters", "grades", "grade_levels"],
        "color": "#4472C4"
    },
    "02-Users-Permissions": {
        "title": "المستخدمين والصلاحيات - Users & Permissions",
        "tables": ["users", "roles", "permissions", "role_permissions"],
        "color": "#ED7D31"
    },
    "03-Schools": {
        "title": "المدارس - Schools",
        "tables": ["schools", "governorates"],
        "color": "#70AD47"
    },
    "04-Students-Parents": {
        "title": "الطلاب وأولياء الأمور - Students & Parents",
        "tables": ["students", "student_enrollments", "student_notes", "parent_students", "parent_acknowledgments"],
        "color": "#5B9BD5"
    },
    "05-Subjects-Teaching": {
        "title": "المواد والتدريس - Subjects & Teaching",
        "tables": ["subjects", "subject_teachers", "subject_grade_years", "subject_weight_distribution", "teacher_assignments", "classes"],
        "color": "#FFC000"
    },
    "06-Assessments": {
        "title": "التقييمات والاختبارات - Assessments",
        "tables": ["assessment_forms", "assessment_responses", "assessment_structure", "assessment_types", "tests", "evaluations"],
        "color": "#7030A0"
    },
    "07-Behavior-Climate": {
        "title": "السلوك والمناخ - Behavior & Climate",
        "tables": ["behavior_categories", "behavior_incidents", "behavior_scores", "climate_items", "surveys", "survey_items"],
        "color": "#C00000"
    },
    "08-EQI-Improvement": {
        "title": "مؤشر الجودة والتحسين - EQI & Improvement",
        "tables": ["eqi_results", "improvement_plans", "school_improvement_plans"],
        "color": "#00B050"
    },
    "09-Operations": {
        "title": "العمليات والإشعارات - Operations & Notifications",
        "tables": ["promotions", "promotion_overrides", "notifications", "alerts", "activity_logs", "attachments", "export_history", "system_settings", "user_preferences", "learning_outcomes", "attendance_daily"],
        "color": "#44546A"
    }
}

def read_sql_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        return f.read()

def extract_tables(sql_content):
    tables = {}
    pattern = r'CREATE TABLE `(\w+)` \((.*?)\);'
    matches = re.findall(pattern, sql_content, re.DOTALL)
    
    for table_name, columns_text in matches:
        columns = []
        lines = columns_text.strip().split('\n')
        for line in lines:
            line = line.strip().rstrip(',').strip()
            if not line or line.startswith('--'):
                continue
            col_match = re.match(r'`(\w+)`\s+(.*)', line)
            if col_match:
                col_name = col_match.group(1)
                col_def = col_match.group(2).strip()
                columns.append({'name': col_name, 'def': col_def})
        tables[table_name] = columns
    return tables

def extract_all_foreign_keys(sql_content):
    """
    Extract all FK relationships from SQL.
    Returns list of: {child_table, parent_table, fk_column, pk_column}
    """
    fks = []
    pattern = r'ADD CONSTRAINT `(\w+)` FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`(\w+)`\)'
    matches = re.findall(pattern, sql_content)
    
    # Map column name patterns to their parent tables
    # Key = column suffix, Value = parent table name
    column_to_parent = {
        'school_id': 'schools',
        'user_id': 'users',
        'student_id': 'students',
        'teacher_id': 'users',
        'parent_id': 'users',
        'academic_year_id': 'academic_years',
        'semester_id': 'semesters',
        'class_id': 'classes',
        'grade_level_id': 'grade_levels',
        'role_id': 'roles',
        'permission_id': 'permissions',
        'category_id': 'behavior_categories',
        'form_id': 'assessment_forms',
        'item_id': 'assessment_structure',
        'test_id': 'tests',
        'subject_id': 'subjects',
        'subject_grade_year_id': 'subject_grade_years',
        'assessment_type_id': 'assessment_types',
        'evaluation_id': 'evaluations',
        'survey_id': 'surveys',
        'created_by': 'users',
        'updated_by': 'users',
        'decided_by': 'users',
        'approved_by': 'users',
        'recorded_by': 'users',
        'evaluator_id': 'users',
        'responsible_user_id': 'users',
        'resolved_by': 'users',
        'responded_by': 'users',
        'calculated_by': 'users',
        'locked_by': 'users',
        'reported_by': 'users',
        'evaluated_by': 'users',
    }
    
    for fk_name, col, ref_table, ref_col in matches:
        # The child table is the one that has the FK column
        # We need to infer the child from the full constraint context
        # For now, infer based on column type patterns
        
        # The ref_table is the parent (referenced table)
        parent = ref_table
        
        # Infer child table - check which table in our sections has this column
        # We'll handle this in the section processing
        fks.append({
            'child_column': col,
            'parent_table': parent,
            'fk_column': col,
            'pk_column': ref_col
        })
    
    return fks

def get_table_columns(table_name, tables):
    cols = tables.get(table_name, [])
    return [c['name'] for c in cols]

def escape_xml(text):
    """Escape special characters for XML"""
    return (text.replace('&', '&amp;')
              .replace('<', '&lt;')
              .replace('>', '&gt;')
              .replace('"', '&quot;')
              .replace("'", '&apos;'))

def generate_enhanced_drawio(section_key, section_info, tables, all_fks):
    section_tables = section_info['tables']
    color = section_info['color']
    
    xml = '''<?xml version="1.0" encoding="UTF-8"?>
<mxfile host="app.diagrams.net" modified="2026-05-18T05:00:00.000Z" agent="Python-ERD-Generator" version="21.0.0">
  <diagram name="%s" id="diagram_%s">
    <mxGraphModel dx="1500" dy="900" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1400" pageHeight="1000" math="0" shadow="0">
      <root>
        <mxCell id="0" />
        <mxCell id="1" parent="0" />
''' % (section_key, section_key.split('-')[0])
    
    # Title - escaped
    title_text = escape_xml(section_info['title'])
    
    # Build column-to-table mapping for this section (for counting)
    column_to_table_count = {}
    for table, cols in tables.items():
        if table in section_tables:
            for col in cols:
                col_name = col['name']
                if col_name.endswith('_id') and col_name != 'id':
                    column_to_table_count[col_name] = table
    
    # Count relationships in this section
    rel_count = 0
    for fk in all_fks:
        fk_col = fk['child_column']
        parent = fk['parent_table']
        child = column_to_table_count.get(fk_col)
        if child and child in section_tables and parent in section_tables:
            rel_count += 1
    
    xml += '''        <mxCell id="title" value="%s" style="text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontColor=#333333;fontSize=20;fontStyle=1" vertex="1" parent="1">
          <mxGeometry x="200" y="20" width="1000" height="40" as="geometry" />
        </mxCell>
        <mxCell id="subtitle" value="Database ERD - %d Tables, %d Relationships" style="text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontColor=#666666;fontSize=12;" vertex="1" parent="1">
          <mxGeometry x="200" y="55" width="1000" height="20" as="geometry" />
        </mxCell>
''' % (title_text, len(section_tables), rel_count)
    
    # Calculate grid positions
    cols = 3
    table_data = []
    for i, table in enumerate(section_tables):
        row = i // cols
        col = i % cols
        x = 50 + col * 420
        y = 120 + row * 280
        table_data.append((table, x, y))
    
    # Generate table shapes
    cell_id = 100
    for table, x, y in table_data:
        cols_list = tables.get(table, [])
        height = 35 + min(len(cols_list), 10) * 22
        
        # Table header
        escaped_table = escape_xml(table)
        xml += '''        <mxCell id="%d" value="%s" style="swimlane;childLayout=stackLayout;horizontal=1;startSize=35;fillColor=%s;horizontalStack=0;resizeParent=1;resizeParentMax=0;resizeLast=0;collapsible=1;marginBottom=0;fontColor=#FFFFFF;fontStyle=1;fontSize=14" vertex="1" parent="1">
          <mxGeometry x="%d" y="%d" width="380" height="%d" as="geometry" />
        </mxCell>
''' % (cell_id, escaped_table, section_info['color'], x, y, height)
        cell_id += 1
        
        # Columns
        for j, col in enumerate(cols_list[:10]):
            col_name = col['name']
            col_type = col['def'].split(' ')[0]
            if len(col['def']) > 30:
                col_type = col_type[:25] + "..."
            
            # Style based on key type
            if col_name == 'id':
                style = "text;html=1;align=left;verticalAlign=middle;whiteSpace=wrap;rounded=0;fillColor=#E7E6E6;fontStyle=1;fontColor=#000000;"
            elif col_name.endswith('_id'):
                style = "text;html=1;align=left;verticalAlign=middle;whiteSpace=wrap;rounded=0;fillColor=#F2F2F2;fontColor=#006600;fontStyle=2"
            else:
                style = "text;html=1;align=left;verticalAlign=middle;whiteSpace=wrap;rounded=0;"
            
            escaped_name = escape_xml(col_name)
            escaped_type = escape_xml(col_type)
            xml += '''        <mxCell id="%d" value="%s (%s)" style="%s" vertex="1" parent="1">
          <mxGeometry x="%d" y="%d" width="380" height="20" as="geometry" />
        </mxCell>
''' % (cell_id, escaped_name, escaped_type, style, x, y + 35 + j * 22)
            cell_id += 1
        
        if len(cols_list) > 10:
            xml += '''        <mxCell id="%d" value="... +%d more columns" style="text;html=1;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontColor=#888888;fontStyle=2;fontSize=11" vertex="1" parent="1">
          <mxGeometry x="%d" y="%d" width="380" height="20" as="geometry" />
        </mxCell>
''' % (cell_id, len(cols_list)-10, x, y + 35 + 10 * 22)
            cell_id += 1
    
    # Add relationship connectors - draw arrows between tables based on FK
    table_indices = {table: idx for idx, (table, _, _) in enumerate(table_data)}
    section_table_set = set(section_tables)
    
    # Build column-to-table mapping for this section
    column_to_table = {}
    for table, cols in tables.items():
        if table in section_table_set:
            for col in cols:
                col_name = col['name']
                if col_name.endswith('_id') and col_name != 'id':
                    column_to_table[col_name] = table
    
    # Find all FK relationships within this section
    for fk in all_fks:
        fk_col = fk['child_column']
        parent = fk['parent_table']
        
        # Find which table in this section has this FK column
        child = column_to_table.get(fk_col)
        
        if child and child in section_table_set and parent in section_table_set:
            i = table_indices[child]
            j = table_indices[parent]
            
            table1, x1, y1 = table_data[i]
            table2, x2, y2 = table_data[j]
            
            # Calculate edge points based on relative position
            if x2 > x1:  # parent is to the right
                source_x = x1 + 380
                target_x = x2
                mid_x = (x1 + x2 + 380) // 2
            else:  # parent is to the left
                source_x = x1
                target_x = x2 + 380
                mid_x = (x1 + x2) // 2
            
            # Connect to middle of table height
            source_y = y1 + 100
            target_y = y2 + 100
            
            xml += '''        <mxCell id="rel_%d_%d" value="" style="edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;strokeColor=#C00000;strokeWidth=2;endArrow=classic;endFill=1;" edge="1" parent="1">
          <mxGeometry relative="1" as="geometry">
            <Array as="points">
              <mxPoint x="%d" y="%d" />
              <mxPoint x="%d" y="%d" />
            </Array>
          </mxGeometry>
        </mxCell>
''' % (i, j, source_x, source_y, mid_x, source_y)
    
    xml += '''      </root>
    </mxGraphModel>
  </diagram>
</mxfile>'''
    
    return xml

def main():
    print("EQAS ERD Enhanced Generator")
    print("=" * 50)
    
    if not os.path.exists(SQL_FILE):
        print(f"Error: SQL file not found: {SQL_FILE}")
        return
    
    print("Reading SQL file...")
    sql_content = read_sql_file(SQL_FILE)
    
    print("Extracting tables...")
    all_tables = extract_tables(sql_content)
    print(f"Found {len(all_tables)} tables")
    
    print("Extracting relationships...")
    all_fks = extract_all_foreign_keys(sql_content)
    
    print("\nGenerating enhanced ERD files...")
    
    for section_key, section_info in SECTIONS.items():
        print(f"  Processing {section_key}...")
        
        # Get section tables
        section_table_data = {t: all_tables.get(t, []) for t in section_info['tables'] if t in all_tables}
        
        # Generate enhanced Draw.io
        xml = generate_enhanced_drawio(section_key, section_info, all_tables, all_fks)
        xml_path = os.path.join(OUTPUT_DIR, f"{section_key}.drawio")
        
        with open(xml_path, 'w', encoding='utf-8') as f:
            f.write(xml)
        
        print(f"    Created: {xml_path}")
    
    print("\n" + "=" * 50)
    print("Enhanced ERD generation complete!")
    print(f"Output directory: {OUTPUT_DIR}")

if __name__ == "__main__":
    main()