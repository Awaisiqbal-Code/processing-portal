PROCESSING TEAM PORTAL — HOW TO MAKE IT LIVE (cPanel / any PHP hosting)
=====================================================================

Requirements: PHP 8.1 or newer, MySQL or MariaDB, HTTPS (free SSL in cPanel).

1. CREATE THE DATABASE
   cPanel > MySQL Databases
   - Create a database (e.g. yourname_portal)
   - Create a user with a strong password
   - Add the user to the database with ALL PRIVILEGES

2. IMPORT THE DATA
   cPanel > phpMyAdmin > select the new database > Import
   - First import  database/schema.sql
   - Then import   database/seed.sql   (253 students, 3 staff accounts, 253 student logins)
   Do NOT upload the "database" folder to the website.

3. UPLOAD THE PORTAL
   cPanel > File Manager > public_html
   - Upload the "portal" folder (e.g. public_html/portal, or a subdomain like portal.yoursite.com)
   - Open portal/app/config.php and fill in db_name, db_user, db_pass

4. TURN ON HTTPS
   cPanel > SSL/TLS Status > Run AutoSSL. The portal forces https automatically.
   If the site shows a redirect loop, remove the two "Force HTTPS" lines in portal/.htaccess.

5. OPEN IT
   https://yoursite.com/portal/              -> choose a portal
   https://yoursite.com/portal/admin/        -> Admin portal
   https://yoursite.com/portal/officer/      -> Processing officer portal
   https://yoursite.com/portal/student/      -> Student portal
   Logins are in the separate file "Portal logins.xlsx" (not inside this zip).
   Everyone must choose their own password the first time they sign in.

6. CHECK THE SECURITY (one minute)
   Open https://yoursite.com/portal/app/config.php in the browser -> it must show "Forbidden" (403).
   If it shows anything else, your host is ignoring .htaccess. Ask hosting support to enable
   AllowOverride for your account before sharing the portal.

ADDING PEOPLE LATER
   Admin portal > Staff and admins > Add a person (Processing officer or Admin).
   Admin portal > Student logins > Create login / Reset password for any student.
   New passwords are shown once on screen. Copy them and send them privately.

SECURITY BUILT IN
   - Passwords stored only as bcrypt hashes; everyone must change password on first sign-in
   - Each portal accepts only its own role (a student cannot sign in to the admin portal, etc.)
   - Officers see and edit only students assigned to them; the server checks this on every request
   - Students see only their own file, taken from their account (never from the URL),
     and never refunds, payments, discrepancies, remarks or other students
   - Lockout after 8 failed sign-ins in 15 minutes
   - CSRF tokens on every form, prepared SQL statements, all output escaped
   - Secure, HttpOnly, SameSite cookies; automatic sign-out after 30 minutes idle
   - Security headers (CSP, no framing, no sniffing), pages hidden from search engines
   - Private "app" folder and .sql files blocked from the web
   - Activity log of sign-ins and changes (table activity_log)

BACKUPS
   cPanel > Backup > download a database backup every week.
