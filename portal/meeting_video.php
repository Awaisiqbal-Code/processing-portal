<?php
declare(strict_types=1);
// Streams a meeting recording uploaded on a student's profile (staff only, scope-checked, supports seeking).

require_once __DIR__ . '/app/bootstrap.php';

$u = current_user();
if (!$u || $u['role'] === 'student') { http_response_code(403); exit('Access denied.'); }

$id = (int)($_GET['id'] ?? 0);
if (is_admin_role($u['role'])) {
    $s = q('SELECT id, meeting_video_path, meeting_video_name FROM students WHERE id = ?', [$id])->fetch();
} else {
    [$w, $p] = scope_sql($u, 's');
    $s = q("SELECT s.id, s.meeting_video_path, s.meeting_video_name FROM students s WHERE s.id = ? AND $w", array_merge([$id], $p))->fetch();
}
if (!$s || empty($s['meeting_video_path'])) { http_response_code(404); exit('Recording not found.'); }

$path = realpath(ROOT . '/' . ltrim((string)$s['meeting_video_path'], '/\\'));
$baseDir = realpath(ROOT . '/uploads/meetings');
if (!$path || !$baseDir || !str_starts_with($path, $baseDir . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit('Recording file is missing on the server.'); }

$size = filesize($path);
$mime = mime_content_type($path) ?: 'application/octet-stream';
if (!preg_match('#^(video|audio)/#', $mime)) $mime = 'application/octet-stream';
$name = preg_replace('/[^\w.\- ]+/u', '_', (string)($s['meeting_video_name'] ?: basename($path)));
$start = 0; $end = $size - 1;

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="' . $name . '"');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));

$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, (int)min(1048576, $left));
    if ($chunk === false) break;
    echo $chunk; flush();
    $left -= strlen($chunk);
}
fclose($fh);
