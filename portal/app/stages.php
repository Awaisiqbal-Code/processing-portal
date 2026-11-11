<?php
declare(strict_types=1);

const SUPPORTED_COUNTRIES = ['Italy', 'France', 'Germany', 'Czech Republic', 'Austria', 'China'];   // built-in sheet layouts (and fallback list)

/** Every country row from Admin → Countries (cached per request). Falls back to the built-in list before migration 015. */
function country_rows(bool $refresh = false): array {
    static $rows = null;
    if ($rows !== null && !$refresh) return $rows;
    try {
        $rows = q('SELECT * FROM countries ORDER BY sort_order, name')->fetchAll();
    } catch (Throwable) {
        $rows = array_map(fn($c) => ['name' => $c, 'code' => null, 'sheet_format' => $c, 'default_officer_id' => null, 'color' => null, 'active' => 1], SUPPORTED_COUNTRIES);
    }
    return $rows;
}
/** Country names for pick lists. Inactive countries are hidden from new choices but kept on existing students. */
function portal_countries(bool $includeInactive = false): array {
    return array_values(array_map(fn($r) => (string)$r['name'], array_filter(country_rows(), fn($r) => $includeInactive || (int)$r['active'] === 1)));
}
function country_row(?string $name): ?array {
    $n = mb_strtolower(trim((string)$name));
    foreach (country_rows() as $r) if (mb_strtolower((string)$r['name']) === $n) return $r;
    return null;
}
/** Which built-in sheet layout a country's student form uses (a new country picks one when it is added). */
function country_sheet_format(string $country): string {
    if (in_array($country, SUPPORTED_COUNTRIES, true)) return $country;
    $f = (string)(country_row($country)['sheet_format'] ?? 'Italy');
    return in_array($f, SUPPORTED_COUNTRIES, true) ? $f : 'Italy';
}

// Master superset of all student fields: key => [label, type, options]
// Types: text, date, yn, select, officer, textarea, readonly_textarea, url
const FIELD_GROUPS = [
    'Student basics' => [
        'name'                => ['Student name', 'text'],
        'country'             => ['Destination Country', 'select', ['', 'Italy', 'France', 'Germany', 'Czech Republic', 'Austria', 'China', 'Other']],
        'program_level'       => ['Program Level (BS / MS)', 'select', ['', 'BS', 'MS']],
        'counselor'           => ['Counselor', 'counselor'],
        'frontend_officer_id' => ['Front-End Processing Officer', 'officer'],
        'officer_id'          => ['Back-End Processing Officer', 'officer'],
        'branch'              => ['Branch', 'select', ['', 'Lahore', 'Faisalabad', 'Islamabad', 'Sargodha', 'Multan', 'Karachi', 'Peshawar', 'Other']],
        'intake'              => ['Intake', 'text'],
        'refund'              => ['Refund status', 'select', ['', '30%', '50%', '90%', 'Non-Refundable']],
        'received_date'       => ['Received date', 'date'],
        'contact_number'      => ['Contact / WhatsApp Number', 'text'],
        'email'               => ['Student Email', 'text'],
        'recovery_email'      => ['Recovery Email', 'text'],
        'student_password'    => ['Account / Portal Password', 'text'],
        'consult1'            => ['Consultancy received', 'select', ['', '1st', '2nd', '3rd']],
    ],
    'Academic & Country-Specific Details' => [
        'last_degree'           => ['Last Degree / Qualification', 'text'],
        'gpa'                   => ['CGPA / Percentage', 'text'],
        'ielts_score'           => ['IELTS / Language Score', 'text'],
        'applied_for'           => ['Applied For (Program / Track)', 'text'],
        'application_fees'      => ['Application Fees Status', 'text'],
        'dues'                  => ['Dues / Consultancy Balance', 'text'],
        'campus_france_status'  => ['Campus France / Uni-Assist Portal', 'text'],
        'cv_status'             => ['CV Status', 'text'],
        'sop_status'            => ['SOP / LOM Status', 'text'],
        'mock_interview_status' => ['Mock Interview Status', 'text'],
        'embassy_status'        => ['Embassy Appointment Status', 'text'],
        'admission_secured'     => ['Admission Secured / Final Decision', 'text'],
    ],
    'Onboarding & Credentials' => [
        'portal_id'         => ['UniverseItaly / Application Portal ID', 'text'],
        'portal_password'   => ['UniverseItaly / Application Portal Password', 'text'],
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
        'remarks'             => ['Latest Remark (Read-only)', 'readonly_textarea'],
    ],
];

/**
 * Returns country-tailored field groups matching the exact spreadsheet structure of each country.
 */
function country_field_groups(string $country): array {
    $c = trim($country) ?: 'Italy';
    $countryOpts = array_merge([''], portal_countries(), ['Other']);
    if (!in_array($c, $countryOpts, true) && $c !== '') $countryOpts[] = $c;
    $label = $c;                          // sheet titles show the real country name
    $c = country_sheet_format($c);        // a new country reuses the layout chosen for it
    $branchOpts  = ['', 'Lahore', 'Faisalabad', 'Islamabad', 'Sargodha', 'Multan', 'Karachi', 'Peshawar', 'Other'];
    $refundOpts  = ['', '30%', '50%', '90%', 'Non-Refundable'];

    return match ($c) {
        'France' => [
            "{$label} Sheet — Student Registration & Contact Details" => [
                'name'           => ['Student Name', 'text'],
                'country'        => ['Destination Country', 'select', $countryOpts],
                'contact_number' => ['Contact Number (WhatsApp)', 'text'],
                'counselor'      => ['Counselor', 'counselor'],
                'officer_id'     => ['Processing Officer (Muhammad Usman)', 'officer'],
                'branch'         => ['Registered Branch', 'select', $branchOpts],
                'received_date'  => ['Registration Date', 'date'],
                'applied_for'    => ['Applied For (Bachelors / Masters)', 'text'],
                'program_level'  => ['Degree Tab Level (BS / MS)', 'select', ['', 'BS', 'MS']],
                'intake'         => ['Intake (e.g. 2027-28)', 'text'],
                'dues'           => ['Dues / Consultancy Balance', 'text'],
                'refund'         => ['Refund Policy Tier', 'select', $refundOpts],
            ],
            "{$label} Sheet — Campus France, CV/SOP, Mock Interview & Portals" => [
                'email'                 => ['Email Made by Processor', 'text'],
                'student_password'      => ['Email / Account Password', 'text'],
                'recovery_email'        => ['Recovery Email', 'text'],
                'drive_link'            => ['Documents Drive Folder Link', 'url'],
                'pending_docs_note'     => ['Document Deficit (Pending Documents)', 'textarea'],
                'cv_status'             => ['CV Status (Done / Pending)', 'text'],
                'sop_status'            => ['SOP Status (Done / Pending)', 'text'],
                'campus_france_status'  => ['Campus France Portal Status', 'text'],
                'shortlisting'          => ['University Portals / Shortlisting Status', 'text'],
                'mock_interview_status' => ['Mock Interview Status', 'text'],
                'application_fees'      => ['Application Fees Status', 'text'],
                'consent'               => ['Consent Form Received', 'yn'],
                'offer'                 => ['Admission / Offer Received', 'yn'],
                'visa'                  => ['Visa Status', 'select', ['', 'Pending', 'Granted', 'Refused']],
                'threat_cancellation'   => ['Threatening Case (Yes / No)', 'text'],
                'remarks'               => ['Latest Remark (Read-only)', 'readonly_textarea'],
            ],
        ],
        'Germany' => [
            "{$label} Sheet — Student Registration & Academic Profile" => [
                'name'           => ['Student Name', 'text'],
                'country'        => ['Destination Country', 'select', $countryOpts],
                'counselor'      => ['Counselor', 'counselor'],
                'officer_id'     => ['Processing Officer (Nabeel / Tabinda / Nirmal)', 'officer'],
                'branch'         => ['Student Registered Branch', 'select', $branchOpts],
                'received_date'  => ['Registration Date', 'date'],
                'intake'         => ['Intake (Winter 2026 / Summer 2027)', 'text'],
                'program_level'  => ['Program Level (BS / MS)', 'select', ['', 'BS', 'MS']],
                'applied_for'    => ['Applied For (BS / MS / Erasmus)', 'text'],
                'last_degree'    => ['Last Degree / Qualification', 'text'],
                'gpa'            => ['CGPA / Percentage', 'text'],
                'ielts_score'    => ['IELTS / Language Score', 'text'],
                'contact_number' => ['Client Contact Number', 'text'],
                'dues'           => ['Dues / Consultancy Status', 'text'],
                'refund'         => ['Refund Status', 'select', $refundOpts],
            ],
            "{$label} Sheet — Uni-Assist, Documents, Portals & Embassy" => [
                'consent'             => ['Consent Form Received', 'yn'],
                'docs_checklist'      => ['Documents Checklist Provided', 'yn'],
                'pending_docs_note'   => ['Update Names of Pending Documents', 'textarea'],
                'doc_request_date'    => ['Documentation Request Date', 'date'],
                'drive_link'          => ['Documents Drive Link', 'url'],
                'email'               => ['Email ID', 'text'],
                'student_password'    => ['Email Password', 'text'],
                'portal_id'           => ['Uni-Assist / Portal ID', 'text'],
                'portal_password'     => ['Uni-Assist / Portal Password', 'text'],
                'recovery_email'      => ['Recovery Number / Email', 'text'],
                'shortlisting'        => ['University Shortlisting (Done / Pending)', 'text'],
                'application_fees'    => ['Application Fees', 'text'],
                'consult1'            => ['Consultancy Installment', 'select', ['', '1st', '2nd', '3rd']],
                'offer'               => ['Offer Received', 'yn'],
                'embassy_status'      => ['Embassy Appointment Status', 'text'],
                'visa'                => ['Visa Status', 'select', ['', 'Pending', 'Granted', 'Refused']],
                'threat_cancellation' => ['Student Threatening Cancellation / Refund', 'text'],
                'remarks'             => ['Latest Remark (Read-only)', 'readonly_textarea'],
            ],
        ],
        'Czech Republic', 'Austria' => [
            "{$label} Sheet — Student Registration & Academic Profile" => [
                'name'           => ['Student Name', 'text'],
                'country'        => ['Destination Country', 'select', $countryOpts],
                'counselor'      => ['Counselor', 'counselor'],
                'officer_id'     => ['Processor Assigned (Hamza)', 'officer'],
                'branch'         => ['Student Registered Branch', 'select', $branchOpts],
                'received_date'  => ['Registration Date', 'date'],
                'intake'         => ['Intake', 'text'],
                'program_level'  => ['Program Level (BS / MS)', 'select', ['', 'BS', 'MS']],
                'applied_for'    => ['Applied For (BS / MS)', 'text'],
                'last_degree'    => ['Last Degree', 'text'],
                'gpa'            => ['GPA / CGPA', 'text'],
                'ielts_score'    => ['IELTS / MOI', 'text'],
                'contact_number' => ['Client Phone No', 'text'],
                'refund'         => ['Refund Status', 'select', $refundOpts],
            ],
            "{$label} Sheet — Nostrification, Portals, Embassy & Admission" => [
                'consent'             => ['Consent Form Received', 'yn'],
                'docs_checklist'      => ['Documents Checklist Provided', 'yn'],
                'pending_docs_note'   => ['Update Names of Pending Documents', 'textarea'],
                'doc_request_date'    => ['Documentation Request Date', 'date'],
                'drive_link'          => ['Documents Drive Link', 'url'],
                'email'               => ['Email ID', 'text'],
                'student_password'    => ['Email Password', 'text'],
                'portal_id'           => ['University Portal ID', 'text'],
                'portal_password'     => ['University Portal Password', 'text'],
                'recovery_email'      => ['Recovery Email', 'text'],
                'shortlisting'        => ['University Shortlisting (Done / Pending)', 'text'],
                'application_fees'    => ['Application & Nostrification Fees', 'text'],
                'offer'               => ['Admission / Offer Received', 'yn'],
                'admission_secured'   => ['Admission Secured Status', 'text'],
                'embassy_status'      => ['Embassy Appointment Status', 'text'],
                'visa'                => ['Visa Status', 'select', ['', 'Pending', 'Granted', 'Refused']],
                'threat_cancellation' => ['Student Threatening Cancellation / Refund', 'text'],
                'remarks'             => ['Latest Remark (Read-only)', 'readonly_textarea'],
            ],
        ],
        'China' => [
            "{$label} Sheet — Student Registration & Academic Profile" => [
                'name'           => ['Student Name', 'text'],
                'country'        => ['Destination Country', 'select', $countryOpts],
                'counselor'      => ['Counselor', 'counselor'],
                'officer_id'     => ['Processor Assigned (Zeeshan / Hamna)', 'officer'],
                'branch'         => ['Student Registered Branch', 'select', $branchOpts],
                'received_date'  => ['Registration Date', 'date'],
                'consent'        => ['Consent Form Received', 'yn'],
                'last_degree'    => ['Last Degree (e.g. BS / MPhil / MBBS)', 'text'],
                'gpa'            => ['GPA / CGPA', 'text'],
                'contact_number' => ['Client Phone No', 'text'],
                'intake'         => ['Intake (e.g. Sep 2027)', 'text'],
                'applied_for'    => ['Applied For (BS / MS / PhD)', 'text'],
                'program_level'  => ['Degree Tab Level (BS / MS)', 'select', ['', 'BS', 'MS']],
                'ielts_score'    => ['IELTS / MOI', 'text'],
            ],
            "{$label} Sheet — Documents, CSC/University Portal & Status" => [
                'pending_docs_note'   => ['Update Names of Pending Documents', 'textarea'],
                'docs_checklist'      => ['All Documents Submitted', 'yn'],
                'drive_link'          => ['Documents Drive Link', 'url'],
                'doc_request_date'    => ['Documentation Request Date', 'date'],
                'email'               => ['Email ID', 'text'],
                'student_password'    => ['Email Password', 'text'],
                'portal_id'           => ['CSC / University Portal ID', 'text'],
                'portal_password'     => ['CSC / University Portal Password', 'text'],
                'recovery_email'      => ['Recovery Email & Recovery Phone', 'text'],
                'shortlisting'        => ['University Shortlisting (Done / Pending)', 'text'],
                'application_fees'    => ['Application Fees', 'text'],
                'admission_secured'   => ['Application Status (Offer Received / Pending)', 'text'],
                'offer'               => ['Offer Received (Yes / No)', 'yn'],
                'visa'                => ['Visa Status', 'select', ['', 'Pending', 'Granted', 'Refused']],
                'threat_cancellation' => ['Student Threatening Cancellation / Refund', 'text'],
                'remarks'             => ['Last Contacted Remarks (Read-only)', 'readonly_textarea'],
            ],
        ],
        default => [
            "{$label} Sheet — Student Basics & Registration" => [
                'name'                => ['Student name', 'text'],
                'country'             => ['Destination Country', 'select', $countryOpts],
                'program_level'       => ['Program Level (BS / MS)', 'select', ['', 'BS', 'MS']],
                'gpa'                 => ['CGPA', 'text'],
                'counselor'           => ['Counselor', 'counselor'],
                'frontend_officer_id' => ['Front-End Processing Officer (Nimra / Mujassam)', 'officer'],
                'officer_id'          => ['Back-End Processing Officer (Minahil / Sehrish / Farwa)', 'officer'],
                'branch'              => ['Branch', 'select', $branchOpts],
                'intake'              => ['Intake', 'text'],
                'refund'              => ['Refund status', 'select', $refundOpts],
                'received_date'       => ['Received date', 'date'],
                'contact_number'      => ['Contact Number', 'text'],
                'email'               => ['Student Email', 'text'],
                'student_password'    => ['Account / Portal Password', 'text'],
                'consult1'            => ['Consultancy received', 'select', ['', '1st', '2nd', '3rd']],
            ],
            "{$label} Sheet — Onboarding & UniverseItaly Credentials" => [
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
            "{$label} Sheet — Tax Consultant Meeting" => [
                'meeting_priority' => ['Meeting priority', 'select', ['', 'High', 'Normal', 'Low']],
                'tax_meeting'      => ['Tax consultant meeting done', 'yn'],
                'follow_up_3'      => ['If not, follow-up date', 'date'],
            ],
            "{$label} Sheet — Offer, Enrollment, Installments & Visa" => [
                'offer'               => ['Admission / offer received', 'yn'],
                'enrollment'          => ['Enrollment / summary received', 'yn'],
                'enrollment_date'     => ['Enrollment summary received date', 'date'],
                'installment_1'       => ['1st Installment status', 'text'],
                'installment_2'       => ['2nd Installment status', 'text'],
                'installment_3'       => ['3rd Installment status', 'text'],
                'installment_4'       => ['4th Installment status', 'text'],
                'consult2'            => ['Consultancy received (2nd)', 'select', ['', '1st', '2nd', '3rd']],
                'disc2'               => ['Discrepancies recorded (2nd payment)', 'text'],
                'consult3'            => ['Consultancy received (3rd)', 'select', ['', '1st', '2nd', '3rd']],
                'disc3'               => ['Discrepancies recorded (3rd payment)', 'text'],
                'visa'                => ['Visa status', 'select', ['', 'Pending', 'Granted', 'Refused']],
                'last_installment'    => ['Last installment received', 'yn'],
                'threat_cancellation' => ['Threatening cancellation / refund', 'text'],
                'remarks'             => ['Latest Remark (Read-only)', 'readonly_textarea'],
            ],
        ],
    };
}

const DOCS = ['Passport', 'CNIC / B-form', 'Matric certificate', 'Intermediate certificate',
    'Bachelor degree and transcript', 'IELTS / PTE result', 'CV', 'Bank statement', 'Tax documents',
    'Offer letter', 'Enrollment / CAS', 'Visa decision letter'];
const DOC_STATUS = ['', 'Pending', 'Requested', 'Received', 'Verified', 'Not Required'];

const STAGES = [
    ['Consent signed',          'consent',           'You sign the consent form so we can open your file.'],
    ['Docs checklist',          'docs_checklist',    'We share the list of documents you need to collect.'],
    ['Initial meeting',         'initial_meeting',   'Recorded meeting with your counselor and our front-end team.'],
    ['Recording uploaded',      'recorded_uploaded', 'Your meeting recording is saved in your student folder.'],
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
        if (!empty($r['follow_up_3']) && ($r['tax_meeting'] ?? '') !== 'Yes')     $out[] = ['r' => $r, 'date' => $r['follow_up_3'], 'what' => 'Tax consultant meeting'];
    }
    usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}
