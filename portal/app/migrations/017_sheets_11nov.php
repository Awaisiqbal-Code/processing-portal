<?php
declare(strict_types=1);

/**
 * Migration 017 — Merge of the 11 Nov sheets (Italy Front-End/Back-End dashboards, China, Germany, Czech Republic,
 * Austria, Erasmus 2027). Runs on the live database, so nothing staff entered on the portal is lost:
 *  - new students are added only when no student with the same name and country exists;
 *  - a field is filled only while it is still empty;
 *  - remarks and university applications are added only when the same one is not already there;
 *  - an existing student is touched only if their name still matches the sheet.
 * Adds "Erasmus" as a country (process) for the Erasmus 2027 students; it uses the Germany sheet layout.
 * The plan is in data_017_sheets_11nov.json (built from the sheets and checked name by name, per country).
 */
return function (PDO $pdo): void {
    $plan = json_decode((string)file_get_contents(__DIR__ . '/data_017_sheets_11nov.json'), true);
    if (!is_array($plan)) throw new RuntimeException('017: plan file missing or unreadable');
    $now = gmdate('Y-m-d H:i:s');
    $norm = fn($s) => preg_replace('/[^a-z]/', '', strtolower((string)$s));
    $nt = fn($s) => preg_replace('/\W+/u', '', mb_strtolower((string)$s));

    // Erasmus as a country (process)
    try {
        $pdo->prepare("INSERT OR IGNORE INTO countries (name, code, sheet_format, default_officer_id, color, active, sort_order, created_at) VALUES ('Erasmus', 'EU', 'Germany', NULL, '#2563EB', 1, 70, ?)")->execute([$now]);
    } catch (Throwable) { /* countries table comes from 015 */ }

    $scols = array_column($pdo->query('PRAGMA table_info(students)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $acols = array_column($pdo->query('PRAGMA table_info(student_applications)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $n = ['new' => 0, 'fields' => 0, 'remarks' => 0, 'apps' => 0, 'skipped' => 0];

    foreach ($plan as $op) {
        $sid = $op['sid'] ?? null;
        if ($sid) {
            $row = $pdo->query('SELECT id, name FROM students WHERE id = ' . (int)$sid)->fetch(PDO::FETCH_ASSOC);
            if (!$row || $norm($row['name']) !== $norm($op['name'])) { $n['skipped']++; continue; }   // record changed since: leave it
            $sid = (int)$row['id'];
        } else {
            $st = $pdo->prepare('SELECT id FROM students WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(country, \'\'))) = LOWER(?) LIMIT 1');
            $st->execute([$op['name'], $op['country']]);
            $sid = (int)$st->fetchColumn();
            if (!$sid) {
                $pdo->prepare("INSERT INTO students (name, country, student_status, is_legacy, is_refunded, entered_by, created_at, updated_at, pending_since, last_edited_at) VALUES (?, ?, 'active', 0, 0, 1, ?, ?, ?, ?)")
                    ->execute([$op['name'], $op['country'], $now, $now, $now, $now]);
                $sid = (int)$pdo->lastInsertId();
                $n['new']++;
            }
        }
        foreach ((array)($op['fill'] ?? []) as $f => $v) {
            if (!in_array($f, $scols, true) || $v === null || $v === '') continue;
            $u = $pdo->prepare("UPDATE students SET \"$f\" = ? WHERE id = ? AND (\"$f\" IS NULL OR TRIM(CAST(\"$f\" AS TEXT)) = '')");
            $u->execute([$v, $sid]);
            $n['fields'] += $u->rowCount();
        }
        if (!empty($op['remarks'])) {
            $have = array_map($nt, $pdo->query('SELECT remark_text FROM student_remarks WHERE deleted_at IS NULL AND student_id = ' . $sid)->fetchAll(PDO::FETCH_COLUMN));
            foreach ($op['remarks'] as [$text, $date, $author]) {
                if (in_array($nt($text), $have, true)) continue;
                $ts = $date ? $date . ' 12:00:00' : $now;
                $pdo->prepare('INSERT INTO student_remarks (student_id, remark_text, author_id, author_name, created_at) VALUES (?,?,NULL,?,?)')->execute([$sid, $text, $author, $ts]);
                $pdo->prepare("UPDATE students SET last_remark_at = MAX(COALESCE(last_remark_at, ''), ?), remarks = COALESCE(NULLIF(TRIM(remarks), ''), ?) WHERE id = ?")->execute([$ts, $text, $sid]);
                $have[] = $nt($text); $n['remarks']++;
            }
        }
        foreach ((array)($op['apps'] ?? []) as $a) {
            $d = $pdo->prepare('SELECT 1 FROM student_applications WHERE student_id = ? AND LOWER(TRIM(university)) = LOWER(TRIM(?)) AND LOWER(TRIM(program)) = LOWER(TRIM(?))');
            $d->execute([$sid, $a['university'], $a['program']]);
            if ($d->fetchColumn()) continue;
            $a = array_intersect_key($a, array_flip($acols));
            $a['student_id'] = $sid; $a['created_at'] = $now; $a['updated_at'] = $now;
            $cols = array_keys($a);
            $pdo->prepare('INSERT INTO student_applications (' . implode(',', array_map(fn($c) => "\"$c\"", $cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($a));
            $n['apps']++;
        }
    }
    echo "  -> 11 Nov sheets: {$n['new']} new students, {$n['fields']} fields filled, {$n['remarks']} remarks, {$n['apps']} applications ({$n['skipped']} records changed since and left as they are)\n";
};
