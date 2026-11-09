<?php
declare(strict_types=1);

/**
 * Order-style student workflow: processing status, 8-step journey, assignments,
 * communication log, pending evaluation and dropdown data helpers.
 *
 * Everything here reads real records (students, users, student_documents,
 * student_applications, student_remarks). Nothing is hard-coded per person.
 */

// =========================================================================
// 1. Processing status (order-style badge)
// =========================================================================

const PROCESSING_STATUSES = ['New', 'Pending', 'In Progress', 'Completed', 'On Hold', 'Rejected'];

function processing_status_class(string $s): string {
    return match ($s) {
        'New'         => 'st-new',
        'Pending'     => 'st-pending',
        'In Progress' => 'st-progress',
        'Completed'   => 'st-done',
        'On Hold'     => 'st-hold',
        'Rejected'    => 'st-rejected',
        default       => 'st-progress',
    };
}

function processing_status_badge(string $s, string $extra = ''): string {
    return '<span class="st-badge ' . processing_status_class($s) . ($extra ? ' ' . h($extra) : '') . '"><i></i>' . h($s) . '</span>';
}

/**
 * Status shown on lists and profile. A manual override (students.processing_status) wins;
 * otherwise it is worked out from the record so existing students need no data entry.
 */
function processing_status(array $r, ?array $journey = null): string {
    $manual = trim((string)($r['processing_status'] ?? ''));
    if ($manual !== '' && in_array($manual, PROCESSING_STATUSES, true)) {
        return $manual;
    }
    $life = (string)($r['student_status'] ?? 'active');
    if ($life === 'disabled') return 'Rejected';
    if ($life === 'inactive') return 'On Hold';
    if (($r['visa'] ?? '') === 'Refused') return 'Rejected';
    if (($r['visa'] ?? '') === 'Granted') return 'Completed';
    if ($journey !== null) {
        $final = $journey['final']['status'] ?? '';
        if ($final === 'Completed') return 'Completed';
        if (in_array('Rejected', array_column($journey, 'status'), true)) return 'Rejected';
        if (in_array('On Hold', array_column($journey, 'status'), true)) return 'On Hold';
    }
    $created = strtotime((string)($r['created_at'] ?? '')) ?: 0;
    $isFresh = $created > 0 && (time() - $created) < 7 * 86400 && empty($r['is_legacy']);
    if ($isFresh && ($journey === null || journey_done_count($journey) <= 1)) return 'New';
    if (!empty($r['pending_since'])) return 'Pending';
    return 'In Progress';
}

// =========================================================================
// 2. Processing journey (8 steps)
// =========================================================================

const JOURNEY_STAGES = [
    'registration' => ['Student Registration',  'Registration saved and file opened.',                      'frontend'],
    'documents'    => ['Document Collection',   'Checklist shared, documents collected and verified.',      'frontend'],
    'processing'   => ['Application Processing','Email / portal accounts, CV, SOP and shortlisting.',       'backend'],
    'university'   => ['University Application','Applications submitted to universities.',                  'backend'],
    'offer'        => ['Offer Letter',          'Admission / offer letter received.',                       'backend'],
    'payment'      => ['Fee / Payment',         'Tuition / consultancy installments and enrollment.',       'backend'],
    'visa'         => ['Visa Processing',       'Embassy appointment, visa file and decision.',             'backend'],
    'final'        => ['Final Completion',      'Visa granted and file closed.',                            'backend'],
];
const STAGE_STATUSES = ['Not Started', 'In Progress', 'Pending', 'Completed', 'On Hold', 'Rejected'];

function stage_status_class(string $s): string {
    return match ($s) {
        'Completed'   => 'ok',
        'In Progress' => 'acc',
        'Pending'     => 'warn',
        'On Hold'     => 'hold',
        'Rejected'    => 'bad',
        default       => 'muted',
    };
}

/** Is a document-pending note just a "nothing pending" placeholder? */
function doc_note_is_empty(?string $note): bool {
    $n = strtolower(trim((string)$note));
    return $n === '' || in_array($n, ['n/a', 'none', 'nil', 'complete', 'completed', 'all complete', 'done', '-'], true);
}

/** Works out each stage from the existing sheet fields. */
function auto_journey(array $r, array $apps = [], int $docsReceived = 0): array {
    $yes = fn($k) => ($r[$k] ?? '') === 'Yes';
    $has = fn($k) => trim((string)($r[$k] ?? '')) !== '';
    $appCount = count($apps);
    $appSubmitted = false;
    foreach ($apps as $a) {
        $st = strtolower((string)($a['status'] ?? ''));
        if ($st !== '' && preg_match('/appl|submit|offer|admit|accept|enrol/', $st)) { $appSubmitted = true; break; }
    }
    $shortRaw = strtolower(trim((string)($r['shortlisting'] ?? '')));
    $out = [];

    $out['registration'] = ['Completed', $r['received_date'] ?: substr((string)($r['created_at'] ?? ''), 0, 10)];

    $docInfo = $r['_doc_info'] ?? null;
    if ($docInfo !== null && $docInfo['complete']) {
        $out['documents'] = ['Completed', $r['doc_request_date'] ?? null];
    } elseif ($docInfo === null && $yes('docs_checklist') && doc_note_is_empty($r['pending_docs_note'] ?? '')) {
        $out['documents'] = ['Completed', $r['doc_request_date'] ?? null];
    } elseif (($docInfo['in_hand'] ?? 0) > 0 || $docsReceived > 0 || $has('doc_request_date') || !doc_note_is_empty($r['pending_docs_note'] ?? '')) {
        $out['documents'] = ['In Progress', $r['doc_request_date'] ?? null];
    } else {
        $out['documents'] = ['Not Started', null];
    }

    if ($appCount > 0 || $yes('offer')) {
        $out['processing'] = ['Completed', null];
    } elseif ($has('email') || $has('portal_id') || ($shortRaw !== '' && $shortRaw !== 'no') || $has('cv_status') || $has('sop_status') || $has('campus_france_status')) {
        $out['processing'] = ['In Progress', null];
    } else {
        $out['processing'] = ['Not Started', null];
    }

    if ($appSubmitted || $yes('offer')) {
        $out['university'] = ['Completed', null];
    } elseif ($appCount > 0) {
        $out['university'] = ['In Progress', null];
    } else {
        $out['university'] = ['Not Started', null];
    }

    if ($yes('offer')) {
        $out['offer'] = ['Completed', null];
    } elseif ($appSubmitted) {
        $out['offer'] = ['Pending', null];
    } else {
        $out['offer'] = ['Not Started', null];
    }

    $pay = $r['_payments'] ?? null;
    if ($pay !== null && $pay['received'] > 0 && $pay['received'] === $pay['total'] && $pay['total'] >= 3) {
        $out['payment'] = ['Completed', $pay['last_date']];
    } elseif ($yes('last_installment')) {
        $out['payment'] = ['Completed', null];
    } elseif (($pay['received'] ?? 0) > 0 || $has('consult1') || $has('installment_1') || $has('consult2') || $yes('enrollment')) {
        $out['payment'] = ['In Progress', $r['enrollment_date'] ?? null];
    } else {
        $out['payment'] = ['Not Started', null];
    }

    $visa = (string)($r['visa'] ?? '');
    if ($visa === 'Granted') {
        $out['visa'] = ['Completed', null];
    } elseif ($visa === 'Refused') {
        $out['visa'] = ['Rejected', null];
    } elseif ($visa === 'Pending' || $has('embassy_status')) {
        $out['visa'] = ['In Progress', null];
    } else {
        $out['visa'] = ['Not Started', null];
    }

    $out['final'] = $visa === 'Granted' ? ['Completed', null] : ['Not Started', null];
    return $out;
}

/**
 * Full journey for one student: saved stage rows win, otherwise the automatic value.
 * Each item: key, label, hint, status, date, assigned_to, assigned_name, notes, updated_at, updated_by_name, is_auto
 */
function student_journey(array $r, ?array $apps = null, ?array $savedRows = null, ?array $staffNames = null): array {
    $sid = (int)($r['id'] ?? 0);
    if ($apps === null) {
        $apps = $sid ? q('SELECT status FROM student_applications WHERE student_id = ?', [$sid])->fetchAll() : [];
    }
    if ($savedRows === null) {
        $savedRows = [];
        if ($sid) {
            foreach (q('SELECT ss.*, u.name AS updated_by_name FROM student_stages ss LEFT JOIN users u ON u.id = ss.updated_by WHERE ss.student_id = ?', [$sid]) as $row) {
                $savedRows[$row['stage_key']] = $row;
            }
        }
    }
    if ($staffNames === null) {
        $staffNames = staff_name_map();
    }
    $docsReceived = 0;
    if ($sid && !isset($r['_doc_info'])) {
        $r['_doc_info'] = student_pending_docs($r, load_doc_state([$sid])[$sid] ?? []);
    }
    if ($sid && !isset($r['_payments'])) {
        $r['_payments'] = payment_summary(student_payments($r));
    }
    $auto = auto_journey($r, $apps, $docsReceived);

    $out = [];
    foreach (JOURNEY_STAGES as $key => [$label, $hint, $side]) {
        $defaultOwner = $side === 'frontend'
            ? ((int)($r['frontend_officer_id'] ?? 0) ?: (int)($r['officer_id'] ?? 0))
            : ((int)($r['officer_id'] ?? 0) ?: (int)($r['frontend_officer_id'] ?? 0));
        $row = $savedRows[$key] ?? null;
        $assigned = $row && !empty($row['assigned_to']) ? (int)$row['assigned_to'] : $defaultOwner;
        $out[$key] = [
            'key'             => $key,
            'label'           => $label,
            'hint'            => $hint,
            'status'          => $row ? (string)$row['status'] : $auto[$key][0],
            'date'            => $row ? ($row['stage_date'] ?? null) : ($auto[$key][1] ?? null),
            'assigned_to'     => $assigned ?: null,
            'assigned_name'   => $assigned ? ($staffNames[$assigned] ?? null) : null,
            'notes'           => $row['notes'] ?? null,
            'updated_at'      => $row['updated_at'] ?? null,
            'updated_by_name' => $row['updated_by_name'] ?? null,
            'is_auto'         => $row === null,
        ];
    }
    return $out;
}

function journey_done_count(array $journey): int {
    return count(array_filter($journey, fn($s) => ($s['status'] ?? '') === 'Completed'));
}

/** The first stage that is not completed (the "current" step), or null when all are done. */
function journey_current(array $journey): ?array {
    foreach ($journey as $s) {
        if ($s['status'] !== 'Completed') return $s;
    }
    return null;
}

/** Can this user update journey stages for this student? (counselors are read-only) */
function can_update_journey(array $u): bool {
    return in_array($u['role'] ?? '', ['super_admin', 'admin', 'pms_lead', 'processing_staff', 'officer'], true);
}

/**
 * Save one stage. Keeps a full history row and mirrors the few stages that have a
 * matching sheet column (offer, visa, documents checklist) so old reports stay correct.
 */
function update_journey_stage(array $actor, int $studentId, string $stageKey, string $status, ?string $date, ?int $assignedTo, ?string $notes, bool $postAsRemark = true): array {
    if (!can_update_journey($actor)) {
        return ['ok' => false, 'error' => 'You have read-only access to the processing journey.'];
    }
    if (!isset(JOURNEY_STAGES[$stageKey])) return ['ok' => false, 'error' => 'Unknown stage.'];
    if (!in_array($status, STAGE_STATUSES, true)) return ['ok' => false, 'error' => 'Unknown stage status.'];
    if ($date !== null && $date !== '' && !valid_date($date)) return ['ok' => false, 'error' => 'Stage date is not valid.'];
    $date = ($date === '' ? null : $date);
    $notes = $notes !== null ? trim(mb_substr($notes, 0, 2000)) : null;
    if ($notes === '') $notes = null;
    if ($assignedTo !== null && $assignedTo > 0) {
        $ok = q("SELECT 1 FROM users WHERE id = ? AND role IN ('super_admin','admin','pms_lead','processing_staff','officer') AND active = 1", [$assignedTo])->fetchColumn();
        if (!$ok) $assignedTo = null;
    } else {
        $assignedTo = null;
    }

    $st = q('SELECT * FROM students WHERE id = ?', [$studentId])->fetch();
    if (!$st) return ['ok' => false, 'error' => 'Student not found.'];
    $before = student_journey($st)[$stageKey];

    $now = now();
    q('INSERT INTO student_stages (student_id, stage_key, status, stage_date, assigned_to, notes, updated_by, updated_at)
       VALUES (?,?,?,?,?,?,?,?)
       ON CONFLICT(student_id, stage_key) DO UPDATE SET status = excluded.status, stage_date = excluded.stage_date,
           assigned_to = excluded.assigned_to, notes = excluded.notes, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
      [$studentId, $stageKey, $status, $date, $assignedTo, $notes, (int)$actor['id'], $now]);
    q('INSERT INTO student_stage_history (student_id, stage_key, old_status, new_status, stage_date, assigned_to, notes, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,?,?)',
      [$studentId, $stageKey, $before['status'], $status, $date, $assignedTo, $notes, (int)$actor['id'], $now]);

    // Mirror to the original sheet columns so dashboards / exports stay consistent
    $mirror = [];
    if ($stageKey === 'offer' && $status === 'Completed' && ($st['offer'] ?? '') !== 'Yes') $mirror['offer'] = 'Yes';
    if ($stageKey === 'visa' && $status === 'Completed' && ($st['visa'] ?? '') !== 'Granted') $mirror['visa'] = 'Granted';
    if ($stageKey === 'visa' && $status === 'Rejected' && ($st['visa'] ?? '') !== 'Refused') $mirror['visa'] = 'Refused';
    if ($stageKey === 'visa' && in_array($status, ['In Progress', 'Pending'], true) && ($st['visa'] ?? '') === '') $mirror['visa'] = 'Pending';
    if ($stageKey === 'documents' && $status === 'Completed' && ($st['docs_checklist'] ?? '') !== 'Yes') $mirror['docs_checklist'] = 'Yes';
    if ($mirror) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($mirror)));
        q("UPDATE students SET $set, updated_at = ? WHERE id = ?", array_merge(array_values($mirror), [$now, $studentId]));
    }

    log_activity('student', $studentId, 'stage_updated', ['stage' => $stageKey, 'status' => $before['status']], ['stage' => $stageKey, 'status' => $status, 'notes' => $notes ? mb_substr($notes, 0, 100) : null], (int)$actor['id']);

    if ($postAsRemark && ($notes !== null || $before['status'] !== $status)) {
        $label = JOURNEY_STAGES[$stageKey][0];
        $text = "[{$label}: {$status}]" . ($notes ? ' ' . $notes : '');
        add_student_remark($actor, $studentId, $text);
    }
    return ['ok' => true, 'mirrored' => array_keys($mirror)];
}

// =========================================================================
// 3. Assignments (counselor / front-end / back-end / PMS lead) with history
// =========================================================================

const ASSIGNMENT_FIELDS = [
    'frontend_officer_id'   => 'Front-End Processing Officer',
    'officer_id'            => 'Back-End Processing Officer',
    'assigned_counselor_id' => 'Counselor',
    'assigned_pms_lead_id'  => 'PMS Lead',
];

function staff_name_map(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (q("SELECT id, name FROM users WHERE role != 'student'") as $u) {
            $map[(int)$u['id']] = $u['name'];
        }
    }
    return $map;
}

/** Real staff lists for dropdowns (active accounts only). */
function staff_options(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = q("SELECT id, name, role, COALESCE(department, 'backend') AS department FROM users WHERE active = 1 AND role IN ('counselor','pms_lead','processing_staff','officer') ORDER BY name COLLATE NOCASE")->fetchAll();
    $cache = ['counselor' => [], 'frontend' => [], 'backend' => [], 'pms_lead' => []];
    foreach ($rows as $r) {
        if ($r['role'] === 'counselor') { $cache['counselor'][] = $r; continue; }
        if ($r['role'] === 'pms_lead') $cache['pms_lead'][] = $r;
        if ($r['department'] === 'frontend') $cache['frontend'][] = $r; else $cache['backend'][] = $r;
    }
    return $cache;
}

function can_change_assignments(array $u): bool {
    return ($u['role'] ?? '') === 'super_admin' || (in_array($u['role'] ?? '', ['admin', 'pms_lead'], true) && user_can($u, 'students.assign'));
}

/** Record one assignment change in assignment_history. */
function record_assignment_change(int $studentId, string $field, ?int $oldId, ?string $oldName, ?int $newId, ?string $newName, ?int $actorId, ?string $note = null): void {
    if ((int)$oldId === (int)$newId && trim((string)$oldName) === trim((string)$newName)) return;
    try {
        q('INSERT INTO assignment_history (student_id, field, old_user_id, old_name, new_user_id, new_name, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,?,?)',
          [$studentId, $field, $oldId ?: null, $oldName ?: null, $newId ?: null, $newName ?: null, $note, $actorId, now()]);
    } catch (Throwable $e) {
        error_log('assignment history: ' . $e->getMessage());
    }
}

/**
 * Change any of the four assignments. Keeps the legacy text columns
 * (counselor, frontend_officer_name) in sync so old screens and exports keep working.
 */
function change_assignments(array $actor, int $studentId, array $new, ?string $note = null): array {
    if (!can_change_assignments($actor)) {
        return ['ok' => false, 'error' => 'Only Admin and PMS Lead can change assignments.'];
    }
    $st = q('SELECT * FROM students WHERE id = ?', [$studentId])->fetch();
    if (!$st) return ['ok' => false, 'error' => 'Student not found.'];
    $names = staff_name_map();
    $valid = [
        'frontend_officer_id'   => "role IN ('pms_lead','processing_staff','officer')",
        'officer_id'            => "role IN ('pms_lead','processing_staff','officer')",
        'assigned_counselor_id' => "role = 'counselor'",
        'assigned_pms_lead_id'  => "role IN ('pms_lead','admin','super_admin')",
    ];
    $set = [];
    foreach ($new as $field => $val) {
        if (!isset(ASSIGNMENT_FIELDS[$field])) continue;
        $val = (int)$val ?: null;
        if ($val && !q("SELECT 1 FROM users WHERE id = ? AND {$valid[$field]}", [$val])->fetchColumn()) {
            return ['ok' => false, 'error' => ASSIGNMENT_FIELDS[$field] . ': selected person is not valid for this role.'];
        }
        $oldId = (int)($st[$field] ?? 0) ?: null;
        $oldName = $field === 'assigned_counselor_id' ? ($st['counselor'] ?: ($oldId ? ($names[$oldId] ?? null) : null)) : ($oldId ? ($names[$oldId] ?? null) : null);
        if ($oldId === $val) continue;
        $set[$field] = $val;
        if ($field === 'frontend_officer_id') $set['frontend_officer_name'] = $val ? ($names[$val] ?? null) : null;
        if ($field === 'assigned_counselor_id') $set['counselor'] = $val ? ($names[$val] ?? null) : ($st['counselor'] ?? null);
        record_assignment_change($studentId, $field, $oldId, $oldName, $val, $val ? ($names[$val] ?? null) : null, (int)$actor['id'], $note);
    }
    if (!$set) return ['ok' => true, 'changed' => 0];
    $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
    q("UPDATE students SET $sql, updated_at = ? WHERE id = ?", array_merge(array_values($set), [now(), $studentId]));
    log_activity('student', $studentId, 'assignment_changed', null, $set, (int)$actor['id']);

    // Let the newly assigned officers know
    foreach (['frontend_officer_id', 'officer_id'] as $f) {
        if (!empty($set[$f]) && (int)$set[$f] !== (int)$actor['id']) {
            create_notification((int)$set[$f], 'student_assigned', 'New student assigned: ' . $st['name'], "{$actor['name']} assigned {$st['name']} to you as " . ASSIGNMENT_FIELDS[$f] . '.', $studentId);
        }
    }
    return ['ok' => true, 'changed' => count($set)];
}

function assignment_history(int $studentId): array {
    return q('SELECT ah.*, u.name AS changed_by_name FROM assignment_history ah LEFT JOIN users u ON u.id = ah.changed_by WHERE ah.student_id = ? ORDER BY ah.changed_at DESC, ah.id DESC', [$studentId])->fetchAll();
}

/** Counselor name → user id (case-insensitive), used to keep assigned_counselor_id in sync with the text column. */
function counselor_id_by_name(?string $name): ?int {
    $name = trim((string)$name);
    if ($name === '') return null;
    $id = q("SELECT id FROM users WHERE role = 'counselor' AND LOWER(TRIM(name)) = LOWER(?) LIMIT 1", [$name])->fetchColumn();
    return $id ? (int)$id : null;
}

// =========================================================================
// 4. Quick registration (Add Student modal)
// =========================================================================

function can_quick_add_student(array $u): bool {
    return ($u['role'] ?? '') === 'super_admin' || (in_array($u['role'] ?? '', ['admin', 'pms_lead', 'processing_staff', 'officer'], true) && user_can($u, 'students.add'));
}

/** Joins first + last name into the full name used everywhere ("" parts are skipped). */
function compose_student_name(?string $first, ?string $last): string {
    $clean = fn($x) => trim(preg_replace('/\s+/u', ' ', mb_substr((string)$x, 0, 80)));
    return trim($clean($first) . ' ' . $clean($last));
}
/** [first, last] for a student row: the saved parts when they still match the full name, else the full name split at the last space. */
function student_name_parts(array $r): array {
    $full = trim(preg_replace('/\s+/u', ' ', (string)($r['name'] ?? '')));
    $f = trim((string)($r['first_name'] ?? '')); $l = trim((string)($r['last_name'] ?? ''));
    if ($f !== '' && compose_student_name($f, $l) === $full) return [$f, $l];
    $pos = mb_strrpos($full, ' ');
    return $pos === false ? [$full, ''] : [mb_substr($full, 0, $pos), mb_substr($full, $pos + 1)];
}

/**
 * Creates a student from the short registration form with role-based assignment:
 *  - Front-end login: front-end officer = the logged-in user (locked); back-end officer is chosen.
 *  - Back-end login:  back-end officer = the logged-in user (locked); front-end officer is chosen.
 *  - Admin / PMS Lead: both are chosen.
 */
function quick_register_student(array $actor, array $in): array {
    if (!can_quick_add_student($actor)) {
        return ['ok' => false, 'error' => 'You do not have permission to register students.'];
    }
    $opts = staff_options();
    $feIds = array_map(fn($o) => (int)$o['id'], $opts['frontend']);
    $beIds = array_map(fn($o) => (int)$o['id'], $opts['backend']);
    $cnIds = array_map(fn($o) => (int)$o['id'], $opts['counselor']);
    $names = staff_name_map();

    $hasParts = array_key_exists('first_name', $in) || array_key_exists('last_name', $in);
    $firstName = trim(mb_substr((string)($in['first_name'] ?? ''), 0, 80));
    $lastName = trim(mb_substr((string)($in['last_name'] ?? ''), 0, 80));
    $name = $hasParts ? compose_student_name($firstName, $lastName) : trim(mb_substr((string)($in['name'] ?? ''), 0, 150));
    if (!$hasParts) [$firstName, $lastName] = student_name_parts(['name' => $name]);
    $errors = [];
    if ($hasParts && mb_strlen($firstName) < 2) $errors[] = 'Enter the first name.';
    elseif (mb_strlen($name) < 2) $errors[] = 'Enter the student name.';
    $country = trim((string)($in['country'] ?? ''));
    $countries = array_merge(SUPPORTED_COUNTRIES, ['Other']);
    if (!in_array($country, $countries, true)) $errors[] = 'Choose a destination country.';
    $level = in_array($in['program_level'] ?? '', ['BS', 'MS'], true) ? $in['program_level'] : null;
    $email = trim((string)($in['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'The email address does not look right.';
    $phone = trim(mb_substr((string)($in['contact_number'] ?? ''), 0, 40));
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $errors[] = 'Contact number may contain digits, spaces, + and - only.';
    $received = trim((string)($in['received_date'] ?? '')) ?: date('Y-m-d');
    if (!valid_date($received)) $errors[] = 'Registration date is not valid.';

    $counselorId = (int)($in['assigned_counselor_id'] ?? 0) ?: null;
    if ($counselorId && !in_array($counselorId, $cnIds, true)) $errors[] = 'Choose a counselor from the list.';
    if (!$counselorId) $errors[] = 'Choose the counselor.';

    $myDept = (string)($actor['department'] ?? 'backend');
    $isLead = in_array($actor['role'] ?? '', ['super_admin', 'admin', 'pms_lead'], true);
    $fe = (int)($in['frontend_officer_id'] ?? 0) ?: null;
    $be = (int)($in['officer_id'] ?? 0) ?: null;
    if (!$isLead) {
        if ($myDept === 'frontend') $fe = (int)$actor['id']; else $be = (int)$actor['id'];
    }
    if ($fe && !in_array($fe, $feIds, true) && $fe !== (int)$actor['id']) $errors[] = 'Choose a valid front-end officer.';
    if ($be && !in_array($be, $beIds, true) && $be !== (int)$actor['id']) $errors[] = 'Choose a valid back-end officer.';

    // Duplicate guard: same name + same phone or email
    if (!$errors && ($phone !== '' || $email !== '')) {
        $dup = q('SELECT id, name FROM students WHERE LOWER(TRIM(name)) = LOWER(?) AND ((contact_number IS NOT NULL AND contact_number != "" AND contact_number = ?) OR (email IS NOT NULL AND email != "" AND LOWER(email) = LOWER(?))) LIMIT 1', [$name, $phone, $email])->fetch();
        if ($dup && empty($in['allow_duplicate'])) {
            return ['ok' => false, 'duplicate_id' => (int)$dup['id'], 'error' => "A student named {$dup['name']} with the same phone/email already exists (#{$dup['id']})."];
        }
    }
    if ($errors) return ['ok' => false, 'error' => implode(' ', $errors)];

    $data = [
        'name'                  => $name,
        'first_name'            => $firstName !== '' ? $firstName : null,
        'last_name'             => $lastName !== '' ? $lastName : null,
        'country'               => $country,
        'program_level'         => $level,
        'intake'                => trim(mb_substr((string)($in['intake'] ?? ''), 0, 60)) ?: null,
        'branch'                => trim(mb_substr((string)($in['branch'] ?? ''), 0, 60)) ?: null,
        'contact_number'        => $phone ?: null,
        'email'                 => $email ?: null,
        'received_date'         => $received,
        'assigned_counselor_id' => $counselorId,
        'counselor'             => $counselorId ? ($names[$counselorId] ?? null) : null,
        'frontend_officer_id'   => $fe,
        'frontend_officer_name' => $fe ? ($names[$fe] ?? null) : null,
        'officer_id'            => $be,
        'applied_for'           => trim(mb_substr((string)($in['applied_for'] ?? ''), 0, 120)) ?: null,
        'entered_by'            => (int)$actor['id'],
    ];
    $res = create_student($actor, $data);
    if (!$res['ok']) return $res;
    $sid = (int)$res['id'];

    record_assignment_change($sid, 'assigned_counselor_id', null, null, $counselorId, $data['counselor'], (int)$actor['id'], 'Registration');
    if ($fe) record_assignment_change($sid, 'frontend_officer_id', null, null, $fe, $data['frontend_officer_name'], (int)$actor['id'], 'Registration');
    if ($be) record_assignment_change($sid, 'officer_id', null, null, $be, $names[$be] ?? null, (int)$actor['id'], 'Registration');

    q('INSERT INTO student_stages (student_id, stage_key, status, stage_date, assigned_to, notes, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?)',
      [$sid, 'registration', 'Completed', $received, $fe ?: (int)$actor['id'], 'Registered via Add Student', (int)$actor['id'], now()]);

    foreach ([$fe, $be] as $oid) {
        if ($oid && $oid !== (int)$actor['id']) {
            create_notification($oid, 'student_assigned', 'New student assigned: ' . $name, "{$actor['name']} registered {$name} ({$country}) and assigned the file to you.", $sid);
        }
    }
    $note = trim((string)($in['first_note'] ?? ''));
    if ($note !== '') add_student_remark($actor, $sid, $note);
    return ['ok' => true, 'id' => $sid];
}

// =========================================================================
// 5. Communication history
// =========================================================================

const COMM_CHANNELS = ['Call', 'WhatsApp', 'Email', 'Meeting', 'Zoom / Online', 'SMS', 'Walk-in'];

function add_communication(array $actor, int $studentId, string $channel, ?string $direction, string $summary, string $date, ?string $followUp): array {
    if (!is_staff_role($actor['role'] ?? '')) return ['ok' => false, 'error' => 'Not allowed.'];
    if (!in_array($channel, COMM_CHANNELS, true)) return ['ok' => false, 'error' => 'Choose a channel.'];
    $summary = trim(mb_substr($summary, 0, 3000));
    if ($summary === '') return ['ok' => false, 'error' => 'Write a short summary of the conversation.'];
    if (!valid_date($date)) return ['ok' => false, 'error' => 'Contact date is not valid.'];
    if ($followUp !== null && $followUp !== '' && !valid_date($followUp)) return ['ok' => false, 'error' => 'Follow-up date is not valid.'];
    $direction = in_array($direction, ['Outgoing', 'Incoming'], true) ? $direction : null;
    q('INSERT INTO student_communications (student_id, channel, direction, summary, follow_up_date, contact_date, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
      [$studentId, $channel, $direction, $summary, $followUp ?: null, $date, (int)$actor['id'], now()]);
    log_activity('student', $studentId, 'communication_logged', null, ['channel' => $channel, 'summary' => mb_substr($summary, 0, 100)], (int)$actor['id']);
    return ['ok' => true];
}

function list_communications(int $studentId): array {
    return q('SELECT c.*, u.name AS by_name FROM student_communications c LEFT JOIN users u ON u.id = c.created_by WHERE c.student_id = ? ORDER BY c.contact_date DESC, c.id DESC', [$studentId])->fetchAll();
}

// =========================================================================
// 6. Pending evaluation (shared by Pending Tracker and Officer Pending Reports)
// =========================================================================

// Required documents (index in DOCS => short name shown in lists)
const REQUIRED_DOCS = [
    0 => 'Passport', 1 => 'CNIC / B-form', 2 => 'Matric', 3 => 'Intermediate', 4 => 'Bachelor degree',
    5 => 'IELTS / PTE / MOI', 6 => 'CV', 7 => 'Bank statement', 8 => 'Tax documents',
];
const CORE_DOC_INDICES = REQUIRED_DOCS;   // kept for older callers
const DOC_IN_HAND = ['Received', 'Verified', 'Not Required'];

/** Per-student document statuses: [student_id => [doc_index => status]]. Drive and uploaded files count the same. */
function load_doc_state(?array $studentIds = null): array {
    $sql = 'SELECT student_id, doc_index, status, file_path FROM student_documents';
    $params = [];
    if ($studentIds !== null) {
        if (!$studentIds) return [];
        $sql .= ' WHERE student_id IN (' . implode(',', array_fill(0, count($studentIds), '?')) . ')';
        $params = array_values($studentIds);
    }
    $out = [];
    foreach (q($sql, $params) as $d) {
        $st = (string)$d['status'];
        if ($st === 'Uploaded') $st = 'Received';
        $out[(int)$d['student_id']][(int)$d['doc_index']] = $st;
    }
    return $out;
}
/** Backwards-compatible name used by older pages. */
function load_doc_stats(?array $studentIds = null): array { return load_doc_state($studentIds); }

/**
 * ONE list of pending documents for a student.
 * A required document is pending until it is marked Received / Verified / Not Required in the
 * Documents section (whether the file is in Google Drive or uploaded here makes no difference).
 * Documents an officer typed in the old "pending documents" sheet note are added to the same list.
 */
/** Sheet "Docs checklist" says the documents are complete ("Yes", or a later-stage note such as nostrification). */
function sheet_docs_complete(array $r): bool {
    $v = strtolower(trim((string)($r['docs_checklist'] ?? '')));
    return $v !== '' && !in_array($v, ['no', 'pending', 'n/a', '-'], true);
}

/** Keeps the sheet "Docs checklist" in step with the document statuses (one source of truth for "documents complete"). */
function sync_docs_checklist(int $studentId): void {
    $r = q('SELECT * FROM students WHERE id = ?', [$studentId])->fetch();
    if (!$r) return;
    $st = load_doc_state([$studentId])[$studentId] ?? [];
    $required = REQUIRED_DOCS;
    if (strtoupper(trim((string)($r['program_level'] ?? ''))) === 'BS') unset($required[4]);
    $all = true;
    foreach ($required as $i => $_) if (!in_array($st[$i] ?? '', DOC_IN_HAND, true)) $all = false;
    // all required in hand -> the sheet column says Yes as well (never flipped back: a document asked for again is "Requested")
    if ($all && !sheet_docs_complete($r)) q("UPDATE students SET docs_checklist = 'Yes' WHERE id = ?", [$studentId]);
}

function student_pending_docs(array $r, array $docStatus): array {
    $level = strtoupper(trim((string)($r['program_level'] ?? '')));
    $required = REQUIRED_DOCS;
    if ($level === 'BS') unset($required[4]);
    $missing = [];
    $inHand = 0;
    // The sheet's "Docs checklist" (Yes / later-stage text) means the officer confirmed the documents are complete.
    // That stands unless a document was asked for again in the portal ("Requested") or named in the note below.
    $sheetDone = sheet_docs_complete($r);
    $bySheet = 0;
    foreach ($required as $i => $label) {
        $st = $docStatus[$i] ?? '';
        if (in_array($st, DOC_IN_HAND, true)) $inHand++;
        elseif ($sheetDone && $st !== 'Requested') { $inHand++; $bySheet++; }
        else $missing[$i] = $label;
    }
    // Officer's sheet note ("LOR, bank statement") -> same list, no separate category
    $note = trim((string)($r['pending_docs_note'] ?? ''));
    if (!doc_note_is_empty($note)) {
        $map = ['passport' => 0, 'cnic' => 1, 'b-form' => 1, 'b form' => 1, 'matric' => 2, 'ssc' => 2, '10th' => 2,
                'inter' => 3, 'hssc' => 3, 'fsc' => 3, '12th' => 3, 'degree' => 4, 'transcript' => 4, 'bachelor' => 4,
                'ielts' => 5, 'pte' => 5, 'moi' => 5, 'cv' => 6, 'resume' => 6, 'bank' => 7, 'tax' => 8, 'fbr' => 8];
        if (preg_match('/^\s*all( documents| docs)?\s*(pending|required)?\s*$/i', $note)) {
            foreach ($required as $i => $label) $missing[$i] = $label;
        } else {
            foreach (preg_split('/[,\/\n;&+]|\band\b/i', $note) as $part) {
                $part = trim($part, " .-\t");
                if ($part === '' || strlen($part) < 2) continue;
                $hit = null;
                foreach ($map as $kw => $idx) {
                    if (stripos($part, $kw) !== false && isset($required[$idx])) { $hit = $idx; break; }
                }
                if ($hit !== null) {
                    $missing[$hit] = $required[$hit];
                } else {
                    $missing['n:' . strtolower($part)] = mb_strimwidth(ucfirst($part), 0, 40, '…');
                }
            }
        }
    }
    $inHand = count(array_filter(array_keys($required), fn($i) => !isset($missing[$i])));
    return [
        'missing_idx' => array_values(array_filter(array_keys($missing), 'is_int')),
        'by_sheet'  => $bySheet,
        'missing'   => array_values($missing),
        'count'     => count($missing),
        'in_hand'   => $inHand,
        'required'  => count($required),
        'complete'  => !$missing,
    ];
}

/** Meeting recording state: recorded (link / uploaded video), explained (reason why not), or pending. */
function meeting_recording_state(array $r): array {
    $link = trim((string)($r['meeting_link'] ?? ''));
    $url = safe_link($link) ? $link : null;
    if ($url && str_contains($url, '/drive/folders/') && $url === trim((string)($r['drive_link'] ?? ''))) $url = null;
    $video = trim((string)($r['meeting_video_path'] ?? ''));
    $reason = trim((string)($r['meeting_note'] ?? ''));
    if ($reason === '' && $link !== '' && !safe_link($link)) $reason = $link;   // old sheets typed the reason in the link column
    $meetingUrl = function_exists('student_meeting_url') ? student_meeting_url($r) : $url;
    if ($video !== '' || $meetingUrl || ($r['recorded_uploaded'] ?? '') === 'Yes') {
        return ['state' => 'recorded', 'url' => $meetingUrl, 'video' => $video !== '', 'reason' => ''];
    }
    if ($reason !== '') return ['state' => 'not_recorded', 'url' => null, 'video' => false, 'reason' => $reason];
    return ['state' => 'pending', 'url' => null, 'video' => false, 'reason' => ''];
}

const PENDING_TASKS = [
    'docs'         => 'Documents',
    'consent'      => 'Consent form',
    'first_meeting'=> '1st meeting',
    'recording'    => 'Meeting recording',
    'second_tax'   => 'Tax consultant meeting',
    'shortlisting' => 'University shortlisting',
    'credentials'  => 'Email / portal account',
    'remarks'      => 'Staff remark due',
];

/**
 * Everything pending for one student, in plain categories (each student is counted once per category).
 * $docState = load_doc_state()[student_id] ?? [].
 */
function student_pending(array $r, array $docState, int $appCount): array {
    $country = trim((string)($r['country'] ?? 'Italy')) ?: 'Italy';
    $isItaly = strtolower($country) === 'italy';
    $docs = student_pending_docs($r, $docState);
    $rec = meeting_recording_state($r);
    $shortRaw = strtolower(trim((string)($r['shortlisting'] ?? '')));
    $shortDone = ($shortRaw !== '' && !str_contains($shortRaw, 'pending') && $shortRaw !== 'no') || $appCount > 0;
    $flags = [
        'docs'          => !$docs['complete'],
        'consent'       => ($r['consent'] ?? '') !== 'Yes',
        'first_meeting' => ($r['initial_meeting'] ?? '') !== 'Yes',
        'recording'     => $isItaly && $rec['state'] === 'pending',
        'second_tax'    => $isItaly && ($r['tax_meeting'] ?? '') !== 'Yes',
        'shortlisting'  => !$shortDone,
        'credentials'   => trim((string)($r['email'] ?? '')) === '' || trim((string)($r['portal_id'] ?? '')) === '',
        'remarks'       => !empty($r['pending_since']),
    ];
    $items = [];
    foreach ($flags as $k => $on) {
        if (!$on) continue;
        $items[] = $k === 'docs' ? 'Documents (' . $docs['count'] . ')' : PENDING_TASKS[$k];
    }
    $hours = !empty($r['pending_since']) ? max(0, (int)floor((time() - (strtotime((string)$r['pending_since']) ?: time())) / 3600)) : null;
    return $flags + [
        'any'           => (bool)array_filter($flags),
        'items'         => $items,
        'task_keys'     => array_keys(array_filter($flags)),
        'docs_missing'  => $docs['missing'],
        'docs_missing_idx' => $docs['missing_idx'],
        'docs_count'    => $docs['count'],
        'docs_in_hand'  => $docs['in_hand'],
        'docs_required' => $docs['required'],
        'meetings'      => $flags['first_meeting'] || $flags['recording'],
        'recording_state' => $rec,
        'remark_hours'  => $hours,
        'app_count'     => $appCount,
        // older keys still read by some pages
        'missing_core'  => $docs['missing'],
        'received_docs' => $docs['in_hand'],
        'doc_note'      => '',
        'has_doc_note'  => false,
        'checklist_open'=> false,
        'pending_timer' => !empty($r['pending_since']),
    ];
}

/** Compatibility wrapper: older pages pass (row, docStats, appCount). */
function evaluate_student_pending(array $r, array $docStats, int $appCount): array {
    // $docStats may be the new [index => status] map or an old stats array
    $state = (isset($docStats['received']) || isset($docStats['missing_core'])) ? [] : $docStats;
    return student_pending($r, $state, $appCount);
}

// =========================================================================
// 6b. Payments (1st / 2nd / 3rd payment …)
// =========================================================================

/** Largest meeting video the server will accept (bytes), from php.ini upload limits. */
function meeting_upload_limit(): int {
    $toBytes = function (string $v): int {
        $v = trim($v); if ($v === '') return 0;
        $n = (float)$v; $u = strtolower(substr($v, -1));
        return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n)));
    };
    $a = $toBytes((string)ini_get('upload_max_filesize')); $b = $toBytes((string)ini_get('post_max_size'));
    $lim = min(array_filter([$a, $b, 512 * 1048576]) ?: [512 * 1048576]);
    return $lim;
}

const PAYMENT_METHODS = ['Bank transfer', 'Cash', 'Online / Card', 'Cheque', 'Easypaisa / JazzCash'];

function ordinal_label(int $n): string {
    return $n . (in_array($n % 100, [11, 12, 13], true) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th'));
}

/**
 * Payment rows for a student. Saved rows win; for students never updated here the 1st/2nd/3rd rows
 * are read from the old sheet columns (consult1 = "1st / 2nd Installment Received", installment_N = amount).
 */
/** "100000.0" (how the sheets stored amounts) -> "100000". */
function clean_amount($v): string {
    $v = trim((string)$v);
    return preg_match('/^\d+\.0+$/', $v) ? preg_replace('/\.0+$/', '', $v) : $v;
}

function student_payments(array $r): array {
    $sid = (int)($r['id'] ?? 0);
    $saved = [];
    if ($sid) {
        foreach (q('SELECT p.*, u.name AS updated_by_name FROM student_payments p LEFT JOIN users u ON u.id = p.updated_by WHERE p.student_id = ? ORDER BY installment_no', [$sid]) as $p) {
            $saved[(int)$p['installment_no']] = $p + ['is_auto' => false];
        }
    }
    $legacy = strtolower(trim((string)($r['consult1'] ?? '')));
    $receivedUpTo = preg_match('/(\d)/', $legacy, $m) ? (int)$m[1] : 0;
    if (($r['consult2'] ?? '') !== '' && $receivedUpTo < 2) $receivedUpTo = max($receivedUpTo, (int)preg_replace('/\D/', '', (string)$r['consult2']) ?: 2);
    if (($r['consult3'] ?? '') !== '' && $receivedUpTo < 3) $receivedUpTo = 3;
    $rows = [];
    $sheetMax = 0;
    for ($n = 1; $n <= 4; $n++) if (trim((string)($r['installment_' . $n] ?? '')) !== '') $sheetMax = $n;
    $max = max(3, $saved ? max(array_keys($saved)) : 0, $sheetMax);
    for ($n = 1; $n <= $max; $n++) {
        if (isset($saved[$n])) { $rows[$n] = $saved[$n]; continue; }
        $amt = clean_amount($r['installment_' . $n] ?? '');
        $word = $amt !== '' && !preg_match('/\d/', $amt);
        $status = ($word && stripos($amt, 'waiv') !== false) ? 'Waived' : (($word && stripos($amt, 'partial') !== false) ? 'Partially received' : (($n <= $receivedUpTo || $amt !== '') ? 'Received' : 'Pending'));
        if (stripos($amt, 'partial') !== false && !$word) { $status = 'Partially received'; $amt = trim(preg_replace('/\(?partial\)?/i', '', $amt)); }
        if ($word) $amt = '';
        $rows[$n] = ['installment_no' => $n, 'status' => $status, 'amount' => $amt !== '' ? $amt : null,
                     'received_date' => null, 'method' => null, 'note' => null, 'updated_by_name' => null, 'updated_at' => null, 'is_auto' => true];
    }
    return $rows;
}

function payment_summary(array $rows): array {
    $received = 0; $amount = 0.0; $last = null;
    foreach ($rows as $p) {
        if ($p['status'] === 'Received') {
            $received++;
            $amount += (float)preg_replace('/[^0-9.]/', '', (string)$p['amount']);
            if ($p['received_date'] && (!$last || $p['received_date'] > $last)) $last = $p['received_date'];
        }
    }
    return ['received' => $received, 'total' => count($rows), 'amount_received' => $amount, 'last_date' => $last];
}

function save_payment(array $actor, int $studentId, int $no, string $status, ?string $amount, ?string $date, ?string $method, ?string $note): array {
    if (!can_update_journey($actor)) return ['ok' => false, 'error' => 'You have read-only access to payments.'];
    if ($no < 1 || $no > 12) return ['ok' => false, 'error' => 'Unknown payment number.'];
    if (!in_array($status, ['Pending', 'Received', 'Partially received', 'Waived'], true)) return ['ok' => false, 'error' => 'Choose a payment status.'];
    if ($date !== null && $date !== '' && !valid_date($date)) return ['ok' => false, 'error' => 'Payment date is not valid.'];
    $amount = trim(mb_substr((string)$amount, 0, 40));
    if ($amount !== '' && !preg_match('/^[0-9.,\s]+(PKR|Rs\.?|EUR|€|USD|\$)?$/i', $amount)) return ['ok' => false, 'error' => 'Amount should be a number, e.g. 100000.'];
    $method = in_array($method, PAYMENT_METHODS, true) ? $method : null;
    $now = now();
    q('INSERT INTO student_payments (student_id, installment_no, status, amount, received_date, method, note, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?)
       ON CONFLICT(student_id, installment_no) DO UPDATE SET status = excluded.status, amount = excluded.amount, received_date = excluded.received_date,
       method = excluded.method, note = excluded.note, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
      [$studentId, $no, $status, $amount ?: null, $date ?: null, $method, trim(mb_substr((string)$note, 0, 500)) ?: null, (int)$actor['id'], $now]);
    sync_payments_to_sheet($studentId);
    log_activity('student', $studentId, 'payment_updated', null, ['payment' => ordinal_label($no), 'status' => $status, 'amount' => $amount], (int)$actor['id']);
    return ['ok' => true];
}

/**
 * Keeps the sheet columns (Consultancy received, 1st–4th Installment status, Last installment received)
 * in step with the Payments panel, so nobody has to type the same thing twice.
 */
function sync_payments_to_sheet(int $studentId): void {
    $st = q('SELECT * FROM students WHERE id = ?', [$studentId])->fetch();
    if (!$st) return;
    $rows = student_payments($st);
    $highest = 0; $allDone = true;
    foreach ($rows as $p) {
        if ($p['status'] === 'Received') $highest = max($highest, (int)$p['installment_no']);
        if (!in_array($p['status'], ['Received', 'Waived'], true)) $allDone = false;
    }
    $set = ['consult1' => $highest ? ordinal_label($highest) : null];
    for ($n = 1; $n <= 4; $n++) {
        $p = $rows[$n] ?? null;
        $amt = $p ? clean_amount($p['amount'] ?? '') : '';
        $set['installment_' . $n] = match ($p['status'] ?? 'Pending') {
            'Received' => $amt !== '' ? $amt : 'Received',
            'Partially received' => $amt !== '' ? $amt . ' (partial)' : 'Partially received',
            'Waived' => 'Waived',
            default => null,
        };
    }
    if ($allDone && $rows) $set['last_installment'] = 'Yes';
    elseif (($st['last_installment'] ?? '') === 'Yes') $set['last_installment'] = null;
    $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
    q("UPDATE students SET $sql WHERE id = ?", array_merge(array_values($set), [$studentId]));
}

/** Saves every row as it is shown now, so later changes (remove / renumber) do not bring back sheet-derived rows. */
function materialize_payments(array $actor, int $studentId): void {
    $st = q('SELECT * FROM students WHERE id = ?', [$studentId])->fetch();
    foreach (student_payments($st) as $p) {
        if (!$p['is_auto']) continue;
        q('INSERT OR IGNORE INTO student_payments (student_id, installment_no, status, amount, received_date, method, note, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
          [$studentId, (int)$p['installment_no'], $p['status'], $p['amount'], null, null, null, (int)$actor['id'], now()]);
    }
}

/** Removes an extra payment (4th or later). The first three are always kept. Later payments move up one number. */
function delete_payment(array $actor, int $studentId, int $no): array {
    if (!can_update_journey($actor)) return ['ok' => false, 'error' => 'You have read-only access to payments.'];
    if ($no <= 3) return ['ok' => false, 'error' => 'The 1st, 2nd and 3rd payments are always kept. Set the status instead.'];
    materialize_payments($actor, $studentId);
    q('DELETE FROM student_payments WHERE student_id = ? AND installment_no = ?', [$studentId, $no]);
    foreach (q('SELECT id, installment_no FROM student_payments WHERE student_id = ? AND installment_no > ? ORDER BY installment_no', [$studentId, $no])->fetchAll() as $p) {
        q('UPDATE student_payments SET installment_no = ? WHERE id = ?', [(int)$p['installment_no'] - 1, (int)$p['id']]);
    }
    // clear the sheet columns first so the removed row is not read back from the sheet
    q('UPDATE students SET installment_4 = NULL WHERE id = ?', [$studentId]);
    sync_payments_to_sheet($studentId);
    log_activity('student', $studentId, 'payment_removed', null, ['payment' => ordinal_label($no)], (int)$actor['id']);
    return ['ok' => true];
}

/**
 * The other direction: when someone fills the sheet fields in the profile form
 * (an amount in "Nth Installment status", or "Consultancy received"), the Payments panel updates itself.
 * $extra = amounts typed for payments after the 4th (they have no sheet column).
 */
function sync_sheet_to_payments(array $actor, int $studentId, array $old, array $new, array $extra = []): void {
    if (!can_update_journey($actor)) return;
    $typed = [];
    for ($n = 1; $n <= 4; $n++) {
        $k = 'installment_' . $n;
        if (!array_key_exists($k, $new)) continue;
        if (clean_amount($new[$k] ?? '') !== clean_amount($old[$k] ?? '')) $typed[$n] = clean_amount($new[$k] ?? '');
    }
    foreach ($extra as $n => $v) {
        $n = (int)$n; if ($n < 5 || $n > 12) continue;
        $cur = q('SELECT amount FROM student_payments WHERE student_id = ? AND installment_no = ?', [$studentId, $n])->fetchColumn();
        if (clean_amount($v) !== clean_amount($cur ?: '')) $typed[$n] = clean_amount($v);
    }
    $consultOld = (int)preg_replace('/\D/', '', (string)($old['consult1'] ?? ''));
    $consultNew = array_key_exists('consult1', $new) ? (int)preg_replace('/\D/', '', (string)($new['consult1'] ?? '')) : $consultOld;
    if (!$typed && $consultNew === $consultOld) return;

    materialize_payments($actor, $studentId);
    $saved = [];
    foreach (q('SELECT * FROM student_payments WHERE student_id = ?', [$studentId]) as $p) $saved[(int)$p['installment_no']] = $p;
    $now = now(); $today = date('Y-m-d');
    $put = function (int $n, string $status, ?string $amount, ?string $date) use ($studentId, $actor, $now) {
        q('INSERT INTO student_payments (student_id, installment_no, status, amount, received_date, updated_by, updated_at) VALUES (?,?,?,?,?,?,?)
           ON CONFLICT(student_id, installment_no) DO UPDATE SET status = excluded.status, amount = excluded.amount, received_date = excluded.received_date, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
          [$studentId, $n, $status, $amount, $date, (int)$actor['id'], $now]);
    };
    foreach ($typed as $n => $v) {
        $p = $saved[$n] ?? null;
        $isWord = $v !== '' && !preg_match('/\d/', $v);
        $partial = stripos($v, 'partial') !== false;
        if ($partial && !$isWord) $v = trim(preg_replace('/\(?partial(ly received)?\)?/i', '', $v));
        if ($v === '') {
            $put($n, 'Pending', null, null);
        } elseif ($isWord && stripos($v, 'waiv') !== false) {
            $put($n, 'Waived', null, $p['received_date'] ?? null);
        } else {
            // an amount (or "Received") typed in the sheet means this payment came in
            $put($n, $partial ? 'Partially received' : 'Received', $isWord ? ($p['amount'] ?? null) : $v, $p['received_date'] ?? $today);
        }
    }
    if ($consultNew > $consultOld) {
        for ($n = 1; $n <= min(12, $consultNew); $n++) {
            if (isset($typed[$n])) continue;
            $p = $saved[$n] ?? null;
            if (!$p || $p['status'] === 'Pending') $put($n, 'Received', $p['amount'] ?? null, $p['received_date'] ?? $today);
        }
    }
    sync_payments_to_sheet($studentId);
    log_activity('student', $studentId, 'payments_synced_from_sheet', null, ['payments' => implode(', ', array_map('ordinal_label', array_keys($typed)))], (int)$actor['id']);
}

// =========================================================================
// 7. Dropdown data from real records
// =========================================================================

/** Most-used existing values of a column (for searchable <datalist> suggestions; free text still allowed). */
function distinct_values(string $column, int $limit = 30): array {
    static $allowed = ['intake', 'branch', 'applied_for', 'last_degree', 'cv_status', 'sop_status', 'mock_interview_status',
        'embassy_status', 'admission_secured', 'campus_france_status', 'application_fees', 'shortlisting', 'dues',
        'installment_1', 'installment_2', 'installment_3', 'installment_4', 'ielts_score', 'threat_cancellation', 'disc2', 'disc3'];
    static $cache = [];
    if (!in_array($column, $allowed, true)) return [];
    if (isset($cache[$column])) return $cache[$column];
    $vals = q("SELECT TRIM($column) AS v, COUNT(*) AS c FROM students WHERE $column IS NOT NULL AND TRIM($column) != '' AND LENGTH(TRIM($column)) <= 60 GROUP BY LOWER(TRIM($column)) ORDER BY c DESC LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
    return $cache[$column] = $vals;
}

/** Suggested standard values merged with values already used in the database. */
function field_suggestions(string $column): array {
    $std = match ($column) {
        'cv_status', 'sop_status', 'mock_interview_status' => ['Done', 'Pending', 'In Progress', 'Not Required'],
        'embassy_status'       => ['Not Booked', 'Appointment Booked', 'Submitted', 'Interview Done', 'Visa Granted', 'Visa Refused'],
        'admission_secured'    => ['Offer Received', 'Pending', 'Conditional Offer', 'Rejected'],
        'campus_france_status' => ['Account Created', 'Submitted', 'Interview Scheduled', 'Interview Done', 'Pending'],
        'application_fees'     => ['Paid', 'Pending', 'Waived', 'Not Required'],
        'shortlisting'         => ['Shortlisting Done', 'Pending', 'In Progress'],
        'installment_1', 'installment_2', 'installment_3', 'installment_4' => ['Received', 'Pending', 'Partially Received'],
        'threat_cancellation'  => ['No', 'Yes'],
        'intake'               => intake_suggestions(),
        'applied_for'          => ['Bachelors', 'Masters', 'PhD', 'Erasmus', 'Foundation'],
        default                => [],
    };
    $merged = [];
    foreach (array_merge($std, distinct_values($column)) as $v) {
        $k = strtolower(trim((string)$v));
        if ($k !== '' && !isset($merged[$k])) $merged[$k] = trim((string)$v);
    }
    return array_values($merged);
}

function intake_suggestions(): array {
    $y = (int)date('Y');
    $out = [];
    for ($i = 0; $i <= 2; $i++) {
        $yy = $y + $i;
        $out[] = "Fall {$yy}";
        $out[] = "Winter {$yy}";
        $out[] = "Spring " . ($yy + 1);
        $out[] = "Summer " . ($yy + 1);
        $out[] = $yy . '-' . substr((string)($yy + 1), 2);
    }
    return $out;
}

const BRANCHES = ['Lahore', 'Faisalabad', 'Islamabad', 'Sargodha', 'Multan', 'Karachi', 'Peshawar', 'Other'];
/** Has this university application actually been sent? (Shortlisted / pending / pre-apply = not yet.) */
function app_is_submitted(?string $status): bool {
    $st = strtolower(trim((string)$status));
    if ($st === '' || $st === 'withdrawn') return false;
    foreach (['shortlist', 'pending', 'pre-apply', 'pre apply', 'not applied', 'invoice', 'fee issue'] as $no) if (str_contains($st, $no)) return false;
    foreach (['submit', 'applied', 'apply', 'review', 'interview', 'offer', 'accept', 'enrol', 'reject', 'vpd', 'admit'] as $yes) if (str_contains($st, $yes)) return true;
    return false;
}
/** Still to be sent (shortlisted / documents pending …), not withdrawn or decided. */
function app_is_remaining(?string $status): bool {
    $st = strtolower(trim((string)$status));
    return !app_is_submitted($status) && $st !== 'withdrawn';
}

const APPLICATION_STATUSES = ['Shortlisted', 'Documents Pending', 'Applied', 'Under Review', 'Interview', 'Offer Received', 'Conditional Offer', 'Accepted', 'Enrolled', 'Rejected', 'Withdrawn'];
const APPLICATION_TYPES = ['Direct', 'Campus France', 'Uni-Assist', 'UniversItaly', 'CSC Scholarship', 'University Portal', 'DSU / Regional Scholarship'];
