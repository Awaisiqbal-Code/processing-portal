<?php
declare(strict_types=1);
// PMS Assistant endpoint (JSON). Every signed-in person can use it; what it can look up follows their own access.

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $a, int $code = 200): never { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; };

$u = current_user();
if (!$u) $out(['ok' => false, 'error' => 'Your session ended. Please sign in again.', 'reason' => 'auth'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(['ok' => false, 'error' => 'POST only.'], 405);
csrf_check();
if (!assistant_enabled()) $out(['ok' => false, 'error' => 'The assistant is not set up yet. The Super Admin can add the Gemini API key in Team → System.']);
@set_time_limit(120);

$action = (string)($_POST['action'] ?? '');
switch ($action) {
    case 'history':
        $rows = array_map(fn($r) => ['role' => $r['role'], 'text' => $r['content']], assistant_history((int)$u['id'], 30));
        $out(['ok' => true, 'messages' => $rows, 'name' => explode(' ', trim((string)$u['name']))[0]]);
    case 'chat':
        $out(assistant_chat($u, (string)($_POST['message'] ?? '')));
    case 'transcribe':
        $f = $_FILES['audio'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $out(['ok' => false, 'error' => 'The recording did not upload. Please try again.']);
        $mime = (string)($_POST['mime'] ?? $f['type'] ?? 'audio/webm');
        $out(assistant_transcribe($u, (string)file_get_contents($f['tmp_name']), $mime));
    case 'clear':
        q('DELETE FROM assistant_messages WHERE user_id = ?', [(int)$u['id']]);
        $out(['ok' => true]);
    default:
        $out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
