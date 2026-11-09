<?php
declare(strict_types=1);
// Full backup: database + spreadsheets + every student's files in a folder with their name — one ZIP, one click.
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
require_once APP . '/reporting_service.php';
require_once APP . '/backup.php';
$u = require_role('admin');
if (!can_backup($u)) { http_response_code(403); exit('Only the Super Admin (or an Admin with "Export data" access) can download backups.'); }

if (is_post() && post('action') === 'download') {
    csrf_check();
    $withVideos = post('videos') === '1';
    try {
        [$zipPath, $stats] = backup_build($u, $withVideos);
    } catch (Throwable $e) {
        error_log('backup: ' . $e->getMessage());
        flash('Backup failed: ' . $e->getMessage(), 'bad');
        redirect('admin/backup.php');
    }
    log_activity('backup', null, 'downloaded', null, ['videos' => $withVideos, 'students' => $stats['students'], 'documents' => $stats['documents'], 'missing' => count($stats['missing'])], (int)$u['id']);
    set_setting('LAST_BACKUP', date('Y-m-d H:i') . ' · ' . $u['name']);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="PMS_Backup_' . date('Y-m-d_Hi') . ($withVideos ? '_with_videos' : '') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

$inv = backup_inventory();
$base = $inv['db_bytes'] + $inv['documents_bytes'] + $inv['consents_bytes'];
$last = (string)setting('LAST_BACKUP', '');
page_start('Backup', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel backup-page">
  <div class="panel-h"><div><h1>Backup</h1><p class="muted">Download everything in one ZIP — keep a copy on your computer or Google Drive. <?= $last !== '' ? 'Last backup: <b>' . h($last) . '</b>.' : 'No backup downloaded yet.' ?></p></div></div>

  <div class="kpis backup-kpis">
    <div class="kpi"><div class="l">Students</div><div class="v"><?= number_format($inv['students']) ?></div><div class="s">a folder for each, by name</div></div>
    <div class="kpi"><div class="l">Uploaded documents</div><div class="v"><?= number_format($inv['documents']) ?></div><div class="s"><?= backup_human($inv['documents_bytes']) ?></div></div>
    <div class="kpi"><div class="l">Signed consents</div><div class="v"><?= number_format($inv['consents']) ?></div><div class="s"><?= backup_human($inv['consents_bytes']) ?></div></div>
    <div class="kpi"><div class="l">Meeting videos</div><div class="v"><?= number_format($inv['videos']) ?></div><div class="s"><?= backup_human($inv['videos_bytes']) ?></div></div>
    <div class="kpi"><div class="l">In Google Drive</div><div class="v"><?= number_format($inv['drive_documents']) ?></div><div class="s">links saved, files stay in Drive</div></div>
  </div>

  <div class="backup-actions">
    <form method="post" class="backup-card"><?= csrf_field() ?><input type="hidden" name="action" value="download"><input type="hidden" name="videos" value="0">
      <div><b>⬇ Full backup</b><p class="small muted">Database, Excel sheets, and every student's documents and consent forms in their own folder. About <?= backup_human((int)($base * 1.02) + 200000) ?>.</p></div>
      <button class="btn primary" type="submit" data-busy="Preparing backup…">Download backup</button>
    </form>
    <form method="post" class="backup-card"><?= csrf_field() ?><input type="hidden" name="action" value="download"><input type="hidden" name="videos" value="1">
      <div><b>⬇ Full backup + meeting videos</b><p class="small muted">Everything above plus uploaded meeting recordings. About <?= backup_human((int)(($base + $inv['videos_bytes']) * 1.02) + 200000) ?> — can take longer.</p></div>
      <button class="btn" type="submit" data-busy="Preparing backup…" <?= $inv['videos'] ? '' : 'disabled title="No meeting videos uploaded"' ?>>Download with videos</button>
    </form>
  </div>

  <details class="backup-tree" open><summary><b>What's inside the ZIP</b></summary>
<pre>PMS_Backup_<?= date('Y-m-d_Hi') ?>/
├─ README.txt                      counts + any files that were missing on the server
├─ database/portal.sqlite          the complete database (restorable)
├─ spreadsheets/PMS_data.xlsx      Students · Remarks · Payments · Applications · Documents · Journey · Staff
├─ students/
│   └─ Italy/
│       └─ Ali Raza (#56)/
│           ├─ documents/          01 Passport.pdf, 02 CNIC B-form.jpg, …
│           ├─ consent/            signed consent form
│           ├─ meeting/            recording (with-videos backup)
│           └─ info.txt            details, officers, Google Drive and meeting links
└─ consent_templates/</pre>
  </details>
  <?php if ($inv['missing']): ?><p class="flash warn" style="margin-top:12px"><?= (int)$inv['missing'] ?> file<?= $inv['missing'] === 1 ? ' is' : 's are' ?> listed in the portal but not found on this server — the README inside the backup names them.</p><?php endif; ?>
  <?php if (getenv('VERCEL')): ?><p class="flash warn" style="margin-top:12px">This copy runs on Vercel, where uploaded files and the database are temporary. Download backups from your main (Railway) server.</p><?php endif; ?>
  <p class="small muted" style="margin-top:12px">The backup holds personal data and passwords stored for portals — keep it somewhere private. Every download is recorded in the activity log.</p>
</section>
<script>
document.querySelectorAll('.backup-card').forEach(function (f) {
  f.addEventListener('submit', function () {
    var b = f.querySelector('button[type=submit]'); if (!b) return;
    var t = b.textContent; b.textContent = b.dataset.busy || 'Preparing…'; b.disabled = true;
    setTimeout(function () { b.textContent = t; b.disabled = false; }, 8000);   // the browser shows the download when it is ready
  });
});
</script>
<?php page_end();
