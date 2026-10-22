<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP . '/layout.php';
require_once APP . '/services.php';

$u = require_role('admin');

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'upload') {
        $country = trim((string)post('country'));
        $title = trim((string)post('title'));
        $file = $_FILES['template_file'] ?? [];

        $res = upload_consent_template($u, $country !== '' ? $country : null, $title, $file);
        if ($res['ok']) {
            $msg = 'Consent template uploaded successfully.';
        } else {
            $err = $res['error'] ?? 'Failed to upload template.';
        }
    } elseif ($action === 'replace') {
        $tplId = (int)post('template_id');
        $file = $_FILES['replacement_file'] ?? [];

        $res = replace_consent_template($u, $tplId, $file);
        if ($res['ok']) {
            $msg = 'Consent template file replaced successfully.';
        } else {
            $err = $res['error'] ?? 'Failed to replace template.';
        }
    } elseif ($action === 'delete') {
        $tplId = (int)post('template_id');
        $res = delete_consent_template($u, $tplId);
        if ($res['ok']) {
            $msg = 'Consent template deleted.';
        } else {
            $err = $res['error'] ?? 'Failed to delete template.';
        }
    }
}

$templates = q("
    SELECT ct.*, u.name AS uploader_name 
    FROM consent_templates ct 
    LEFT JOIN users u ON u.id = ct.uploaded_by 
    ORDER BY CASE WHEN ct.country IS NULL OR ct.country = '' OR LOWER(ct.country) = 'general' THEN 1 ELSE 0 END, ct.country ASC, ct.title ASC
")->fetchAll();

$distinctCountries = q("SELECT DISTINCT country FROM students WHERE country IS NOT NULL AND country != '' ORDER BY country ASC")->fetchAll(PDO::FETCH_COLUMN);

page_start('Consent Templates Management', $u, PORTALS[$u['role']]['label']);
?>

<div class="panel-h" style="margin-bottom:1.25rem;">
  <div>
    <h1>Consent Templates Management</h1>
    <p>Upload, configure, and maintain official consent form templates per destination country or global general templates.</p>
  </div>
</div>

<?php if ($msg): ?>
  <div class="flash ok" style="margin-bottom:1.25rem;"><?= h($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="flash bad" style="margin-bottom:1.25rem;"><?= h($err) ?></div>
<?php endif; ?>

<!-- Upload New Consent Template -->
<section class="panel" style="margin-bottom:1.5rem;">
  <div class="panel-h">
    <h2>Upload New Consent Template</h2>
    <p>Supported file formats: PDF, DOCX, PNG, JPG (Maximum 10 MB).</p>
  </div>

  <form method="post" enctype="multipart/form-data" class="fields" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; align-items:flex-end;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">

    <label class="f">
      <span>Destination Country</span>
      <input type="text" name="country" list="country-presets" placeholder="e.g. Italy, UK, or leave empty for General">
      <datalist id="country-presets">
        <option value="General">General (Default fallback for all countries)</option>
        <?php foreach ($distinctCountries as $cName): ?>
          <option value="<?= h($cName) ?>"><?= h($cName) ?></option>
        <?php endforeach; ?>
      </datalist>
      <small class="muted">Leave blank or enter 'General' to apply as default fallback</small>
    </label>

    <label class="f">
      <span>Template Title</span>
      <input type="text" name="title" required placeholder="e.g. Italy Student Visa Consent 2026">
    </label>

    <label class="f">
      <span>Template File</span>
      <input type="file" name="template_file" required accept=".pdf,.docx,.jpg,.jpeg,.png">
    </label>

    <div class="f">
      <button class="btn primary" type="submit" style="padding:0.45rem 1.25rem;">Upload Template</button>
    </div>
  </form>
</section>

<!-- Existing Consent Templates -->
<section class="panel">
  <div class="panel-h">
    <h2>Active Consent Templates (<?= count($templates) ?>)</h2>
    <p>Templates will be automatically offered on matching student file workspaces.</p>
  </div>

  <?php if (empty($templates)): ?>
    <div class="empty">No consent templates uploaded yet. Upload a General or Country-specific template above.</div>
  <?php else: ?>
    <div class="tbl"><table>
      <thead>
        <tr>
          <th>Country Scope</th>
          <th>Template Title</th>
          <th>Original File</th>
          <th style="text-align:center;">Size</th>
          <th>Uploaded By</th>
          <th>Date</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($templates as $t): 
          $isGeneral = empty($t['country']) || strtolower($t['country']) === 'general';
        ?>
          <tr>
            <td>
              <?php if ($isGeneral): ?>
                <span class="pill sm" style="background:var(--sunk); font-weight:700;">🌐 General (Fallback)</span>
              <?php else: ?>
                <span class="pill sm acc" style="font-weight:700;">📍 <?= h($t['country']) ?></span>
              <?php endif; ?>
            </td>
            <td><b><?= h($t['title']) ?></b></td>
            <td><code><?= h($t['original_name']) ?></code></td>
            <td style="text-align:center; font-size:0.85rem;"><?= round((int)$t['file_size'] / 1024, 1) ?> KB</td>
            <td><?= h($t['uploader_name'] ?: 'Staff') ?></td>
            <td class="num"><?= h(fmt_date(substr((string)$t['created_at'], 0, 10))) ?></td>
            <td style="text-align:right;">
              <div style="display:inline-flex; gap:0.35rem; align-items:center;">
                <a class="btn sm" href="<?= h(url('consent_download.php?type=template&id=' . (int)$t['id'] . '&download=1')) ?>" title="Download template file">⬇ Download</a>
                
                <!-- Replace File Trigger -->
                <button type="button" class="btn sm ghost" onclick="document.getElementById('replace-form-<?= (int)$t['id'] ?>').style.display = 'block';" title="Replace template file">Replace</button>

                <!-- Delete -->
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete template <?= h($t['title']) ?>?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                  <button type="submit" class="btn sm bad" title="Delete template">Delete</button>
                </form>
              </div>

              <!-- Inline Replacement Form (Hidden by default) -->
              <div id="replace-form-<?= (int)$t['id'] ?>" style="display:none; margin-top:0.5rem; background:var(--sunk); border:1px solid var(--border); border-radius:4px; padding:0.5rem; text-align:left;">
                <form method="post" enctype="multipart/form-data" style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="replace">
                  <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                  <input type="file" name="replacement_file" required accept=".pdf,.docx,.jpg,.jpeg,.png" style="font-size:0.75rem;">
                  <button type="submit" class="btn sm primary">Save New File</button>
                  <button type="button" class="btn sm ghost" onclick="document.getElementById('replace-form-<?= (int)$t['id'] ?>').style.display = 'none';">Cancel</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php page_end(); ?>
