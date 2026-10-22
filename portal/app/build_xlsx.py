#!/usr/bin/env python3
"""
Zero-dependency native .xlsx generator using Python standard library zipfile.
Works across Windows, macOS, and Railway Linux containers without any pip packages.
Usage: python build_xlsx.py <input_json_path> <output_xlsx_path>
"""

import sys
import json
import zipfile
import xml.sax.saxutils as saxutils

def escape_xml(v):
    if v is None:
        return ""
    return saxutils.escape(str(v))

def col_letter(col_idx):
    """Converts 0-based column index to Excel column letters (A, B, ... Z, AA, AB, etc.)."""
    result = ""
    col_idx += 1
    while col_idx > 0:
        col_idx, remainder = divmod(col_idx - 1, 26)
        result = chr(65 + remainder) + result
    return result

def generate_xlsx(input_json_file, output_xlsx_file):
    with open(input_json_file, 'r', encoding='utf-8') as f:
        data = json.load(f)

    title = data.get('title', 'Sheet1')
    headers = data.get('headers', [])
    rows = data.get('rows', [])

    # Calculate column widths
    col_widths = []
    for c_idx, h in enumerate(headers):
        max_len = len(str(h))
        for r in rows:
            if c_idx < len(r):
                max_len = max(max_len, len(str(r[c_idx] or '')))
        col_widths.append(max(12, min(max_len + 3, 50)))

    # 1. xl/worksheets/sheet1.xml
    sheet_xml_parts = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n',
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">\n',
        '<cols>\n'
    ]
    for c_idx, w in enumerate(col_widths):
        sheet_xml_parts.append(f'<col min="{c_idx+1}" max="{c_idx+1}" width="{w}" customWidth="1"/>\n')
    sheet_xml_parts.append('</cols>\n<sheetData>\n')

    # Header row (row 1, style 1 = bold with fill)
    sheet_xml_parts.append('<row r="1">\n')
    for c_idx, h in enumerate(headers):
        ref = f"{col_letter(c_idx)}1"
        val = escape_xml(h)
        sheet_xml_parts.append(f'<c r="{ref}" t="inlineStr" s="1"><is><t>{val}</t></is></c>\n')
    sheet_xml_parts.append('</row>\n')

    # Data rows
    for r_idx, row in enumerate(rows, start=2):
        sheet_xml_parts.append(f'<row r="{r_idx}">\n')
        for c_idx, cell in enumerate(row):
            ref = f"{col_letter(c_idx)}{r_idx}"
            val = escape_xml(cell)
            sheet_xml_parts.append(f'<c r="{ref}" t="inlineStr" s="0"><is><t>{val}</t></is></c>\n')
        sheet_xml_parts.append('</row>\n')

    sheet_xml_parts.append('</sheetData>\n</worksheet>')
    sheet_xml = "".join(sheet_xml_parts)

    # 2. [Content_Types].xml
    content_types = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">\n'
        '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>\n'
        '  <Default Extension="xml" ContentType="application/xml"/>\n'
        '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>\n'
        '  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>\n'
        '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>\n'
        '</Types>'
    )

    # 3. _rels/.rels
    root_rels = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">\n'
        '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>\n'
        '</Relationships>'
    )

    # 4. xl/_rels/workbook.xml.rels
    wb_rels = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">\n'
        '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>\n'
        '  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>\n'
        '</Relationships>'
    )

    # 5. xl/workbook.xml
    safe_title = escape_xml(title[:31] or "Report")
    workbook_xml = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">\n'
        '  <sheets>\n'
        f'    <sheet name="{safe_title}" sheetId="1" r:id="rId1"/>\n'
        '  </sheets>\n'
        '</workbook>'
    )

    # 6. xl/styles.xml
    styles_xml = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n'
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">\n'
        '  <fonts count="2">\n'
        '    <font><name val="Calibri"/><sz val="11"/></font>\n'
        '    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FFFFFFFF"/></font>\n'
        '  </fonts>\n'
        '  <fills count="3">\n'
        '    <fill><patternFill patternType="none"/></fill>\n'
        '    <fill><patternFill patternType="gray125"/></fill>\n'
        '    <fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>\n'
        '  </fills>\n'
        '  <borders count="1">\n'
        '    <border><left/><right/><top/><bottom/></border>\n'
        '  </borders>\n'
        '  <cellStyleXfs count="1">\n'
        '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>\n'
        '  </cellStyleXfs>\n'
        '  <cellXfs count="2">\n'
        '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>\n'
        '    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>\n'
        '  </cellXfs>\n'
        '</styleSheet>'
    )

    # Create ZIP/XLSX
    with zipfile.ZipFile(output_xlsx_file, 'w', compression=zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', content_types)
        z.writestr('_rels/.rels', root_rels)
        z.writestr('xl/_rels/workbook.xml.rels', wb_rels)
        z.writestr('xl/workbook.xml', workbook_xml)
        z.writestr('xl/styles.xml', styles_xml)
        z.writestr('xl/worksheets/sheet1.xml', sheet_xml)

if __name__ == '__main__':
    if len(sys.argv) < 3:
        print("Usage: python build_xlsx.py <input.json> <output.xlsx>")
        sys.exit(1)
    generate_xlsx(sys.argv[1], sys.argv[2])
    print("OK")
