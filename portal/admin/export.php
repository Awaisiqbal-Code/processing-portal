<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$u = require_role('admin');
log_action('Downloaded student CSV');

$cols = ['id', 'name', 'counselor', 'frontend_officer_name', 'officer_name'];
foreach (FIELD_GROUPS as $fields) foreach ($fields as $k => $f) if (!in_array($k, ['name', 'officer_id', 'frontend_officer_id'], true)) $cols[] = $k;
$officerFilter = get('officer');
$officerName = '';

if ($officerFilter !== '') {
    if ($officerFilter === 'none') {
        $rows = q('SELECT s.*, o.name AS officer_name, COALESCE(fo.name, s.frontend_officer_name) AS frontend_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users fo ON fo.id = s.frontend_officer_id WHERE (s.officer_id IS NULL OR s.officer_id = 0) AND (s.frontend_officer_id IS NULL OR s.frontend_officer_id = 0) ORDER BY s.id')->fetchAll();
        $officerName = 'unassigned-';
    } else {
        $oid = (int)$officerFilter;
        $officerRow = q('SELECT name FROM users WHERE id = ?', [$oid])->fetch();
        if ($officerRow) {
            $officerName = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($officerRow['name'])) . '-';
        }
        $rows = q('SELECT s.*, o.name AS officer_name, COALESCE(fo.name, s.frontend_officer_name) AS frontend_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users fo ON fo.id = s.frontend_officer_id WHERE s.officer_id = ? OR s.frontend_officer_id = ? ORDER BY s.id', [$oid, $oid])->fetchAll();
    }
} else {
    $rows = q('SELECT s.*, o.name AS officer_name, COALESCE(fo.name, s.frontend_officer_name) AS frontend_officer_name FROM students s LEFT JOIN users o ON o.id = s.officer_id LEFT JOIN users fo ON fo.id = s.frontend_officer_id ORDER BY s.id')->fetchAll();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="students-' . $officerName . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $cols);
foreach ($rows as $r) {
    // Prefix cells that start with = + - @ so spreadsheet apps do not run them as formulas.
    fputcsv($out, array_map(fn($c) => preg_match('/^[=+\-@]/', (string)($r[$c] ?? '')) ? "'" . $r[$c] : ($r[$c] ?? ''), $cols));
}
fclose($out);
