# System Handover & Architecture Documentation: Processing Portal

This document outlines the architecture, invariants, security controls, business workflows, and operational procedures implemented across Phases 1 through 6.

---

## 1. System Architecture & Role Hierarchy

The Processing Portal enforces a 6-tier role hierarchy with strict permission gating and database trigger-level invariants:

```
[ super_admin ] (Exactly 1 active account)
       │
   [ admin ]     (At most 1 active account)
       │
 [ pms_lead ]    (Caseload supervisor & verifier)
       │
┌──────┴──────────────────────────┐
│                                 │
[ processing_staff ]       [ counselor ]
(Dossier preparation)      (Advising & consent)
                                  │
                           [ student ] (Portal view)
```

### Role Capabilities & Invariants

| Role | Count Invariant | Lifecycle Management | Remarks Clearance | Caseload Visibility |
|---|---|---|---|---|
| `super_admin` | Exactly 1 (`trg_users_single_super_admin_ins`, `trg_users_single_super_admin_upd`) | Full (Verifications, Refunds, Status) | Yes (clears Pending) | All students (`1=1`) |
| `admin` | At most 1 (`trg_users_single_admin_ins`, `trg_users_single_admin_upd`) | Full | Yes (clears Pending) | All students (`1=1`) |
| `pms_lead` | Multiple | Full | Yes (clears Pending) | All students (`1=1`) |
| `processing_staff` | Multiple | Blocked (403 on status/verify/refund) | Yes (clears Pending) | Assigned caseload (`officer_id` or `frontend_officer_id`) |
| `counselor` | 19 canonical | Blocked (403) | **No** (remarks tagged "Counselor", do not clear Pending) | Assigned caseload (`assigned_counselor_id` or `counselor` name) |
| `student` | Per student | Read-only | Blocked (403) | Own account only |

---

## 2. Student Lifecycle & State Machine

Student accounts transition through three core states:
- `active`: Standard operating status.
- `inactive`: Student temporarily on hold.
- `disabled`: Student dropped out, cancelled, or terminated.

### Lifecycle Rules:
1. **Mandatory Reason on Disable:** Disabling a student requires a non-empty reason (`mb_strlen >= 3`). The system automatically prepends `[Status: disabled] <reason>` to `student_remarks`, updating `last_remark_at` and recording the change in `activity_logs`.
2. **Linked Login Deactivation:** Disabling a student automatically sets `users.active = 0` for their linked portal login account.
3. **Reactivation Restores Login:** Marking a student `active` restores `users.active = 1`.
4. **Verification:** Reuses existing columns `csc_status` ('Verified' / 'Unverified'), `csc_verified_at`, and `csc_verified_by`. Restricted to `super_admin`, `admin`, and `pms_lead`.
5. **Refunds:** Uses `is_refunded` (0 or 1), `refund_date` (TEXT), and `refund_note` (TEXT). The existing `refund` column represents policy tier (e.g. "30%") and is preserved untouched.

---

## 3. Append-Only Remarks & Backward Compatibility

Remarks are stored in an append-only table `student_remarks`:
- Fields: `id`, `student_id`, `remark_text`, `author_id`, `author_name`, `created_at`, `deleted_at`, `deleted_by`.
- **Soft Deletion & Editing:** Only `super_admin` and `admin` can edit or soft-delete remarks. Processing staff and counselors are blocked (403).
- **Two-Way Sync:** Whenever a remark is added, edited, or soft-deleted, `students.remarks` is synchronized with the latest active remark text. This preserves 100% backward compatibility for legacy exports, report views, and external consumers.
- **Form Protection:** Direct editing of `students.remarks` in edit forms is disabled (read-only). All new remarks must route through `add_student_remark()`.

---

## 4. Pending Timer & Hourly Escalation Cron

To eliminate the bug where repeated edits could reset the 48-hour alert window indefinitely:
1. **`pending_since` Field:**
   - When a qualifying staff edit occurs and `pending_since IS NULL`, `pending_since` is stamped with the current UTC timestamp.
   - Subsequent staff edits while `pending_since` is already set do **not** change `pending_since`.
   - Adding a clearing remark (by PMS Lead or Processing Staff) sets `pending_since = NULL`.
   - Counselor remarks do **not** clear `pending_since`.
   - Bulk edits on `program_level` or `country` do **not** trigger a pending cycle.
2. **Alert Timeline & Deduplication:**
   - **48 Hours:** At `now - pending_since >= 48 hours`, a `pending_reminder` notification is delivered to the student's assigned PMS Lead.
   - **72 Hours:** At `now - pending_since >= 72 hours`, a `pending_escalation` notification is delivered to the Super Admin / Admin.
   - Alerts are deduplicated per pending cycle: once sent for a given `pending_since` timestamp, duplicates are suppressed.

---

## 5. Counselor Dashboard & Consent Management

1. **Caseload Isolation:** Counselors log in and see only students where `assigned_counselor_id` matches their user ID (or legacy `counselor` matches their canonical name).
2. **Country-Matched Consent Templates:**
   - Super Admin/PMS Lead manages templates in `portal/admin/consent_templates.php`.
   - Template matching checks for an exact country match (e.g., "Italy"); if none is found, it automatically falls back to the "General" template.
3. **Counselor Upload Workflow:**
   - Counselors download the applicable blank template and upload the student's signed consent form.
   - Uploading stores the PDF securely in `/data/storage_secure/consent_documents/`, creates an entry in `consent_documents`, logs the activity, and sends a `consent_uploaded` notification to the assigned PMS Lead.
   - Uploading does **NOT** auto-mark `consent = 'Yes'`. The PMS Lead inspects the document and manually marks the consent stage.
   - Uploading a consent document does not touch `last_edited_at` or `pending_since`.

---

## 6. API Architecture & Security Controls

All endpoints under `portal/api/` (`login.php`, `students.php`, `student.php`, `status.php`, `remarks.php`, `documents.php`) adhere to strict operational controls:
1. **Killswitch Gatekeeper:** Setting `api_enabled` (default `0` in DB or `API_ENABLED=0` in environment). When `0`, all API endpoints return HTTP `404 Not Found`.
2. **12-Hour Token Expiration:** Tokens are cryptographically signed HMAC payloads containing `exp = iat + 43200` (12 hours). Expired tokens are rejected with HTTP 401.
3. **Brute-Force Rate Limiting:** `portal/api/login.php` enforces a maximum of 5 failed login attempts per IP within 15 minutes using the `login_attempts` table.
4. **Role & Caseload Scoping:** API requests enforce identical role barriers and caseload scoping as the web application.

---

## 7. Timezone Standard: UTC & Asia/Karachi (PKT)

1. **Database Storage (UTC):**
   - All internal timestamps created by the application (`now()`, `CURRENT_TIMESTAMP`) store canonical UTC in standard SQLite format: `YYYY-MM-DD HH:MM:SS` (matching `gmdate('Y-m-d H:i:s')`).
   - Legacy imported timestamps were preserved untouched.
2. **User Display (PKT):**
   - The user interface and reporting date ranges operate in Pakistan Standard Time (`Asia/Karachi`, UTC+5).
   - Date range filters convert PKT start and end dates to UTC boundaries before querying the database.

---

## 8. Native Pure-PHP Excel Generator (`SimpleXLSXGen`)

- Caseload exports generate standard `.xlsx` (OpenXML) spreadsheets natively in PHP via `portal/app/SimpleXLSXGen.php`.
- Zero Composer dependencies, zero `exec()` or `shell_exec()`.
- Python 3 and PDF libraries (`pypdf`, `reportlab`, `pillow`) remain installed in the Dockerfile for building student PDF dossiers (`build_student_dossier.py`).

---

## 9. Persistent Storage & Volume Fallback Safeguards

1. **Volume Mount:** Railway persistent volume mounted at `/data`.
2. **Database:** `/data/portal.sqlite`.
3. **Uploads:** `/data/uploads` and `/data/storage_secure`.
4. **Production Protection:** If running under `RAILWAY_ENVIRONMENT=production` and `/data` is unmounted or read-only:
   - All HTTP `POST`, `PUT`, `DELETE`, and `PATCH` requests are blocked with HTTP 503.
   - A bright red alert banner is displayed to all administrators.
   - Container ephemeral disk writes are strictly prohibited to protect against silent data loss during container restarts.

---

## 10. CLI Management Scripts

All maintenance tasks can be executed headlessly via CLI:

| Script | Purpose | Command |
|---|---|---|
| Safe Migrations | Runs pending migrations with automated backups | `php portal/app/migrate.php` |
| Super Admin Reset | Emergency super_admin password recovery | `php portal/app/cli_reset_super_admin.php "<password>"` |
| Credential Generator | Generates unique random passwords for staff | `php portal/app/cli_generate_production_credentials.php` |
| Pending Alerts Cron | Hourly cron for 48h/72h notifications | `php portal/cron/reminders.php` |
