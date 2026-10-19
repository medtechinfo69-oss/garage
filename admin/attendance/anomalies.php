<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$period = (string) ($_GET['period'] ?? 'month');
if ($period === 'week') {
    $from = monday_of();
    $to = date('Y-m-d');
} else {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
}

/* ---------- POST : notifier les admins ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'notify') {
    verify_csrf();
    $anoms = detect_anomalies($from, $to);
    $cnt = count($anoms);
    notify_admins(
        t('nav.anomalies'),
        $cnt . ' : ' . $from . ' → ' . $to,
        'warning',
        'admin/attendance/anomalies.php?period=' . $period,
        'anomalies_' . $from . '_' . $to
    );
    log_activity('anomalies_notified', 'attendance', null, $cnt . ' anomalies');
    flash('success', t('flash.notification_sent'));
    redirect('admin/attendance/anomalies.php?period=' . $period);
}

$anomalies = detect_anomalies($from, $to);

/* Métadonnées de chaque type d'anomalie : icône, sévérité, libellé, couleur. */
$anomalyMeta = [
    'in_no_out'  => ['bi-box-arrow-in-left', 'danger',  'critical', '#ff6b6b'],
    'out_no_in'  => ['bi-box-arrow-right',   'danger',  'critical', '#ff6b6b'],
    'double_in'  => ['bi-arrow-repeat',      'warning', 'major',    '#fbbf24'],
    'double_out' => ['bi-arrow-repeat',      'warning', 'major',    '#fbbf24'],
    'incomplete' => ['bi-hourglass-split',   'warning', 'major',    '#fbbf24'],
    'forgot'     => ['bi-phone-vibrate',     'neutral', 'minor',    '#93a5bd'],
];
$anomalyOrder = array_keys($anomalyMeta);

/* Répartition par type + nombre de mécaniciens touchés. */
$byType    = array_fill_keys($anomalyOrder, 0);
$affected  = [];
foreach ($anomalies as $a) {
    if (isset($byType[$a['type']])) {
        $byType[$a['type']]++;
    }
    $affected[$a['user_id']] = true;
}
$total      = count($anomalies);
$nbAffected = count($affected);

$pageTitle = t('anom.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-shield-exclamation"></i><?= e(t('anom.title')) ?></h1>
    <div class="subtitle"><?= fmt_date($from) ?> → <?= fmt_date($to) ?></div>
  </div>
  <div class="d-flex gap-2 align-items-center flex-wrap period-actions">
    <div class="period-seg" role="group" aria-label="<?= e(t('anom.title')) ?>">
      <a class="period-seg__btn <?= $period === 'week' ? 'is-active' : '' ?>" href="?period=week">
        <i class="bi bi-calendar-week" aria-hidden="true"></i><?= e(t('anom.period_week')) ?>
      </a>
      <a class="period-seg__btn <?= $period === 'month' ? 'is-active' : '' ?>" href="?period=month">
        <i class="bi bi-calendar-month" aria-hidden="true"></i><?= e(t('anom.period_month')) ?>
      </a>
    </div>
  </div>
</div>

<!-- ===== Bannière de résumé ===== -->
<section class="anom-hero anom-hero--<?= $total === 0 ? 'ok' : ($nbAffected > 3 ? 'alert' : 'watch') ?>" aria-label="<?= e(t('anom.breakdown')) ?>">
  <span class="anom-hero__ico" aria-hidden="true">
    <i class="bi <?= $total === 0 ? 'bi-check2-circle' : 'bi-exclamation-octagon' ?>"></i>
  </span>

  <div class="anom-hero__body">
    <h2 class="anom-hero__title"><?= e($total === 0 ? t('anom.all_clear') : t('anom.needs_action')) ?></h2>
    <p class="anom-hero__sub mb-0">
      <?= e($total === 0 ? t('anom.all_clear_sub') : t('anom.needs_action_sub')) ?>
      <?php if ($nbAffected > 0): ?>
        <span class="d-inline-block ms-1"><?= (int) $nbAffected ?> <?= e(t('anom.affected')) ?></span>
      <?php endif; ?>
    </p>
  </div>

  <div class="anom-hero__kpi">
    <b><?= (int) $total ?></b>
    <span><?= e(t('anom.total')) ?></span>
  </div>

  <?php if ($total > 0): ?>
  <div class="anom-chips">
    <?php foreach ($anomalyOrder as $type):
        [$chipIcon, , , $chipColor] = $anomalyMeta[$type];
        $n = $byType[$type];
        if ($n === 0) { continue; } ?>
      <span class="anom-chip" title="<?= e(t('anom.' . $type)) ?>">
        <span class="dot" style="background: <?= e($chipColor) ?>"></span>
        <i class="bi <?= e($chipIcon) ?>" aria-hidden="true"></i>
        <?= e(t('anom.' . $type)) ?>
        <b><?= (int) $n ?></b>
      </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:52px"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span class="visually-hidden"><?= e(t('anom.severity')) ?></span></th>
          <th><?= e(t('anom.type')) ?></th>
          <th class="text-nowrap"><?= e(t('anom.severity')) ?></th>
          <th><?= e(t('anom.user')) ?></th>
          <th><?= e(t('anom.date')) ?></th>
          <th><?= e(t('anom.detail')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$anomalies): ?>
          <tr>
            <td colspan="6" class="p-0 border-0">
              <div class="empty-state">
                <span class="empty-state__ico empty-state__ico--ok"><i class="bi bi-check2-circle"></i></span>
                <div class="empty-state__title"><?= e(t('anom.all_clear')) ?></div>
                <div class="empty-state__text"><?= e(t('anom.all_clear_sub')) ?></div>
              </div>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($anomalies as $a):
            [$icon, $sev, $sevLabel, $dotColor] = $anomalyMeta[$a['type']] ?? ['bi-question-circle', 'neutral', 'minor', '#93a5bd']; ?>
          <tr class="anom-row--<?= e($sev) ?>">
            <td class="text-center">
              <i class="bi <?= e($icon) ?> anomaly-icon" style="color: <?= e($dotColor) ?>"></i>
            </td>
            <td>
              <span class="anom-type">
                <i class="bi <?= e($icon) ?>" aria-hidden="true" style="color: <?= e($dotColor) ?>"></i>
                <?= e(t('anom.' . $a['type'])) ?>
              </span>
            </td>
            <td><span class="anom-type__badge anom-sev--<?= e($sev) ?>"><?= e(t('anom.' . $sevLabel)) ?></span></td>
            <td><?= e($a['mech']) ?></td>
            <td class="text-nowrap"><?= fmt_date($a['date']) ?></td>
            <td class="text-muted small"><?= $a['detail'] !== '' ? e($a['detail']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
