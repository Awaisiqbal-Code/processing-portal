<?php
declare(strict_types=1);
// Officer pending report as a PDF: KPI tiles, work done, then one card per student with only what is missing.

require_once APP . '/reports.php';

class PendingCardsPDF extends PortalReportPDF {
    /** Rounded rectangle (style: D = border, F = fill, FD = both). */
    function RoundedRect(float $x, float $y, float $w, float $h, float $r, string $style = 'D'): void {
        $k = $this->k; $hp = $this->h;
        $op = $style === 'F' ? 'f' : (($style === 'FD' || $style === 'DF') ? 'B' : 'S');
        $arc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->arcTo($xc + $r * $arc, $yc - $r, $xc + $r, $yc - $r * $arc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->arcTo($xc + $r, $yc + $r * $arc, $xc + $r * $arc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->arcTo($xc - $r * $arc, $yc + $r, $xc - $r, $yc + $r * $arc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->arcTo($xc - $r, $yc - $r * $arc, $xc - $r * $arc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }
    private function arcTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void {
        $h = $this->h; $k = $this->k;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x1 * $k, ($h - $y1) * $k, $x2 * $k, ($h - $y2) * $k, $x3 * $k, ($h - $y3) * $k));
    }

    /** Lays chips into rows that fit $width. Returns rows of [text, width]. */
    function chipRows(array $texts, float $width, float $font = 7.5): array {
        $this->SetFont('Arial', 'B', $font);
        $rows = [[]]; $used = 0;
        foreach ($texts as $t) {
            $t = pdf_text($t);
            $w = min($width, $this->GetStringWidth($t) + 5);
            if ($used > 0 && $used + $w > $width) { $rows[] = []; $used = 0; }
            $rows[count($rows) - 1][] = [$t, $w];
            $used += $w + 1.6;
        }
        return $rows[0] ? $rows : [];
    }

    function drawChips(array $rows, float $x, float $y, array $fill, array $text, float $font = 7.5): float {
        $this->SetFont('Arial', 'B', $font);
        foreach ($rows as $row) {
            $cx = $x;
            foreach ($row as [$t, $w]) {
                $this->SetFillColor(...$fill);
                $this->RoundedRect($cx, $y, $w, 4.6, 2.2, 'F');
                $this->SetTextColor(...$text);
                $this->SetXY($cx, $y + 0.2);
                $this->Cell($w, 4.2, $t, 0, 0, 'C');
                $cx += $w + 1.6;
            }
            $y += 5.6;
        }
        return $y;
    }

    function kpiTile(float $x, float $y, float $w, string $label, string $value, array $color): void {
        $this->SetFillColor(248, 250, 252); $this->SetDrawColor(226, 232, 240);
        $this->RoundedRect($x, $y, $w, 17, 2.5, 'FD');
        $this->SetFillColor(...$color);
        $this->Rect($x + 2.5, $y, $w - 5, 0.9, 'F');
        $this->SetXY($x + 3, $y + 2.5); $this->SetFont('Arial', 'B', 6.5); $this->SetTextColor(100, 116, 139);
        $this->Cell($w - 6, 4, pdf_text(strtoupper($label)), 0, 2);
        $this->SetFont('Arial', 'B', 15); $this->SetTextColor(...$color);
        $this->Cell($w - 6, 8, pdf_text($value), 0, 0);
    }
}

/**
 * $rows: evaluated students of this officer (each with _p, _pending_hours, _idle_days, last remark fields).
 */
function generate_pending_report_pdf(array $officer, array $sum, array $work, array $rows, string $from, string $to, string $byName): string {
    $pdf = new PendingCardsPDF('P', 'mm', 'A4');
    $pdf->reportTitle = 'PENDING REPORT - ' . strtoupper($officer['name']);
    $pdf->reportSubtitle = (($officer['department'] ?? '') === 'frontend' ? 'Front-End' : 'Back-End') . ' processing officer  |  Period ' . fmt_date($from) . ' - ' . fmt_date($to) . '  |  Prepared by ' . $byName;
    $pdf->footerNotice = 'Confidential - ScholarshipHive Edutancy';
    $pdf->AliasNbPages();
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();

    // KPI tiles
    $y = $pdf->GetY() + 1;
    $tiles = [
        ['Assigned', (string)$sum['assigned'], [15, 93, 158]],
        ['Clear', (string)$sum['clear'], [5, 150, 105]],
        ['With pending', (string)$sum['with_pending'], [220, 38, 38]],
        ['48h overdue', (string)$sum['overdue'], [217, 119, 6]],
        ['Done in period', (string)array_sum($work['counts']), [5, 150, 105]],
    ];
    $tw = (190 - 4 * 3) / 5;
    foreach ($tiles as $i => [$l, $v, $c]) $pdf->kpiTile(10 + $i * ($tw + 3), $y, $tw, $l, $v, $c);
    $y += 21;

    // Done in period
    $pdf->SetXY(10, $y); $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 5, pdf_text('Done in this period (' . $work['touched'] . ' students worked on)'), 0, 1);
    $done = [];
    foreach ($work['counts'] as $k => $v) if ($v) $done[] = $k . ': ' . $v;
    $y = $pdf->drawChips($pdf->chipRows($done ?: ['Nothing recorded in this period'], 190), 10, $pdf->GetY() + 1, [220, 252, 231], [21, 128, 61]);

    // Pending by type
    $types = ['Documents' => $sum['docs'], 'Consent' => $sum['consent'], 'Meetings' => $sum['meetings'], 'Tax meeting' => $sum['second_tax'], 'Shortlisting' => $sum['shortlisting'], 'Email / portal' => $sum['credentials']];
    $pdf->SetXY(10, $y + 1); $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 5, pdf_text('Pending by type (students)'), 0, 1);
    $y = $pdf->drawChips($pdf->chipRows(array_map(fn($k, $v) => "$k: $v", array_keys($types), $types), 190), 10, $pdf->GetY() + 1, [254, 243, 199], [146, 64, 14]);

    // Student cards
    $pending = array_values(array_filter($rows, fn($r) => $r['_p']['any']));
    usort($pending, fn($a, $b) => [($b['_pending_hours'] ?? -1), count($b['_p']['task_keys'])] <=> [($a['_pending_hours'] ?? -1), count($a['_p']['task_keys'])]);
    $pdf->SetXY(10, $y + 2); $pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(15, 93, 158);
    $pdf->Cell(0, 6, pdf_text('Students still pending (' . count($pending) . ')'), 0, 1);
    $y = $pdf->GetY() + 1;

    $innerW = 182;
    foreach ($pending as $i => $r) {
        $p = $r['_p'];
        $items = [];
        foreach ($p['task_keys'] as $k) if ($k !== 'docs') $items[] = PENDING_TASKS[$k] . ($k === 'remarks' && $p['remark_hours'] !== null ? ' (' . $p['remark_hours'] . 'h)' : '');
        $itemRows = $pdf->chipRows($items, $innerW);
        $docRows = $p['docs'] ? $pdf->chipRows($p['docs_missing'], $innerW - 26) : [];
        $remark = trim((string)($r['last_remark_text'] ?? ''));
        $remark = $remark !== '' ? mb_strimwidth(preg_replace('/\s+/', ' ', $remark), 0, 150, '...') : '';
        $h = 13 + count($itemRows) * 5.6 + ($docRows ? count($docRows) * 5.6 + 1 : 0) + ($remark !== '' ? 5 : 0) + 2;
        if ($y + $h > 280) { $pdf->AddPage(); $y = $pdf->GetY() + 1; }

        $pdf->SetDrawColor(226, 232, 240); $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(10, $y, 190, $h, 2.5, 'FD');
        $tone = ($r['_pending_hours'] ?? 0) >= 48 ? [220, 38, 38] : ($p['docs'] ? [234, 88, 12] : [217, 119, 6]);
        $pdf->SetFillColor(...$tone); $pdf->Rect(10, $y + 2, 1.2, $h - 4, 'F');

        // header line
        $pdf->SetXY(14, $y + 2.5); $pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(110, 5, pdf_text(($i + 1) . '. ' . $r['name']), 0, 0);
        $pdf->SetFont('Arial', '', 7.5); $pdf->SetTextColor(100, 116, 139);
        $meta = trim(($r['country'] ?: '-') . ' | ' . ($r['program_level'] ?: '-') . ' | FE ' . (($r['fe_user_name'] ?: $r['frontend_officer_name']) ?: '-') . ' | BE ' . ($r['officer_name'] ?: '-'));
        $pdf->Cell(0, 5, pdf_text($meta), 0, 1, 'R');
        $cy = $y + 9;
        if ($itemRows) $cy = $pdf->drawChips($itemRows, 14, $cy, [254, 243, 199], [146, 64, 14]);
        if ($docRows) {
            $pdf->SetXY(14, $cy + 0.2); $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetTextColor(185, 28, 28);
            $pdf->Cell(26, 4.4, pdf_text('Docs (' . $p['docs_count'] . '):'), 0, 0);
            $cy = $pdf->drawChips($docRows, 40, $cy, [254, 226, 226], [185, 28, 28]);
        }
        if ($remark !== '') {
            $pdf->SetXY(14, $cy); $pdf->SetFont('Arial', 'I', 7.5); $pdf->SetTextColor(71, 85, 105);
            $pdf->Cell($innerW, 4.5, pdf_text('Last remark: ' . $remark . (!empty($r['last_remark_author_name']) ? ' - ' . $r['last_remark_author_name'] : '')), 0, 0);
        }
        $y += $h + 2.5;
    }
    if (!$pending) {
        $pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(5, 150, 105);
        $pdf->Cell(0, 8, pdf_text('Nothing pending - every assigned student is clear.'), 0, 1);
    }
    return $pdf->Output('S');
}
