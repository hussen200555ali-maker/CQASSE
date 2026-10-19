#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
EQAS ERD Generator - Extract database schema and relationships from SQL file
Generates CSV files and PlantUML code for Draw.io ERD
"""

import re
import os
from collections import defaultdict

SQL_FILE = r"C:\xamppp\htdocs\eqas\docs\eqas_db (8).sql"
OUTPUT_DIR = r"C:\xamppp\htdocs\eqas\docs\erd"

# Define table sections based on database design
SECTIONS = {
    "01-Academic": [
        "academic_years", "semesters", "grades", "grade_levels"
    ],
    "02-Users-Permissions": [
        "users", "roles", "permissions", "role_permissions"
    ],
    "03-Schools": [
        "schools", "governorates"
    ],
    "04-Students-Parents": [
        "students", "student_enrollments", "student_notes", 
        "parent_students", "parent_acknowledgments"
    ],
    "05-Subjects-Teaching": [
        "subjects", "subject_teachers", "subject_grade_years",
        "subject_weight_distribution", "teacher_assignments", "classes"
    ],
    "06-Assessments": [
        "assessment_forms", "assessment_responses", "assessment_structure",
        "assessment_types", "tests", "evaluations"
    ],
    "07-Behavior-Climate": [
        "behavior_categories", "behavior_incidents", "behavior_scores",
        "climate_items", "surveys", "survey_items"
    ],
    "08-EQI-Improvement": [
        "eqi_results", "improvement_plans", "school_improvement_plans"
    ],
    "09-Operations": [
        "promotions", "promotion_overrides", "notifications", "alerts",
        "activity_logs", "attachments", "export_history", "system_settings",
        "user_preferences", "learning_outcomes", "attendance_daily",
        "student_subject_summary", "v_attendance_summary", "v_semester_scores",
        "v_student_current", "v_student_subject_summary"
    ]
}

def read_sql_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        return f.read()

def extract_tables(sql_content):
    """Extract all CREATE TABLE statements"""
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
            
            # Extract column name and definition
            col_match = re.match(r'`(\w+)`\s+(.*)', line)
            if col_match:
                col_name = col_match.group(1)
                col_def = col_match.group(2).strip()
                columns.append({'name': col_name, 'def': col_def})
        
        tables[table_name] = columns
    
    return tables

def extract_foreign_keys(sql_content):
    """Extract FOREIGN KEY constraints"""
    fk_pattern = r'ADD CONSTRAINT `fk_(\w+)` FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`(\w+)`\)'
    fks = re.findall(fk_pattern, sql_content)
    
    relationships = []
    for fk_name, col, ref_table, ref_col in fks:
        relationships.append({
            'from_table': get_table_from_fk(col),
            'from_column': col,
            'to_table': ref_table,
            'to_column': ref_col
        })
    
    return relationships

def get_table_from_fk(column):
    """Infer table name from foreign key column"""
    # Common patterns: table_id, table_name_id
    table_map = {
        'school_id': 'schools',
        'user_id': 'users',
        'student_id': 'students',
        'teacher_id': 'users',
        'academic_year_id': 'academic_years',
        'semester_id': 'semesters',
        'class_id': 'classes',
        'grade_level_id': 'grade_levels',
        'role_id': 'roles',
        'permission_id': 'permissions',
        'parent_id': 'users',
        'category_id': 'behavior_categories',
        'form_id': 'assessment_forms',
        'item_id': 'assessment_structure',
        'test_id': 'tests',
        'subject_id': 'subjects',
        'respondent_id': 'users',
        'created_by': 'users',
        'updated_by': 'users',
        'decided_by': 'users',
        'approved_by': 'users',
        'recorded_by': 'users',
        'evaluated_by': 'users',
        'evaluator_id': 'users',
        'responsible_user_id': 'users',
        'resolved_by': 'users',
        'responded_by': 'users',
        'calculated_by': 'users',
        'locked_by': 'users',
        'subject_grade_year_id': 'subject_grade_years',
        'assessment_type_id': 'assessment_types',
        'evaluation_id': 'evaluations',
        'survey_id': 'surveys',
        'checklist_id': 'facility_checklists',
        'behavior_category_id': 'behavior_categories',
    }
    return table_map.get(column, 'unknown')

def extract_section(sql_content, section_name):
    """Extract tables and relationships for a specific section"""
    section_tables = SECTIONS.get(section_name, [])
    
    # Extract relevant tables
    tables = extract_tables(sql_content)
    section_table_data = {t: tables.get(t, []) for t in section_tables if t in tables}
    
    # Extract relevant relationships
    all_relationships = extract_foreign_keys(sql_content)
    section_relationships = [
        r for r in all_relationships 
        if r['from_table'] in section_tables or r['to_table'] in section_tables
    ]
    
    return section_table_data, section_relationships

def generate_drawio_xml(section_name, tables, relationships):
    """Generate Draw.io XML for a section"""
    # Calculate positions for tables
    rows = []
    cols = 3
    tables_list = list(tables.keys())
    
    # Simple grid layout
    for i, table in enumerate(tables_list):
        row = i // cols
        col = i % cols
        x = 100 + col * 350
        y = 100 + row * 250
        rows.append((table, x, y))
    
    # Generate XML
    xml = '''<?xml version="1.0" encoding="UTF-8"?>
<mxfile host="app.diagrams.net" modified="2026-05-18T00:00:00.000Z" agent="Python" version="21.0.0">
  <diagram name="%s" id="diagram_1">
    <mxGraphModel dx="1200" dy="800" grid="1" gridSize="10" guides="1" tooltips="1" connect="1" arrows="1" fold="1" page="1" pageScale="1" pageWidth="1200" pageHeight="900" math="0" shadow="0">
      <root>
        <mxCell id="0" />
        <mxCell id="1" parent="0" />
''' % section_name
    
    # Add title
    title_y = 30
    xml += '''        <mxCell id="title_1" value="<div><font size=&quot;20&quot; face=&quot;Arabic Typesetting&quot;><b>%s</b></font></div>" style="text;html=1;strokeColor=none;fillColor=none;align=center;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontColor=#333333;" vertex="1" parent="1">
          <mxGeometry x="300" y="%d" width="600" height="40" as="geometry" />
        </mxCell>
''' % (section_name, title_y)
    
    # Add tables
    table_id = 2
    for table, x, y in rows:
        cols_list = tables.get(table, [])
        
        # Table header
        xml += '''        <mxCell id="%d" value="%s" style="swimlane;childLayout=stackLayout;horizontal=1;startSize=30;fillColor=#4472C4;horizontalStack=0;resizeParent=1;resizeParentMax=0;resizeLast=0;collapsible=1;marginBottom=0;fontColor=#FFFFFF;fontStyle=1" vertex="1" parent="1">
          <mxGeometry x="%d" y="%d" width="300" height="%d" as="geometry" />
        </mxCell>
''' % (table_id, table, x, y, 30 + min(len(cols_list), 8) * 24 + 10)
        
        table_id += 1
        
        # Table columns
        for i, col in enumerate(cols_list[:8]):  # Limit to 8 columns display
            col_name = col['name']
            col_type = col['def'].split(' ')[0][:20]  # Get type only
            
            # Determine if primary/foreign key
            style = "text;html=1;align=left;verticalAlign=middle;whiteSpace=wrap;rounded=0;"
            if col_name.endswith('_id'):
                style = "text;html=1;align=left;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontColor=#006600;fontStyle=2"
            
            xml += '''        <mxCell id="%d" value="%s (%s)" style="%s" vertex="1" parent="1">
          <mxGeometry x="%d" y="%d" width="300" height="20" as="geometry" />
        </mxCell>
''' % (table_id, col_name, col_type, style, x, y + 30 + i * 24)
            table_id += 1
        
        if len(cols_list) > 8:
            xml += '''        <mxCell id="%d" value="... +%d more" style="text;html=1;align=left;verticalAlign=middle;whiteSpace=wrap;rounded=0;fontColor=#666666;fontStyle=2" vertex="1" parent="1">
          <mxGeometry x="%d" y="%d" width="300" height="20" as="geometry" />
        </mxCell>
''' % (table_id, len(cols_list) - 8, x, y + 30 + 8 * 24)
            table_id += 1
    
    # Add relationships (simplified as straight lines)
    # This is a simplified version - in real Draw.io you'd use connectors
    
    xml += '''      </root>
    </mxGraphModel>
  </diagram>
</mxfile>'''
    
    return xml

def generate_plantuml(section_name, tables, relationships):
    """Generate PlantUML code for a section"""
    uml = f'@startuml {section_name}\n'
    uml += 'skinparam linetype ortho\n'
    uml += 'skinparam rectangle {+ backgroundColor White }\n\n'
    
    # Add tables with columns
    for table, cols in tables.items():
        uml += f'entity "{table}" as {table} {{\n'
        for col in cols[:10]:  # Limit columns
            col_name = col['name']
            col_type = col['def'].split(' ')[0]
            if col_name.endswith('_id'):
                uml += f'  * {col_name} : {col_type}\n'
            else:
                uml += f'  {col_name} : {col_type}\n'
        uml += '}\n\n'
    
    # Add relationships
    for rel in relationships:
        from_t = rel['from_table']
        to_t = rel['to_table']
        if from_t in tables and to_t in tables:
            uml += f'{from_t} ||--o{{ {to_t}\n'
    
    uml += '@enduml\n'
    return uml

def generate_csv(section_name, tables, relationships):
    """Generate CSV for Draw.io table import"""
    csv = "Table,Column,Type,Key\n"
    
    for table, cols in tables.items():
        for col in cols:
            col_name = col['name']
            col_type = col['def'].split(' ')[0]
            key = "PK" if col_name == 'id' else ("FK" if col_name.endswith('_id') else "")
            csv += f"{table},{col_name},{col_type},{key}\n"
    
    return csv

def generate_summary():
    """Generate summary of all sections"""
    summary = "# EQAS Database ERD Summary\n\n"
    summary += "## Sections\n\n"
    
    for section, tables in SECTIONS.items():
        summary += f"### {section}\n"
        summary += f"- Tables: {len(tables)}\n"
        summary += f"- {', '.join(tables)}\n\n"
    
    return summary

def main():
    print("EQAS ERD Generator")
    print("=" * 50)
    
    if not os.path.exists(SQL_FILE):
        print(f"Error: SQL file not found: {SQL_FILE}")
        return
    
    # Read SQL file
    print("Reading SQL file...")
    sql_content = read_sql_file(SQL_FILE)
    
    # Extract all tables
    print("Extracting tables...")
    all_tables = extract_tables(sql_content)
    print(f"Found {len(all_tables)} tables")
    
    # Extract relationships
    print("Extracting relationships...")
    all_relationships = extract_foreign_keys(sql_content)
    print(f"Found {len(all_relationships)} foreign key relationships")
    
    # Generate files for each section
    print("\nGenerating ERD files...")
    
    for section_name, section_tables in SECTIONS.items():
        print(f"  Processing {section_name}...")
        
        # Get section tables
        section_table_data = {t: all_tables.get(t, []) for t in section_tables if t in all_tables}
        
        # Get section relationships
        section_relationships = [
            r for r in all_relationships 
            if r['from_table'] in section_tables or r['to_table'] in section_tables
        ]
        
        # Generate Draw.io XML
        xml = generate_drawio_xml(section_name, section_table_data, section_relationships)
        xml_path = os.path.join(OUTPUT_DIR, f"{section_name}.drawio")
        with open(xml_path, 'w', encoding='utf-8') as f:
            f.write(xml)
        print(f"    Created: {xml_path}")
        
        # Generate PlantUML
        uml = generate_plantuml(section_name, section_table_data, section_relationships)
        puml_path = os.path.join(OUTPUT_DIR, f"{section_name}.puml")
        with open(puml_path, 'w', encoding='utf-8') as f:
            f.write(uml)
        print(f"    Created: {puml_path}")
    
    # Generate summary
    summary = generate_summary()
    summary_path = os.path.join(OUTPUT_DIR, "README.md")
    with open(summary_path, 'w', encoding='utf-8') as f:
        f.write(summary)
    print(f"    Created: {summary_path}")
    
    print("\n" + "=" * 50)
    print("ERD generation complete!")
    print(f"Output directory: {OUTPUT_DIR}")

if __name__ == "__main__":
    main()