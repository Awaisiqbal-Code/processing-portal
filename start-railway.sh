#!/bin/sh
set -e

# Preserve existing SQLite database (250 students, 5 officers, 3000 document records).
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
        echo "[Railway Init] Using existing persistent database at $VOLUME_DIR/portal.sqlite (preserving all records)..."
    fi
    export PORTAL_SQLITE="$VOLUME_DIR/portal.sqlite"
else
    export PORTAL_SQLITE="/app/portal/app/portal.sqlite"
    echo "[Railway Init] Using bundled existing database at $PORTAL_SQLITE (250 students preserved)..."
fi

chmod 664 "$PORTAL_SQLITE" 2>/dev/null || true
chmod 775 "$(dirname "$PORTAL_SQLITE")" 2>/dev/null || true
mkdir -p /app/portal/uploads/cache /app/portal/uploads/documents

PORT_NUM="${PORT:-8000}"
echo "[Railway Start] Starting Processing Team Portal on 0.0.0.0:${PORT_NUM}..."
exec php -d upload_max_filesize=20M -d post_max_size=22M -S "0.0.0.0:${PORT_NUM}" -t /app/portal /app/portal/router.php
