<?php
declare(strict_types=1);

require_once APP . '/fpdf/fpdf.php';
require_once APP . '/stages.php';

/**
 * Clean and convert UTF-8 strings for standard FPDF fonts (Windows-1252/ISO-8859-1).
 */
function pdf_text(?string $s): string {
    if ($s === null || $s === '') return '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = str_replace(['’', '‘'], "'", $s);
    $s = str_replace(['“', '”'], '"', $s);
    $s = str_replace(['–', '—'], '-', $s);
    $s = str_replace('•', '*', $s);
    $s = str_replace(['✓', '✔'], '[X]', $s);
    $s = str_replace('✗', '[-]', $s);
    return @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s) ?: $s;
}

/**
 * Neutralize formula injection in CSV exports (Excel / LibreOffice).
 */
function csv_cell($val): string {
    if ($val === null) return '';
    $s = (string)$val;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $s;
    }
    return $s;
}

class PortalReportPDF extends FPDF {
    public string $reportTitle = 'REPORT';
    public string $reportSubtitle = '';
    public string $footerNotice = 'Confidential · Processing Team Portal';
    public string $generatedAt = '';
    
    function KeyValueRow(string $k1, string $v1, string $k2 = '', string $v2 = ''): void {
        $this->InfoGrid([[$k1, $v1, $k2, $v2]]);
    }
    
    function Header(): void {
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(30, 41, 59);
        $this->Cell(120, 7, pdf_text('PROCESSING TEAM PORTAL'), 0, 0, 'L');
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 7, pdf_text('Generated: ' . date('d/m/Y H:i') . ' UTC'), 0, 1, 'R');

        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(37, 99, 235);
        $this->Cell(0, 6, pdf_text($this->reportTitle), 0, 1, 'L');

        if ($this->reportSubtitle !== '') {
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(71, 85, 105);
            $this->Cell(0, 5, pdf_text($this->reportSubtitle), 0, 1, 'L');
        }

        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY() + 2, 200, $this->GetY() + 2);
        $this->Ln(4);
    }

    function Footer(): void {
        $this->SetY(-14);
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(100, 9, pdf_text($this->footerNotice), 0, 0, 'L');
        $this->Cell(0, 9, pdf_text('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'R');
    }

    function SectionHeading(string $title): void {
        if ($this->GetY() > 255) {
            $this->AddPage();
        }
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(241, 245, 249);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 6, '  ' . pdf_text($title), 0, 1, 'L', true);
        $this->Ln(1.5);
    }

    function InfoGrid(array $pairs): void {
        $this->SetFont('Arial', '', 8.5);
        foreach ($pairs as $row) {
            if ($this->GetY() > 265) $this->AddPage();
            // Col 1 label
            $this->SetFont('Arial', 'B', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(38, 5, pdf_text($row[0] ?? ''), 0, 0, 'L');
            // Col 1 value
            $this->SetFont('Arial', '', 8.5);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(57, 5, pdf_text($row[1] ?? ''), 0, 0, 'L');

            // Col 2 label
            $this->SetFont('Arial', 'B', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(38, 5, pdf_text($row[2] ?? ''), 0, 0, 'L');
            // Col 2 value
            $this->SetFont('Arial', '', 8.5);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(57, 5, pdf_text($row[3] ?? ''), 0, 1, 'L');
        }
        $this->Ln(2);
    }
}

/**
 * Generate Individual Student PDF Report.
 */
function generate_student_pdf(array $r, array $docs, array $activity = [], bool $isStaff = true): string {
    $pdf = new PortalReportPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->reportTitle = 'STUDENT PROGRESS & FILE REPORT';
    $pdf->reportSubtitle = 'Student: ' . $r['name'] . ' (ID: ' . $r['id'] . ') · Reference: STU-' . str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT);
    $pdf->AddPage();

    // 1. Profile & Office Basics
    $pdf->SectionHeading('1. Student Profile & Office Information');
    $grid1 = [
        ['Student Name:', $r['name'], 'Application ID:', 'STU-' . str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT)],
        ['Counselor:', $r['counselor'] ?: 'Not assigned', 'Processing Officer:', $r['officer_name'] ?: 'Not assigned'],
        ['Branch Location:', $r['branch'] ?: 'Main / Lahore', 'Intake Period:', $r['intake'] ?: 'Not specified'],
        ['File Received Date:', fmt_date($r['received_date']) ?: 'Not set', 'Refund Status:', $isStaff ? ($r['refund'] ?: 'Standard') : 'Registered'],
    ];
    if (!empty($r['email']) || !empty($r['portal_id'])) {
        $grid1[] = ['Student Email:', $r['email'] ?: 'Not set', 'Portal ID:', $r['portal_id'] ?: 'Not set'];
    }
    if (!empty($r['shortlisting'])) {
        $grid1[] = ['University Shortlisting:', $r['shortlisting'], '', ''];
    }
    $pdf->InfoGrid($grid1);

    // 2. Application & Progress Status
    $pdf->SectionHeading('2. Current Application & Progress Status');
    $ns = next_stage($r);
    $done = done_count($r);
    $total = count(STAGES);
    $pct = round(($done / $total) * 100);

    $pdf->InfoGrid([
        ['Overall Progress:', "$done of $total steps ($pct%)", 'Next Required Step:', $ns ?: 'All steps completed'],
        ['Visa Application Status:', $r['visa'] ?: 'Pending in process', 'Meeting Priority:', $r['meeting_priority'] ?: 'Normal'],
        ['Docs Checklist Provided:', $r['docs_checklist'] === 'Yes' ? 'Yes (Confirmed)' : 'Pending', 'Initial Meeting Done:', $r['initial_meeting'] ?: 'No'],
        ['Recorded Meeting Status:', student_has_meeting($r) ? 'Video Recording Available' : 'Not Available', 'Student Folder:', !empty($r['drive_link']) ? 'Linked' : 'Not linked'],
    ]);

    // Prominent Clickable Cloud & Video Session Links
    $driveLink = safe_link($r['drive_link'] ?? '');
    $meetingLink = student_meeting_url($r);

    if ($driveLink || $meetingLink) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(30, 41, 59);
        if ($driveLink) {
            $pdf->Cell(38, 5, 'Documents Folder:', 0, 0, 'L');
            $pdf->SetFont('Arial', 'U', 8);
            $pdf->SetTextColor(37, 99, 235);
            $pdf->Cell(0, 5, pdf_text('Click to Open Google Drive Documents Folder -> ' . substr($driveLink, 0, 65)), 0, 1, 'L', false, $driveLink);
        }
        if ($meetingLink && $meetingLink !== $driveLink) {
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(38, 5, 'Recorded Meeting:', 0, 0, 'L');
            $pdf->SetFont('Arial', 'U', 8);
            $pdf->SetTextColor(37, 99, 235);
            $pdf->Cell(0, 5, pdf_text('Click to Watch / Play Recorded Video Session -> ' . substr($meetingLink, 0, 65)), 0, 1, 'L', false, $meetingLink);
        }
        $pdf->Ln(1);
    }

    // 3. Progress Milestones Table
    $pdf->SectionHeading('3. Progress Milestones Workflow');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(10, 6, '#', 1, 0, 'C', true);
    $pdf->Cell(55, 6, 'Milestone Step', 1, 0, 'L', true);
    $pdf->Cell(95, 6, 'Description', 1, 0, 'L', true);
    $pdf->Cell(30, 6, 'Status', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);
    foreach (STAGES as $i => $s) {
        if ($pdf->GetY() > 265) $pdf->AddPage();
        $isDone = stage_done($r, $i);
        $isNow = (!$isDone && $s[0] === $ns);
        $stText = $isDone ? 'Completed' : ($isNow ? 'In Progress' : 'Pending');
        
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(10, 5.5, (string)($i + 1), 1, 0, 'C');
        $pdf->Cell(55, 5.5, pdf_text($s[0]), 1, 0, 'L');
        $pdf->Cell(95, 5.5, pdf_text(substr($s[2], 0, 62)), 1, 0, 'L');
        
        if ($isDone) {
            $pdf->SetTextColor(22, 101, 52); // green
            $pdf->SetFont('Arial', 'B', 8);
        } elseif ($isNow) {
            $pdf->SetTextColor(37, 99, 235); // blue
            $pdf->SetFont('Arial', 'B', 8);
        } else {
            $pdf->SetTextColor(100, 116, 139); // grey
            $pdf->SetFont('Arial', '', 8);
        }
        $pdf->Cell(30, 5.5, pdf_text($stText), 1, 1, 'C');
        $pdf->SetFont('Arial', '', 8);
    }
    $pdf->Ln(2);

    // 4. Documents Checklist Table
    $pdf->SectionHeading('4. Document Checklist & Attached Files (Included in Dossier)');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(10, 6, '#', 1, 0, 'C', true);
    $pdf->Cell(60, 6, 'Required Document', 1, 0, 'L', true);
    $pdf->Cell(30, 6, 'Checklist Status', 1, 0, 'C', true);
    $pdf->Cell(90, 6, 'Attached Document File in Dossier', 1, 1, 'L', true);

    $pdf->SetFont('Arial', '', 8);
    foreach (DOCS as $i => $dName) {
        if ($pdf->GetY() > 265) $pdf->AddPage();
        $dRow = $docs[$i] ?? null;
        $status = is_array($dRow) ? ($dRow['status'] ?? '') : (string)$dRow;
        $fileInfo = 'No file attached';
        if (is_array($dRow) && !empty($dRow['file_name'])) {
            $kb = round(($dRow['file_size'] ?? 0) / 1024, 1);
            if ($kb > 0) {
                $fileInfo = $dRow['file_name'] . ' (' . $kb . ' KB)';
            } else {
                $fileInfo = $dRow['file_name'] . ' (Included Below)';
            }
        }

        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(10, 5.2, (string)($i + 1), 1, 0, 'C');
        $pdf->Cell(60, 5.2, pdf_text($dName), 1, 0, 'L');

        if ($status === 'Verified') {
            $pdf->SetTextColor(22, 101, 52);
            $pdf->SetFont('Arial', 'B', 8);
        } elseif ($status === 'Received' || $status === 'Uploaded') {
            $pdf->SetTextColor(37, 99, 235);
            $pdf->SetFont('Arial', 'B', 8);
        } elseif ($status === 'Pending') {
            $pdf->SetTextColor(180, 83, 9);
            $pdf->SetFont('Arial', 'B', 8);
        } else {
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('Arial', '', 8);
        }
        $pdf->Cell(30, 5.2, pdf_text($status ?: 'Pending'), 1, 0, 'C');

        $pdf->SetFont('Arial', '', 7.5);
        if ($fileInfo !== 'No file attached') {
            $pdf->SetTextColor(30, 41, 59);
        } else {
            $pdf->SetTextColor(148, 163, 184);
        }
        $pdf->Cell(90, 5.2, pdf_text(substr($fileInfo, 0, 56)), 1, 1, 'L');
        $pdf->SetFont('Arial', '', 8);
    }
    $pdf->Ln(2);

    // 5. Relevant counselor / follow-up notes & activity
    $pdf->SectionHeading('5. Follow-ups & Processing Record');
    $grid5 = [
        ['Initial Meeting Follow-up:', $r['follow_up_1'] ?: 'None', '2nd Processing Follow-up:', $r['follow_up_2'] ?: 'None'],
        ['Tax Consultant Follow-up:', $r['follow_up_3'] ?: 'None', 'Second Meeting Done:', $r['second_meeting'] ?: 'No'],
        ['Admission / Offer Done:', $r['offer'] ?: 'No', 'Enrollment / CAS Done:', $r['enrollment'] ?: 'No'],
    ];
    if ($isStaff && !empty($r['remarks'])) {
        $grid5[] = ['Internal Remarks:', substr($r['remarks'], 0, 80), '', ''];
    }
    $pdf->InfoGrid($grid5);

    if ($activity && $isStaff) {
        $pdf->SectionHeading('Recent Activity Log');
        $pdf->SetFont('Arial', '', 8);
        foreach (array_slice($activity, 0, 5) as $act) {
            if ($pdf->GetY() > 265) $pdf->AddPage();
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(35, 4.5, pdf_text(substr($act['created_at'], 0, 16)), 0, 0, 'L');
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(0, 4.5, pdf_text($act['action']), 0, 1, 'L');
        }
    }

    return $pdf->Output('S');
}

/**
 * Compiles a comprehensive student dossier PDF booklet containing the executive progress report
 * merged with all the student's actual physical documents, certificates, and photos.
 */
function compile_full_student_dossier(int $studentId, bool $isStaff = true): string {
    $r = q('SELECT s.*, o.name AS officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id WHERE s.id = ?', [$studentId])->fetch();
    if (!$r) return '';

    $docRows = q('SELECT * FROM student_documents WHERE student_id = ? ORDER BY doc_index', [$studentId])->fetchAll();
    $docs = [];
    foreach ($docRows as $d) {
        $docs[(int)$d['doc_index']] = $d;
    }

    $activity = $isStaff ? q('SELECT action, created_at FROM activity_log WHERE student_id = ? ORDER BY id DESC LIMIT 10', [$studentId])->fetchAll() : [];

    // 1. Generate base executive summary PDF
    $basePdfContent = generate_student_pdf($r, $docs, $activity, $isStaff);

    // 2. Setup temporary paths
    $cacheDir = ROOT . '/uploads/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $uploadsDir = ROOT . '/uploads/documents';
    $dbPath = getenv('PORTAL_SQLITE') ?: (APP . '/portal.sqlite');
    $scriptPath = APP . '/build_student_dossier.py';

    if (!is_file($scriptPath)) {
        return $basePdfContent;
    }

    $tempBase = $cacheDir . '/temp_base_' . $studentId . '_' . uniqid() . '.pdf';
    $tempOut  = $cacheDir . '/temp_dossier_' . $studentId . '_' . uniqid() . '.pdf';

    file_put_contents($tempBase, $basePdfContent);

    // 3. Invoke Python compiler
    $cmd = sprintf(
        'python %s --db %s --student-id %d --base-pdf %s --output-pdf %s --cache-dir %s --uploads-dir %s 2>&1',
        escapeshellarg($scriptPath),
        escapeshellarg($dbPath),
        $studentId,
        escapeshellarg($tempBase),
        escapeshellarg($tempOut),
        escapeshellarg($cacheDir),
        escapeshellarg($uploadsDir)
    );

    @exec($cmd, $output, $returnCode);

    @unlink($tempBase);

    if ($returnCode === 0 && is_file($tempOut) && filesize($tempOut) > 1000) {
        $dossierContent = file_get_contents($tempOut);
        @unlink($tempOut);
        return $dossierContent;
    }

    if (is_file($tempOut)) {
        @unlink($tempOut);
    }

    return $basePdfContent;
}

/**
 * Generate Individual Student CSV Export.
 */
function generate_student_csv(array $r, array $docs, bool $isStaff = true): string {
    $out = fopen('php://memory', 'r+');
    
    // Header
    $headers = [
        'Student ID', 'Student Name', 'Email', 'Portal ID', 'University Shortlisting',
        'Counselor', 'Processing Officer', 'Branch', 'Intake',
        'Received Date', 'Progress Steps Done', 'Total Steps', 'Next Step', 'Visa Status',
        'Docs Checklist Provided', 'Pending Documents Note', 'Consent Form Signed',
        'Initial Meeting', 'Follow-up 1', 'Recording Uploaded', 'Second Meeting', 'Follow-up 2',
        'Tax Meeting', 'Follow-up 3', 'Offer Received', 'Enrollment Summary',
        '1st Installment', '2nd Installment', '3rd Installment', '4th Installment',
        'Created At', 'Updated At'
    ];
    if ($isStaff) {
        $headers[] = 'Refund Status';
        $headers[] = 'Meeting Priority';
        $headers[] = 'Threatening Cancellation';
        $headers[] = 'Remarks';
    }

    // Append document status column headers
    foreach (DOCS as $i => $dName) {
        $headers[] = 'Doc: ' . $dName . ' Status';
        $headers[] = 'Doc: ' . $dName . ' File';
    }

    fputcsv($out, array_map('csv_cell', $headers));

    $ns = next_stage($r);
    $done = done_count($r);
    $total = count(STAGES);

    $row = [
        $r['id'],
        $r['name'],
        $r['email'] ?: '',
        $r['portal_id'] ?: '',
        $r['shortlisting'] ?: '',
        $r['counselor'] ?: '',
        $r['officer_name'] ?: '',
        $r['branch'] ?: '',
        $r['intake'] ?: '',
        $r['received_date'] ?: '',
        $done,
        $total,
        $ns ?: 'Completed',
        $r['visa'] ?: 'Pending',
        $r['docs_checklist'] ?: '',
        $r['pending_docs_note'] ?: '',
        $r['consent'] ?: '',
        $r['initial_meeting'] ?: '',
        $r['follow_up_1'] ?: '',
        student_has_meeting($r) ? 'Yes' : 'Not Available',
        $r['second_meeting'] ?: '',
        $r['follow_up_2'] ?: '',
        $r['tax_meeting'] ?: '',
        $r['follow_up_3'] ?: '',
        $r['offer'] ?: '',
        $r['enrollment'] ?: '',
        $r['installment_1'] ?: '',
        $r['installment_2'] ?: '',
        $r['installment_3'] ?: '',
        $r['installment_4'] ?: '',
        $r['created_at'] ?: '',
        $r['updated_at'] ?: '',
    ];

    if ($isStaff) {
        $row[] = $r['refund'] ?: '';
        $row[] = $r['meeting_priority'] ?: '';
        $row[] = $r['threat_cancellation'] ?: '';
        $row[] = $r['remarks'] ?: '';
    }

    foreach (DOCS as $i => $_) {
        $dRow = $docs[$i] ?? null;
        $status = is_array($dRow) ? ($dRow['status'] ?? '') : (string)$dRow;
        $file = (is_array($dRow) && !empty($dRow['file_name'])) ? $dRow['file_name'] : '';
        $row[] = $status ?: 'Not Requested';
        $row[] = $file;
    }

    fputcsv($out, array_map('csv_cell', $row));

    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv ?: '';
}

/**
 * Generate Admin Monthly PDF Report.
 */
function generate_monthly_pdf(int $year, int $month, array $summary, array $students, string $filterType = 'received'): string {
    $monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $monthLabel = ($monthNames[$month] ?? 'Month') . ' ' . $year;

    $pdf = new PortalReportPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->reportTitle = 'MONTHLY PROCESSING & PROGRESS REPORT - ' . strtoupper($monthLabel);
    $filterDesc = $filterType === 'active' ? 'Active Pipeline Cases During Month' : 'Applications Received During Month';
    $pdf->reportSubtitle = 'Reporting Scope: ' . $filterDesc . ' · Total Records: ' . count($students);
    $pdf->AddPage();

    // 1. Executive Summary & Key Metrics
    $pdf->SectionHeading('1. Executive Summary & Key Performance Indicators');
    $pdf->InfoGrid([
        ['Total Applications in Scope:', (string)($summary['total'] ?? count($students)), 'Reporting Period:', $monthLabel],
        ['Visa Granted / Closed:', (string)($summary['granted'] ?? 0), 'Visa Pending in Pipeline:', (string)($summary['pending_visa'] ?? 0)],
        ['Student Folders Linked:', (string)($summary['drive_folders'] ?? 0), 'Active Cases:', (string)($summary['pending_visa'] ?? 0)],
        ['Consent Forms Signed:', (string)($summary['consent_done'] ?? 0), 'Docs Checklist Provided:', (string)($summary['checklist_done'] ?? 0)],
    ]);

    // 2. Status Breakdown by Progress Stage
    $pdf->SectionHeading('2. Breakdown by Progress Stage');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(80, 6, 'Progress Stage', 1, 0, 'L', true);
    $pdf->Cell(45, 6, 'Number of Students', 1, 0, 'C', true);
    $pdf->Cell(65, 6, 'Percentage of Active Scope', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);
    $stageCounts = $summary['stage_counts'] ?? [];
    $totalCount = max(1, count($students));
    foreach (STAGES as $s) {
        $c = $stageCounts[$s[0]] ?? 0;
        $pct = round(($c / $totalCount) * 100, 1);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(80, 5, pdf_text($s[0]), 1, 0, 'L');
        $pdf->Cell(45, 5, (string)$c, 1, 0, 'C');
        $pdf->Cell(65, 5, $pct . '%', 1, 1, 'C');
    }
    $closedCount = $stageCounts['Closed'] ?? 0;
    $closedPct = round(($closedCount / $totalCount) * 100, 1);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(80, 5, 'File Complete / Closed', 1, 0, 'L');
    $pdf->Cell(45, 5, (string)$closedCount, 1, 0, 'C');
    $pdf->Cell(65, 5, $closedPct . '%', 1, 1, 'C');
    $pdf->SetFont('Arial', '', 8);
    $pdf->Ln(2);

    // 3. Breakdown by Officer & Branch
    $pdf->SectionHeading('3. Officer & Branch Distribution');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(65, 6, 'Processing Officer', 1, 0, 'L', true);
    $pdf->Cell(35, 6, 'Assigned Students', 1, 0, 'C', true);
    $pdf->Cell(55, 6, 'Branch Location', 1, 0, 'L', true);
    $pdf->Cell(35, 6, 'Branch Students', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);
    $officerCounts = $summary['officer_counts'] ?? [];
    $branchCounts = $summary['branch_counts'] ?? [];
    $maxRows = max(count($officerCounts), count($branchCounts));
    $offKeys = array_keys($officerCounts);
    $brKeys = array_keys($branchCounts);

    for ($i = 0; $i < $maxRows; $i++) {
        if ($pdf->GetY() > 265) $pdf->AddPage();
        $oName = $offKeys[$i] ?? '';
        $oVal = $oName !== '' ? (string)$officerCounts[$oName] : '';
        $bName = $brKeys[$i] ?? '';
        $bVal = $bName !== '' ? (string)$branchCounts[$bName] : '';

        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(65, 5, pdf_text($oName), 1, 0, 'L');
        $pdf->Cell(35, 5, $oVal, 1, 0, 'C');
        $pdf->Cell(55, 5, pdf_text($bName), 1, 0, 'L');
        $pdf->Cell(35, 5, $bVal, 1, 1, 'C');
    }
    $pdf->Ln(3);

    // 4. Detailed Student Listing Table
    $pdf->SectionHeading('4. Student Application Records Detail (' . count($students) . ' total)');
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(10, 6, 'ID', 1, 0, 'C', true);
    $pdf->Cell(44, 6, 'Student Name', 1, 0, 'L', true);
    $pdf->Cell(28, 6, 'Counselor', 1, 0, 'L', true);
    $pdf->Cell(28, 6, 'Officer', 1, 0, 'L', true);
    $pdf->Cell(18, 6, 'Received', 1, 0, 'C', true);
    $pdf->Cell(34, 6, 'Current Stage', 1, 0, 'L', true);
    $pdf->Cell(28, 6, 'Folder & Meeting', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 7.5);
    foreach ($students as $s) {
        if ($pdf->GetY() > 265) {
            $pdf->AddPage();
            // Reprint headers
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->SetFillColor(226, 232, 240);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(10, 6, 'ID', 1, 0, 'C', true);
            $pdf->Cell(44, 6, 'Student Name', 1, 0, 'L', true);
            $pdf->Cell(28, 6, 'Counselor', 1, 0, 'L', true);
            $pdf->Cell(28, 6, 'Officer', 1, 0, 'L', true);
            $pdf->Cell(18, 6, 'Received', 1, 0, 'C', true);
            $pdf->Cell(34, 6, 'Current Stage', 1, 0, 'L', true);
            $pdf->Cell(28, 6, 'Folder & Meeting', 1, 1, 'C', true);
            $pdf->SetFont('Arial', '', 7.5);
        }

        $ns = next_stage($s) ?: 'Closed';
        $dLink = safe_link($s['drive_link'] ?? '');
        $mLink = student_meeting_url($s);

        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(10, 5, (string)$s['id'], 1, 0, 'C');
        $pdf->Cell(44, 5, pdf_text(substr($s['name'], 0, 26)), 1, 0, 'L');
        $pdf->Cell(28, 5, pdf_text(substr($s['counselor'] ?: '-', 0, 16)), 1, 0, 'L');
        $pdf->Cell(28, 5, pdf_text(substr($s['officer_name'] ?: 'Unassigned', 0, 16)), 1, 0, 'L');
        $pdf->Cell(18, 5, pdf_text(fmt_date($s['received_date'])), 1, 0, 'C');
        $pdf->Cell(34, 5, pdf_text(substr($ns, 0, 20)), 1, 0, 'L');

        // Folder & Meeting clickable links
        $linkStr = '';
        if ($dLink) $linkStr .= '[Docs] ';
        if ($mLink) $linkStr .= '[Video]';
        if ($linkStr === '') $linkStr = '-';

        $pdf->SetTextColor(37, 99, 235);
        $pdf->Cell(28, 5, $linkStr, 1, 1, 'C', false, $dLink ?: ($mLink ?: ''));
        $pdf->SetTextColor(15, 23, 42);
    }

    return $pdf->Output('S');
}

/**
 * Generate Admin Monthly CSV Export.
 */
function generate_monthly_csv(int $year, int $month, array $students, string $filterType = 'received'): string {
    $out = fopen('php://memory', 'r+');
    
    $headers = [
        'ID', 'Student Name', 'Counselor', 'Processing Officer', 'Branch', 'Intake',
        'Received Date', 'Progress Steps Done', 'Total Steps', 'Next Step', 'Visa Status',
        'Refund Status', 'Meeting Priority', 'Consent Form',
        'Docs Checklist', 'Initial Meeting', 'Recording Uploaded', 'Second Meeting',
        'Tax Meeting', 'Offer Received', 'Enrollment Summary', 'Last Installment',
        'Remarks', 'Created At', 'Updated At'
    ];
    fputcsv($out, array_map('csv_cell', $headers));

    foreach ($students as $s) {
        $ns = next_stage($s);
        $done = done_count($s);
        $total = count(STAGES);
        $row = [
            $s['id'],
            $s['name'],
            $s['counselor'] ?: '',
            $s['officer_name'] ?: 'Not assigned',
            $s['branch'] ?: '',
            $s['intake'] ?: '',
            $s['received_date'] ?: '',
            $done,
            $total,
            $ns ?: 'Completed',
            $s['visa'] ?: 'Pending',
            $s['refund'] ?: '',
            $s['meeting_priority'] ?: '',
            $s['consent'] ?: '',
            $s['docs_checklist'] ?: '',
            $s['initial_meeting'] ?: '',
            student_has_meeting($s) ? 'Yes' : 'Not Available',
            $s['second_meeting'] ?: '',
            $s['tax_meeting'] ?: '',
            $s['offer'] ?: '',
            $s['enrollment'] ?: '',
            $s['last_installment'] ?: '',
            $s['remarks'] ?: '',
            $s['created_at'] ?: '',
            $s['updated_at'] ?: '',
        ];
        fputcsv($out, array_map('csv_cell', $row));
    }

    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv ?: '';
}
