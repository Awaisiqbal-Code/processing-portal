<?php
declare(strict_types=1);
// Forms library: the consent / country forms a staff member may use — only their own countries (plus General).
// Expects $u.

$forms = forms_for_user($u);
$mine = user_countries($u);
$byCountry = [];
foreach ($forms as $f) {
    $c = trim((string)$f['country']);
    $byCountry[($c === '' || strtolower($c) === 'general') ? 'General' : $c][] = $f;
}
uksort($byCountry, fn($a, $b) => [$a === 'General', $a] <=> [$b === 'General', $b]);
page_start('Forms', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel">
  <div class="panel-h">
    <div><div class="eyebrow">Forms</div><h1>Forms for your countries</h1>
      <p><?php if ($mine === null): ?>You can see the forms of every country.<?php else: ?>You see the forms for <b><?= h($mine ? implode(', ', $mine) : 'no country yet') ?></b> (from your students<?= extra_countries((int)$u['id']) ? ' and the extra access given to you' : '' ?>) and the General forms. Need another country? Ask the Super Admin.<?php endif; ?></p></div>
  </div>
  <?php if (!$byCountry): ?>
    <div class="empty">No forms uploaded for your countries yet.</div>
  <?php else: foreach ($byCountry as $country => $list): ?>
    <h2 class="form-country"><?= $country === 'General' ? '🌐' : country_flag($country) ?> <?= h($country) ?> <span class="pill sm"><?= count($list) ?></span></h2>
    <div class="form-cards">
      <?php foreach ($list as $f): $ext = strtoupper(pathinfo((string)$f['original_name'], PATHINFO_EXTENSION)); ?>
        <article class="form-card">
          <span class="fc-ext"><?= h($ext ?: 'FILE') ?></span>
          <div class="fc-body"><b><?= h($f['title']) ?></b>
            <div class="small muted"><?= round((int)$f['file_size'] / 1024) ?> KB · updated <?= h(fmt_date(substr((string)$f['created_at'], 0, 10))) ?></div></div>
          <div class="fc-go">
            <a class="btn sm" href="<?= h(url('consent_download.php?type=template&id=' . (int)$f['id'])) ?>" target="_blank">Open</a>
            <a class="btn sm primary" href="<?= h(url('consent_download.php?type=template&id=' . (int)$f['id'] . '&download=1')) ?>">⬇ Download</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endforeach; endif; ?>
</section>
<?php page_end();
