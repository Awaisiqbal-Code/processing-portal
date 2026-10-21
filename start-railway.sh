#!/bin/sh
set -e

# Preserve existing SQLite database (265 students, 5 officers, 3180 document records).
# If a Railway Persistent Volume is mounted (via RAILWAY_VOLUME_MOUNT_PATH or /data),
# copy the existing database to the volume ONLY on first boot (never overwrite existing volume data).
VOLUME_DIR="${RAILWAY_VOLUME_MOUNT_PATH:-}"
if [ -z "$VOLUME_DIR" ] && [ -d "/data" ]; then
    VOLUME_DIR="/data"
fi

if [ -n "$VOLUME_DIR" ] && [ -d "$VOLUME_DIR" ]; then
    if [ ! -f "$VOLUME_DIR/portal.sqlite" ]; then
        echo "[Railway Init] First boot with persistent volume: copying existing portal.sqlite to $VOLUME_DIR/portal.sqlite..."
        cp /app/portal/app/portal.sqlite "$VOLUME_DIR/portal.sqlite"
    else
        echo "[Railway Init] Syncing updated master records (265 students) into persistent database at $VOLUME_DIR/portal.sqlite..."
        sqlite3 "$VOLUME_DIR/portal.sqlite" "ATTACH DATABASE '/app/portal/app/portal.sqlite' AS bundled; INSERT OR IGNORE INTO students SELECT * FROM bundled.students; INSERT OR IGNORE INTO users SELECT * FROM bundled.users; INSERT OR IGNORE INTO student_documents SELECT * FROM bundled.student_documents; UPDATE students SET sheet_sr = (SELECT b.sheet_sr FROM bundled.students b WHERE b.id = students.id), name = (SELECT b.name FROM bundled.students b WHERE b.id = students.id), counselor = (SELECT b.counselor FROM bundled.students b WHERE b.id = students.id), officer_id = (SELECT b.officer_id FROM bundled.students b WHERE b.id = students.id), frontend_officer_id = (SELECT b.frontend_officer_id FROM bundled.students b WHERE b.id = students.id), frontend_officer_name = (SELECT b.frontend_officer_name FROM bundled.students b WHERE b.id = students.id), refund = (SELECT b.refund FROM bundled.students b WHERE b.id = students.id), branch = (SELECT b.branch FROM bundled.students b WHERE b.id = students.id), intake = (SELECT b.intake FROM bundled.students b WHERE b.id = students.id), received_date = (SELECT b.received_date FROM bundled.students b WHERE b.id = students.id), consent = (SELECT b.consent FROM bundled.students b WHERE b.id = students.id), consult1 = (SELECT b.consult1 FROM bundled.students b WHERE b.id = students.id), docs_checklist = (SELECT b.docs_checklist FROM bundled.students b WHERE b.id = students.id), initial_meeting = (SELECT b.initial_meeting FROM bundled.students b WHERE b.id = students.id), recorded_uploaded = (SELECT b.recorded_uploaded FROM bundled.students b WHERE b.id = students.id), second_meeting = (SELECT b.second_meeting FROM bundled.students b WHERE b.id = students.id), follow_up_2 = (SELECT b.follow_up_2 FROM bundled.students b WHERE b.id = students.id), tax_meeting = (SELECT b.tax_meeting FROM bundled.students b WHERE b.id = students.id), meeting_priority = (SELECT b.meeting_priority FROM bundled.students b WHERE b.id = students.id), follow_up_1 = (SELECT b.follow_up_1 FROM bundled.students b WHERE b.id = students.id), offer = (SELECT b.offer FROM bundled.students b WHERE b.id = students.id), enrollment = (SELECT b.enrollment FROM bundled.students b WHERE b.id = students.id), shortlisting = (SELECT b.shortlisting FROM bundled.students b WHERE b.id = students.id), pending_docs_note = (SELECT b.pending_docs_note FROM bundled.students b WHERE b.id = students.id), installment_1 = (SELECT b.installment_1 FROM bundled.students b WHERE b.id = students.id), remarks = (SELECT b.remarks FROM bundled.students b WHERE b.id = students.id), drive_link = (SELECT b.drive_link FROM bundled.students b WHERE b.id = students.id), meeting_link = (SELECT b.meeting_link FROM bundled.students b WHERE b.id = students.id), drive_files_json = (SELECT b.drive_files_json FROM bundled.students b WHERE b.id = students.id) WHERE EXISTS (SELECT 1 FROM bundled.students b WHERE b.id = students.id); DETACH DATABASE bundled;" || true
    fi
    export PORTAL_SQLITE="$VOLUME_DIR/portal.sqlite"
else
    export PORTAL_SQLITE="/app/portal/app/portal.sqlite"
    echo "[Railway Init] Using bundled existing database at $PORTAL_SQLITE (265 students preserved)..."
fi

chmod 664 "$PORTAL_SQLITE" 2>/dev/null || true
chmod 775 "$(dirname "$PORTAL_SQLITE")" 2>/dev/null || true
mkdir -p /app/portal/uploads/cache /app/portal/uploads/documents

PORT_NUM="${PORT:-8000}"
echo "[Railway Start] Starting Processing Team Portal on 0.0.0.0:${PORT_NUM}..."
exec php -d upload_max_filesize=20M -d post_max_size=22M -S "0.0.0.0:${PORT_NUM}" -t /app/portal /app/portal/router.php
