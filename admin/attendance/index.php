<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$mechUsers = active_mechanic_users();
$settings = att_settings();

/* ---------- POST : pointage (pour un mécanicien) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'clock') {
    verify_csrf();
    $targetUser = (int) ($_POST['user_id'] ?? 0);
    $type = (string) ($_POST['clock_type'] ?? '');
    if ($targetUser && in_array($type, ['IN', 'OUT'], true)) {
        [$ok, $msg] = clock_in_out($targetUser, $type);
        flash($ok ? 'success' : 'warning', $msg);
    } else {
        flash('danger', t('error.invalid_input'));
    }
    redirect('admin/attendance/index.php?' . http_build_query(array_filter([
        'week' => $_POST['week'] ?? null, 'mechanic_id' => $_POST['user_id'] ?? null,
    ])));
}

/* ---------- Navigation semaine ---------- */
$week = (string) ($_GET['week'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    $week = monday_of();
}
$days = week_days($week);
$mondayTs = strtotime($week);
$prevWeek = date('Y-m-d', $mondayTs - 7 * 86400);
$nextWeek = date('Y-m-d', $mondayTs + 7 * 86400);

$selectedMech = (int) ($_GET['mechanic_id'] ?? 0);
if (!$selectedMech && $mechUsers) {
    // le sélecteur utilise l'id UTILISATEUR renvoyé par active_mechanic_users() (colonne « id »)
    $selectedMech = (int) $mechUsers[0]['id'];
}
// id user vs id mechanic : le sélecteur utilise user_id
$selectedUser = null;
foreach ($mechUsers as $mu) {
    if ((int) $mu['id'] === $selectedMech) {
        $selectedUser = $mu;
        break;
    }
}

/* ---------- Présence actuelle (tous) ---------- */
$board = [];
foreach ($mechUsers as $mu) {
    $p = presence_status((int) $mu['id']);
    $board[] = ['user' => $mu, 'presence' => $p];
}

/* ---------- Tableau hebdo du mécanicien sélectionné ---------- */
$weekData = [];
$weekTotals = ['worked' => 0, 'delay' => 0, 'break' => 0, 'overtime' => 0];
if ($selectedUser) {
    $byDay = attendance_by_day((int) $selectedUser['id'], $days[0], $days[6]);
    foreach ($days as $d) {
        $punches = $byDay[$d] ?? [];
        // tri par heure (id)
        usort($punches, fn($a, $b) => strcmp($a['id'], $b['id']));
        $times = array_map(fn($p) => date('H:i', strtotime($p['clocked_at'])), $punches);
        $in1 = null; $out1 = null; $in2 = null; $out2 = null;
        foreach ($punches as $i => $p) {
            $hm = date('H:i', strtotime($p['clocked_at']));
            if ($p['clock_type'] === 'IN') {
                if ($in1 === null) $in1 = $hm;
                elseif ($in2 === null) $in2 = $hm;
            } else {
                if ($out1 === null) $out1 = $hm;
                elseif ($out2 === null) $out2 = $hm;
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
}

$pageTitle = t('att.title');
require __DIR__ . '/../../includes/header.php';

?>


<div class="page-title">
  <div>
    <h1><i class="bi bi-clock me-2 text-danger"></i><?= e(t('att.title')) ?></h1>
    <div class="subtitle"><?= e(sprintf(t('att.week_range'), date('d/m/Y', strtotime($days[0])), date('d/m/Y', strtotime($days[6])))) ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap period-actions">
    <div class="period-seg" role="group" aria-label="<?= e(t('att.week')) ?>">
      <a class="period-seg__btn" href="?week=<?= e($prevWeek) ?>&mechanic_id=<?= $selectedMech ?>">
        <i class="bi bi-chevron-left" aria-hidden="true"></i><?= e(t('att.prev_week')) ?>
      </a>
      <a class="period-seg__btn" href="?week=<?= e($nextWeek) ?>&mechanic_id=<?= $selectedMech ?>">
        <?= e(t('att.next_week')) ?><i class="bi bi-chevron-right" aria-hidden="true"></i>
      </a>
    </div>
  </div>
</div>

<!-- Présence actuelle -->
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-activity me-2"></i><?= e(t('att.current_presence')) ?> — <?= e(t('common.today')) ?></div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($board as $b): ?>
        <?php $st = $b['presence']['status']; ?>
        <div class="col-md-4 col-xl-3">
          <div class="border rounded-3 p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <strong><?= e($b['user']['mech_name'] ?: $b['user']['full_name']) ?></strong>
              <span class="presence-dot <?= $st ?>"></span>
            </div>
            <?php if ($st === 'absent'): ?>
              <div class="text-danger small fw-semibold"><i class="bi bi-x-circle me-1"></i><?= e(t('att.absent')) ?></div>
            <?php else: ?>
              <div class="text-<?= $st === 'present' ? 'success' : 'warning' ?> small fw-semibold">
                <i class="bi bi-<?= $st === 'present' ? 'check-circle' : 'cup-hot' ?> me-1"></i>
                <?= e($st === 'present' ? t('att.present') : t('att.on_break')) ?>
                <?php if ($b['presence']['since']): ?>
                  — <?= e(t('att.since')) ?> <?= fmt_time($b['presence']['since']) ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="small text-muted mt-1">
              <?php if ($b['presence']['delay'] > 0): ?>
                <span class="badge text-bg-warning"><?= e(sprintf(t('att.late'), fmt_duration($b['presence']['delay']))) ?></span>
              <?php else: ?>
                <span class="badge text-bg-success"><?= e(t('att.no_delay')) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="mech-title"><i class="bi bi-calendar-week me-2"></i><?= e(t('att.week')) ?> — <?= e($selectedUser['mech_name'] ?? $selectedUser['full_name'] ?? '') ?></span>
        <div class="input-group input-group-sm mech-picker-wrap">
          <span class="input-group-text"><i class="bi bi-person-gear"></i></span>
          <select class="form-select form-select-sm mech-picker" onchange="window.location='?week=<?= e($week) ?>&mechanic_id='+this.value" aria-label="<?= e(t('att.mechanic')) ?>">
          <?php foreach ($mechUsers as $mu): ?>
            <?php $nm = trim((string) ($mu['mech_name'] ?: $mu['full_name'])); ?>
            <option value="<?= (int) $mu['id'] ?>" <?= (int) ($selectedUser['id'] ?? 0) === (int) $mu['id'] ? 'selected' : '' ?> title="<?= e($nm) ?>">
              <?= e($nm) ?>
            </option>
          <?php endforeach; ?>
        </select>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th><?= e(t('att.day')) ?></th><th><?= e(t('att.date')) ?></th>
              <th><?= e(t('att.in1')) ?></th><th><?= e(t('att.out1')) ?></th>
              <th><?= e(t('att.in2')) ?></th><th><?= e(t('att.out2')) ?></th>
              <th><?= e(t('att.today_total')) ?></th><th><?= e(t('att.delay')) ?></th><th><?= e(t('att.break_time')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$selectedUser): ?>
              <tr><td colspan="9" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($weekData as $wd): ?>
              <tr class="<?= $wd['date'] === date('Y-m-d') ? 'table-primary' : '' ?>">
                <td class="fw-semibold"><?= e(day_name((int) date('N', strtotime($wd['date'])))) ?></td>
                <td><?= fmt_date($wd['date']) ?></td>
                <td><?= e($wd['in1'] ?? '—') ?></td>
                <td><?= e($wd['out1'] ?? '—') ?></td>
                <td><?= e($wd['in2'] ?? '—') ?></td>
                <td><?= e($wd['out2'] ?? '—') ?></td>
                <td class="fw-semibold">
                  <?= $wd['n'] ? fmt_duration($wd['worked']) . ($wd['incomplete'] ? ' <span class="badge text-bg-warning">' . e(t('att.incomplete')) . '</span>' : '') : '—' ?>
                </td>
                <td>
                  <?php if ($wd['delay'] > 0): ?>
                    <span class="badge text-bg-warning"><?= fmt_duration($wd['delay']) ?></span>
                  <?php elseif ($wd['n']): ?>
                    <span class="badge text-bg-success"><i class="bi bi-check"></i></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= $wd['break'] ? fmt_duration($wd['break']) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <?php if ($selectedUser): ?>
            <tfoot>
              <tr class="att-total-row fw-bold">
                <th colspan="6" class="text-end"><?= e(t('common.total')) ?></th>
                <th><?= fmt_duration($weekTotals['worked']) ?></th>
                <th><?= $weekTotals['delay'] ? fmt_duration($weekTotals['delay']) : '—' ?></th>
                <th><?= $weekTotals['break'] ? fmt_duration($weekTotals['break']) : '—' ?></th>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
      </div>
      <?php if ($selectedUser): ?>
        <div class="card-footer small text-muted">
          <?= e(t('att.expected')) ?> : <strong><?= e($settings['expected_in']) ?></strong> ·
          <?= e(t('att.overtime')) ?> : <strong><?= fmt_duration($weekTotals['overtime']) ?></strong>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-stopwatch me-2"></i><?= e(t('att.title')) ?></div>
      <div class="card-body">
        <?php if (!$selectedUser): ?>
          <div class="text-muted small"><?= e(t('common.no_results')) ?></div>
        <?php else: ?>
          <p class="mb-2"><strong><?= e($selectedUser['mech_name'] ?: $selectedUser['full_name']) ?></strong>
            <span class="badge text-bg-light border"><?= e(date('d/m/Y H:i')) ?></span></p>
          <?php $chk = clock_check((int) $selectedUser['id']); ?>
          <div class="d-grid gap-2">
            <?php if (!$chk[0]): ?>
              <button type="button" class="btn btn-clock btn-clock-in" disabled><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
              <button type="button" class="btn btn-clock btn-clock-out" disabled><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
            <?php elseif ($chk[1] === 'IN'): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clock">
                <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                <input type="hidden" name="clock_type" value="IN">
                <input type="hidden" name="week" value="<?= e($week) ?>">
                <button type="submit" class="btn btn-clock btn-clock-in"><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
              </form>
              <button type="button" class="btn btn-clock btn-clock-out" disabled><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
            <?php else: ?>
              <button type="button" class="btn btn-clock btn-clock-in" disabled><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clock">
                <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                <input type="hidden" name="clock_type" value="OUT">
                <input type="hidden" name="week" value="<?= e($week) ?>">
                <button type="submit" class="btn btn-clock btn-clock-out"><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
              </form>
            <?php endif; ?>
          </div>
          <?= banner('info', $chk[2] . ' ' . t('att.clock_hint'), null, [], null, false, 'app-banner--sm mt-3 mb-0') ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="mt-3">
      <a href="history.php" class="btn btn-outline-primary w-100"><i class="bi bi-clock-history me-1"></i><?= e(t('att.history')) ?></a>
      <a href="anomalies.php" class="btn btn-outline-warning w-100 mt-2"><i class="bi bi-exclamation-octagon me-1"></i><?= e(t('nav.anomalies')) ?></a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
