<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_mechanic()) {
    redirect('admin/dashboard.php');
}

$u = current_user();
$settings = att_settings();

/* ---------- POST : pointage ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['clock_type'])) {
    verify_csrf();
    $type = (string) ($_POST['clock_type'] ?? '');
    [$ok, $msg] = clock_in_out((int) $u['id'], $type);
    flash($ok ? 'success' : 'warning', $msg);
    redirect('mechanic/attendance.php?' . http_build_query(array_filter(['week' => $_POST['week'] ?? null])));
}

/* ---------- Semaine ---------- */
$week = (string) ($_GET['week'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    $week = monday_of();
}
$days = week_days($week);
$mondayTs = strtotime($week);
$prevWeek = date('Y-m-d', $mondayTs - 7 * 86400);
$nextWeek = date('Y-m-d', $mondayTs + 7 * 86400);

$byDay = attendance_by_day((int) $u['id'], $days[0], $days[6]);

$weekData = [];
$weekTotals = ['worked' => 0, 'delay' => 0, 'break' => 0, 'overtime' => 0];
foreach ($days as $d) {
    $punches = $byDay[$d] ?? [];
    $in1 = null; $out1 = null; $in2 = null; $out2 = null;
    foreach ($punches as $p) {
        $hm = date('H:i', strtotime($p['clocked_at']));
        if ($p['clock_type'] === 'IN') {
            if ($in1 === null) $in1 = $hm; elseif ($in2 === null) $in2 = $hm;
        } else {
            if ($out1 === null) $out1 = $hm; elseif ($out2 === null) $out2 = $hm;
        }
    }
    $dayCalc = compute_day($punches, $d, $settings);
    $weekTotals['worked'] += $dayCalc['worked'];
    $weekTotals['delay'] += $dayCalc['delay'];
    $weekTotals['break'] += $dayCalc['break_time'];
    $weekTotals['overtime'] += $dayCalc['overtime'];
    $weekData[] = [
        'date' => $d, 'in1' => $in1, 'out1' => $out1, 'in2' => $in2, 'out2' => $out2,
        'worked' => $dayCalc['worked'], 'delay' => $dayCalc['delay'], 'break' => $dayCalc['break_time'],
        'incomplete' => $dayCalc['incomplete'], 'n' => count($punches),
    ];
}

$todayPunches = today_punches((int) $u['id']);
$todayCalc = compute_day($todayPunches, date('Y-m-d'), $settings);
$presence = presence_status((int) $u['id']);
$check = clock_check((int) $u['id']);

$pageTitle = t('att.my_attendance');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-clock me-2 text-danger"></i><?= e(t('att.my_attendance')) ?></h1>
    <div class="subtitle"><?= e(sprintf(t('att.week_range'), date('d/m/Y', strtotime($days[0])), date('d/m/Y', strtotime($days[6])))) ?></div>
  </div>
  <div class="d-flex gap-2 week-nav" role="group" aria-label="<?= e(t('att.week')) ?>">
    <a class="btn btn-outline-secondary week-nav-btn" href="?week=<?= e($prevWeek) ?>" aria-label="<?= e(t('att.prev_week')) ?>"><i class="bi bi-chevron-left"></i></a>
    <a class="btn btn-outline-secondary week-nav-today" href="?week=<?= e($week) ?>"><i class="bi bi-calendar-check me-1"></i><span><?= e(t('common.today')) ?></span></a>
    <a class="btn btn-outline-secondary week-nav-btn" href="?week=<?= e($nextWeek) ?>" aria-label="<?= e(t('att.next_week')) ?>"><i class="bi bi-chevron-right"></i></a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-stopwatch me-2"></i><?= e(t('att.title')) ?> — <?= e(t('common.today')) ?></div>
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="presence-dot <?= $presence['status'] ?>"></span>
          <strong>
            <?= e($presence['status'] === 'absent' ? t('att.absent') : ($presence['status'] === 'break' ? t('att.on_break') : t('att.present'))) ?>
            <?php if ($presence['since']): ?><span class="text-muted small">— <?= e(t('att.since')) ?> <?= fmt_time($presence['since']) ?></span><?php endif; ?>
          </strong>
        </div>
        <div class="d-grid gap-2">
          <?php if (!$check[0]): ?>
            <button class="btn btn-clock btn-clock-in" disabled><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
            <button class="btn btn-clock btn-clock-out" disabled><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
          <?php elseif ($check[1] === 'IN'): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="clock_type" value="IN">
              <input type="hidden" name="week" value="<?= e($week) ?>">
              <button class="btn btn-clock btn-clock-in"><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
            </form>
            <button class="btn btn-clock btn-clock-out" disabled><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
          <?php else: ?>
            <button class="btn btn-clock btn-clock-in" disabled><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="clock_type" value="OUT">
              <input type="hidden" name="week" value="<?= e($week) ?>">
              <button class="btn btn-clock btn-clock-out"><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
            </form>
          <?php endif; ?>
        </div>
        <?= banner('info', $check[2] . ' ' . t('att.clock_hint'), null, [], null, false, 'app-banner--sm mt-3') ?>
        <table class="table table-sm mb-0 small">
          <tr><th class="text-muted"><?= e(t('att.today_total')) ?></th><td class="fw-bold"><?= fmt_duration($todayCalc['worked']) ?><?= $todayCalc['incomplete'] ? ' <span class="badge text-bg-warning">' . e(t('att.incomplete')) . '</span>' : '' ?></td></tr>
          <tr><th class="text-muted"><?= e(t('att.delay')) ?></th>
              <td><?= $todayCalc['delay'] > 0 ? '<span class="badge text-bg-warning">' . fmt_duration($todayCalc['delay']) . '</span>' : '<span class="badge text-bg-success">' . e(t('att.no_delay')) . '</span>' ?></td></tr>
          <tr><th class="text-muted"><?= e(t('att.break_time')) ?></th><td><?= $todayCalc['break_time'] ? fmt_duration($todayCalc['break_time']) : '—' ?></td></tr>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><i class="bi bi-calendar-week me-2"></i><?= e(t('att.week')) ?></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th><?= e(t('att.day')) ?></th><th><?= e(t('att.date')) ?></th>
              <th><?= e(t('att.in1')) ?></th><th><?= e(t('att.out1')) ?></th>
              <th><?= e(t('att.in2')) ?></th><th><?= e(t('att.out2')) ?></th>
              <th><?= e(t('att.today_total')) ?></th><th><?= e(t('att.delay')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($weekData as $wd): ?>
              <tr class="<?= $wd['date'] === date('Y-m-d') ? 'table-primary' : '' ?>">
                <td class="fw-semibold"><?= e(day_name((int) date('N', strtotime($wd['date'])))) ?></td>
                <td><?= fmt_date($wd['date']) ?></td>
                <td><?= e($wd['in1'] ?? '—') ?></td>
                <td><?= e($wd['out1'] ?? '—') ?></td>
                <td><?= e($wd['in2'] ?? '—') ?></td>
                <td><?= e($wd['out2'] ?? '—') ?></td>
                <td class="fw-semibold"><?= $wd['n'] ? fmt_duration($wd['worked']) . ($wd['incomplete'] ? ' <span class="badge text-bg-warning">' . e(t('att.incomplete')) . '</span>' : '') : '—' ?></td>
                <td>
                  <?php if ($wd['delay'] > 0): ?>
                    <span class="badge text-bg-warning"><?= fmt_duration($wd['delay']) ?></span>
                  <?php elseif ($wd['n']): ?>
                    <span class="badge text-bg-success"><i class="bi bi-check"></i></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light fw-bold">
              <th colspan="6" class="text-end"><?= e(t('common.total')) ?></th>
              <th><?= fmt_duration($weekTotals['worked']) ?></th>
              <th><?= $weekTotals['delay'] ? fmt_duration($weekTotals['delay']) : '—' ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="card-footer bg-white small text-muted">
        <?= e(t('att.expected')) ?> : <strong><?= e($settings['expected_in']) ?></strong> ·
        <?= e(t('att.normal_hours')) ?> : <strong><?= e(number_format($settings['normal_hours'], 0, '.', '')) ?>h</strong> ·
        <?= e(t('att.overtime')) ?> : <strong><?= fmt_duration($weekTotals['overtime']) ?></strong>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
