<?php
declare(strict_types=1);
// Staff management: per-person access (permissions), contact details, role / team changes
// with student hand-over, and a change history. Only Super Admin adds people or changes roles.

/** What can be switched on for an Admin or a PMS Lead. [label, explanation] */
const PERMISSIONS = [
    'students.edit_all' => ['Edit any student',            'Change profile, documents, payments, meeting and journey of students who are not assigned to them.'],
    'students.add'      => ['Register students',           'Add new students (Add Student form).'],
    'students.assign'   => ['Change assignments',          'Move students between front-end / back-end officers, counselors and leads.'],
    'docs.verify'       => ['Verify documents',            'Check received documents and mark them Verified (or send them back). Processing officers can only mark Received.'],
    'students.status'   => ['Disable / refund students',   'Set students inactive or disabled, record refunds, bulk edits.'],
    'reports.view'      => ['Reports',                     'Reports and officer pending reports for every officer (with Excel sharing).'],
    'data.export'       => ['Download student data',       'Full CSV / Excel download of student records.'],
    'programs.manage'   => ['Edit University Programs',    'Add programs to the University Programs list.'],
    'consent.manage'    => ['Consent templates',           'Upload, replace and delete consent templates.'],
    'staff.manage'      => ['Staff passwords & status',    'Reset passwords, enable / disable staff and sign them out. Adding people and changing roles stay with Super Admin.'],
    'logins.manage'     => ['Student portal logins',       'Create or reset student portal logins.'],
];

/** Access a role starts with. Admin / PMS Lead: view everything, change nothing unless Super Admin switches it on. */
const ROLE_DEFAULT_PERMISSIONS = [
    'admin'            => ['reports.view', 'data.export', 'docs.verify'],
    'pms_lead'         => ['reports.view', 'docs.verify'],
    'processing_staff' => ['students.add'],
    'officer'          => ['students.add'],
    'counselor'        => [],
];

/** Roles whose access Super Admin can tick on / off person by person. */
const CONFIGURABLE_PERM_ROLES = ['admin', 'pms_lead'];

/** Page => [permission needed to open it, permission needed to save on it]. Applies to Admin and PMS Lead only. */
const PAGE_PERMISSIONS = [
    'admin/reports.php'           => ['reports.view', 'reports.view'],
    'admin/pending_report.php'    => ['reports.view', 'reports.view'],
    'officer/reports.php'         => ['reports.view', 'reports.view'],
    'admin/export.php'            => ['data.export', 'data.export'],
    'admin/programs.php'          => [null, 'programs.manage'],
    'officer/programs.php'        => [null, 'programs.manage'],
    'admin/consent_templates.php' => [null, 'consent.manage'],
    'admin/disabled_refunds.php'  => [null, 'students.status'],
    'admin/logins.php'            => [null, 'logins.manage'],
    'admin/staff.php'             => [null, 'staff.manage'],
    'admin/staff_member.php'      => [null, 'staff.manage'],
    'admin/verify_docs.php'       => ['docs.verify', 'docs.verify'],
    'officer/verify_docs.php'     => ['docs.verify', 'docs.verify'],
];

const STAFF_ROLE_OPTIONS = [
    'processing_staff' => 'Processing Officer',
    'pms_lead'         => 'PMS Lead',
    'counselor'        => 'Counselor',
    'admin'            => 'Admin',
];

function is_officer_like_role(?string $role): bool {
    return in_array($role, ['pms_lead', 'processing_staff', 'officer'], true);
}

function team_label(?string $dept): string {
    return match ($dept) { 'frontend' => 'Front End', 'backend' => 'Back End', 'counselor' => 'Counselling', default => '—' };
}

function user_perms(array $u): array {
    $role = $u['role'] ?? '';
    if ($role === 'super_admin') return array_keys(PERMISSIONS);
    $id = (int)($u['id'] ?? 0);
    if (isset($GLOBALS['__perm_cache'][$id])) return $GLOBALS['__perm_cache'][$id];
    $perms = ROLE_DEFAULT_PERMISSIONS[$role] ?? [];
    if (in_array($role, CONFIGURABLE_PERM_ROLES, true) && $id) {
        try { $raw = q('SELECT permissions FROM users WHERE id = ?', [$id])->fetchColumn(); } catch (Throwable) { $raw = null; }
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);
            if (is_array($j)) $perms = array_values(array_intersect(array_keys(PERMISSIONS), $j));
        }
    }
    return $GLOBALS['__perm_cache'][$id] = $perms;
}

function can_verify_docs(array $u): bool {
    return ($u['role'] ?? '') === 'super_admin' || (in_array($u['role'] ?? '', ['admin', 'pms_lead'], true) && user_can($u, 'docs.verify'));
}

function user_can(array $u, string $perm): bool {
    return in_array($perm, user_perms($u), true);
}

/** Is this student assigned to this staff member (front-end or back-end)? */
function is_own_student(array $u, array $r): bool {
    $id = (int)($u['id'] ?? 0);
    return $id > 0 && ((int)($r['officer_id'] ?? 0) === $id || (int)($r['frontend_officer_id'] ?? 0) === $id);
}

/** May this user change this student's record? */
function can_edit_student(array $u, array $r): bool {
    return match ($u['role'] ?? '') {
        'super_admin'                   => true,
        'admin', 'pms_lead'             => user_can($u, 'students.edit_all') || is_own_student($u, $r),
        'processing_staff', 'officer'   => true,    // they only ever see their own students
        default                         => false,
    };
}

/** True when an Admin / PMS Lead is looking at a student they may view but not change. */
function staff_edit_blocked(array $u, array $r): bool {
    return in_array($u['role'] ?? '', ['admin', 'pms_lead'], true) && !can_edit_student($u, $r);
}

function view_only_message(?string $perm = null): string {
    $what = $perm && isset(PERMISSIONS[$perm]) ? ' (' . PERMISSIONS[$perm][0] . ')' : '';
    return "Your account has view-only access here{$what}. Ask the Super Admin to switch it on if you need it.";
}

/** Called by require_role(): blocks pages / saves an Admin or PMS Lead has not been given. */
function enforce_page_permission(array $u, string $callerFile): void {
    if (!in_array($u['role'] ?? '', ['admin', 'pms_lead'], true)) return;
    $root = str_replace('\\', '/', (string)(realpath(ROOT) ?: ROOT));
    $file = str_replace('\\', '/', (string)(realpath($callerFile) ?: $callerFile));
    $rel = ltrim(str_starts_with($file, $root) ? substr($file, strlen($root)) : $file, '/');
    $rule = PAGE_PERMISSIONS[$rel] ?? null;
    if (!$rule) return;
    $need = is_post() ? $rule[1] : $rule[0];
    if (!$need || user_can($u, $need)) return;
    if (is_json_request()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => view_only_message($need)]);
        exit;
    }
    if (is_post()) {
        flash(view_only_message($need), 'bad');
        redirect($rel . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    }
    http_response_code(403);
    $home = url(PORTALS[$u['role']]['home'] ?? 'index.php');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>No access</title>'
       . '<link rel="stylesheet" href="' . h(asset_url('assets/style.css')) . '"></head><body><div class="wrap"><main><div class="narrow" style="margin-top:4rem">'
       . '<section class="panel"><h1>No access to this page</h1><p>' . h(view_only_message($need)) . '</p>'
       . '<p><a class="btn primary" href="' . h($home) . '">Back to dashboard</a></p></section></div></main></div></body></html>';
    exit;
}

/** Nav items the user may open (hides pages switched off for an Admin / PMS Lead). */
function nav_allowed(array $u, string $href): bool {
    if (!in_array($u['role'] ?? '', ['admin', 'pms_lead'], true)) return true;
    $need = PAGE_PERMISSIONS[$href][0] ?? null;
    return !$need || user_can($u, $need);
}

function staff_log(int $userId, string $kind, ?string $old, ?string $new, int $moved, ?string $note, ?int $actorId): void {
    try {
        q('INSERT INTO staff_changes (user_id, kind, old_value, new_value, students_moved, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,?)',
          [$userId, $kind, $old, $new, $moved, $note ? mb_substr($note, 0, 500) : null, $actorId, now()]);
    } catch (Throwable) { /* table missing before migration */ }
}

/** "0300 1234567" / "923001234567" / "+92 300-1234567" -> "+923001234567". Returns null when it does not look like a phone number. */
function normalize_phone(?string $v): ?string {
    $d = preg_replace('/\D+/', '', (string)$v);
    if ($d === '') return null;
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '0') && strlen($d) === 11) $d = '92' . substr($d, 1);
    if (strlen($d) === 10 && str_starts_with($d, '3')) $d = '92' . $d;
    return (strlen($d) >= 10 && strlen($d) <= 15) ? '+' . $d : null;
}

function wa_link(?string $phone, string $text = ''): ?string {
    $d = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($d) < 10) return null;
    return 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/** Ready-to-send login message for a new person / a password reset. */
function login_message(string $name, string $username, string $password): string {
    return "Assalam o Alaikum {$name},\n\nYour ScholarshipHive processing portal account is ready.\n"
         . "Portal: " . absolute_url('index.php') . "\nUsername: {$username}\nTemporary password: {$password}\n\n"
         . "You will be asked to set your own password the first time you sign in. Please do not share it.\n";
}

function absolute_url(string $path): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
    $u = url($path);
    return $host !== '' && !preg_match('#^https?://#', $u) ? ($https ? 'https://' : 'http://') . $host . $u : $u;
}

/** Suggests a free username from a full name: "Ayesha Khan" -> "ayesha.khan" (or ayesha.khan2 …). */
function suggest_username(string $name): string {
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '.', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '.'));
    $base = substr($base !== '' ? $base : 'user', 0, 34);
    if (strlen($base) < 3) $base = str_pad($base, 3, '0');
    $u = $base; $i = 2;
    while (q('SELECT 1 FROM users WHERE LOWER(username) = ?', [$u])->fetchColumn()) $u = $base . $i++;
    return $u;
}

/** Validates and cleans the contact part of a staff form. Returns [data, errors]. */
function staff_clean_details(array $in, ?int $exceptId = null): array {
    $err = [];
    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 150));
    if ($name === '') $err[] = 'Enter the full name.';
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err[] = 'Enter a valid email address.';
    elseif (q('SELECT 1 FROM users WHERE LOWER(email) = ? AND id != ?', [$email, (int)$exceptId])->fetchColumn()) $err[] = 'Another account already uses this email.';
    $phone = normalize_phone($in['phone'] ?? '');
    if ($phone === null) $err[] = 'Enter a valid WhatsApp number, e.g. 0300 1234567.';
    $branch = trim((string)($in['branch'] ?? ''));
    if ($branch !== '' && !in_array($branch, BRANCHES, true)) $branch = '';
    $title = trim(mb_substr((string)($in['job_title'] ?? ''), 0, 80));
    return [['name' => $name, 'email' => $email, 'phone' => $phone, 'branch' => $branch ?: null, 'job_title' => $title ?: null], $err];
}

/** Permission list from a form (only keys we know). */
function clean_perm_list($raw): array {
    $raw = is_array($raw) ? $raw : [];
    return array_values(array_intersect(array_keys(PERMISSIONS), array_map('strval', $raw)));
}

function dept_for_role(string $role, ?string $dept): string {
    if (is_officer_like_role($role)) return in_array($dept, ['frontend', 'backend'], true) ? $dept : 'backend';
    return $role === 'counselor' ? 'counselor' : 'backend';
}

/** Add a person (Super Admin only) with contact details, team and access. */
function create_staff_member(array $actor, array $in): array {
    if (($actor['role'] ?? '') !== 'super_admin') return ['ok' => false, 'error' => 'Only the Super Admin can add people.'];
    [$d, $err] = staff_clean_details($in);
    $role = (string)($in['role'] ?? '');
    if (!isset(STAFF_ROLE_OPTIONS[$role])) $err[] = 'Choose a role.';
    $username = strtolower(trim((string)($in['username'] ?? '')));
    if ($username === '' && $d['name'] !== '') $username = suggest_username($d['name']);
    if ($err) return ['ok' => false, 'error' => implode(' ', $err)];
    $dept = dept_for_role($role, $in['department'] ?? null);
    $res = create_staff_user($actor, $d['name'], $username, $role, $dept);
    if (!$res['ok']) return $res;
    $id = (int)$res['user_id'];
    $perms = in_array($role, CONFIGURABLE_PERM_ROLES, true) ? json_encode(clean_perm_list($in['perms'] ?? [])) : null;
    q('UPDATE users SET email = ?, phone = ?, branch = ?, job_title = ?, permissions = ? WHERE id = ?',
      [$d['email'], $d['phone'], $d['branch'], $d['job_title'], $perms, $id]);
    staff_log($id, 'created', null, STAFF_ROLE_OPTIONS[$role] . (is_officer_like_role($role) ? ' · ' . team_label($dept) : ''), 0, null, (int)$actor['id']);
    return ['ok' => true, 'user_id' => $id, 'credentials' => $res['credentials'], 'phone' => $d['phone'], 'email' => $d['email']];
}

function update_staff_details(array $actor, int $id, array $in): array {
    if (($actor['role'] ?? '') !== 'super_admin') return ['ok' => false, 'error' => 'Only the Super Admin can edit staff details.'];
    $t = q("SELECT * FROM users WHERE id = ? AND role != 'student'", [$id])->fetch();
    if (!$t) return ['ok' => false, 'error' => 'Person not found.'];
    [$d, $err] = staff_clean_details($in, $id);
    $username = strtolower(trim((string)($in['username'] ?? $t['username'])));
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) $err[] = 'Username: 3 to 40 letters, numbers, dots or dashes.';
    elseif (q('SELECT 1 FROM users WHERE LOWER(username) = ? AND id != ?', [$username, $id])->fetchColumn()) $err[] = 'That username is already taken.';
    if ($err) return ['ok' => false, 'error' => implode(' ', $err)];
    $changed = [];
    foreach (['name', 'email', 'phone', 'branch', 'job_title'] as $k) if ((string)($t[$k] ?? '') !== (string)($d[$k] ?? '')) $changed[] = $k;
    if ($username !== $t['username']) $changed[] = 'username';
    if (!$changed) return ['ok' => true, 'message' => 'Nothing changed.'];
    q('UPDATE users SET name = ?, username = ?, email = ?, phone = ?, branch = ?, job_title = ? WHERE id = ?',
      [$d['name'], $username, $d['email'], $d['phone'], $d['branch'], $d['job_title'], $id]);
    if ($d['name'] !== $t['name']) {
        // keep the denormalised names on student rows in step
        q('UPDATE students SET frontend_officer_name = ? WHERE frontend_officer_id = ?', [$d['name'], $id]);
        if ($t['role'] === 'counselor') q('UPDATE students SET counselor = ? WHERE assigned_counselor_id = ?', [$d['name'], $id]);
    }
    staff_log($id, 'details', null, implode(', ', $changed), 0, null, (int)$actor['id']);
    return ['ok' => true, 'message' => 'Details saved.'];
}

function set_staff_permissions(array $actor, int $id, array $perms): array {
    if (($actor['role'] ?? '') !== 'super_admin') return ['ok' => false, 'error' => 'Only the Super Admin can change access.'];
    $t = q('SELECT id, role, permissions FROM users WHERE id = ?', [$id])->fetch();
    if (!$t || !in_array($t['role'], CONFIGURABLE_PERM_ROLES, true)) return ['ok' => false, 'error' => 'Access can be set for Admin and PMS Lead only.'];
    $old = user_perms(['id' => $id, 'role' => $t['role']]);
    $new = clean_perm_list($perms);
    q('UPDATE users SET permissions = ? WHERE id = ?', [json_encode($new), $id]);
    unset($GLOBALS['__perm_cache'][$id]);
    $add = array_diff($new, $old); $rem = array_diff($old, $new);
    $lbl = fn($k) => PERMISSIONS[$k][0];
    $txt = trim(($add ? 'Added: ' . implode(', ', array_map($lbl, $add)) . '. ' : '') . ($rem ? 'Removed: ' . implode(', ', array_map($lbl, $rem)) . '.' : ''));
    if ($txt !== '') staff_log($id, 'access', null, $txt, 0, null, (int)$actor['id']);
    return ['ok' => true, 'message' => $txt !== '' ? 'Access updated.' : 'Nothing changed.'];
}

/** How many students are linked to a staff member, by slot. */
function staff_caseload(int $id): array {
    return [
        'frontend'  => (int)q('SELECT COUNT(*) FROM students WHERE frontend_officer_id = ?', [$id])->fetchColumn(),
        'backend'   => (int)q('SELECT COUNT(*) FROM students WHERE officer_id = ?', [$id])->fetchColumn(),
        'counselor' => (int)q('SELECT COUNT(*) FROM students WHERE assigned_counselor_id = ?', [$id])->fetchColumn(),
        'lead'      => (int)q('SELECT COUNT(*) FROM students WHERE assigned_pms_lead_id = ?', [$id])->fetchColumn(),
        'be_taken'  => (int)q('SELECT COUNT(*) FROM students WHERE frontend_officer_id = ? AND officer_id IS NOT NULL AND officer_id != 0 AND officer_id != ?', [$id, $id])->fetchColumn(),
        'fe_taken'  => (int)q('SELECT COUNT(*) FROM students WHERE officer_id = ? AND frontend_officer_id IS NOT NULL AND frontend_officer_id != 0 AND frontend_officer_id != ?', [$id, $id])->fetchColumn(),
    ];
}

/** Moves one assignment slot for a set of students and records it in each student's assignment history. */
function move_students_slot(array $studentIds, string $field, ?int $toId, int $actorId, string $note): int {
    if (!$studentIds) return 0;
    $names = staff_name_map();
    $moved = 0;
    foreach ($studentIds as $sid) {
        $s = q('SELECT id, frontend_officer_id, officer_id, assigned_counselor_id, assigned_pms_lead_id, counselor FROM students WHERE id = ?', [(int)$sid])->fetch();
        if (!$s) continue;
        $old = (int)($s[$field] ?? 0) ?: null;
        if ($old === $toId) continue;
        $set = [$field => $toId];
        if ($field === 'frontend_officer_id') $set['frontend_officer_name'] = $toId ? ($names[$toId] ?? null) : null;
        if ($field === 'assigned_counselor_id' && $toId) $set['counselor'] = $names[$toId] ?? $s['counselor'];
        $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
        q("UPDATE students SET $sql, updated_at = ? WHERE id = ?", array_merge(array_values($set), [now(), (int)$sid]));
        $oldName = $field === 'assigned_counselor_id' ? ($s['counselor'] ?: ($names[(int)$old] ?? null)) : ($old ? ($names[$old] ?? null) : null);
        record_assignment_change((int)$sid, $field, $old, $oldName, $toId, $toId ? ($names[$toId] ?? null) : null, $actorId, $note);
        $moved++;
    }
    return $moved;
}

/** Checks a hand-over target: an active person of the right kind who is not the person being moved. */
function valid_handover(?int $toId, string $kind, int $notId): ?int {
    if (!$toId || $toId === $notId) return null;
    $t = q("SELECT id, role, COALESCE(department, 'backend') AS department, active FROM users WHERE id = ?", [$toId])->fetch();
    if (!$t || !(int)$t['active']) return null;
    $ok = match ($kind) {
        'frontend'  => is_officer_like_role($t['role']) && $t['department'] === 'frontend',
        'backend'   => is_officer_like_role($t['role']) && $t['department'] === 'backend',
        'counselor' => $t['role'] === 'counselor',
        'lead'      => $t['role'] === 'pms_lead',
        default     => false,
    };
    return $ok ? (int)$t['id'] : null;
}

/**
 * Change someone's role and / or team, and decide what happens to their students.
 *  $o['students']     'carry' (team change: they take their students along to the new side),
 *                     'keep' (same team: keep their caseload), or 'handover' (give them to someone else).
 *  $o['to_frontend'], $o['to_backend'], $o['to_counselor'], $o['to_lead']  hand-over targets (empty = leave the slot unassigned).
 *  $o['perms']        access for Admin / PMS Lead; $o['note'] reason.
 */
function change_staff_role(array $actor, int $id, string $newRole, ?string $newDept, array $o = []): array {
    if (($actor['role'] ?? '') !== 'super_admin') return ['ok' => false, 'error' => 'Only the Super Admin can change roles and teams.'];
    $t = q("SELECT * FROM users WHERE id = ? AND role != 'student'", [$id])->fetch();
    if (!$t) return ['ok' => false, 'error' => 'Person not found.'];
    if ($t['role'] === 'super_admin' || (int)$t['id'] === (int)$actor['id']) return ['ok' => false, 'error' => 'The Super Admin role cannot be changed here.'];
    if (!isset(STAFF_ROLE_OPTIONS[$newRole])) return ['ok' => false, 'error' => 'Choose a role.'];
    $oldRole = $t['role'] === 'officer' ? 'processing_staff' : $t['role'];
    $oldDept = (string)($t['department'] ?: 'backend');
    $newDept = dept_for_role($newRole, $newDept);
    $mode = in_array($o['students'] ?? '', ['carry', 'keep', 'handover'], true) ? $o['students'] : 'carry';
    $note = trim(mb_substr((string)($o['note'] ?? ''), 0, 400));
    $roleChanged = $oldRole !== $newRole;
    $teamChanged = is_officer_like_role($newRole) && is_officer_like_role($oldRole) && $oldDept !== $newDept;
    if ($teamChanged && $mode === 'keep') $mode = 'carry';   // cannot keep the same slot on the other team
    $permsNew = in_array($newRole, CONFIGURABLE_PERM_ROLES, true) && array_key_exists('perms', $o) ? clean_perm_list($o['perms']) : null;
    if (!$roleChanged && !$teamChanged && $permsNew === null) return ['ok' => true, 'message' => 'Nothing changed.'];
    if ($newRole === 'admin' && $roleChanged && (int)q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id != ?", [$id])->fetchColumn() >= 1) {
        return ['ok' => false, 'error' => 'Only one Admin is allowed. Change the current Admin first.'];
    }

    $fe = array_column(q('SELECT id FROM students WHERE frontend_officer_id = ?', [$id])->fetchAll(), 'id');
    $be = array_column(q('SELECT id FROM students WHERE officer_id = ?', [$id])->fetchAll(), 'id');
    $cn = array_column(q('SELECT id FROM students WHERE assigned_counselor_id = ?', [$id])->fetchAll(), 'id');
    $ld = array_column(q('SELECT id FROM students WHERE assigned_pms_lead_id = ?', [$id])->fetchAll(), 'id');
    $toFe = valid_handover((int)($o['to_frontend'] ?? 0), 'frontend', $id);
    $toBe = valid_handover((int)($o['to_backend'] ?? 0), 'backend', $id);
    $toCn = valid_handover((int)($o['to_counselor'] ?? 0), 'counselor', $id);
    $toLd = valid_handover((int)($o['to_lead'] ?? 0), 'lead', $id);

    $why = 'Staff change: ' . $t['name'] . ' → ' . STAFF_ROLE_OPTIONS[$newRole] . (is_officer_like_role($newRole) ? ' (' . team_label($newDept) . ')' : '') . ($note !== '' ? ' — ' . $note : '');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $moved = 0;
        if (is_officer_like_role($oldRole) && is_officer_like_role($newRole)) {
            if ($teamChanged && $mode === 'carry') {
                // They take their students with them to the other side.
                if ($oldDept === 'frontend') {               // FE -> BE
                    $moved += move_students_slot($fe, 'officer_id', $id, (int)$actor['id'], $why);
                    $moved += move_students_slot($fe, 'frontend_officer_id', $toFe, (int)$actor['id'], $why);
                } else {                                     // BE -> FE
                    $moved += move_students_slot($be, 'frontend_officer_id', $id, (int)$actor['id'], $why);
                    $moved += move_students_slot($be, 'officer_id', $toBe, (int)$actor['id'], $why);
                }
            } elseif ($teamChanged || $mode === 'handover') {
                // Students stay on their side and go to the chosen colleague.
                $moved += move_students_slot($fe, 'frontend_officer_id', $toFe, (int)$actor['id'], $why);
                $moved += move_students_slot($be, 'officer_id', $toBe, (int)$actor['id'], $why);
            }
        } elseif (is_officer_like_role($oldRole) && $roleChanged) {
            // Becoming Admin / Counselor: their officer caseload must go to someone else.
            $moved += move_students_slot($fe, 'frontend_officer_id', $toFe, (int)$actor['id'], $why);
            $moved += move_students_slot($be, 'officer_id', $toBe, (int)$actor['id'], $why);
        }
        if ($oldRole === 'counselor' && $newRole !== 'counselor' && $cn) {
            $moved += move_students_slot($cn, 'assigned_counselor_id', $toCn, (int)$actor['id'], $why);
            if (!$toCn) q('UPDATE students SET counselor = NULL WHERE id IN (' . implode(',', array_map('intval', $cn)) . ')');
        }
        if ($oldRole === 'pms_lead' && $newRole !== 'pms_lead' && $ld) {
            $moved += move_students_slot($ld, 'assigned_pms_lead_id', $toLd, (int)$actor['id'], $why);
            if ((int)setting('default_pms_lead_id', 0) === $id) {
                q("UPDATE settings SET value = ? WHERE key = 'default_pms_lead_id'", [$toLd ? (string)$toLd : '0']);
            }
        }
        if ($roleChanged) q('UPDATE users SET role = ? WHERE id = ?', [$newRole, $id]);
        q('UPDATE users SET department = ? WHERE id = ?', [$newDept, $id]);
        if (in_array($newRole, CONFIGURABLE_PERM_ROLES, true)) {
            if ($permsNew !== null) q('UPDATE users SET permissions = ? WHERE id = ?', [json_encode($permsNew), $id]);
            elseif ($roleChanged) q('UPDATE users SET permissions = NULL WHERE id = ?', [$id]);    // start from the new role's defaults
        } else {
            q('UPDATE users SET permissions = NULL WHERE id = ?', [$id]);
        }
        unset($GLOBALS['__perm_cache'][$id]);
        $oldTxt = STAFF_ROLE_OPTIONS[$oldRole] ?? role_label($oldRole);
        if (is_officer_like_role($oldRole)) $oldTxt .= ' · ' . team_label($oldDept);
        $newTxt = STAFF_ROLE_OPTIONS[$newRole] . (is_officer_like_role($newRole) ? ' · ' . team_label($newDept) : '');
        if ($roleChanged || $teamChanged) {
            $how = match (true) {
                $teamChanged && $mode === 'carry' => 'took their students along',
                $moved > 0                        => 'students handed over',
                default                           => 'kept their students',
            };
            staff_log($id, $roleChanged ? 'role' : 'team', $oldTxt, $newTxt, $moved, trim($how . ($note !== '' ? ' · ' . $note : '')), (int)$actor['id']);
            log_activity('user', $id, 'role_changed', ['role' => $oldRole, 'department' => $oldDept], ['role' => $newRole, 'department' => $newDept, 'students_moved' => $moved], (int)$actor['id']);
        }
        if ($permsNew !== null && !$roleChanged) staff_log($id, 'access', null, 'Access updated with the role change', 0, null, (int)$actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = $e->getMessage();
        if (str_contains($msg, 'Only one Admin')) return ['ok' => false, 'error' => 'Only one Admin is allowed.'];
        throw $e;
    }
    return ['ok' => true, 'moved' => $moved, 'message' => $t['name'] . ' is now ' . $newTxt . ($moved ? " · {$moved} student assignment" . ($moved === 1 ? '' : 's') . ' updated.' : '.')];
}

/** Ends this person's current sign-in on every device. */
function force_sign_out(array $actor, int $id): array {
    $t = q("SELECT id, role, name FROM users WHERE id = ? AND role != 'student'", [$id])->fetch();
    if (!$t) return ['ok' => false, 'error' => 'Person not found.'];
    if ((int)$t['id'] === (int)$actor['id']) return ['ok' => false, 'error' => 'Use Sign out to end your own session.'];
    if (is_admin_role($t['role']) && ($actor['role'] ?? '') !== 'super_admin') return ['ok' => false, 'error' => 'Only the Super Admin can sign out an admin.'];
    try { q('UPDATE users SET session_token = ? WHERE id = ?', ['revoked-' . bin2hex(random_bytes(8)), $id]); } catch (Throwable) {}
    staff_log($id, 'status', null, 'Signed out by ' . $actor['name'], 0, null, (int)$actor['id']);
    return ['ok' => true, 'message' => $t['name'] . ' is signed out. They can sign in again with their password.'];
}

function staff_history(int $id, int $limit = 40): array {
    try {
        return q('SELECT c.*, u.name AS by_name FROM staff_changes c LEFT JOIN users u ON u.id = c.changed_by WHERE c.user_id = ? ORDER BY c.changed_at DESC, c.id DESC LIMIT ' . (int)$limit, [$id])->fetchAll();
    } catch (Throwable) { return []; }
}
