<?php
declare(strict_types=1);

/**
 * Migration 015 — Countries (processes) managed in the portal.
 * The Super Admin can add a new country (e.g. Spain) from Admin → Countries: it then shows up in
 * Add student, the sheet switch, PMS Lead / officer country access, dashboards and programs.
 * Each country says which existing sheet layout its student form uses, and who its default back-end officer is.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS countries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE COLLATE NOCASE,
        code TEXT,
        sheet_format TEXT NOT NULL DEFAULT 'Italy',
        default_officer_id INTEGER,
        color TEXT,
        active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 100,
        created_at TEXT,
        created_by INTEGER
    )");
    $now = gmdate('Y-m-d H:i:s');
    $seed = [
        ['Italy', 'IT', 'Italy', null, '#10B981'],
        ['France', 'FR', 'France', 'muhammad.usman', '#3B82F6'],
        ['Germany', 'DE', 'Germany', 'nabeel', '#F59E0B'],
        ['Czech Republic', 'CZ', 'Czech Republic', 'hamza', '#EF4444'],
        ['Austria', 'AT', 'Austria', 'hamza', '#F43F5E'],
        ['China', 'CN', 'China', 'zeeshan', '#E11D48'],
    ];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO countries (name, code, sheet_format, default_officer_id, color, active, sort_order, created_at) VALUES (?,?,?,?,?,1,?,?)');
    $find = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    foreach ($seed as $i => [$name, $code, $fmt, $officer, $color]) {
        $oid = null;
        if ($officer) { $find->execute([$officer]); $oid = $find->fetchColumn() ?: null; }
        $ins->execute([$name, $code, $fmt, $oid, $color, ($i + 1) * 10, $now]);
    }
    // countries already used by students but not in the list (typed by hand earlier) are kept as active countries too
    foreach ($pdo->query("SELECT DISTINCT TRIM(country) c FROM students WHERE TRIM(COALESCE(country,'')) NOT IN ('', 'Other')")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $ins->execute([$c, null, 'Italy', null, null, 200, $now]);
    }
    echo "  -> countries table ready (" . (int)$pdo->query('SELECT COUNT(*) FROM countries')->fetchColumn() . " countries)\n";
};
