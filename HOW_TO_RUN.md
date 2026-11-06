# How to run the Processing Portal (v2 — November 2026 update)

## A. On your own computer (Windows / Mac) — for testing

1. **Install PHP 8.2 or newer**
   - Windows: download "VS16 x64 Thread Safe" zip from https://windows.php.net/download, unzip to `C:\php`.
     Copy `php.ini-development` to `php.ini` and remove the `;` in front of these lines:
     `extension=curl`, `extension=fileinfo`, `extension=gd`, `extension=mbstring`, `extension=openssl`,
     `extension=pdo_sqlite`, `extension=sqlite3`. Add `C:\php` to your PATH.
     (XAMPP also works — it already includes these.)
   - Mac: `brew install php`
2. Open a terminal **inside the project folder** (the folder that contains `portal/`).
3. Start the portal:

   ```
   php -S localhost:8000 -t portal portal/router.php
   ```

4. Open **http://localhost:8000** in the browser and sign in with your normal username and password.

On the first page load the portal upgrades the database by itself (migration 007 — it only *adds*
new tables/columns; no existing record is changed). A backup copy of the database is saved in the
`backups/` folder before the upgrade.

Optional — run the upgrade by hand instead: `php portal/app/migrate.php`

## B. Live on Railway (current production)

Nothing new to configure.

1. Commit and push the updated code to the branch Railway deploys from.
2. Railway builds with the `Dockerfile`; `start-railway.sh` runs `php /app/portal/app/migrate.php`
   on every start, so migration 007 is applied automatically (with a backup in `/data/backups`).
3. Keep the existing variables (`PORTAL_SQLITE=/data/portal.sqlite`, volume mounted at `/data`, etc.).

Before deploying, download a backup of the live database (see `DEPLOY_RUNBOOK.md`, section 2).

## C. Optional settings (in the `settings` table, or as environment variables)

| Setting | Default | What it does |
|---|---|---|
| `single_session_scope` | `staff` | `staff` = every admin/officer/counselor account can be signed in on one device only. `all` = students too. `off` = disable. |
| `IDLE_WARNING_MINUTES` | 15 | Warning popup after this many idle minutes |
| `IDLE_LOGOUT_MINUTES` | 20 | Automatic sign-out after this many idle minutes |
