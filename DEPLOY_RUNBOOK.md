# Production Deployment Runbook: Processing Portal (Railway)

This runbook defines the exact, verified deployment and migration procedures for the Processing Portal on Railway.

---

## 1. Pre-Deployment Prerequisites

### 1.1 Railway Volume Verification
> [!IMPORTANT]
> A persistent volume **MUST** be mounted at `/data`.
> In production (`RAILWAY_ENVIRONMENT=production`), if `/data` is missing or not writable, the application blocks all database write operations and displays a blocking alert banner to administrators to prevent container disk data loss.

In your Railway Project Dashboard:
1. Open your Service -> **Settings** -> **Volumes**.
2. Verify a persistent volume is attached with **Mount Path** set to `/data`.
3. If not attached, click **Add Volume**, name it `portal-data`, and set mount path to `/data`.

### 1.2 Required Environment Variables
Add/verify the following variables in Railway Service -> **Variables**:

| Variable Name | Recommended Value | Purpose |
|---|---|---|
| `RAILWAY_ENVIRONMENT` | `production` | Enables production security guards and volume health enforcement |
| `RAILWAY_VOLUME_MOUNT_PATH` | `/data` | Path to persistent disk mount |
| `PORTAL_SQLITE` | `/data/portal.sqlite` | SQLite database file location |
| `PORTAL_UPLOADS_DIR` | `/data/uploads` | Uploaded student dossiers & attachments |
| `PORTAL_STORAGE_SECURE_DIR` | `/data/storage_secure` | Secure consent templates & signed forms |
| `PORTAL_APP_KEY` | *(64-hex secret)* | Cryptographic application key (see `credentials_production_env.txt`) |
| `PORTAL_CSRF_SECRET` | *(64-hex secret)* | CSRF token secret (see `credentials_production_env.txt`) |
| `API_ENABLED` | `0` | Disables REST API endpoints until Flutter/client rollout (returns 404) |
| `TZ` | `Asia/Karachi` | Default display timezone (PKT, UTC+5) |

---

## 2. Pre-Migration Live Database Backup

Before applying code changes or executing migrations, download a full snapshot of the live database from Railway to your local machine:

```bash
# Option A: Direct cat via Railway SSH
railway ssh "cat /data/portal.sqlite" > portal_railway_backup_$(date +%Y%m%d_%H%M%S).sqlite

# Option B: Run a backup command inside the container
railway run cp /data/portal.sqlite /data/backups/pre_migration_backup_$(date +%Y%m%d_%H%M%S).sqlite
```

Verify that the downloaded file is non-empty and valid SQLite:
```bash
sqlite3 portal_railway_backup_*.sqlite "PRAGMA integrity_check;"
# Output must be: ok
```

---

## 3. Code Deployment

Deploy the repository to Railway:
```bash
# Push latest verified code to your production git branch
git push origin main
```
Railway will automatically trigger a build using the project `Dockerfile` and startup script `start-railway.sh`.

---

## 4. Running Migrations on Railway

Once the container is healthy and running, execute the migration runner via Railway CLI:

### Step 4.1: Dry-Run / Status Inspection
Connect to the Railway container shell or execute:
```bash
railway run php portal/app/migrate.php
```

The runner performs the following automatic safety operations:
1. **Automated Snapshot:** Copies `/data/portal.sqlite` to `/data/backups/portal_<timestamp>.sqlite` before every single migration file.
2. **Transaction Isolation:** Disables foreign keys temporarily, begins an immediate exclusive transaction, applies the migration, commits, and enables foreign keys.
3. **Safety Invariants:** Verifies row counts in `students`, `users`, `student_documents`, and `activity_log`. Aborts and rolls back if any core table loses rows.
4. **Foreign Key Integrity Check:** Executes `PRAGMA foreign_key_check`. If any violation occurs, the migration fails and rolls back.
5. **Notification Suppression:** Sets `$GLOBALS['MIGRATION_RUNNING'] = true` to prevent alert emails/notifications during historical backfill.

### Step 4.2: Output Verification
Ensure the console prints:
- `Migration 001_phase1_roles_and_settings`: Completed (super_admin created, triggers active).
- `Migration 002_phase1_role_mapping`: Completed (officers mapped to processing_staff, pms_lead assigned).
- `Migration 003_phase2_student_model`: Completed (counselor variants mapped, remarks backfilled).
- `Migration 004_add_pending_since`: Completed (`pending_since` column and index created).
- `Migration 005_legacy_flag_and_utc_timestamps`: Completed (`is_legacy = 1` set for all 265 legacy rows).
- `PRAGMA foreign_key_check: OK (0 errors)`

---

## 5. Post-Migration Credential Distribution

To generate temporary passwords for the 19 counselor accounts without leaking passwords in console logs:

```bash
railway run php portal/app/cli_generate_production_credentials.php
```

Output:
- Updates bcrypt password hashes directly in `/data/portal.sqlite`.
- Writes formatted credentials to `/data/credentials_<date>.txt` with permissions `0600`.
- Printout shows: `Accounts updated: 19`, `Output file: /data/credentials_<date>.txt`.

To read the credentials securely:
```bash
railway ssh "cat /data/credentials_*.txt"
# Distribute to counselors, then delete:
railway ssh "rm -f /data/credentials_*.txt"
```

---

## 6. Super Admin Emergency Recovery

If the super_admin password is ever lost or locked out, recover it via CLI without database surgery:
```bash
railway run php portal/app/cli_reset_super_admin.php "YourNewSecurePassword123!"
```

---

## 7. Rollback Procedure

If any issue occurs during rollout:

1. Restore the pre-migration snapshot:
```bash
railway ssh "cp /data/backups/pre_migration_backup_*.sqlite /data/portal.sqlite"
```
2. Restart the Railway service:
```bash
railway restart
```
3. Verify integrity:
```bash
railway run sqlite3 /data/portal.sqlite "PRAGMA integrity_check;"
```
