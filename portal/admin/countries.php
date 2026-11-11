<?php
declare(strict_types=1);
// Countries (processes): the Super Admin adds a new country here — it then appears everywhere a country is chosen.
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');
if ($u['role'] !== 'super_admin') { http_response_code(403); exit('Only the Super Admin can manage countries.'); }

$formats = SUPPORTED_COUNTRIES;
$officers = q("SELECT id, name, role FROM users WHERE active = 1 AND role IN ('processing_staff', 'officer', 'pms_lead') ORDER BY name")->fetchAll();
$officerIds = array_map('intval', array_column($officers, 'id'));
$clean = function (array $in) use ($formats, $officerIds): array {
    $code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($in['code'] ?? '')));
    $fmt = in_array($in['sheet_format'] ?? '', $formats, true) ? $in['sheet_format'] : 'Italy';
    $oid = (int)($in['default_officer_id'] ?? 0);
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? $in['color'] : null;
    return [substr($code, 0, 2) ?: null, $fmt, in_array($oid, $officerIds, true) ? $oid : null, $color];
};

if (is_post()) {
    csrf_check();
    $act = post('action');
    if ($act === 'add') {
        $name = trim(preg_replace('/\s+/', ' ', post('name')));
        [$code, $fmt, $oid, $color] = $clean($_POST);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || !preg_match('/^[\p{L} .\'-]+$/u', $name)) {
            flash('Enter a country name (letters only).', 'bad');
        } elseif (strcasecmp($name, 'Other') === 0 || country_row($name)) {
            flash("$name is already in the list.", 'bad');
        } else {
            $sort = (int)q('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM countries')->fetchColumn();
            q('INSERT INTO countries (name, code, sheet_format, default_officer_id, color, active, sort_order, created_at, created_by) VALUES (?,?,?,?,?,1,?,?,?)',
              [$name, $code, $fmt, $oid, $color ?: '#1392CB', $sort, now(), (int)$u['id']]);
            log_activity('country', null, 'added', null, ['name' => $name, 'sheet_format' => $fmt], (int)$u['id']);
            flash("$name added. It now appears in Add student, the sheet switch and in Team → country access.", 'ok');
        }
        redirect('admin/countries.php');
    }
    $row = q('SELECT * FROM countries WHERE id = ?', [(int)post('id')])->fetch();
    if (!$row) { flash('Country not found.', 'bad'); redirect('admin/countries.php'); }
    if ($act === 'save') {
        [$code, $fmt, $oid, $color] = $clean($_POST);
        if (in_array($row['name'], SUPPORTED_COUNTRIES, true)) $fmt = $row['name'];   // built-in countries keep their own sheet
        q('UPDATE countries SET code = ?, sheet_format = ?, default_officer_id = ?, color = COALESCE(?, color) WHERE id = ?', [$code, $fmt, $oid, $color, (int)$row['id']]);
        log_activity('country', null, 'updated', null, ['name' => $row['name']], (int)$u['id']);
        flash($row['name'] . ' saved.', 'ok');
    } elseif ($act === 'toggle') {
        $on = (int)$row['active'] === 1 ? 0 : 1;
        q('UPDATE countries SET active = ? WHERE id = ?', [$on, (int)$row['id']]);
        log_activity('country', null, $on ? 'activated' : 'deactivated', null, ['name' => $row['name']], (int)$u['id']);
        flash($row['name'] . ($on ? ' is active again.' : ' is hidden from new students. Existing students keep it.'), 'ok');
    } elseif ($act === 'delete') {
        $used = (int)q('SELECT COUNT(*) FROM students WHERE LOWER(TRIM(country)) = LOWER(?)', [$row['name']])->fetchColumn();
        if ($used > 0 || in_array($row['name'], SUPPORTED_COUNTRIES, true)) {
            flash($row['name'] . ' has students or is built in, so it can only be hidden, not removed.', 'bad');
        } else {
            q('DELETE FROM countries WHERE id = ?', [(int)$row['id']]);
            log_activity('country', null, 'removed', null, ['name' => $row['name']], (int)$u['id']);
            flash($row['name'] . ' removed.', 'ok');
        }
    }
    redirect('admin/countries.php');
}

$rows = country_rows(true);
$counts = q("SELECT LOWER(TRIM(country)) c, COUNT(*) n FROM students GROUP BY 1")->fetchAll(PDO::FETCH_KEY_PAIR);
$leads = [];
foreach (q("SELECT name, countries FROM users WHERE role = 'pms_lead' AND active = 1")->fetchAll() as $l) {
    foreach ((array)json_decode((string)$l['countries'], true) as $c) $leads[mb_strtolower((string)$c)][] = $l['name'];
}
$officerName = array_column($officers, 'name', 'id');
page_start('Countries', $u, PORTALS[$u['role']]['label']);
?>
<section class="panel">
  <div class="panel-h"><div><h1>Countries</h1><p class="muted">Every country (process) the team works on. A new country shows up in Add student, the sheet switch, PMS Lead and officer country access, dashboards and University Programs.</p></div></div>

  <div class="tbl countries-scroll"><table class="countries-tbl">
    <thead><tr><th>Country</th><th>Students</th><th>PMS Lead</th><th>Student form uses</th><th>Default back-end officer</th><th>Code / colour</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $n = (int)($counts[mb_strtolower((string)$r['name'])] ?? 0); $builtin = in_array($r['name'], SUPPORTED_COUNTRIES, true); $fid = 'cf' . (int)$r['id']; ?>
      <tr class="<?= (int)$r['active'] === 1 ? '' : 'is-off' ?>">
        <td><b><?= country_flag($r['name']) ?> <?= h($r['name']) ?></b><?= (int)$r['active'] === 1 ? '' : ' <span class="pill">Hidden</span>' ?></td>
        <td><?= $n ?></td>
        <td class="small"><?= h(implode(', ', $leads[mb_strtolower((string)$r['name'])] ?? [])) ?: '<span class="muted">—</span>' ?></td>
        <td><?php if ($builtin): ?><span class="small"><?= h($r['name']) ?> sheet</span><?php else: ?>
          <select name="sheet_format" form="<?= $fid ?>"><?php foreach ($formats as $f): ?><option value="<?= h($f) ?>" <?= $r['sheet_format'] === $f ? 'selected' : '' ?>><?= h($f) ?> sheet</option><?php endforeach; ?></select><?php endif; ?></td>
        <td><select name="default_officer_id" form="<?= $fid ?>"><option value="">— none —</option><?php foreach ($officers as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$r['default_officer_id'] === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></td>
        <td class="nowrap"><input name="code" form="<?= $fid ?>" value="<?= h((string)$r['code']) ?>" maxlength="2" style="width:52px;text-transform:uppercase" aria-label="2-letter code">
          <input type="color" name="color" form="<?= $fid ?>" value="<?= h((string)($r['color'] ?: '#1392CB')) ?>" aria-label="Colour"></td>
        <td class="nowrap">
          <form method="post" id="<?= $fid ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm primary">Save</button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost"><?= (int)$r['active'] === 1 ? 'Hide' : 'Show' ?></button></form>
          <?php if (!$builtin && $n === 0): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost danger">Remove</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <div class="panel-h"><div><h2>Add a new country</h2><p class="muted">For example Spain or Hungary. Pick which existing sheet its student form should follow; you can change it later.</p></div></div>
  <form method="post" class="fields country-add">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="f"><span>Country name *</span><input name="name" required maxlength="60" placeholder="e.g. Spain"></label>
    <label class="f"><span>2-letter code</span><input name="code" maxlength="2" placeholder="ES" style="text-transform:uppercase"></label>
    <label class="f"><span>Student form uses</span><select name="sheet_format"><?php foreach ($formats as $f): ?><option value="<?= h($f) ?>"><?= h($f) ?> sheet</option><?php endforeach; ?></select></label>
    <label class="f"><span>Default back-end officer</span><select name="default_officer_id"><option value="">— none —</option><?php foreach ($officers as $o): ?><option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
    <label class="f"><span>Colour</span><input type="color" name="color" value="#1392CB"></label>
    <div class="country-add-btn"><button class="btn primary" type="submit">Add country</button></div>
  </form>
  <p class="small muted" style="margin-top:10px">Next steps after adding: give a PMS Lead or officers this country in <a href="<?= h(url('admin/staff.php')) ?>">Team</a>, and add its consent form in Consent Templates if it has one.</p>
</section>
<?php page_end();
