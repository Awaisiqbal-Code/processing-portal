<?php
declare(strict_types=1);

// Fields of a student record: key => [label, type, options]
// Types: text, date, yn, select, officer, textarea, url
const FIELD_GROUPS = [
    'Student basics' => [
        'name'                => ['Student name', 'text'],
        'counselor'           => ['Counselor', 'text'],
        'frontend_officer_id' => ['Front-End Processing Officer', 'officer'],
        'officer_id'          => ['Back-End Processing Officer', 'officer'],
        'branch'              => ['Branch', 'select', ['', 'Lahore', 'Faisalabad', 'Islamabad', 'Other']],
        'intake'           => ['Intake', 'text'],
        'refund'           => ['Refund status', 'select', ['', '30%', '50%', '90%', 'Non-Refundable']],
        'received_date'    => ['Received date', 'date'],
        'email'            => ['Student Email', 'text'],
        'student_password' => ['Account / Portal Password', 'text'],
        'consult1'         => ['Consultancy received', 'select', ['', '1st', '2nd', '3rd']],
    ],
    'Onboarding & Credentials' => [
        'portal_id'         => ['UniverseItaly Portal ID', 'text'],
        'portal_password'   => ['UniverseItaly Portal Password', 'text'],
        'shortlisting'      => ['University Shortlisting', 'text'],
        'consent'           => ['Consent form signed', 'yn'],
        'docs_checklist'    => ['Documents checklist provided', 'yn'],
        'pending_docs_note' => ['Names of Pending Documents', 'textarea'],
        'doc_request_date'  => ['Documentation Request Date', 'date'],
        'initial_meeting'   => ['Initial recorded meeting (front end + counselor)', 'yn'],
        'follow_up_1'       => ['If not, follow-up date', 'date'],
        'recorded_uploaded' => ['Recorded meeting uploaded to student folder', 'yn'],
        'drive_link'        => ['Student Documents Drive folder link', 'url'],
        'meeting_link'      => ['Recorded Meeting / Zoom Link', 'url'],
    ],
    'Processing' => [
        'meeting_priority' => ['Meeting priority', 'select', ['', 'High', 'Normal', 'Low']],
        'second_meeting'   => ['2nd processing online meeting with client', 'yn'],
        'follow_up_2'      => ['If not, follow-up date', 'date'],
        'tax_meeting'      => ['Tax consultant meeting done', 'yn'],
        'follow_up_3'      => ['If not, follow-up date', 'date'],
    ],
    'Offer and payments' => [
        'offer'            => ['Admission / offer received', 'yn'],
        'enrollment'       => ['Enrollment / summary received', 'yn'],
        'enrollment_date'  => ['Enrollment summary received date', 'date'],
        'installment_1'    => ['1st Installment status', 'text'],
        'installment_2'    => ['2nd Installment status', 'text'],
        'installment_3'    => ['3rd Installment status', 'text'],
        'installment_4'    => ['4th Installment status', 'text'],
        'consult2'         => ['Consultancy received', 'select', ['', '1st', '2nd', '3rd']],
        'disc2'            => ['Discrepancies recorded (2nd payment)', 'text'],
        'consult3'         => ['Consultancy received', 'select', ['', '1st', '2nd', '3rd']],
        'disc3'            => ['Discrepancies recorded (3rd payment)', 'text'],
    ],
    'Visa and close' => [
        'visa'                => ['Visa status', 'select', ['', 'Pending', 'Granted', 'Refused']],
        'last_installment'    => ['Last installment received', 'yn'],
        'threat_cancellation' => ['Threatening cancellation / refund', 'text'],
        'remarks'             => ['Remarks', 'textarea'],
    ],
];

const DOCS = ['Passport', 'CNIC / B-form', 'Matric certificate', 'Intermediate certificate',
    'Bachelor degree and transcript', 'IELTS / PTE result', 'CV', 'Bank statement', 'Tax documents',
    'Offer letter', 'Enrollment / CAS', 'Visa decision letter'];
const DOC_STATUS = ['', 'Pending', 'Requested', 'Received', 'Verified'];

const STAGES = [
    ['Consent signed',          'consent',           'You sign the consent form so we can open your file.'],
    ['Docs checklist',          'docs_checklist',    'We share the list of documents you need to collect.'],
    ['Initial meeting',         'initial_meeting',   'Recorded meeting with your counselor and our front-end team.'],
    ['Recording uploaded',      'recorded_uploaded', 'Your meeting recording is saved in your student folder.'],
    ['2nd processing meeting',  'second_meeting',    'Online meeting with your processing officer to review your file.'],
    ['Tax consultant',          'tax_meeting',       'Meeting with the tax consultant about your financial documents.'],
    ['Offer received',          'offer',             'Your university admission or offer letter arrives.'],
    ['Enrollment / summary',    'enrollment',        'Your enrollment confirmation or summary is received.'],
    ['Visa granted',            'visa',              'Your visa application is decided.'],
];

function stage_done(array $r, int $i): bool {
    $k = STAGES[$i][1];
    if ($k === 'recorded_uploaded') {
        return student_has_meeting($r);
    }
    return $k === 'visa' ? (($r['visa'] ?? '') === 'Granted') : (($r[$k] ?? '') === 'Yes');
}
function done_count(array $r): int { $n = 0; foreach (STAGES as $i => $_) if (stage_done($r, $i)) $n++; return $n; }
function next_stage(array $r): ?string {
    if (($r['visa'] ?? '') === 'Refused') return null;
    foreach (STAGES as $i => $s) if (!stage_done($r, $i)) return $s[0];
    return null;
}

/** Follow-ups: missed meetings that have a follow-up date. */
function due_list(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        if (!empty($r['follow_up_1']) && ($r['initial_meeting'] ?? '') !== 'Yes') $out[] = ['r' => $r, 'date' => $r['follow_up_1'], 'what' => 'Initial recorded meeting'];
        if (!empty($r['follow_up_2']) && ($r['second_meeting'] ?? '') !== 'Yes')  $out[] = ['r' => $r, 'date' => $r['follow_up_2'], 'what' => '2nd processing meeting'];
        if (!empty($r['follow_up_3']) && ($r['tax_meeting'] ?? '') !== 'Yes')     $out[] = ['r' => $r, 'date' => $r['follow_up_3'], 'what' => 'Tax consultant meeting'];
    }
    usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}
