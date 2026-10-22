<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once APP . '/layout.php';
require_once APP . '/reporting_service.php';

$u = require_role('admin'); // Super admin and admin
$base = 'admin';

$data = get_disabled_and_refunds_data();
$disabledList = $data['disabled'];
$refundList = $data['refunds'];

// Handle Excel or CSV / PDF downloads
$download = strtolower(get('download') ?: '');

if ($download === 'xlsx_disabled') {
    $headers = ['ID', 'Student Name', 'Branch', 'Counselor', 'Status', 'Disabled Reason', 'Disabled By', 'Role', 'Disabled Date'];
    $rows = [];
    foreach ($disabledList as $d) {
        $rows[] = [
            $d['id'],
            $d['name'],
            $d['branch'],
            $d['counselor'],
            $d['student_status'],
            $d['disabled_reason'],
            $d['disabled_by_name'] ?: 'System',
            $d['disabled_by_role'] ? role_label($d['disabled_by_role']) : '—',
            $d['disabled_at'],
        ];
    }
    stream_xlsx_download('Disabled_Students', $headers, $rows, 'Disabled_Students_Report_' . date('Ymd') . '.xlsx');
}

if ($download === 'xlsx_refunds') {
    $headers = ['ID', 'Student Name', 'Branch', 'Counselor', 'Status', 'Policy Tier', 'Is Refunded', 'Refund Date', 'Refund Note'];
    $rows = [];
    foreach ($refundList as $r) {
        $rows[] = [
            $r['id'],
            $r['name'],
            $r['branch'],
            $r['counselor'],
            $r['student_status'],
            $r['refund'],
            !empty($r['is_refunded']) ? 'Yes' : 'No',
            $r['refund_date'],
            $r['refund_note'],
        ];
    }
    stream_xlsx_download('Refund_Records', $headers, $rows, 'Refund_Records_Report_' . date('Ymd') . '.xlsx');
}

$activeTab = get('tab') === 'refunds' ? 'refunds' : 'disabled';

page_start('Disabled & Refund Records', $u, PORTALS[$u['role']]['label']);
?>

<div class="panel-h">
  <div>
    <h1>Disabled &amp; Refund Audit Report</h1>
    <p>Comprehensive audit record of student file deactivations, withdrawal reasons, and refund tier settlements.</p>
  </div>
  <div class="row-actions">
    <?php if ($activeTab === 'disabled'): ?>
      <a class="btn sm primary" href="<?= h(url('admin/disabled_refunds.php?tab=disabled&download=xlsx_disabled')) ?>">⬇ Export Disabled (.xlsx)</a>
    <?php else: ?>
      <a class="btn sm primary" href="<?= h(url('admin/disabled_refunds.php?tab=refunds&download=xlsx_refunds')) ?>">⬇ Export Refunds (.xlsx)</a>
    <?php endif; ?>
  </div>
</div>

<!-- Tabs -->
<div style="display:flex; gap:0.5rem; margin-bottom:1.25rem; border-bottom:1.5px solid var(--border); padding-bottom:0.5rem;">
  <a class="btn sm <?= $activeTab === 'disabled' ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/disabled_refunds.php?tab=disabled')) ?>">
    Disabled Students <span class="pill sm bad" style="margin-left:0.35rem;"><?= count($disabledList) ?></span>
  </a>
  <a class="btn sm <?= $activeTab === 'refunds' ? 'primary' : 'ghost' ?>" href="<?= h(url('admin/disabled_refunds.php?tab=refunds')) ?>">
    Refund Records <span class="pill sm" style="margin-left:0.35rem;"><?= count($refundList) ?></span>
  </a>
</div>

<?php if ($activeTab === 'disabled'): ?>
<section class="panel">
  <div class="panel-h">
    <h2>Deactivated / Disabled Student Accounts (<?= count($disabledList) ?>)</h2>
    <p>All accounts deactivated with required justifications and auditing metadata.</p>
  </div>

  <?php if (empty($disabledList)): ?>
    <div class="empty">No disabled student accounts recorded.</div>
  <?php else: ?>
    <div class="tbl"><table>
      <thead>
        <tr>
          <th>SR#</th>
          <th>Student</th>
          <th>Counselor</th>
          <th>Branch</th>
          <th>Disabled Reason</th>
          <th>Disabled By</th>
          <th>Timestamp</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($disabledList as $d): ?>
          <tr>
            <td class="num"><?= (int)$d['id'] ?></td>
            <td>
              <a href="<?= h(url('admin/student.php?id=' . $d['id'])) ?>"><b><?= h($d['name']) ?></b></a>
              <span class="pill sm muted" style="background:#64748b;color:#fff;margin-left:0.3rem;">Disabled</span>
            </td>
            <td><?= h($d['counselor']) ?></td>
            <td><?= h($d['branch']) ?></td>
            <td style="max-width:280px; font-size:0.85rem; color:var(--bad);">
              <b><?= h($d['disabled_reason'] ?: 'No reason specified') ?></b>
            </td>
            <td>
              <b><?= h($d['disabled_by_name'] ?: 'System / Admin') ?></b>
              <?php if (!empty($d['disabled_by_role'])): ?>
                <div class="small muted"><?= h(role_label($d['disabled_by_role'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="num small"><?= h($d['disabled_at']) ?></td>
            <td>
              <a class="btn sm" href="<?= h(url('admin/student.php?id=' . $d['id'])) ?>">View / Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<?php else: ?>
<section class="panel">
  <div class="panel-h">
    <h2>Student Refund Records (<?= count($refundList) ?>)</h2>
    <p>Overview of student policy tiers (30%, 50%, 90%) and confirmed refund disbursements.</p>
  </div>

  <?php if (empty($refundList)): ?>
    <div class="empty">No refund records recorded.</div>
  <?php else: ?>
    <div class="tbl"><table>
      <thead>
        <tr>
          <th>SR#</th>
          <th>Student</th>
          <th>Counselor</th>
          <th>Branch</th>
          <th>Policy Tier</th>
          <th>Disbursed</th>
          <th>Refund Date</th>
          <th>Refund Note</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($refundList as $r): 
            $isDisbursed = !empty($r['is_refunded']);
        ?>
          <tr>
            <td class="num"><?= (int)$r['id'] ?></td>
            <td>
              <a href="<?= h(url('admin/student.php?id=' . $r['id'])) ?>"><b><?= h($r['name']) ?></b></a>
            </td>
            <td><?= h($r['counselor']) ?></td>
            <td><?= h($r['branch']) ?></td>
            <td>
              <span class="pill <?= ($r['refund'] ?? '') === 'Non-Refundable' ? 'bad' : 'warn' ?>">
                <?= h($r['refund'] ?: 'Not set') ?>
              </span>
            </td>
            <td>
              <span class="pill sm <?= $isDisbursed ? 'bad' : 'ok' ?>">
                <?= $isDisbursed ? '✓ Refunded' : 'Not Refunded' ?>
              </span>
            </td>
            <td class="num small"><?= h(fmt_date($r['refund_date'])) ?></td>
            <td class="small" style="max-width:220px;"><?= h($r['refund_note'] ?: '—') ?></td>
            <td>
              <a class="btn sm" href="<?= h(url('admin/student.php?id=' . $r['id'])) ?>">View / Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php page_end();
