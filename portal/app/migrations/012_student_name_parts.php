<?php
declare(strict_types=1);

/**
 * Migration 012 — First name + last name for students.
 * The full name (students.name) stays the name used everywhere; first/last are kept beside it so two
 * students with the same full name can be told apart by adjusting either part.
 * Existing names are split at the last space: "Muhammad Ali Khan" -> "Muhammad Ali" + "Khan".
 */
return function (PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(students)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('first_name', $cols, true)) $pdo->exec('ALTER TABLE students ADD COLUMN first_name TEXT NULL');
    if (!in_array('last_name', $cols, true)) $pdo->exec('ALTER TABLE students ADD COLUMN last_name TEXT NULL');
    $up = $pdo->prepare('UPDATE students SET first_name = ?, last_name = ? WHERE id = ?');
    $n = 0;
    foreach ($pdo->query("SELECT id, name FROM students WHERE first_name IS NULL OR first_name = ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $full = trim(preg_replace('/\s+/u', ' ', (string)$r['name']));
        $pos = mb_strrpos($full, ' ');
        $first = $pos === false ? $full : mb_substr($full, 0, $pos);
        $last = $pos === false ? '' : mb_substr($full, $pos + 1);
        $up->execute([$first, $last !== '' ? $last : null, (int)$r['id']]);
        $n++;
    }
    echo "  -> first/last name ready ($n split)\n";
};
