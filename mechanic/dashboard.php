<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_mechanic()) {
    redirect('admin/dashboard.php');
}

$u = current_user();
$mechId = (int) $u['mechanic_id'];

$myVehicles = fetch_all(
    "SELECT v.*, c.first_name, c.last_name,
            (SELECT problem_description FROM inspections i WHERE i.vehicle_id = v.id ORDER BY i.id DESC LIMIT 1) problem,
            a.priority
     FROM vehicles v
     JOIN clients c ON c.id = v.client_id
     LEFT JOIN (
        SELECT vehicle_id, MAX(id) mid FROM vehicle_assignments GROUP BY vehicle_id
     ) lm ON lm.vehicle_id = v.id
     LEFT JOIN vehicle_assignments a ON a.id = lm.mid
     WHERE v.mechanic_id = ? AND v.status IN ('en_reparation','repare')
     ORDER BY v.status, v.entry_date",
    [$mechId]
);

$doneThisMonth = (int) fetch_one(
    "SELECT COUNT(*) c FROM repairs WHERE mechanic_id = ? AND status = 'terminee' AND end_date >= DATE_FORMAT(NOW(), '%Y-%m-01')",
    [$mechId]
)['c'];

$settings = att_settings();
$myPunches = today_punches((int) $u['id']);
$todayCalc = compute_day($myPunches, date('Y-m-d'), $settings);
$presence = presence_status((int) $u['id']);
$check = clock_check((int) $u['id']);

$pageTitle = t('mech.dashboard');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-speedometer2 me-2 text-danger"></i><?= e(t('mech.dashboard')) ?> — <?= e($u['full_name']) ?></h1>
    <div class="subtitle"><?= e(fmt_date_long(date('Y-m-d'))) ?></div>
  </div>
</div>

<!-- Cartes -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-blue"><i class="bi bi-car-front"></i></span>
      <div><div class="stat-value"><?= count(array_filter($myVehicles, fn($v) => $v['status'] === 'en_reparation')) ?></div>
      <div class="stat-label"><?= e(t('status.en_reparation')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-green"><i class="bi bi-check2-circle"></i></span>
      <div><div class="stat-value"><?= $doneThisMonth ?></div>
      <div class="stat-label"><?= e(t('repairs.done')) ?> — <?= e(fmt_month_year(date('Y-m-d'))) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-orange"><i class="bi bi-stopwatch"></i></span>
      <div><div class="stat-value"><?= fmt_duration($todayCalc['worked']) ?></div>
      <div class="stat-label"><?= e(t('att.today_total')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon <?= $todayCalc['delay'] > 0 ? 'bg-soft-red' : 'bg-soft-green' ?>"><i class="bi bi-<?= $todayCalc['delay'] > 0 ? 'alarm' : 'check2' ?>"></i></span>
      <div><div class="stat-value" style="font-size:1.1rem;"><?= $todayCalc['delay'] > 0 ? e(fmt_duration($todayCalc['delay'])) : e(t('att.no_delay')) ?></div>
      <div class="stat-label"><?= e(t('att.delay')) ?></div></div>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><i class="bi bi-car-front me-2"></i><?= e(t('mech.my_vehicles')) ?></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th><?= e(t('nav.my_vehicles')) ?></th><th><?= e(t('common.client')) ?></th>
              <th><?= e(t('mech.problem')) ?></th><th><?= e(t('mech.entry')) ?></th>
              <th><?= e(t('mech.priority')) ?></th><th><?= e(t('common.status')) ?></th>
              <th class="text-end"><?= e(t('common.actions')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$myVehicles): ?>
              <tr><td colspan="7" class="text-center text-muted py-4"><?= e(t('mech.no_vehicles')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($myVehicles as $v): ?>
              <tr>
                <td><a class="fw-semibold text-decoration-none" href="vehicle_view.php?id=<?= (int) $v['id'] ?>"><?= e($v['license_plate']) ?></a>
                  <div class="small text-muted"><?= e(trim($v['brand'] . ' ' . $v['model'])) ?></div></td>
                <td><?= e(trim($v['first_name'] . ' ' . $v['last_name'])) ?></td>
                <td><span class="text-truncate d-inline-block" style="max-width:200px;" title="<?= e($v['problem'] ?? '') ?>"><?= e(mb_strimwidth((string) ($v['problem'] ?? ''), 0, 45, '…')) ?></span></td>
                <td class="text-nowrap"><?= fmt_date($v['entry_date']) ?></td>
                <td><?= $v['priority'] ? priority_badge($v['priority']) : '—' ?></td>
                <td><?= status_badge($v['status']) ?></td>
                <td class="text-end">
                  <a class="btn btn-outline-primary" href="vehicle_view.php?id=<?= (int) $v['id'] ?>"><i class="bi bi-eye"></i> <?= e(t('common.view')) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <!-- Pointage express -->
    <div class="card">
      <div class="card-header"><i class="bi bi-clock me-2"></i><?= e(t('att.my_attendance')) ?></div>
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
            <form method="post" action="attendance.php">
              <?= csrf_field() ?>
              <input type="hidden" name="clock_type" value="IN">
              <button class="btn btn-clock btn-clock-in"><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
            </form>
            <button class="btn btn-clock btn-clock-out" disabled><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
          <?php else: ?>
            <button class="btn btn-clock btn-clock-in" disabled><i class="bi bi-box-arrow-in-left me-1"></i><?= e(t('att.entry')) ?></button>
            <form method="post" action="attendance.php">
              <?= csrf_field() ?>
              <input type="hidden" name="clock_type" value="OUT">
              <button class="btn btn-clock btn-clock-out"><i class="bi bi-box-arrow-right me-1"></i><?= e(t('att.exit')) ?></button>
            </form>
          <?php endif; ?>
        </div>
        <?= banner('info', $check[2] . ' ' . t('att.clock_hint'), null, [], null, false, 'app-banner--sm mt-3') ?>
        <div class="small text-muted mt-3">
          <?= e(sprintf(t('att.worked_today'), fmt_duration($todayCalc['worked']))) ?>
        </div>
        <a href="attendance.php" class="btn btn-sm btn-outline-primary w-100 mt-2"><i class="bi bi-calendar-week me-1"></i><?= e(t('att.week')) ?></a>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><i class="bi bi-clock-history me-2"></i><?= e(t('mech.my_repairs')) ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0">
          <?php
          $doneList = fetch_all(
              "SELECT r.*, v.license_plate FROM repairs r JOIN vehicles v ON v.id = r.vehicle_id
               WHERE r.mechanic_id = ? AND r.status = 'terminee' ORDER BY r.end_date DESC, r.id DESC LIMIT 5",
              [$mechId]
          );
          if (!$doneList): ?>
            <tr><td class="text-muted text-center py-3"><?= e(t('common.no_results')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($doneList as $r): ?>
            <tr>
              <td><a class="text-decoration-none fw-semibold" href="vehicle_view.php?id=<?= (int) $r['vehicle_id'] ?>"><?= e($r['license_plate']) ?></a></td>
              <td class="text-muted small text-end"><?= fmt_date($r['end_date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
