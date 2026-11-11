<?php
declare(strict_types=1);

/**
 * Full backup in one ZIP:
 *   database/portal.sqlite                  exact copy of the whole database (restorable)
 *   spreadsheets/PMS_data.xlsx              Students, Remarks, Payments, Applications, Documents, Journey, Staff
 *   students/<Country>/<Name> (#id)/        each student's files, in a folder with their name:
 *        documents/  uploaded documents (Passport.pdf, CNIC.jpg, …)
 *        consent/    signed consent forms
 *        meeting/    uploaded meeting recording (optional — videos can be large)
 *        info.txt    key details + Google Drive / meeting links
 *   consent_templates/                      the consent form templates
 *   README.txt                              what is inside, counts, files that were missing on the server
 * Files kept in Google Drive stay in Drive; their links are in info.txt and the Documents sheet.
 */

const BACKUP_STORE_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'zip', 'rar', '7z', 'mp4', 'webm', 'mov', 'm4a', 'mp3', 'ogg', 'docx', 'xlsx', 'pptx', 'heic'];

function can_backup(array $u): bool {
    return ($u['role'] ?? '') === 'super_admin' || (($u['role'] ?? '') === 'admin' && user_can($u, 'data.export'));
}

/** Finds a stored file on this server (paths may be relative to the app or absolute). */
function backup_resolve(?string $path, ?string $fallbackDir = null): ?string {
    $path = trim((string)$path);
    if ($path === '' || str_starts_with($path, 'gdrive:')) return null;
    foreach ([$path, ROOT . '/' . ltrim($path, '/\\'), $fallbackDir ? rtrim($fallbackDir, '/\\') . '/' . basename($path) : null] as $c) {
        if ($c && is_file($c) && is_readable($c)) return $c;
    }
    return null;
}
function backup_safe(string $s, int $max = 80): string {
    $s = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $s);
    $s = trim(preg_replace('/\s+/u', ' ', $s), " .");
    return mb_substr($s !== '' ? $s : 'unnamed', 0, $max);
}
function backup_student_dir(array $r): string {
    $country = backup_safe((string)($r['country'] ?: 'No country'), 40);
    return 'students/' . $country . '/' . backup_safe((string)$r['name'], 70) . ' (#' . (int)$r['id'] . ')';
}

/** What a backup would contain (shown on the page before downloading). */
function backup_inventory(): array {
    $inv = ['students' => (int)q('SELECT COUNT(*) FROM students')->fetchColumn(), 'documents' => 0, 'documents_bytes' => 0, 'drive_documents' => 0,
            'consents' => 0, 'consents_bytes' => 0, 'videos' => 0, 'videos_bytes' => 0, 'missing' => 0, 'db_bytes' => 0];
    $count = function (?string $real, string $kind) use (&$inv) {
        if ($real) { $inv[$kind]++; $inv[$kind . '_bytes'] += (int)filesize($real); } else $inv['missing']++;
    };
    foreach (q("SELECT file_path FROM student_documents WHERE file_path IS NOT NULL AND file_path != ''") as $d) {
        if (str_starts_with((string)$d['file_path'], 'gdrive:')) { $inv['drive_documents']++; continue; }
        $count(backup_resolve($d['file_path']), 'documents');
    }
    $cdir = function_exists('secure_consents_storage_dir') ? secure_consents_storage_dir() : null;
    foreach (q('SELECT file_path FROM consent_documents') as $d) $count(backup_resolve($d['file_path'], $cdir), 'consents');
    foreach (q("SELECT meeting_video_path FROM students WHERE meeting_video_path IS NOT NULL AND meeting_video_path != ''") as $d) $count(backup_resolve($d['meeting_video_path']), 'videos');
    $dbPath = (string)(db()->query('PRAGMA database_list')->fetch()['file'] ?? '');
    if ($dbPath && is_file($dbPath)) $inv['db_bytes'] = filesize($dbPath);
    return $inv;
}

function backup_xlsx(): string {
    $names = []; foreach (q('SELECT id, name FROM users') as $x) $names[(int)$x['id']] = $x['name'];
    $sheet = function (array $rows, array $skip = []) {
        if (!$rows) return [['(empty)']];
        $cols = array_values(array_diff(array_keys($rows[0]), $skip));
        $out = [$cols];
        foreach ($rows as $r) { $line = []; foreach ($cols as $c) $line[] = $r[$c] === null ? '' : (string)$r[$c]; $out[] = $line; }
        return $out;
    };
    $students = q('SELECT * FROM students ORDER BY id')->fetchAll();
    foreach ($students as &$s) {
        $s = ['front_end_officer_name' => $names[(int)($s['frontend_officer_id'] ?? 0)] ?? ($s['frontend_officer_name'] ?? ''),
              'back_end_officer_name' => $names[(int)($s['officer_id'] ?? 0)] ?? ''] + $s;
        $s = ['id' => $s['id'], 'name' => $s['name']] + $s;
    }
    unset($s);
    $docs = [];
    foreach (q('SELECT d.*, s.name AS student FROM student_documents d JOIN students s ON s.id = d.student_id ORDER BY d.student_id, d.doc_index') as $d) {
        $fp = (string)$d['file_path'];
        $docs[] = ['student_id' => $d['student_id'], 'student' => $d['student'], 'document' => DOCS[(int)$d['doc_index']] ?? ('#' . $d['doc_index']), 'status' => $d['status'],
                   'file' => str_starts_with($fp, 'gdrive:') ? 'https://drive.google.com/file/d/' . substr($fp, 7) . '/view' : (string)$d['file_name'],
                   'updated_at' => $d['updated_at'], 'updated_by' => $names[(int)$d['updated_by']] ?? ''];
    }
    $remarks = q('SELECT r.student_id, s.name AS student, r.created_at, r.author_name, r.remark_text FROM student_remarks r JOIN students s ON s.id = r.student_id WHERE r.deleted_at IS NULL ORDER BY r.student_id, r.id')->fetchAll();
    $pay = q('SELECT p.student_id, s.name AS student, p.installment_no, p.status, p.amount, p.received_date, p.method, p.note FROM student_payments p JOIN students s ON s.id = p.student_id ORDER BY p.student_id, p.installment_no')->fetchAll();
    $apps = q('SELECT a.*, s.name AS student FROM student_applications a JOIN students s ON s.id = a.student_id ORDER BY a.student_id, a.id')->fetchAll();
    $stages = q('SELECT st.*, s.name AS student FROM student_stages st JOIN students s ON s.id = st.student_id ORDER BY st.student_id')->fetchAll();
    $staff = q("SELECT id, name, username, role, department, email, phone, branch, job_title, active, last_login, created_at FROM users WHERE role != 'student' AND COALESCE(hidden, 0) = 0 ORDER BY role, name")->fetchAll();
    $x = SimpleXLSXGen::fromArray($sheet($students), 'Students')
        ->addSheet($sheet($remarks), 'Remarks')->addSheet($sheet($pay), 'Payments')->addSheet($sheet($apps), 'Applications')
        ->addSheet($sheet($docs), 'Documents')->addSheet($sheet($stages), 'Journey')->addSheet($sheet($staff), 'Staff');
    return $x->output();
}

/** Builds the backup ZIP in a temp file and returns [path, stats]. */
function backup_build(array $u, bool $withVideos): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP zip extension is not installed on the server.');
    @set_time_limit(0);
    $tmpDir = rtrim(sys_get_temp_dir(), '/\\');
    $stamp = date('Y-m-d_Hi');
    $zipPath = $tmpDir . '/pms_backup_' . $stamp . '_' . bin2hex(random_bytes(4)) . '.zip';
    $dbCopy = $tmpDir . '/pms_db_' . bin2hex(random_bytes(4)) . '.sqlite';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the backup file on the server.');
    $root = 'PMS_Backup_' . $stamp . '/';
    $add = function (string $file, string $name) use ($zip, $root) {
        $zip->addFile($file, $root . $name);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, BACKUP_STORE_EXT, true)) $zip->setCompressionName($root . $name, ZipArchive::CM_STORE);   // already compressed: store as-is (fast)
    };
    $used = [];
    $unique = function (string $name) use (&$used) {
        $n = $name; $i = 2;
        while (isset($used[mb_strtolower($n)])) { $n = preg_replace('/(\.[^.\/]+)?$/', ' (' . $i++ . ')$1', $name, 1); }
        $used[mb_strtolower($n)] = true; return $n;
    };
    $stats = ['students' => 0, 'documents' => 0, 'consents' => 0, 'videos' => 0, 'templates' => 0, 'missing' => []];

    // 1. database — a consistent snapshot
    try { db()->exec('VACUUM INTO ' . db()->quote($dbCopy)); }
    catch (Throwable) { $src = (string)(db()->query('PRAGMA database_list')->fetch()['file'] ?? ''); if (!$src || !copy($src, $dbCopy)) throw new RuntimeException('Could not copy the database.'); }
    $zip->addFile($dbCopy, $root . 'database/portal.sqlite');

    // 2. spreadsheets
    $xlsx = backup_xlsx();
    if ($xlsx !== '') $zip->addFromString($root . 'spreadsheets/PMS_data.xlsx', $xlsx);

    // 3. every student's folder
    $names = []; foreach (q('SELECT id, name FROM users') as $x) $names[(int)$x['id']] = $x['name'];
    $docsBy = []; foreach (q('SELECT * FROM student_documents ORDER BY doc_index') as $d) $docsBy[(int)$d['student_id']][] = $d;
    $consBy = []; foreach (q('SELECT * FROM consent_documents ORDER BY id') as $d) $consBy[(int)$d['student_id']][] = $d;
    $cdir = function_exists('secure_consents_storage_dir') ? secure_consents_storage_dir() : null;
    foreach (q('SELECT * FROM students ORDER BY id') as $r) {
        $sid = (int)$r['id']; $dir = backup_student_dir($r); $stats['students']++;
        $driveDocs = [];
        foreach ($docsBy[$sid] ?? [] as $d) {
            $label = backup_safe(DOCS[(int)$d['doc_index']] ?? ('Document ' . $d['doc_index']), 50);
            $fp = (string)$d['file_path'];
            if ($fp === '') continue;
            if (str_starts_with($fp, 'gdrive:')) { $driveDocs[] = $label . ': https://drive.google.com/file/d/' . substr($fp, 7) . '/view'; continue; }
            $real = backup_resolve($fp);
            if (!$real) { $stats['missing'][] = "{$r['name']} (#$sid) — $label: $fp"; continue; }
            $ext = strtolower(pathinfo((string)($d['file_name'] ?: $real), PATHINFO_EXTENSION)) ?: strtolower(pathinfo($real, PATHINFO_EXTENSION));
            $add($real, $unique("$dir/documents/" . sprintf('%02d', (int)$d['doc_index'] + 1) . " $label" . ($ext ? ".$ext" : '')));
            $stats['documents']++;
        }
        foreach ($consBy[$sid] ?? [] as $d) {
            $real = backup_resolve($d['file_path'], $cdir);
            if (!$real) { $stats['missing'][] = "{$r['name']} (#$sid) — consent: " . basename((string)$d['file_path']); continue; }
            $add($real, $unique("$dir/consent/" . backup_safe((string)($d['original_name'] ?: basename($real)), 90)));
            $stats['consents']++;
        }
        if (!empty($r['meeting_video_path'])) {
            $real = backup_resolve($r['meeting_video_path']);
            if (!$real) $stats['missing'][] = "{$r['name']} (#$sid) — meeting recording";
            elseif ($withVideos) { $add($real, $unique("$dir/meeting/" . backup_safe((string)($r['meeting_video_name'] ?: basename($real)), 90))); $stats['videos']++; }
        }
        $info = [
            'Name: ' . $r['name'], 'Portal ID: #' . $sid . ($r['sheet_sr'] ? ' · Sheet SR: ' . $r['sheet_sr'] : ''),
            'Country: ' . ($r['country'] ?: '-') . ' · Level: ' . ($r['program_level'] ?: '-') . ' · Intake: ' . ($r['intake'] ?: '-'),
            'Status: ' . ($r['student_status'] ?: '-'), 'Phone: ' . ($r['contact_number'] ?: '-') . ' · Email: ' . ($r['email'] ?: '-'),
            'Front-end officer: ' . ($names[(int)$r['frontend_officer_id']] ?? ($r['frontend_officer_name'] ?: '-')) . ' · Back-end officer: ' . ($names[(int)$r['officer_id']] ?? '-'),
            'Counselor: ' . ($r['counselor'] ?: '-'), '',
            'Documents kept in: ' . (($r['docs_source'] ?? '') === 'drive' ? 'Google Drive' : (($r['docs_source'] ?? '') === 'upload' ? 'Uploaded to the portal' : 'not chosen')),
            'Google Drive folder: ' . ($r['drive_link'] ?: '-'), 'Meeting link: ' . ($r['meeting_link'] ?: '-'),
        ];
        if ($driveDocs) { $info[] = ''; $info[] = 'Documents linked from Google Drive:'; foreach ($driveDocs as $dd) $info[] = '  ' . $dd; }
        $zip->addFromString($root . "$dir/info.txt", implode("\r\n", $info) . "\r\n");
    }
    // 4. consent templates
    foreach (q('SELECT * FROM consent_templates ORDER BY id') as $t) {
        $real = backup_resolve($t['file_path'], $cdir);
        if (!$real) { $stats['missing'][] = 'Consent template — ' . ($t['title'] ?? $t['id']); continue; }
        $add($real, $unique('consent_templates/' . backup_safe(($t['country'] ?: 'General') . ' - ' . ($t['original_name'] ?: $t['title'] ?: basename($real)), 100)));
        $stats['templates']++;
    }
    // 5. README
    $readme = [
        'ScholarshipHive Edutancy — PMS Portal full backup', 'Made: ' . date('j M Y, H:i') . ' by ' . $u['name'], '',
        'database/portal.sqlite      Complete database (all students, remarks, payments, applications, staff, settings).',
        '                            To restore: replace the portal database with this file (ask your developer).',
        'spreadsheets/PMS_data.xlsx  The same data as Excel sheets: Students, Remarks, Payments, Applications, Documents, Journey, Staff.',
        'students/<Country>/<Name> (#id)/  Each student\'s files: documents/, consent/' . ($withVideos ? ', meeting/' : '') . ' and info.txt (details + Drive links).',
        'consent_templates/          Consent form templates.', '',
        "Students: {$stats['students']} · Uploaded documents: {$stats['documents']} · Signed consents: {$stats['consents']} · Meeting videos: " . ($withVideos ? $stats['videos'] : 'not included (choose "with videos")') . " · Templates: {$stats['templates']}",
        'Files kept in Google Drive are not copied — their links are in each info.txt and in the Documents sheet.',
    ];
    if ($stats['missing']) { $readme[] = ''; $readme[] = 'Files listed in the portal but NOT found on the server (' . count($stats['missing']) . '):'; foreach ($stats['missing'] as $m) $readme[] = '  - ' . $m; }
    $zip->addFromString($root . 'README.txt', implode("\r\n", $readme) . "\r\n");
    if (!$zip->close()) throw new RuntimeException('Could not finish the backup file.');
    @unlink($dbCopy);
    return [$zipPath, $stats];
}

function backup_human(int $bytes): string {
    foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $u => $n) if ($bytes >= $n) return round($bytes / $n, 1) . ' ' . $u;
    return $bytes . ' B';
}
