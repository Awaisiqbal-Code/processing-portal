<?php
declare(strict_types=1);

/**
 * Migration 006: Multi-Country Caseload Expansion (Italy, France, Germany, Czech Republic, Austria),
 * New Processing Officers & Counselors, University Applications, and Programs Directory.
 */
return function (PDO $pdo): array {
    $seedFile = __DIR__ . '/seed_006_multi_country.json';
    if (!is_file($seedFile)) {
        throw new RuntimeException("Seed file missing: $seedFile");
    }
    $seed = json_decode((string)file_get_contents($seedFile), true);
    if (!is_array($seed)) {
        throw new RuntimeException("Invalid JSON in $seedFile");
    }

    $now = gmdate('Y-m-d H:i:s');

    // 1. Add new columns to students table
    $existingCols = array_column($pdo->query("PRAGMA table_info(students)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $newColumns = [
        'contact_number'        => 'TEXT NULL',
        'last_degree'           => 'TEXT NULL',
        'gpa'                   => 'TEXT NULL',
        'ielts_score'           => 'TEXT NULL',
        'applied_for'           => 'TEXT NULL',
        'application_fees'      => 'TEXT NULL',
        'dues'                  => 'TEXT NULL',
        'recovery_email'        => 'TEXT NULL',
        'campus_france_status'  => 'TEXT NULL',
        'cv_status'             => 'TEXT NULL',
        'sop_status'            => 'TEXT NULL',
        'mock_interview_status' => 'TEXT NULL',
        'embassy_status'        => 'TEXT NULL',
        'admission_secured'     => 'TEXT NULL',
    ];
    foreach ($newColumns as $col => $def) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN {$col} {$def}");
        }
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_country ON students(country)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_program_level ON students(program_level)");

    // Ensure student_remarks has soft-delete columns (deleted_at, deleted_by)
    $remCols = array_map(fn($c) => strtolower((string)$c['name']), $pdo->query("PRAGMA table_info(student_remarks)")->fetchAll(PDO::FETCH_ASSOC));
    if (!in_array('deleted_at', $remCols, true)) {
        $pdo->exec("ALTER TABLE student_remarks ADD COLUMN deleted_at TEXT NULL");
    }
    if (!in_array('deleted_by', $remCols, true)) {
        $pdo->exec("ALTER TABLE student_remarks ADD COLUMN deleted_by INTEGER NULL REFERENCES users(id)");
    }

    // 2. Create student_applications & university_programs tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
            country TEXT NOT NULL,
            university TEXT NOT NULL,
            program TEXT NULL,
            application_type TEXT NULL,
            deadline TEXT NULL,
            applied_date TEXT NULL,
            application_fee TEXT NULL,
            nostrification_status TEXT NULL,
            nostrification_fee TEXT NULL,
            language_req TEXT NULL,
            portal_link TEXT NULL,
            portal_id TEXT NULL,
            portal_password TEXT NULL,
            status TEXT NULL,
            submitted_by TEXT NULL,
            remarks TEXT NULL,
            created_at TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_student_applications_student_id ON student_applications(student_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_student_applications_country ON student_applications(country)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS university_programs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            country TEXT NOT NULL,
            university TEXT NOT NULL,
            uni_type TEXT NULL,
            city TEXT NULL,
            faculty TEXT NULL,
            programme TEXT NOT NULL,
            degree TEXT NULL,
            language TEXT NULL,
            field TEXT NULL,
            study_start TEXT NULL,
            application_opens TEXT NULL,
            deadline TEXT NULL,
            deadline_note TEXT NULL,
            exam_selection TEXT NULL,
            english_requirement TEXT NULL,
            min_cgpa TEXT NULL,
            application_fee TEXT NULL,
            tuition_eur TEXT NULL,
            status TEXT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_university_programs_country ON university_programs(country)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_university_programs_degree ON university_programs(degree)");

    // 3. Create new officers and counselors
    $defaultPmsLeadId = (int)$pdo->query("SELECT id FROM users WHERE role = 'pms_lead' AND active = 1 LIMIT 1")->fetchColumn();
    if ($defaultPmsLeadId <= 0) {
        $defaultPmsLeadId = 1;
    }

    $standardStaffHash = password_hash('Password123!', PASSWORD_BCRYPT);
    $newStaffCreds = [];
    foreach (array_merge($seed['new_officers'] ?? [], $seed['new_counselors'] ?? []) as $staff) {
        $uname = $staff['username'];
        $existingStmt = $pdo->prepare("SELECT id, role FROM users WHERE username = ?");
        $existingStmt->execute([$uname]);
        $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingRow && $existingRow['role'] === 'student') {
            // Free up the canonical username for staff/counselor
            $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$uname . '.student' . $existingRow['id'], (int)$existingRow['id']]);
            $existingRow = false;
        }
        if ($existingRow && $staff['role'] === 'processing_staff' && $existingRow['role'] === 'counselor') {
            // Promote dual counselor/processor (e.g. Hamna in China sheet) to processing_staff
            $pdo->prepare("UPDATE users SET role = 'processing_staff', department = 'backend', must_change_password = 0 WHERE id = ?")->execute([(int)$existingRow['id']]);
        }
        if (!$existingRow) {
            $ins = $pdo->prepare("
                INSERT INTO users (name, username, password_hash, role, department, active, must_change_password, created_at)
                VALUES (?, ?, ?, ?, ?, 1, 0, ?)
            ");
            $ins->execute([$staff['name'], $uname, $standardStaffHash, $staff['role'], $staff['department'], $now]);
            $newStaffCreds[] = "{$staff['role']} | {$staff['name']} | username: {$uname} | password: Password123!";
        }
    }
    // Ensure all staff/admin/counselor accounts have must_change_password = 0 and standardized passwords so local & live sign-in never fails
    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE role != 'student'")->execute([$standardStaffHash]);
    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE username = 'admin'")->execute([password_hash('Admin@2026', PASSWORD_BCRYPT)]);

    if (!empty($newStaffCreds) && defined('ROOT')) {
        $credFile = dirname(ROOT) . '/credentials_staff.txt';
        @file_put_contents($credFile, "\n--- Added in Migration 006 ({$now} UTC) ---\n" . implode("\n", $newStaffCreds) . "\n", FILE_APPEND);
    }

    // Build lookup map username -> id
    $userMap = [];
    foreach ($pdo->query("SELECT id, username, name FROM users WHERE role != 'student'") as $u) {
        $userMap[$u['username']] = (int)$u['id'];
    }

    // 4. Update existing 265 Italy students
    $updStmt = $pdo->prepare("
        UPDATE students SET
            country = 'Italy',
            program_level = COALESCE(program_level, :program_level),
            counselor = COALESCE(:counselor, counselor),
            assigned_counselor_id = COALESCE(:assigned_counselor_id, assigned_counselor_id),
            officer_id = COALESCE(:officer_id, officer_id),
            frontend_officer_id = COALESCE(:frontend_officer_id, frontend_officer_id),
            frontend_officer_name = COALESCE(:frontend_officer_name, frontend_officer_name),
            assigned_pms_lead_id = COALESCE(assigned_pms_lead_id, :assigned_pms_lead_id),
            refund = COALESCE(:refund, refund),
            branch = COALESCE(:branch, branch),
            intake = COALESCE(:intake, intake),
            received_date = COALESCE(:received_date, received_date),
            consent = COALESCE(:consent, consent),
            consult1 = COALESCE(:consult1, consult1),
            docs_checklist = COALESCE(:docs_checklist, docs_checklist),
            pending_docs_note = COALESCE(:pending_docs_note, pending_docs_note),
            drive_link = COALESCE(:drive_link, drive_link),
            doc_request_date = COALESCE(:doc_request_date, doc_request_date),
            email = COALESCE(:email, email),
            student_password = COALESCE(:student_password, student_password),
            portal_id = COALESCE(:portal_id, portal_id),
            portal_password = COALESCE(:portal_password, portal_password),
            shortlisting = COALESCE(:shortlisting, shortlisting),
            offer = 'No',
            enrollment = COALESCE(:enrollment, enrollment),
            enrollment_date = COALESCE(:enrollment_date, enrollment_date),
            installment_1 = COALESCE(:installment_1, installment_1),
            installment_2 = COALESCE(:installment_2, installment_2),
            installment_3 = COALESCE(:installment_3, installment_3),
            installment_4 = COALESCE(:installment_4, installment_4),
            threat_cancellation = COALESCE(:threat_cancellation, threat_cancellation),
            initial_meeting = COALESCE(:initial_meeting, initial_meeting),
            recorded_uploaded = COALESCE(:recorded_uploaded, recorded_uploaded),
            tax_meeting = COALESCE(:tax_meeting, tax_meeting),
            meeting_priority = COALESCE(:meeting_priority, meeting_priority),
            follow_up_1 = COALESCE(:follow_up_1, follow_up_1),
            second_meeting = COALESCE(:second_meeting, second_meeting),
            follow_up_2 = COALESCE(:follow_up_2, follow_up_2),
            consult2 = COALESCE(:consult2, consult2),
            disc2 = COALESCE(:disc2, disc2),
            meeting_link = COALESCE(:meeting_link, meeting_link),
            disc3 = COALESCE(:disc3, disc3),
            visa = COALESCE(:visa, visa),
            last_installment = COALESCE(:last_installment, last_installment),
            remarks = COALESCE(:remarks, remarks),
            drive_files_json = COALESCE(:drive_files_json, drive_files_json)
        WHERE id = :id
    ");

    $updatedItalyCount = 0;
    foreach ($seed['italy_updates'] ?? [] as $dbId => $rec) {
        $counId = isset($rec['counselor_username']) ? ($userMap[$rec['counselor_username']] ?? null) : null;
        $offId  = isset($rec['officer_username']) ? ($userMap[$rec['officer_username']] ?? null) : null;
        $fOffId = isset($rec['frontend_officer_username']) ? ($userMap[$rec['frontend_officer_username']] ?? null) : null;

        $updStmt->execute([
            ':program_level'         => $rec['program_level'] ?? 'MS',
            ':counselor'             => $rec['counselor'] ?? null,
            ':assigned_counselor_id' => $counId,
            ':officer_id'            => $offId,
            ':frontend_officer_id'   => $fOffId,
            ':frontend_officer_name' => $rec['frontend_officer_name'] ?? null,
            ':assigned_pms_lead_id'  => $defaultPmsLeadId,
            ':refund'                => $rec['refund'] ?? null,
            ':branch'                => $rec['branch'] ?? 'Lahore',
            ':intake'                => $rec['intake'] ?? null,
            ':received_date'         => $rec['received_date'] ?? null,
            ':consent'               => $rec['consent'] ?? null,
            ':consult1'              => $rec['consult1'] ?? null,
            ':docs_checklist'        => $rec['docs_checklist'] ?? null,
            ':pending_docs_note'     => $rec['pending_docs_note'] ?? null,
            ':drive_link'            => $rec['drive_link'] ?? null,
            ':doc_request_date'      => $rec['doc_request_date'] ?? null,
            ':email'                 => $rec['email'] ?? null,
            ':student_password'      => $rec['student_password'] ?? null,
            ':portal_id'             => $rec['portal_id'] ?? null,
            ':portal_password'       => $rec['portal_password'] ?? null,
            ':shortlisting'          => $rec['shortlisting'] ?? null,
            ':enrollment'            => $rec['enrollment'] ?? null,
            ':enrollment_date'       => $rec['enrollment_date'] ?? null,
            ':installment_1'         => $rec['installment_1'] ?? null,
            ':installment_2'         => $rec['installment_2'] ?? null,
            ':installment_3'         => $rec['installment_3'] ?? null,
            ':installment_4'         => $rec['installment_4'] ?? null,
            ':threat_cancellation'   => $rec['threat_cancellation'] ?? null,
            ':initial_meeting'       => $rec['initial_meeting'] ?? null,
            ':recorded_uploaded'     => $rec['recorded_uploaded'] ?? null,
            ':tax_meeting'           => $rec['tax_meeting'] ?? null,
            ':meeting_priority'      => $rec['meeting_priority'] ?? null,
            ':follow_up_1'           => $rec['follow_up_1'] ?? null,
            ':second_meeting'        => $rec['second_meeting'] ?? null,
            ':follow_up_2'           => $rec['follow_up_2'] ?? null,
            ':consult2'              => $rec['consult2'] ?? null,
            ':disc2'                 => $rec['disc2'] ?? null,
            ':meeting_link'          => $rec['meeting_link'] ?? null,
            ':disc3'                 => $rec['disc3'] ?? null,
            ':visa'                  => $rec['visa'] ?? null,
            ':last_installment'      => $rec['last_installment'] ?? null,
            ':remarks'               => $rec['remarks'] ?? null,
            ':drive_files_json'      => $rec['drive_files_json'] ?? null,
            ':id'                    => (int)$dbId,
        ]);
        $updatedItalyCount++;
    }
    // Ensure any remaining Italy students with NULL country are set to Italy and have program_level, and offer = 'No'
    $pdo->exec("UPDATE students SET country = 'Italy' WHERE country IS NULL OR TRIM(country) = ''");
    $pdo->exec("UPDATE students SET offer = 'No' WHERE LOWER(TRIM(country)) = 'italy'");
    $pdo->exec("UPDATE students SET branch = 'Lahore', refund = '30%' WHERE id = 192");
    $pdo->exec("UPDATE students SET program_level = 'BS' WHERE program_level IS NULL AND name LIKE '%BS%'");
    $pdo->exec("UPDATE students SET program_level = 'MS' WHERE program_level IS NULL");

    // 5. Helper to insert new students + user login + remarks + applications
    $insStudentStmt = $pdo->prepare("
        INSERT INTO students (
            sheet_sr, name, country, program_level, counselor, assigned_counselor_id,
            officer_id, entered_by, frontend_officer_id, frontend_officer_name, assigned_pms_lead_id,
            refund, is_refunded, refund_note, branch, intake, received_date,
            consent, consult1, docs_checklist, pending_docs_note, drive_link, doc_request_date,
            email, student_password, portal_id, portal_password, shortlisting, offer,
            enrollment, enrollment_date, installment_1, installment_2, installment_3, installment_4,
            threat_cancellation, initial_meeting, recorded_uploaded, tax_meeting, meeting_priority,
            follow_up_1, second_meeting, follow_up_2, consult2, disc2, meeting_link, disc3, visa, last_installment,
            contact_number, last_degree, gpa, ielts_score, applied_for, application_fees, dues, recovery_email,
            campus_france_status, cv_status, sop_status, mock_interview_status, embassy_status, admission_secured,
            student_status, disabled_reason, remarks, last_remark_at, drive_files_json, is_legacy, created_at, updated_at
        ) VALUES (
            :sheet_sr, :name, :country, :program_level, :counselor, :assigned_counselor_id,
            :officer_id, :entered_by, :frontend_officer_id, :frontend_officer_name, :assigned_pms_lead_id,
            :refund, :is_refunded, :refund_note, :branch, :intake, :received_date,
            :consent, :consult1, :docs_checklist, :pending_docs_note, :drive_link, :doc_request_date,
            :email, :student_password, :portal_id, :portal_password, :shortlisting, :offer,
            :enrollment, :enrollment_date, :installment_1, :installment_2, :installment_3, :installment_4,
            :threat_cancellation, :initial_meeting, :recorded_uploaded, :tax_meeting, :meeting_priority,
            :follow_up_1, :second_meeting, :follow_up_2, :consult2, :disc2, :meeting_link, :disc3, :visa, :last_installment,
            :contact_number, :last_degree, :gpa, :ielts_score, :applied_for, :application_fees, :dues, :recovery_email,
            :campus_france_status, :cv_status, :sop_status, :mock_interview_status, :embassy_status, :admission_secured,
            :student_status, :disabled_reason, :remarks, :last_remark_at, :drive_files_json, 1, :created_at, :updated_at
        )
    ");

    $insRemarkStmt = $pdo->prepare("
        INSERT INTO student_remarks (student_id, remark_text, author_id, author_name, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");

    $insAppStmt = $pdo->prepare("
        INSERT INTO student_applications (
            student_id, country, university, program, application_type, deadline, applied_date,
            application_fee, nostrification_status, nostrification_fee, language_req,
            portal_link, portal_id, portal_password, status, submitted_by, remarks, created_at
        ) VALUES (
            :student_id, :country, :university, :program, :application_type, :deadline, :applied_date,
            :application_fee, :nostrification_status, :nostrification_fee, :language_req,
            :portal_link, :portal_id, :portal_password, :status, :submitted_by, :remarks, :created_at
        )
    ");

    $insUserStmt = $pdo->prepare("
        INSERT INTO users (name, username, password_hash, role, student_id, active, must_change_password, created_at, department)
        VALUES (?, ?, ?, 'student', ?, ?, 0, ?, 'student')
    ");

    $checkUserStmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
    $checkStudentExists = $pdo->prepare("SELECT id FROM students WHERE id > 265 AND LOWER(TRIM(country)) = LOWER(TRIM(?)) AND COALESCE(sheet_sr, 0) = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");

    // Pre-hash a standard student portal password once to keep migration fast
    $defaultStudentHash = password_hash('Password123!', PASSWORD_BCRYPT);

    $allNewStudents = array_merge(
        $seed['italy_inserts'] ?? [],
        $seed['france_students'] ?? [],
        $seed['germany_students'] ?? [],
        $seed['cz_at_students'] ?? [],
        $seed['china_students'] ?? []
    );

    // Clear student_applications before seeding so re-runs don't duplicate
    $pdo->exec("DELETE FROM student_applications");

    $insertedStudents = 0;
    $insertedApps = 0;

    foreach ($allNewStudents as $rec) {
        $checkStudentExists->execute([$rec['country'], (int)($rec['sheet_sr'] ?? 0), $rec['name']]);
        $existingSid = $checkStudentExists->fetchColumn();

        $counId = isset($rec['counselor_username']) ? ($userMap[$rec['counselor_username']] ?? null) : null;
        $offId  = isset($rec['officer_username']) ? ($userMap[$rec['officer_username']] ?? null) : null;
        $fOffId = isset($rec['frontend_officer_username']) ? ($userMap[$rec['frontend_officer_username']] ?? null) : null;
        $offName = $rec['officer_username'] ? ucfirst(str_replace('.', ' ', $rec['officer_username'])) : 'Officer';

        $remText = trim((string)($rec['remarks'] ?? ''));
        $remDate = !empty($rec['remark_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$rec['remark_date'])
            ? $rec['remark_date'] . ' 10:00:00'
            : $now;

        if ($existingSid) {
            $sid = (int)$existingSid;
        } else {
            $insStudentStmt->execute([
                ':sheet_sr'              => $rec['sheet_sr'] ?? null,
                ':name'                  => $rec['name'],
                ':country'               => $rec['country'],
                ':program_level'         => $rec['program_level'] ?? 'MS',
                ':counselor'             => $rec['counselor'] ?? null,
                ':assigned_counselor_id' => $counId,
                ':officer_id'            => $offId,
                ':entered_by'            => $offId ?: 1,
                ':frontend_officer_id'   => $fOffId,
                ':frontend_officer_name' => $rec['frontend_officer_name'] ?? null,
                ':assigned_pms_lead_id'  => $defaultPmsLeadId,
                ':refund'                => $rec['refund'] ?? null,
                ':is_refunded'           => (int)($rec['is_refunded'] ?? 0),
                ':refund_note'           => $rec['refund_note'] ?? null,
                ':branch'                => $rec['branch'] ?? 'Lahore',
                ':intake'                => $rec['intake'] ?? null,
                ':received_date'         => $rec['received_date'] ?? null,
                ':consent'               => $rec['consent'] ?? null,
                ':consult1'              => $rec['consult1'] ?? null,
                ':docs_checklist'        => $rec['docs_checklist'] ?? null,
                ':pending_docs_note'     => $rec['pending_docs_note'] ?? null,
                ':drive_link'            => $rec['drive_link'] ?? null,
                ':doc_request_date'      => $rec['doc_request_date'] ?? null,
                ':email'                 => $rec['email'] ?? null,
                ':student_password'      => $rec['student_password'] ?? null,
                ':portal_id'             => $rec['portal_id'] ?? null,
                ':portal_password'       => $rec['portal_password'] ?? null,
                ':shortlisting'          => $rec['shortlisting'] ?? null,
                ':offer'                 => $rec['offer'] ?? null,
                ':enrollment'            => $rec['enrollment'] ?? null,
                ':enrollment_date'       => $rec['enrollment_date'] ?? null,
                ':installment_1'         => $rec['installment_1'] ?? null,
                ':installment_2'         => $rec['installment_2'] ?? null,
                ':installment_3'         => $rec['installment_3'] ?? null,
                ':installment_4'         => $rec['installment_4'] ?? null,
                ':threat_cancellation'   => $rec['threat_cancellation'] ?? null,
                ':initial_meeting'       => $rec['initial_meeting'] ?? null,
                ':recorded_uploaded'     => $rec['recorded_uploaded'] ?? null,
                ':tax_meeting'           => $rec['tax_meeting'] ?? null,
                ':meeting_priority'      => $rec['meeting_priority'] ?? null,
                ':follow_up_1'           => $rec['follow_up_1'] ?? null,
                ':second_meeting'        => $rec['second_meeting'] ?? null,
                ':follow_up_2'           => $rec['follow_up_2'] ?? null,
                ':consult2'              => $rec['consult2'] ?? null,
                ':disc2'                 => $rec['disc2'] ?? null,
                ':meeting_link'          => $rec['meeting_link'] ?? null,
                ':disc3'                 => $rec['disc3'] ?? null,
                ':visa'                  => $rec['visa'] ?? null,
                ':last_installment'      => $rec['last_installment'] ?? null,
                ':contact_number'        => $rec['contact_number'] ?? null,
                ':last_degree'           => $rec['last_degree'] ?? null,
                ':gpa'                   => $rec['gpa'] ?? null,
                ':ielts_score'           => $rec['ielts_score'] ?? null,
                ':applied_for'           => $rec['applied_for'] ?? null,
                ':application_fees'      => $rec['application_fees'] ?? null,
                ':dues'                  => $rec['dues'] ?? null,
                ':recovery_email'        => $rec['recovery_email'] ?? null,
                ':campus_france_status'  => $rec['campus_france_status'] ?? null,
                ':cv_status'             => $rec['cv_status'] ?? null,
                ':sop_status'            => $rec['sop_status'] ?? null,
                ':mock_interview_status' => $rec['mock_interview_status'] ?? null,
                ':embassy_status'        => $rec['embassy_status'] ?? null,
                ':admission_secured'     => $rec['admission_secured'] ?? null,
                ':student_status'        => $rec['student_status'] ?? 'active',
                ':disabled_reason'       => $rec['disabled_reason'] ?? null,
                ':remarks'               => $remText !== '' ? $remText : null,
                ':last_remark_at'        => $remText !== '' ? $remDate : null,
                ':drive_files_json'      => $rec['drive_files_json'] ?? null,
                ':created_at'            => $now,
                ':updated_at'            => $now,
            ]);
            $sid = (int)$pdo->lastInsertId();
            $insertedStudents++;

            // Create student user login account
            $parts = preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z ]/', '', $rec['name'])), -1, PREG_SPLIT_NO_EMPTY);
            $baseU = ($parts[0] ?? 'student') . '.' . ($parts[1] ?? $sid);
            $uname = $baseU;
            $n = 1;
            while (true) {
                $checkUserStmt->execute([$uname]);
                if (!$checkUserStmt->fetchColumn()) break;
                $n++;
                $uname = $baseU . $n;
            }
            $isActiveLogin = (($rec['student_status'] ?? 'active') === 'active') ? 1 : 0;
            $insUserStmt->execute([$rec['name'], $uname, $defaultStudentHash, $sid, $isActiveLogin, $now]);

            // Insert initial remark into student_remarks
            if ($remText !== '') {
                $insRemarkStmt->execute([$sid, $remText, $offId, $offName, $remDate]);
            }
        }

        // Insert student university applications
        foreach ($rec['applications'] ?? [] as $app) {
            $insAppStmt->execute([
                ':student_id'            => $sid,
                ':country'               => $app['country'] ?? $rec['country'],
                ':university'            => $app['university'] ?? 'University',
                ':program'               => $app['program'] ?? null,
                ':application_type'      => $app['application_type'] ?? null,
                ':deadline'              => $app['deadline'] ?? null,
                ':applied_date'          => $app['applied_date'] ?? null,
                ':application_fee'       => $app['application_fee'] ?? null,
                ':nostrification_status' => $app['nostrification_status'] ?? null,
                ':nostrification_fee'    => $app['nostrification_fee'] ?? null,
                ':language_req'          => $app['language_req'] ?? null,
                ':portal_link'           => $app['portal_link'] ?? null,
                ':portal_id'             => $app['portal_id'] ?? null,
                ':portal_password'       => $app['portal_password'] ?? null,
                ':status'                => $app['status'] ?? 'Pending',
                ':submitted_by'          => $app['submitted_by'] ?? $offName,
                ':remarks'               => $app['remarks'] ?? null,
                ':created_at'            => $now,
            ]);
            $insertedApps++;
        }
    }

    // 5b. Sync student_documents from drive_files_json for all students and clean up any fake 'Received' rows
    $pdo->exec("UPDATE student_documents SET status = 'Pending' WHERE (file_path IS NULL OR TRIM(file_path) = '') AND status != 'Pending'");
    $pdo->exec("UPDATE student_documents SET status = 'Pending', file_path = NULL, file_name = NULL WHERE doc_index = 9 AND student_id IN (SELECT id FROM students WHERE LOWER(TRIM(country)) = 'italy')");

    $allStudentsForDocs = $pdo->query("SELECT id, country, drive_files_json FROM students ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $selDocStmt = $pdo->prepare("SELECT doc_index, status, file_path FROM student_documents WHERE student_id = ?");
    $updDocStmt = $pdo->prepare("UPDATE student_documents SET status = ?, file_path = ?, file_name = ?, mime_type = 'application/pdf', updated_at = ? WHERE student_id = ? AND doc_index = ?");
    $insDocStmt = $pdo->prepare("INSERT INTO student_documents (student_id, doc_index, status, file_path, file_name, mime_type, uploaded_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($allStudentsForDocs as $sRow) {
        $sid = (int)$sRow['id'];
        $isItaly = strtolower(trim((string)($sRow['country'] ?? 'Italy'))) === 'italy';
        $docMap = [];
        if (!empty($sRow['drive_files_json'])) {
            $parsed = json_decode((string)$sRow['drive_files_json'], true);
            if (is_array($parsed) && !empty($parsed['doc_map'])) {
                $docMap = $parsed['doc_map'];
            }
        }
        $selDocStmt->execute([$sid]);
        $existingDocs = [];
        foreach ($selDocStmt->fetchAll(PDO::FETCH_ASSOC) as $dr) {
            $existingDocs[(int)$dr['doc_index']] = $dr;
        }
        for ($docIdx = 0; $docIdx < 12; $docIdx++) {
            $cur = $existingDocs[$docIdx] ?? null;
            $curPath = (string)($cur['file_path'] ?? '');
            $isLocal = ($curPath !== '' && !str_starts_with($curPath, 'gdrive:'));
            if ($isLocal) continue;

            if ((!$isItaly || $docIdx !== 9) && isset($docMap[$docIdx]) && !empty($docMap[$docIdx]['id'])) {
                $fInfo = $docMap[$docIdx];
                $gPath = 'gdrive:' . $fInfo['id'];
                $fName = (string)($fInfo['name'] ?? 'document.pdf');
                if (!preg_match('/\.(pdf|jpg|jpeg|png|doc|docx)$/i', $fName)) {
                    $fName .= '.pdf';
                }
                if ($cur) {
                    $updDocStmt->execute(['Received', $gPath, $fName, $now, $sid, $docIdx]);
                } else {
                    $insDocStmt->execute([$sid, $docIdx, 'Received', $gPath, $fName, 'application/pdf', $now, $now]);
                }
            } elseif (!$cur) {
                $insDocStmt->execute([$sid, $docIdx, 'Pending', null, null, null, $now, $now]);
            }
        }
    }

    // 6. Populate university_programs catalog
    $pdo->exec("DELETE FROM university_programs");
    $insProgStmt = $pdo->prepare("
        INSERT INTO university_programs (
            country, university, uni_type, city, faculty, programme, degree, language, field,
            study_start, application_opens, deadline, deadline_note, exam_selection,
            english_requirement, min_cgpa, application_fee, tuition_eur, status
        ) VALUES (
            :country, :university, :uni_type, :city, :faculty, :programme, :degree, :language, :field,
            :study_start, :application_opens, :deadline, :deadline_note, :exam_selection,
            :english_requirement, :min_cgpa, :application_fee, :tuition_eur, :status
        )
    ");
    $insertedProgs = 0;
    foreach ($seed['programs_catalog'] ?? [] as $prog) {
        $insProgStmt->execute([
            ':country'             => $prog['country'],
            ':university'          => $prog['university'],
            ':uni_type'            => $prog['uni_type'] ?? null,
            ':city'                => $prog['city'] ?? null,
            ':faculty'             => $prog['faculty'] ?? null,
            ':programme'           => $prog['programme'],
            ':degree'              => $prog['degree'] ?? null,
            ':language'            => $prog['language'] ?? 'English',
            ':field'               => $prog['field'] ?? null,
            ':study_start'         => $prog['study_start'] ?? null,
            ':application_opens'   => $prog['application_opens'] ?? null,
            ':deadline'            => $prog['deadline'] ?? null,
            ':deadline_note'       => $prog['deadline_note'] ?? null,
            ':exam_selection'      => $prog['exam_selection'] ?? null,
            ':english_requirement' => $prog['english_requirement'] ?? null,
            ':min_cgpa'            => $prog['min_cgpa'] ?? null,
            ':application_fee'     => $prog['application_fee'] ?? null,
            ':tuition_eur'         => $prog['tuition_eur'] ?? null,
            ':status'              => $prog['status'] ?? null,
        ]);
        $insertedProgs++;
    }

    echo "  [006] Updated Italy students: $updatedItalyCount\n";
    echo "  [006] Inserted new multi-country students: $insertedStudents\n";
    echo "  [006] Inserted student university applications: $insertedApps\n";
    echo "  [006] Inserted university programs directory rows: $insertedProgs\n";

    return [
        'updated_italy_students' => $updatedItalyCount,
        'inserted_students'      => $insertedStudents,
        'inserted_applications'  => $insertedApps,
        'inserted_programs'      => $insertedProgs,
    ];
};
