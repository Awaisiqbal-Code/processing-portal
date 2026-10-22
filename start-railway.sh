#!/bin/sh
set -e

# Production Condition 1 & 3: Railway Persistent Volume Safeguard
# If running in Railway production (RAILWAY_ENVIRONMENT or RAILWAY_PROJECT_ID set),
# ensure /data is mounted and writable. Never fall back to container disk in production.
VOLUME_DIR="${RAILWAY_VOLUME_MOUNT_PATH:-}"
if [ -z "$VOLUME_DIR" ] && [ -d "/data" ]; then
    VOLUME_DIR="/data"
fi

if [ -n "$RAILWAY_ENVIRONMENT" ] || [ -n "$RAILWAY_PROJECT_ID" ]; then
    if [ -z "$VOLUME_DIR" ] || [ ! -d "$VOLUME_DIR" ] || [ ! -w "$VOLUME_DIR" ]; then
        echo "[CRITICAL ERROR] Production Railway persistent volume is NOT mounted or NOT writable at /data!" >&2
        echo "[CRITICAL ERROR] Refusing to start on ephemeral container disk to prevent data loss." >&2
        echo "[CRITICAL ERROR] Please attach a persistent volume mounted to /data in Railway service settings." >&2
        exit 1
    fi
fi

if [ -n "$VOLUME_DIR" ] && [ -d "$VOLUME_DIR" ]; then
    if [ ! -f "$VOLUME_DIR/portal.sqlite" ]; then
        echo "[Railway Init] First boot with persistent volume: copying existing portal.sqlite to $VOLUME_DIR/portal.sqlite..."
        cp /app/portal/app/portal.sqlite "$VOLUME_DIR/portal.sqlite"
    fi
    export PORTAL_SQLITE="$VOLUME_DIR/portal.sqlite"
    echo "[Railway Init] Using persistent volume database at $PORTAL_SQLITE..."
else
    export PORTAL_SQLITE="/app/portal/app/portal.sqlite"
    echo "[Local/Preview Init] Using local database at $PORTAL_SQLITE..."
fi

chmod 664 "$PORTAL_SQLITE" 2>/dev/null || true
chmod 775 "$(dirname "$PORTAL_SQLITE")" 2>/dev/null || true
mkdir -p /app/portal/uploads/cache /app/portal/uploads/documents

# Note: migrate.php is MANUAL (Condition 7: do not auto-run on start).
# Run via Railway CLI / deployment console: php /app/portal/app/migrate.php

# Background cron runner for reminders (runs hourly via CLI)
(
    while true; do
        sleep 3600
        echo "[Cron] Running reminders check..."
        php /app/portal/cron/reminders.php || true
    done
) &
CRON_PID=$!

trap "kill $CRON_PID 2>/dev/null || true" EXIT INT TERM

PORT_NUM="${PORT:-8000}"
echo "[Railway Start] Starting Processing Team Portal on 0.0.0.0:${PORT_NUM}..."
exec php -d upload_max_filesize=20M -d post_max_size=22M -S "0.0.0.0:${PORT_NUM}" -t /app/portal /app/portal/router.php
