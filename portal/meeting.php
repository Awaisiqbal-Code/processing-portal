<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/layout.php';
require_once __DIR__ . '/app/drive_resolver.php';

$u = current_user();
if (!$u) {
    redirect('student/login.php');
}

$studentId = (int)($_GET['id'] ?? 0);
if ($studentId <= 0) {
    http_response_code(400);
    exit('Invalid student ID.');
}

if ($u['role'] === 'student') {
    if ((int)($u['student_id'] ?? 0) !== $studentId) {
        http_response_code(403);
        exit('Access denied.');
    }
    $s = q("SELECT * FROM students WHERE id = ?", [$studentId])->fetch();
} elseif (is_officer_role($u['role'])) {
    [$w, $p] = scope_sql($u, 'students');
    $s = q("SELECT * FROM students WHERE id = ? AND $w", array_merge([$studentId], $p))->fetch();
} elseif (is_admin_role($u['role'])) {
    $s = q("SELECT * FROM students WHERE id = ?", [$studentId])->fetch();
} else {
    $s = null;
}

if (!$s) {
    http_response_code(404);
    exit('Student record not found or access denied.');
}

// Handle staff updating meeting link directly
if (is_post() && post('action') === 'save_meeting') {
    csrf_check();
    if (!is_staff_role($u['role'])) {
        http_response_code(403);
        exit('Access denied.');
    }
    $newMeeting = post('meeting_link');
    if (safe_link($newMeeting)) {
        q("UPDATE students SET meeting_link = ?, recorded_uploaded = 'Yes', updated_at = ? WHERE id = ?", [$newMeeting, now(), $studentId]);
        log_action("Attached meeting link", $studentId);
        flash("Meeting link saved successfully!", 'ok');
        redirect('meeting.php?id=' . $studentId);
    } else {
        flash("Please provide a valid URL starting with http:// or https://", 'bad');
    }
}

// 1. Check if student already has a direct meeting_link stored in DB
$meetingUrl = trim((string)($s['meeting_link'] ?? ''));
$dbDrive   = trim((string)($s['drive_link'] ?? ''));

// If meeting_link is merely identical to the root documents folder, it is not a specific meeting video
if ($meetingUrl === $dbDrive) {
    $meetingUrl = '';
}

// 2. Check if student's cached drive files contain a video or meeting asset
if ($meetingUrl === '' && !empty($s['drive_files_json'])) {
    $dfj = json_decode((string)$s['drive_files_json'], true);
    if (!empty($dfj['meeting_link']) && $dfj['meeting_link'] !== $dbDrive) {
        $meetingUrl = trim((string)$dfj['meeting_link']);
    } elseif (!empty($dfj['files'])) {
        foreach ($dfj['files'] as $f) {
            $fn = strtolower($f['name'] ?? '');
            $sf = strtolower($f['subfolder'] ?? '');
            if (!empty($f['is_meeting']) || str_ends_with($fn, '.mp4') || str_contains($fn, 'zoom') || str_contains($sf, 'zoom') || str_contains($sf, 'meeting') || str_contains($sf, 'record')) {
                if (!empty($f['id'])) {
                    $meetingUrl = 'https://drive.google.com/file/d/' . $f['id'] . '/view';
                    q("UPDATE students SET meeting_link = ?, recorded_uploaded = 'Yes', updated_at = ? WHERE id = ?", [$meetingUrl, now(), $studentId]);
                    break;
                }
            }
        }
    }
}

// 3. Resolve from Google Drive if still empty
if ($meetingUrl === '') {
    $resolved = resolve_student_drive_assets($studentId);
    $resMeeting = trim((string)($resolved['meeting_link'] ?? ''));
    if ($resMeeting !== '' && $resMeeting !== $dbDrive) {
        $meetingUrl = $resMeeting;
    }
}

// 4. If meetingUrl points to a Google Drive folder, check if a video file exists inside it
if (preg_match('/\/folders\/([a-zA-Z0-9_\-]+)/', $meetingUrl, $m)) {
    $zoomFolderId = $m[1];
    $htmlMap = fetch_drive_folders_multi(['zoom' => $zoomFolderId]);
    $zoomItems = parse_drive_folder_html($htmlMap['zoom'] ?? '');
    foreach ($zoomItems as $zit) {
        if (!$zit['is_folder']) {
            $meetingUrl = 'https://drive.google.com/file/d/' . $zit['id'] . '/view';
            q("UPDATE students SET meeting_link = ?, recorded_uploaded = 'Yes', updated_at = ? WHERE id = ?", [$meetingUrl, $studentId]);
            break;
        }
    }
}

// 5. If a valid meeting link / video exists, redirect to it safely
if ($meetingUrl !== '' && safe_link($meetingUrl)) {
    $target = $meetingUrl;
    if (!empty($_GET['download']) && preg_match('/\/file\/d\/([a-zA-Z0-9_\-]+)/', $meetingUrl, $fm)) {
        $target = 'https://drive.google.com/uc?export=download&id=' . rawurlencode($fm[1]);
    }
    if (!headers_sent()) {
        header('Location: ' . $target);
    } else {
        echo '<script>window.location.replace(' . json_encode($target) . ');</script>';
    }
    exit;
}

// 6. If no recorded meeting exists, render a clean, informative page with zero fatal errors
$portalBase = PORTALS[$u['role']]['home'] ?? 'index.php';
$backUrl = $u['role'] === 'student'
    ? url('student/index.php')
    : url(dirname($portalBase) . '/progress.php?id=' . $studentId);

page_start('Recorded Meeting — ' . $s['name'], $u, PORTALS[$u['role']]['label']);
?>
<section class="panel" style="max-width: 640px; margin: 2rem auto; text-align: center; padding: 2.5rem 1.75rem;">
  <div style="font-size: 2.75rem; margin-bottom: 0.75rem;">🎥</div>
  <h1 style="margin-bottom: 0.5rem; font-size: 1.4rem;">Recorded Meeting Not Uploaded Yet</h1>
  <p class="muted" style="margin-bottom: 1.25rem;">
    No separate Zoom recording or meeting video has been uploaded for <b><?= h($s['name']) ?></b> yet.
  </p>

  <?php if (is_staff_role($u['role'])): ?>
    <div style="margin: 1.5rem 0; background: var(--bg); border: 1px dashed var(--border); border-radius: 8px; padding: 1.25rem; text-align: left;">
      <h3 style="margin: 0 0 0.35rem 0; font-size: 0.95rem;">Attach Meeting Video / Zoom Link:</h3>
      <p class="small muted" style="margin: 0 0 0.75rem 0;">Paste the Google Drive video link or Zoom cloud recording URL:</p>
      <form method="post" action="<?= h(url('meeting.php?id=' . $studentId)) ?>" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_meeting">
        <input type="url" name="meeting_link" placeholder="https://drive.google.com/file/d/... or https://zoom.us/rec/..." required style="flex:1; min-width:240px; font-size:0.85rem;">
        <button class="btn sm primary" type="submit">Save Link</button>
      </form>
    </div>
  <?php endif; ?>

  <div style="display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap; margin-top: 1rem;">
    <a class="btn primary" href="<?= h($backUrl) ?>">← Back to Student Profile</a>
    <?php if (safe_link($s['drive_link'] ?? '')): ?>
      <a class="btn ghost" href="<?= h($s['drive_link']) ?>" target="_blank" rel="noopener noreferrer">📁 Open Student Drive Folder</a>
    <?php endif; ?>
  </div>
</section>
<?php
page_end();
