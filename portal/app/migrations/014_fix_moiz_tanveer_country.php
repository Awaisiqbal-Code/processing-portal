<?php
declare(strict_types=1);

/**
 * Migration 014 — Data fix after the 10 Nov sheet merge.
 * "Moiz Tanveer" is an Italy student (Italy Back-End sheet: Farwa, Front-End: Mubeen Fatima, registered 04 Nov).
 * The merge matched him to his older France record, so he showed up as France for the Italy lead.
 * His record is moved to Italy with the Italy officers; his France remarks and history stay on the record.
 * Only touches the record while it is still the France one, so it is safe if someone already fixed it by hand.
 */
return function (PDO $pdo): void {
    $st = $pdo->prepare("SELECT * FROM students WHERE TRIM(name) = 'Moiz Tanveer' AND country = 'France'");
    $st->execute();
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) { echo "  -> Moiz Tanveer already fixed\n"; return; }
    $id = (int)$s['id'];
    $now = gmdate('Y-m-d H:i:s');
    $farwa = (int)$pdo->query("SELECT id FROM users WHERE username = 'farwa'")->fetchColumn();
    $set = ['country' => 'Italy', 'updated_at' => $now];
    $oldOfficer = (int)($s['officer_id'] ?? 0);
    if ($farwa && $oldOfficer !== $farwa) $set['officer_id'] = $farwa;
    if (in_array(strtolower(trim((string)$s['drive_link'])), ['', 'pending'], true)) $set['drive_link'] = 'https://drive.google.com/drive/folders/1jKacHmFEwvpFyKeho40VdT4G_zP4kiqE?usp=drive_link';
    if (strtolower(trim((string)$s['email'])) === 'pending') $set['email'] = null;
    if (in_array(strtolower(trim((string)$s['last_degree'])), ['', 'pending'], true)) $set['last_degree'] = 'BSCS (MS SPM last semester)';
    if (in_array(strtolower(trim((string)$s['gpa'])), ['', 'pending'], true)) $set['gpa'] = '3.32';
    $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
    $pdo->prepare("UPDATE students SET $cols WHERE id = ?")->execute(array_merge(array_values($set), [$id]));

    if (isset($set['officer_id'])) {
        $oldName = $oldOfficer ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . $oldOfficer)->fetchColumn() : null;
        $pdo->prepare('INSERT INTO assignment_history (student_id, field, old_user_id, old_name, new_user_id, new_name, note, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, 'officer_id', $oldOfficer ?: null, $oldName, $farwa, 'Farwa', 'Moved from France to Italy (Italy Back-End sheet)', 1, $now]);
    }
    $pdo->prepare('INSERT INTO student_remarks (student_id, remark_text, author_id, author_name, created_at) VALUES (?,?,?,?,?)')
        ->execute([$id, 'Moved from France to Italy process (Italy officers: Farwa back end, Mubeen Fatima front end). Earlier France remarks are kept above.', 1, 'System', $now]);
    echo "  -> Moiz Tanveer (#$id) moved to Italy\n";
};
