import os
import sys
import sqlite3
import urllib.request
import json
import re
import io
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
from pypdf import PdfReader, PdfWriter
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas
from PIL import Image

def get_db(db_path):
    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    return con

def download_file(url, target_path, timeout=12):
    if os.path.exists(target_path) and os.path.getsize(target_path) > 100:
        return target_path
    req = urllib.request.Request(url, headers={
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    })
    try:
        resp = urllib.request.urlopen(req, timeout=timeout)
        data = resp.read()
        if len(data) > 100 and not (data.startswith(b'<!DOCTYPE') and b'Google Drive - Virus scan warning' in data):
            with open(target_path, 'wb') as f:
                f.write(data)
            return target_path
        elif b'confirm=' in data:
            # Handle Google Drive large file virus scan confirm
            match = re.search(r'confirm=([0-9A-Za-z_]+)', data.decode('utf-8', errors='ignore'))
            if match:
                confirm_token = match.group(1)
                url_confirm = url + f"&confirm={confirm_token}"
                req_c = urllib.request.Request(url_confirm, headers={'User-Agent': 'Mozilla/5.0'})
                resp_c = urllib.request.urlopen(req_c, timeout=timeout)
                data_c = resp_c.read()
                if len(data_c) > 100:
                    with open(target_path, 'wb') as f:
                        f.write(data_c)
                    return target_path
    except Exception as e:
        print(f"Download error for {url}: {e}", file=sys.stderr)
    return None

def create_document_header(doc_title, file_name, file_size_kb, student_name, student_ref, cloud_link=""):
    packet = io.BytesIO()
    can = canvas.Canvas(packet, pagesize=A4)
    w, h = A4

    # Top banner background
    can.setFillColorRGB(0.09, 0.16, 0.30) # Dark navy #17294d
    can.rect(0, h - 42, w, 42, fill=1, stroke=0)

    # Accent stripe
    can.setFillColorRGB(0.15, 0.45, 0.95) # Royal blue
    can.rect(0, h - 44, w, 2, fill=1, stroke=0)

    # Title
    can.setFillColorRGB(1, 1, 1)
    can.setFont("Helvetica-Bold", 12)
    can.drawString(25, h - 25, f"ATTACHED DOCUMENT: {doc_title.upper()}")

    can.setFont("Helvetica", 8)
    can.setFillColorRGB(0.85, 0.90, 0.98)
    can.drawString(25, h - 36, f"File: {file_name} ({file_size_kb} KB)")

    # Right side: Student Name
    can.setFont("Helvetica-Bold", 9)
    can.setFillColorRGB(1, 1, 1)
    can.drawRightString(w - 25, h - 25, student_name)
    can.setFont("Helvetica", 8)
    can.setFillColorRGB(0.85, 0.90, 0.98)
    can.drawRightString(w - 25, h - 36, f"Ref: {student_ref}")

    # Bottom footer banner
    can.setFillColorRGB(0.96, 0.97, 0.98)
    can.rect(0, 0, w, 22, fill=1, stroke=0)
    can.setFillColorRGB(0.4, 0.45, 0.5)
    can.setFont("Helvetica", 7.5)
    can.drawString(25, 7, "Confidential · Official Processing Team Portal Dossier")
    if cloud_link:
        can.setFillColorRGB(0.15, 0.45, 0.95)
        can.drawRightString(w - 25, 7, "Verified Cloud Attachment")

    can.save()
    packet.seek(0)
    return PdfReader(packet).pages[0]

def create_image_page(img_path, doc_title, file_name, file_size_kb, student_name, student_ref, cloud_link=""):
    packet = io.BytesIO()
    can = canvas.Canvas(packet, pagesize=A4)
    w, h = A4

    # Top banner background
    can.setFillColorRGB(0.09, 0.16, 0.30)
    can.rect(0, h - 42, w, 42, fill=1, stroke=0)
    can.setFillColorRGB(0.15, 0.45, 0.95)
    can.rect(0, h - 44, w, 2, fill=1, stroke=0)

    can.setFillColorRGB(1, 1, 1)
    can.setFont("Helvetica-Bold", 12)
    can.drawString(25, h - 25, f"ATTACHED DOCUMENT: {doc_title.upper()}")
    can.setFont("Helvetica", 8)
    can.setFillColorRGB(0.85, 0.90, 0.98)
    can.drawString(25, h - 36, f"Image: {file_name} ({file_size_kb} KB)")

    can.setFont("Helvetica-Bold", 9)
    can.setFillColorRGB(1, 1, 1)
    can.drawRightString(w - 25, h - 25, student_name)
    can.setFont("Helvetica", 8)
    can.setFillColorRGB(0.85, 0.90, 0.98)
    can.drawRightString(w - 25, h - 36, f"Ref: {student_ref}")

    # Draw image centered
    try:
        with Image.open(img_path) as im:
            iw, ih = im.size
            max_w = w - 60
            max_h = h - 90
            scale = min(max_w / iw, max_h / ih, 1.0)
            dw = iw * scale
            dh = ih * scale
            dx = (w - dw) / 2
            dy = (h - 44 - dh) / 2 + 15
            can.drawImage(img_path, dx, dy, width=dw, height=dh, preserveAspectRatio=True)
    except Exception as e:
        can.setFillColorRGB(0.8, 0.2, 0.2)
        can.setFont("Helvetica-Bold", 12)
        can.drawCentredString(w/2, h/2, f"Could not render image: {e}")

    # Bottom footer
    can.setFillColorRGB(0.96, 0.97, 0.98)
    can.rect(0, 0, w, 22, fill=1, stroke=0)
    can.setFillColorRGB(0.4, 0.45, 0.5)
    can.setFont("Helvetica", 7.5)
    can.drawString(25, 7, "Confidential · Official Processing Team Portal Dossier")

    can.save()
    packet.seek(0)
    return PdfReader(packet).pages[0]

def create_placeholder_page(doc_title, file_name, student_name, student_ref, drive_link):
    packet = io.BytesIO()
    can = canvas.Canvas(packet, pagesize=A4)
    w, h = A4

    can.setFillColorRGB(0.09, 0.16, 0.30)
    can.rect(0, h - 42, w, 42, fill=1, stroke=0)
    can.setFillColorRGB(0.15, 0.45, 0.95)
    can.rect(0, h - 44, w, 2, fill=1, stroke=0)

    can.setFillColorRGB(1, 1, 1)
    can.setFont("Helvetica-Bold", 12)
    can.drawString(25, h - 25, f"DOCUMENT RECORD: {doc_title.upper()}")
    can.setFont("Helvetica", 8)
    can.setFillColorRGB(0.85, 0.90, 0.98)
    can.drawString(25, h - 36, f"Target: {file_name}")

    can.setFont("Helvetica-Bold", 9)
    can.setFillColorRGB(1, 1, 1)
    can.drawRightString(w - 25, h - 25, student_name)
    can.setFont("Helvetica", 8)
    can.setFillColorRGB(0.85, 0.90, 0.98)
    can.drawRightString(w - 25, h - 36, f"Ref: {student_ref}")

    # Body
    can.setFillColorRGB(0.97, 0.98, 0.99)
    can.roundRect(40, h/2 - 70, w - 80, 140, 8, fill=1, stroke=0)

    can.setFillColorRGB(0.15, 0.25, 0.4)
    can.setFont("Helvetica-Bold", 14)
    can.drawCentredString(w/2, h/2 + 25, "Document Stored on Secure Cloud Storage")

    can.setFont("Helvetica", 10)
    can.setFillColorRGB(0.4, 0.45, 0.5)
    can.drawCentredString(w/2, h/2 + 5, f"Filename: {file_name}")
    can.drawCentredString(w/2, h/2 - 15, "This file is maintained in the student's verified Google Drive folder.")

    if drive_link:
        can.setFont("Helvetica-Bold", 10)
        can.setFillColorRGB(0.15, 0.45, 0.95)
        can.drawCentredString(w/2, h/2 - 40, f"Access Link: {drive_link}")

    # Bottom
    can.setFillColorRGB(0.96, 0.97, 0.98)
    can.rect(0, 0, w, 22, fill=1, stroke=0)
    can.setFillColorRGB(0.4, 0.45, 0.5)
    can.setFont("Helvetica", 7.5)
    can.drawString(25, 7, "Confidential · Official Processing Team Portal Dossier")

    can.save()
    packet.seek(0)
    return PdfReader(packet).pages[0]

DOCS_TITLES = [
    'Passport',
    'CNIC / B-form',
    'Matric certificate & transcript',
    'Intermediate certificate & transcript',
    'Bachelor degree & transcript',
    'IELTS / Language certificate',
    'Curriculum Vitae (CV)',
    'Recommendation letters',
    'Motivation letter / SOP',
    'Offer letter',
    'Tax / Bank documents',
    'Visa application',
]

def build_student_dossier(db_path, student_id, base_pdf_path, output_pdf_path, cache_dir, uploads_dir):
    con = get_db(db_path)
    cur = con.cursor()

    student = cur.execute("SELECT * FROM students WHERE id = ?", (student_id,)).fetchone()
    if not student:
        raise ValueError(f"Student ID {student_id} not found")

    student_name = student['name'] or 'Student'
    student_ref = f"STU-{student_id:05d}"
    drive_link = student['drive_link'] or ''
    meeting_link = student['meeting_link'] or ''

    st_cache = os.path.join(cache_dir, str(student_id))
    os.makedirs(st_cache, exist_ok=True)

    # 1. Fetch document rows from DB
    doc_rows = cur.execute("SELECT * FROM student_documents WHERE student_id = ? ORDER BY doc_index", (student_id,)).fetchall()

    # 1b. Fetch manually uploaded custom documents
    custom_rows = []
    try:
        custom_rows = cur.execute("SELECT * FROM custom_documents WHERE student_id = ? ORDER BY id ASC", (student_id,)).fetchall()
    except Exception as e:
        pass
    
    # 2. Also inspect drive_files_json for any additional specific files (Degree, Transcripts, Pictures, LORs, etc.)
    all_drive_files = []
    if student['drive_files_json']:
        try:
            dfj = json.loads(student['drive_files_json'])
            all_drive_files = dfj.get('files', [])
        except Exception:
            all_drive_files = []

    # Map of document items to process: [(title, filename, source_type, source_val)]
    items_to_process = []
    seen_fids = set()

    for d in doc_rows:
        idx = d['doc_index']
        title = DOCS_TITLES[idx] if idx < len(DOCS_TITLES) else f"Document #{idx+1}"
        fpath = d['file_path'] or ''
        fname = d['file_name'] or (title + '.pdf')
        if fpath.startswith('gdrive:'):
            fid = fpath[7:]
            if fid not in seen_fids:
                seen_fids.add(fid)
                items_to_process.append({
                    'title': title,
                    'filename': fname,
                    'type': 'gdrive',
                    'fid': fid,
                    'doc_index': idx
                })
        elif fpath != '':
            items_to_process.append({
                'title': title,
                'filename': fname,
                'type': 'local',
                'rel_path': fpath,
                'doc_index': idx
            })

    # Include manually uploaded custom documents
    for cd in custom_rows:
        cfpath = cd['file_path'] or ''
        cfname = cd['file_name'] or (cd['title'] + '.pdf')
        if cfpath:
            items_to_process.append({
                'title': cd['title'] or 'Additional Document',
                'filename': cfname,
                'type': 'local',
                'rel_path': cfpath,
                'doc_index': 98
            })

    # Include additional files found in Drive that aren't yet in doc_rows (e.g. Pictures, extra degrees, etc.)
    for gf in all_drive_files:
        fid = gf.get('id')
        gname = gf.get('name', 'Document')
        if not fid or fid in seen_fids or gf.get('is_folder') or gf.get('is_meeting'):
            continue
        # Only include documents or pictures (skip video or unneeded)
        if any(ext in gname.lower() for ext in ['.pdf', '.jpg', '.jpeg', '.png']):
            seen_fids.add(fid)
            items_to_process.append({
                'title': gf.get('subfolder') or 'Additional Attachment',
                'filename': gname,
                'type': 'gdrive',
                'fid': fid,
                'doc_index': 99
            })

    # Sort items nicely: known doc indices first, then additional
    items_to_process.sort(key=lambda x: x.get('doc_index', 99))

    # Download in parallel using thread pool
    download_tasks = []
    with ThreadPoolExecutor(max_workers=8) as ex:
        for it in items_to_process:
            if it['type'] == 'gdrive':
                local_path = os.path.join(st_cache, f"{it['fid']}_{it['filename']}")
                it['local_path'] = local_path
                url = f"https://drive.google.com/uc?export=download&id={it['fid']}"
                download_tasks.append(ex.submit(download_file, url, local_path))
            elif it['type'] == 'local':
                rel = it['rel_path'].lstrip('/\\')
                portal_dir = os.path.dirname(os.path.dirname(uploads_dir))
                cand1 = os.path.join(portal_dir, rel)
                cand2 = os.path.join(uploads_dir, rel)
                cand3 = os.path.join(os.path.dirname(uploads_dir), rel)
                if os.path.exists(cand1):
                    it['local_path'] = cand1
                elif os.path.exists(cand2):
                    it['local_path'] = cand2
                elif os.path.exists(cand3):
                    it['local_path'] = cand3
                else:
                    it['local_path'] = cand1

        # Wait for all downloads
        for fut in as_completed(download_tasks):
            fut.result()

    # Now assemble the comprehensive dossier PDF!
    writer = PdfWriter()

    # 1. Base Executive Summary PDF
    if os.path.exists(base_pdf_path):
        base_reader = PdfReader(base_pdf_path)
        for p in base_reader.pages:
            writer.add_page(p)

    # 2. For each document, merge its pages or embed image
    for it in items_to_process:
        local_path = it.get('local_path')
        title = it['title']
        fname = it['filename']

        if not local_path or not os.path.exists(local_path) or os.path.getsize(local_path) < 100:
            # Add placeholder page with direct cloud link
            placeholder = create_placeholder_page(title, fname, student_name, student_ref, drive_link)
            writer.add_page(placeholder)
            continue

        size_kb = round(os.path.getsize(local_path) / 1024, 1)

        # Check if PDF or Image
        is_pdf = False
        with open(local_path, 'rb') as f_check:
            sig = f_check.read(4)
            if sig.startswith(b'%PDF'):
                is_pdf = True

        if is_pdf:
            try:
                doc_reader = PdfReader(local_path)
                num_pages = len(doc_reader.pages)
                for page_idx, page in enumerate(doc_reader.pages):
                    if page_idx == 0:
                        header = create_document_header(title, fname, size_kb, student_name, student_ref, drive_link)
                        page.merge_page(header)
                    writer.add_page(page)
            except Exception as e:
                print(f"Error parsing PDF {fname}: {e}", file=sys.stderr)
                placeholder = create_placeholder_page(title, fname, student_name, student_ref, drive_link)
                writer.add_page(placeholder)
        else:
            # Image
            try:
                img_page = create_image_page(local_path, title, fname, size_kb, student_name, student_ref, drive_link)
                writer.add_page(img_page)
            except Exception as e:
                print(f"Error rendering image {fname}: {e}", file=sys.stderr)
                placeholder = create_placeholder_page(title, fname, student_name, student_ref, drive_link)
                writer.add_page(placeholder)

    with open(output_pdf_path, 'wb') as f_out:
        writer.write(f_out)

    print(f"SUCCESS: Compiled full student dossier with {len(writer.pages)} pages ({os.path.getsize(output_pdf_path)} bytes)")

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="Compile student complete dossier PDF")
    parser.add_argument('--db', required=True)
    parser.add_argument('--student-id', type=int, required=True)
    parser.add_argument('--base-pdf', required=True)
    parser.add_argument('--output-pdf', required=True)
    parser.add_argument('--cache-dir', required=True)
    parser.add_argument('--uploads-dir', required=True)

    args = parser.parse_args()
    build_student_dossier(
        db_path=args.db,
        student_id=args.student_id,
        base_pdf_path=args.base_pdf,
        output_pdf_path=args.output_pdf,
        cache_dir=args.cache_dir,
        uploads_dir=args.uploads_dir
    )
