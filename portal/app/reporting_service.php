<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/SimpleXLSXGen.php';

/**
 * Phase 5: Reporting, Aggregation & Multi-Format Export Service
 * Strictly respects Asia/Karachi timezone and role permissions.
 */

function get_reporting_summary(array $actor, string $startDate, string $endDate): array {
    [$w, $p] = scope_sql($actor);

    // 1. New students in date range (strictly excludes legacy imported rows using is_legacy = 0)
    $newSql = "SELECT COUNT(*) as total,
                      SUM(CASE WHEN program_level = 'BS' THEN 1 ELSE 0 END) as bs_count,
                      SUM(CASE WHEN program_level = 'MS' THEN 1 ELSE 0 END) as ms_count
               FROM students s 
               WHERE s.is_legacy = 0 
                 AND substr(s.created_at, 1, 10) BETWEEN ? AND ? 
                 AND $w";
    $newParams = array_merge([$startDate, $endDate], $p);
    $newStats = q($newSql, $newParams)->fetch();

    // 2. Global status & verification metrics for students in scope
    $kpiSql = "SELECT COUNT(*) as total_caseload,
                      SUM(CASE WHEN s.student_status = 'active' OR s.student_status IS NULL THEN 1 ELSE 0 END) as active_count,
                      SUM(CASE WHEN s.student_status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
                      SUM(CASE WHEN s.student_status = 'disabled' THEN 1 ELSE 0 END) as disabled_count,
                      SUM(CASE WHEN s.csc_status = 'Verified' OR s.csc_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified_count,
                      SUM(CASE WHEN (s.csc_status != 'Verified' OR s.csc_status IS NULL) AND s.csc_verified_at IS NULL THEN 1 ELSE 0 END) as unverified_count,
                      SUM(CASE WHEN s.is_refunded = 1 THEN 1 ELSE 0 END) as refunded_count,
                      SUM(CASE WHEN s.pending_since IS NOT NULL THEN 1 ELSE 0 END) as pending_count,
                      SUM(CASE WHEN s.program_level IS NULL OR s.program_level = '' OR s.country IS NULL OR s.country = '' THEN 1 ELSE 0 END) as incomplete_count
               FROM students s
               WHERE $w";
    $kpis = q($kpiSql, $p)->fetch();

    // 3. Country-wise distribution
    $countrySql = "SELECT COALESCE(NULLIF(s.country, ''), 'Unassigned / Incomplete') as country_name,
                          COUNT(*) as student_count,
                          SUM(CASE WHEN s.program_level = 'BS' THEN 1 ELSE 0 END) as bs_count,
                          SUM(CASE WHEN s.program_level = 'MS' THEN 1 ELSE 0 END) as ms_count,
                          SUM(CASE WHEN s.pending_since IS NOT NULL THEN 1 ELSE 0 END) as pending_count
                   FROM students s
                   WHERE $w
                   GROUP BY country_name
                   ORDER BY student_count DESC";
    $countryStats = q($countrySql, $p)->fetchAll();

    return [
        'start_date'        => $startDate,
        'end_date'          => $endDate,
        'new_students'      => (int)($newStats['total'] ?? 0),
        'new_bs'            => (int)($newStats['bs_count'] ?? 0),
        'new_ms'            => (int)($newStats['ms_count'] ?? 0),
        'total_caseload'    => (int)($kpis['total_caseload'] ?? 0),
        'active_count'      => (int)($kpis['active_count'] ?? 0),
        'inactive_count'    => (int)($kpis['inactive_count'] ?? 0),
        'disabled_count'    => (int)($kpis['disabled_count'] ?? 0),
        'verified_count'    => (int)($kpis['verified_count'] ?? 0),
        'unverified_count'  => (int)($kpis['unverified_count'] ?? 0),
        'refunded_count'    => (int)($kpis['refunded_count'] ?? 0),
        'pending_count'     => (int)($kpis['pending_count'] ?? 0),
        'incomplete_count'  => (int)($kpis['incomplete_count'] ?? 0),
        'country_stats'     => $countryStats,
    ];
}

function get_staff_and_counselor_breakdown(): array {
    // 1. Processing Staff Breakdown
    $staffSql = "SELECT u.id, u.name, u.role, COALESCE(u.department, 'backend') as department,
                        COUNT(s.id) as assigned_count,
                        SUM(CASE WHEN s.pending_since IS NOT NULL THEN 1 ELSE 0 END) as pending_count,
                        SUM(CASE WHEN s.csc_status = 'Verified' OR s.csc_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified_count,
                        SUM(CASE WHEN s.offer = 'Yes' THEN 1 ELSE 0 END) as offer_count,
                        SUM(CASE WHEN s.visa = 'Granted' THEN 1 ELSE 0 END) as visa_granted_count
                 FROM users u
                 LEFT JOIN students s ON (s.officer_id = u.id OR s.frontend_officer_id = u.id)
                 WHERE u.role IN ('processing_staff', 'officer') AND u.active = 1
                 GROUP BY u.id, u.name, u.role, u.department
                 ORDER BY department DESC, u.name ASC";
    $staffStats = q($staffSql)->fetchAll();

    // 2. Counselor Breakdown
    $counselorSql = "SELECT u.id, u.name, u.username,
                            COUNT(s.id) as caseload_count,
                            SUM(CASE WHEN s.pending_since IS NOT NULL THEN 1 ELSE 0 END) as pending_count,
                            SUM(CASE WHEN s.program_level = 'BS' THEN 1 ELSE 0 END) as bs_count,
                            SUM(CASE WHEN s.program_level = 'MS' THEN 1 ELSE 0 END) as ms_count,
                            SUM(CASE WHEN s.csc_status = 'Verified' OR s.csc_verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified_count
                     FROM users u
                     LEFT JOIN students s ON (s.assigned_counselor_id = u.id OR LOWER(TRIM(s.counselor)) = LOWER(TRIM(u.name)))
                     WHERE u.role = 'counselor' AND u.active = 1
                     GROUP BY u.id, u.name, u.username
                     ORDER BY caseload_count DESC, u.name ASC";
    $counselorStats = q($counselorSql)->fetchAll();

    // 3. PMS Lead Breakdown
    $pmsSql = "SELECT u.id, u.name,
                      COUNT(s.id) as assigned_students,
                      SUM(CASE WHEN s.pending_since IS NOT NULL THEN 1 ELSE 0 END) as pending_count
               FROM users u
               LEFT JOIN students s ON s.assigned_pms_lead_id = u.id
               WHERE u.role = 'pms_lead' AND u.active = 1
               GROUP BY u.id, u.name
               ORDER BY u.name ASC";
    $pmsStats = q($pmsSql)->fetchAll();

    return [
        'staff_stats'     => $staffStats,
        'counselor_stats' => $counselorStats,
        'pms_stats'       => $pmsStats,
    ];
}

function get_disabled_and_refunds_data(): array {
    $disabled = q("
        SELECT s.id, s.name, s.counselor, s.branch, s.student_status, s.disabled_reason, s.disabled_at,
               u.name as disabled_by_name, u.role as disabled_by_role
        FROM students s
        LEFT JOIN users u ON u.id = s.disabled_by
        WHERE s.student_status = 'disabled'
        ORDER BY s.disabled_at DESC, s.id DESC
    ")->fetchAll();

    $refunds = q("
        SELECT s.id, s.name, s.counselor, s.branch, s.refund, s.is_refunded, s.refund_date, s.refund_note,
               s.student_status
        FROM students s
        WHERE s.is_refunded = 1 OR (s.refund IS NOT NULL AND s.refund != '' AND s.refund != 'Non-Refundable')
        ORDER BY CASE WHEN s.is_refunded = 1 THEN 0 ELSE 1 END, s.refund_date DESC, s.id DESC
    ")->fetchAll();

    return [
        'disabled' => $disabled,
        'refunds'  => $refunds,
    ];
}

/**
 * Exports data to native .xlsx format via pure-PHP SimpleXLSXGen (no shell_exec or Composer required).
 */
function stream_xlsx_download(string $sheetTitle, array $headers, array $rows, string $filename): void {
    $fullRows = array_merge([$headers], $rows);
    $xlsx = SimpleXLSXGen::fromArray($fullRows, $sheetTitle);
    $content = $xlsx->output();

    if ($content === '') {
        // Fallback to CSV if generation fails
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/\.xlsx$/i', '.csv', $filename) . '"');
        $fp = fopen('php://output', 'w');
        fputs($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($fp, $headers);
        foreach ($rows as $r) fputcsv($fp, $r);
        fclose($fp);
        exit;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string)strlen($content));
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: private');
    echo $content;
    exit;
}

/**
 * Generate Phase 5 PDF Report using FPDF.
 */
function generate_reporting_pdf(array $summary, ?array $breakdowns, string $title): string {
    $pdf = new PortalReportPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->reportTitle = strtoupper($title);
    $pdf->reportSubtitle = 'Date Range: ' . $summary['start_date'] . ' to ' . $summary['end_date'] . ' (Asia/Karachi PKT)';
    $pdf->AddPage();

    // 1. Executive Summary & Core KPIs
    $pdf->SectionHeading('1. Executive Summary & Caseload KPIs');
    $pdf->InfoGrid([
        ['Date Range:', $summary['start_date'] . ' to ' . $summary['end_date'], 'New Students (Period):', (string)$summary['new_students'] . ' (BS:' . $summary['new_bs'] . ' MS:' . $summary['new_ms'] . ')'],
        ['Total Caseload:', (string)$summary['total_caseload'], 'Active / Inactive / Disabled:', $summary['active_count'] . ' / ' . $summary['inactive_count'] . ' / ' . $summary['disabled_count']],
        ['CSC Verified:', (string)$summary['verified_count'], 'CSC Unverified:', (string)$summary['unverified_count']],
        ['Pending Attention:', (string)$summary['pending_count'], 'Incomplete Profiles:', (string)$summary['incomplete_count']],
        ['Refunded Students:', (string)$summary['refunded_count'], 'Generated At:', date('Y-m-d H:i') . ' PKT'],
    ]);

    // 2. Country Distribution Table
    $pdf->SectionHeading('2. Country Distribution');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(60, 6, 'Country', 1, 0, 'L', true);
    $pdf->Cell(32, 6, 'Total Students', 1, 0, 'C', true);
    $pdf->Cell(32, 6, 'BS Level', 1, 0, 'C', true);
    $pdf->Cell(32, 6, 'MS Level', 1, 0, 'C', true);
    $pdf->Cell(34, 6, 'Pending Attention', 1, 1, 'C', true);

    $pdf->SetFont('Arial', '', 8);
    foreach ($summary['country_stats'] as $cs) {
        if ($pdf->GetY() > 265) $pdf->AddPage();
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(60, 5, pdf_text((string)$cs['country_name']), 1, 0, 'L');
        $pdf->Cell(32, 5, (string)$cs['student_count'], 1, 0, 'C');
        $pdf->Cell(32, 5, (string)$cs['bs_count'], 1, 0, 'C');
        $pdf->Cell(32, 5, (string)$cs['ms_count'], 1, 0, 'C');
        $pdf->Cell(34, 5, (string)$cs['pending_count'], 1, 1, 'C');
    }
    $pdf->Ln(2);

    if ($breakdowns) {
        // 3. Processing Staff Breakdown
        $pdf->SectionHeading('3. Processing Staff Performance & Caseload');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(226, 232, 240);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(45, 6, 'Staff Name', 1, 0, 'L', true);
        $pdf->Cell(25, 6, 'Department', 1, 0, 'C', true);
        $pdf->Cell(24, 6, 'Caseload', 1, 0, 'C', true);
        $pdf->Cell(24, 6, 'Verified', 1, 0, 'C', true);
        $pdf->Cell(24, 6, 'Offers', 1, 0, 'C', true);
        $pdf->Cell(24, 6, 'Visas', 1, 0, 'C', true);
        $pdf->Cell(24, 6, 'Pending', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        foreach ($breakdowns['staff_stats'] as $st) {
            if ($pdf->GetY() > 265) $pdf->AddPage();
            $dept = (($st['department'] ?? 'backend') === 'frontend') ? 'Front-End' : 'Back-End';
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(45, 5, pdf_text((string)$st['name']), 1, 0, 'L');
            $pdf->Cell(25, 5, $dept, 1, 0, 'C');
            $pdf->Cell(24, 5, (string)$st['assigned_count'], 1, 0, 'C');
            $pdf->Cell(24, 5, (string)$st['verified_count'], 1, 0, 'C');
            $pdf->Cell(24, 5, (string)$st['offer_count'], 1, 0, 'C');
            $pdf->Cell(24, 5, (string)$st['visa_granted_count'], 1, 0, 'C');
            $pdf->Cell(24, 5, (string)$st['pending_count'], 1, 1, 'C');
        }
        $pdf->Ln(2);

        // 4. Counselor Breakdown
        $pdf->SectionHeading('4. Counselor Caseload Breakdown');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(226, 232, 240);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(50, 6, 'Counselor Name', 1, 0, 'L', true);
        $pdf->Cell(30, 6, 'Caseload', 1, 0, 'C', true);
        $pdf->Cell(28, 6, 'BS Level', 1, 0, 'C', true);
        $pdf->Cell(28, 6, 'MS Level', 1, 0, 'C', true);
        $pdf->Cell(28, 6, 'Verified', 1, 0, 'C', true);
        $pdf->Cell(26, 6, 'Pending', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        foreach ($breakdowns['counselor_stats'] as $cs) {
            if ($pdf->GetY() > 265) $pdf->AddPage();
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(50, 5, pdf_text((string)$cs['name']), 1, 0, 'L');
            $pdf->Cell(30, 5, (string)$cs['caseload_count'], 1, 0, 'C');
            $pdf->Cell(28, 5, (string)$cs['bs_count'], 1, 0, 'C');
            $pdf->Cell(28, 5, (string)$cs['ms_count'], 1, 0, 'C');
            $pdf->Cell(28, 5, (string)$cs['verified_count'], 1, 0, 'C');
            $pdf->Cell(26, 5, (string)$cs['pending_count'], 1, 1, 'C');
        }
        $pdf->Ln(2);

        // 5. PMS Lead Breakdown
        $pdf->SectionHeading('5. PMS Lead Oversight Breakdown');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(226, 232, 240);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(70, 6, 'PMS Lead Name', 1, 0, 'L', true);
        $pdf->Cell(60, 6, 'Supervised Students', 1, 0, 'C', true);
        $pdf->Cell(60, 6, 'Pending Review', 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8);
        foreach ($breakdowns['pms_stats'] as $pm) {
            if ($pdf->GetY() > 265) $pdf->AddPage();
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(70, 5, pdf_text((string)$pm['name']), 1, 0, 'L');
            $pdf->Cell(60, 5, (string)$pm['assigned_students'], 1, 0, 'C');
            $pdf->Cell(60, 5, (string)$pm['pending_count'], 1, 1, 'C');
        }
    }

    return $pdf->Output('S');
}

/**
 * Multi-section Excel export using build_xlsx.py
 */
function export_reporting_xlsx(array $summary, ?array $breakdowns, string $filename): void {
    $headers = ['Section', 'Category / Name', 'Detail / Metric', 'Value 1', 'Value 2', 'Value 3', 'Value 4'];
    $rows = [];

    // Section 1: Executive Summary
    $rows[] = ['1. Executive Summary', 'Reporting Period', $summary['start_date'] . ' to ' . $summary['end_date'], 'Timezone: Asia/Karachi (PKT)', '', '', ''];
    $rows[] = ['1. Executive Summary', 'New Students (Period, is_legacy = 0)', 'Total: ' . $summary['new_students'], 'BS: ' . $summary['new_bs'], 'MS: ' . $summary['new_ms'], '', ''];
    $rows[] = ['1. Executive Summary', 'Total Caseload', 'Total: ' . $summary['total_caseload'], 'Active: ' . $summary['active_count'], 'Inactive: ' . $summary['inactive_count'], 'Disabled: ' . $summary['disabled_count'], ''];
    $rows[] = ['1. Executive Summary', 'CSC Verification Status', 'Verified: ' . $summary['verified_count'], 'Unverified: ' . $summary['unverified_count'], '', '', ''];
    $rows[] = ['1. Executive Summary', 'Action Attention Flags', 'Pending (pending_since): ' . $summary['pending_count'], 'Incomplete Profiles: ' . $summary['incomplete_count'], 'Refunded: ' . $summary['refunded_count'], '', ''];
    $rows[] = ['', '', '', '', '', '', ''];

    // Section 2: Country Distribution
    $rows[] = ['2. Country Distribution', 'Country Name', 'Total Students', 'BS Level', 'MS Level', 'Pending Attention', ''];
    foreach ($summary['country_stats'] as $cs) {
        $rows[] = [
            '2. Country Distribution',
            $cs['country_name'],
            $cs['student_count'],
            $cs['bs_count'],
            $cs['ms_count'],
            $cs['pending_count'],
            '',
        ];
    }
    $rows[] = ['', '', '', '', '', '', ''];

    if ($breakdowns) {
        // Section 3: Staff Breakdown
        $rows[] = ['3. Processing Staff', 'Staff Name', 'Department', 'Assigned Caseload', 'CSC Verified', 'Offers / Visas', 'Pending Attention'];
        foreach ($breakdowns['staff_stats'] as $st) {
            $dept = (($st['department'] ?? 'backend') === 'frontend') ? 'Front-End' : 'Back-End';
            $rows[] = [
                '3. Processing Staff',
                $st['name'],
                $dept,
                $st['assigned_count'],
                $st['verified_count'],
                'Offers: ' . $st['offer_count'] . ' | Visas: ' . $st['visa_granted_count'],
                $st['pending_count'],
            ];
        }
        $rows[] = ['', '', '', '', '', '', ''];

        // Section 4: Counselor Breakdown
        $rows[] = ['4. Counselors', 'Counselor Name', 'Username', 'Total Caseload', 'BS Level', 'MS Level', 'Pending Attention'];
        foreach ($breakdowns['counselor_stats'] as $cs) {
            $rows[] = [
                '4. Counselors',
                $cs['name'],
                $cs['username'] ?? '',
                $cs['caseload_count'],
                $cs['bs_count'],
                $cs['ms_count'],
                $cs['pending_count'],
            ];
        }
        $rows[] = ['', '', '', '', '', '', ''];

        // Section 5: PMS Leads
        $rows[] = ['5. PMS Leads', 'PMS Lead Name', 'Role', 'Supervised Students', 'Pending Attention', '', ''];
        foreach ($breakdowns['pms_stats'] as $pm) {
            $rows[] = [
                '5. PMS Leads',
                $pm['name'],
                'pms_lead',
                $pm['assigned_students'],
                $pm['pending_count'],
                '',
                '',
            ];
        }
    }

    stream_xlsx_download('Portal_Report', $headers, $rows, $filename);
}
