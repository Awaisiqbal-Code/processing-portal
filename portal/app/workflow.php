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

    if ($yes('docs_checklist') && doc_note_is_empty($r['pending_docs_note'] ?? '')) {
        $out['documents'] = ['Completed', $r['doc_request_date'] ?? null];
    } elseif ($yes('docs_checklist') || $docsReceived > 0 || $has('doc_request_date') || !doc_note_is_empty($r['pending_docs_note'] ?? '')) {
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

    if ($yes('last_installment')) {
        $out['payment'] = ['Completed', null];
    } elseif ($has('installment_1') || $has('consult2') || $yes('enrollment')) {
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
    $docsReceived = $sid ? (int)q("SELECT COUNT(*) FROM student_documents WHERE student_id = ? AND status IN ('Received','Verified')", [$sid])->fetchColumn() : 0;
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
    return in_array($u['role'] ?? '', ['super_admin', 'admin', 'pms_lead'], true);
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
    return in_array($u['role'] ?? '', ['super_admin', 'admin', 'pms_lead', 'processing_staff', 'officer'], true);
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

    $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 150));
    $errors = [];
    if (mb_strlen($name) < 2) $errors[] = 'Enter the student name.';
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
    $isLead = can_manage_student_lifecycle($actor);
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

const CORE_DOC_INDICES = [
    0 => 'Passport', 1 => 'CNIC', 2 => 'Matric', 3 => 'Inter', 4 => 'Bachelor/Transcript',
    5 => 'IELTS/Language', 6 => 'CV', 7 => 'Bank Statement', 8 => 'Tax/FBR',
];

/** Per-student document counts: received (file + Received/Verified), pending, and missing core docs. */
function load_doc_stats(?array $studentIds = null): array {
    $out = [];
    $sql = 'SELECT student_id, doc_index, status, file_path FROM student_documents';
    $params = [];
    if ($studentIds !== null) {
        if (!$studentIds) return [];
        $sql .= ' WHERE student_id IN (' . implode(',', array_fill(0, count($studentIds), '?')) . ')';
        $params = array_values($studentIds);
    }
    foreach (q($sql, $params) as $dr) {
        $sid = (int)$dr['student_id'];
        if (!isset($out[$sid])) $out[$sid] = ['received' => 0, 'pending' => 0, 'missing_core' => CORE_DOC_INDICES];
        if (!empty($dr['file_path']) && in_array($dr['status'], ['Received', 'Verified'], true)) {
            $out[$sid]['received']++;
            unset($out[$sid]['missing_core'][(int)$dr['doc_index']]);
        } else {
            $out[$sid]['pending']++;
        }
    }
    return $out;
}

if (!function_exists('evaluate_student_pending')) {
function evaluate_student_pending(array $r, array $docStats, int $appCount): array {
    $country = trim((string)($r['country'] ?? 'Italy')) ?: 'Italy';
    $isItaly = strtolower($country) === 'italy';
    $level = strtoupper(trim((string)($r['program_level'] ?? 'MS')));

    $missingCore = $docStats['missing_core'] ?? CORE_DOC_INDICES;
    if ($level === 'BS') unset($missingCore[4]);

    $noteRaw = trim((string)($r['pending_docs_note'] ?? ''));
    $hasPendingNote = !doc_note_is_empty($noteRaw);
    $checklistDone = ($r['docs_checklist'] ?? '') === 'Yes';
    $receivedDocs = (int)($docStats['received'] ?? 0);
    $pendingDocsCount = (int)($docStats['pending'] ?? 12);

    $isDocsPending = (!$checklistDone || $hasPendingNote);
    $isConsentPending = ($r['consent'] ?? '') !== 'Yes';
    $isFirstMeetingPending = ($r['initial_meeting'] ?? '') !== 'Yes';
    $isRecordingPending = $isItaly && (($r['recorded_uploaded'] ?? '') !== 'Yes' && empty($r['meeting_link']));
    $isMeetingsPending = $isFirstMeetingPending || $isRecordingPending;
    $isTaxPending = $isItaly && (($r['tax_meeting'] ?? '') !== 'Yes');
    $isSecondMeetingPending = $isItaly && (($r['second_meeting'] ?? '') !== 'Yes');
    $isSecondTaxPending = $isTaxPending || $isSecondMeetingPending;
    $shortRaw = strtolower(trim((string)($r['shortlisting'] ?? '')));
    $shortDone = ($shortRaw !== '' && !str_contains($shortRaw, 'pending') && $shortRaw !== 'no') || $appCount > 0;
    $isShortlistingPending = !$shortDone;
    $isCredentialsPending = empty(trim((string)($r['email'] ?? ''))) || empty(trim((string)($r['portal_id'] ?? '')));
    $isRemarkPending = !empty($r['pending_since']) || empty(trim((string)($r['remarks'] ?? '')));

    $pendingItems = [];
    if ($isConsentPending)       $pendingItems[] = 'Consent Form';
    if (!$checklistDone)         $pendingItems[] = 'Docs Checklist';
    if ($hasPendingNote)         $pendingItems[] = 'Pending Docs Note';
    if ($isFirstMeetingPending)  $pendingItems[] = '1st Meeting';
    if ($isRecordingPending)     $pendingItems[] = 'Meeting Recording';
    if ($isTaxPending)           $pendingItems[] = 'Tax Meeting';
    if ($isSecondMeetingPending) $pendingItems[] = '2nd Meeting';
    if ($isShortlistingPending)  $pendingItems[] = 'Shortlisting / Uni Apps';
    if ($isCredentialsPending)   $pendingItems[] = 'Portal / Email ID';
    if (!empty($r['pending_since'])) $pendingItems[] = '48h Staff Remark Due';

    return [
        'any'              => !empty($pendingItems),
        'docs'             => $isDocsPending,
        'checklist_open'   => !$checklistDone,
        'has_doc_note'     => $hasPendingNote,
        'doc_note'         => $hasPendingNote ? $noteRaw : '',
        'received_docs'    => $receivedDocs,
        'pending_docs_cnt' => $isDocsPending ? $pendingDocsCount : 0,
        'missing_core'     => $isDocsPending ? array_values($missingCore) : [],
        'consent'          => $isConsentPending,
        'meetings'         => $isMeetingsPending,
        'first_meeting'    => $isFirstMeetingPending,
        'recording'        => $isRecordingPending,
        'second_tax'       => $isSecondTaxPending,
        'tax_meeting'      => $isTaxPending,
        'second_meeting'   => $isSecondMeetingPending,
        'shortlisting'     => $isShortlistingPending,
        'credentials'      => $isCredentialsPending,
        'remarks'          => $isRemarkPending,
        'pending_timer'    => !empty($r['pending_since']),
        'items'            => $pendingItems,
        'app_count'        => $appCount,
    ];
}
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
const APPLICATION_STATUSES = ['Shortlisted', 'Documents Pending', 'Applied', 'Under Review', 'Interview', 'Offer Received', 'Conditional Offer', 'Accepted', 'Enrolled', 'Rejected', 'Withdrawn'];
const APPLICATION_TYPES = ['Direct', 'Campus France', 'Uni-Assist', 'UniversItaly', 'CSC Scholarship', 'University Portal', 'DSU / Regional Scholarship'];
