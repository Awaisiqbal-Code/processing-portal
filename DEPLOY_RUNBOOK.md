# Production Deployment & Migration Runbook: Processing Portal

This runbook defines the exact, fail-safe production rollout procedure to transition live staff usage from ephemeral Vercel to Railway (with persistent volume storage), ensuring **zero data loss** and **safe automated dual-deployment**.

---

## 1. Architectural Reality & Platform Behavior

| Characteristic | Vercel (`processing-portal.vercel.app`) | Railway (`processing-portal-production.up.railway.app`) |
|---|---|---|
| **Runtime** | AWS Lambda Serverless Function (`vercel-php`) | Persistent Docker Container |
| **Filesystem** | **Ephemeral** (`/tmp` copied from git on cold start) | **Persistent Volume** (`/data` mounted disk) |
| **Data Durability** | **Writes lost on cold start / restart / redeploy** | **Fully preserved across restarts and deploys** |
| **Deployment Trigger** | Auto-deploys on push to git `main` | Auto-deploys on push to git `main` |
| **Target Role** | **Zero-write redirector** (`Location: https://...railway.app`) | **Single Source of Truth (Production Host)** |

> [!WARNING]
> Because both Vercel and Railway deploy automatically from the `main` branch, you must **NOT** merge or push to `main` until the Railway volume and database have been verified.

---

## 2. Pre-Rollout Phase: Verification & Rescue

### Step 2.1: Verify Baseline Database Integrity
Confirm that the database being migrated is identical to the baseline committed in git:
- `git show HEAD:portal/app/portal.sqlite` SHA256: `2b0fe44ee2853db75cdc3ebf13b93f995bc886167be79fb53880b6886190d267`
- Exact 265 students, 3,180 documents preserved, 271 initial users.

### Step 2.2: Maintenance Notice
Inform staff:
> *"Portal Maintenance in progress (15-30 mins). Please save current work and do not submit new edits until the upgrade is complete."*

### Step 2.3: Vercel Data Rescue (Export Check)
In case staff made edits on a warm Vercel container today:
1. Log in to `https://processing-portal.vercel.app` as Admin.
2. Go to the student list and export the latest student CSV / table data.
3. Compare the exported rows with the local database to verify if any remarks, stages, or counselor notes were modified. If any exist, apply them before running migrations.

---

## 3. Railway Persistent Storage Setup

### Step 3.1: Verify Persistent Volume on Railway
In Railway Dashboard:
1. Open your Service -> **Settings** -> **Volumes**.
2. Verify that a persistent volume is attached with **Mount Path** set to `/data`.
3. If not present, click **Add Volume**, set mount path to `/data`, and redeploy.

### Step 3.2: Verify Required Railway Environment Variables
In Railway Dashboard -> Service -> **Variables**, ensure these are set:
```env
RAILWAY_ENVIRONMENT=production
RAILWAY_VOLUME_MOUNT_PATH=/data
PORTAL_SQLITE=/data/portal.sqlite
PORTAL_UPLOADS_DIR=/data/uploads
PORTAL_STORAGE_SECURE_DIR=/data/storage_secure
PORTAL_APP_KEY=<64-char hex secret from credentials_production_env.txt>
PORTAL_CSRF_SECRET=<64-char hex secret from credentials_production_env.txt>
API_ENABLED=0
TZ=Asia/Karachi
```

### Step 3.3: Verify Container Shell & Volume via SSH
Test that Railway container SSH is functioning and check `/data`:
```bash
railway ssh "ls -la /data"
```
Ensure `/data` exists and is writable.

---

## 4. Live Migration & Production Database Staging

> [!IMPORTANT]
> All execution commands inside the production container must use `railway ssh`.

### Step 4.1: Live Pre-Migration Backup on Railway
If `/data/portal.sqlite` already exists on the Railway volume, create a timestamped safety backup:
```bash
railway ssh "mkdir -p /data/backups && cp /data/portal.sqlite /data/backups/live_backup_\$(date +%Y%m%d_%H%M%S).sqlite"
```
If `/data/portal.sqlite` does NOT exist yet, initialize it from the verified baseline:
```bash
railway ssh "cp /app/portal/app/portal.sqlite /data/portal.sqlite && chmod 664 /data/portal.sqlite"
```

### Step 4.2: Execute Migrations inside Railway Container
Run the automated migration runner:
```bash
railway ssh "php /app/portal/app/migrate.php"
```
Verify the output:
- `Migration 001_phase1_roles_and_settings`: Completed
- `Migration 002_phase1_role_mapping`: Completed
- `Migration 003_phase2_student_model`: Completed
- `Migration 004_add_pending_since`: Completed
- `Migration 005_legacy_flag_and_utc_timestamps`: Completed
- `PRAGMA foreign_key_check: OK (0 errors)`

### Step 4.3: Verify Production Database Integrity
```bash
railway ssh "sqlite3 /data/portal.sqlite 'PRAGMA integrity_check;'"
# Expected output: ok

railway ssh "sqlite3 /data/portal.sqlite 'SELECT COUNT(*) FROM students; SELECT COUNT(*) FROM student_documents; SELECT COUNT(*) FROM users;'"
# Expected output: 265 | 3180 | 291
```

### Step 4.4: Generate Staff & Counselor Credentials
Generate random 12-character passwords for the 19 counselor accounts directly on Railway:
```bash
railway ssh "php /app/portal/app/cli_generate_production_credentials.php"
```
Read the generated file securely via SSH:
```bash
railway ssh "cat /data/credentials_*.txt"
```
*Distribute credentials to counselors, then delete the file:*
```bash
railway ssh "rm -f /data/credentials_*.txt"
```

---

## 5. Switch Live Traffic: Merge to Main & Deploy

Now that Railway has the live, persistent, migrated database on `/data`, enable the redirect from Vercel to Railway.

### Step 5.1: Confirm Vercel Redirect (`api/index.php`)
In [`api/index.php`](file:///c:/Users/hafiz/Downloads/processing-portal%20(1)/api/index.php), verify that requests originating from Vercel are redirected to Railway:
- GET requests: `302 Found`
- POST / API requests: `307 Temporary Redirect` (preserves method and body)

### Step 5.2: Commit and Merge to `main`
```bash
# Ensure you are on the upgrade branch
git status

# Commit any remaining files
git add api/index.php DEPLOY_RUNBOOK.md HANDOVER.md portal/ start-railway.sh
git commit -m "feat: complete phases 1-6 production upgrade with Vercel redirect and persistent Railway storage"

# Merge and push to main
git checkout main
git merge upgrade-branch
git push origin main
```

Both platforms will build:
1. **Vercel:** Deploys the redirect router. Any staff member visiting `https://processing-portal.vercel.app` is instantly redirected to `https://processing-portal-production.up.railway.app`.
2. **Railway:** Deploys the updated application code. `start-railway.sh` boots, detects `/data/portal.sqlite` already present on the volume, and uses it without overwriting.

---

## 6. Post-Deployment Verification

1. Open `https://processing-portal.vercel.app` in an incognito window:
   - Confirm it redirects to `https://processing-portal-production.up.railway.app`.
2. Log in as Super Admin on Railway:
   - Verify Super Admin Dashboard metrics.
   - Confirm persistent volume indicator is green: `Persistent Volume (/data): Mounted & Writable`.
   - Confirm no warning banner is displayed.
3. Check one student record and verify all remarks and documents are intact.

---

## 7. Emergency Rollback Procedure

If any critical issue arises after merging to `main`:

### 1. Restore Database on Railway
```bash
railway ssh "cp /data/backups/live_backup_*.sqlite /data/portal.sqlite"
railway ssh "sqlite3 /data/portal.sqlite 'PRAGMA integrity_check;'"
```

### 2. Revert Git Main
```bash
git revert HEAD -m 1 --no-edit
git push origin main
```
This restores the previous commit on both Vercel and Railway.
