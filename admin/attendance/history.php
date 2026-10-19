<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$mechUsers = active_mechanic_users();
$settings = att_settings();

/* ---------- Export CSV ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['export'] ?? '') === 'csv') {
    $from = (string) ($_GET['from'] ?? date('Y-m-01'));
    $to = (string) ($_GET['to'] ?? date('Y-m-d'));
    $mechFilter = (int) ($_GET['mech_user'] ?? 0);
    $stateFilter = (string) ($_GET['state'] ?? 'all');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pointage_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM UTF-8 pour Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [t('csv.date'), t('csv.mechanic'), t('csv.presence'), t('csv.delay_min'), t('csv.entries'), t('csv.exits'), t('csv.worked_min'), t('csv.break_min'), t('csv.punches')], ',', '"', '\\');
    foreach ($mechUsers as $mu) {
        if ($mechFilter && (int) $mu['id'] !== $mechFilter) continue;
        $byDay = attendance_by_day((int) $mu['id'], $from, $to);
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
            $d = date('Y-m-d', $ts);
            $punches = $byDay[$d] ?? [];
            if (!$punches && $stateFilter === 'all') continue;
            $calc = compute_day($punches, $d, $settings);
            $names = [];
            $inTimes = []; $outTimes = [];
            foreach ($punches as $p) {
                $hm = date('H:i', strtotime($p['clocked_at']));
                if ($p['clock_type'] === 'IN') $inTimes[] = $hm; else $outTimes[] = $hm;
            }
            $present = $calc['n_in'] > 0;
            $row = [
                $d,
                $mu['mech_name'] ?: $mu['full_name'],
                $present ? 'present' : 'absent',
                $calc['delay'],
                implode(' / ', $inTimes),
                implode(' / ', $outTimes),
                intdiv($calc['worked'], 60),
                intdiv($calc['break_time'], 60),
                count($punches),
            ];
            if ($stateFilter === 'present' && !$present) continue;
            if ($stateFilter === 'absent' && $present) continue;
            if ($stateFilter === 'late' && $calc['delay'] <= 0) continue;
            fputcsv($out, $row, ',', '"', '\\');
        }
    }
    fclose($out);
    exit;
}

/* ---------- Filtres ---------- */
$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$mechFilter = (int) ($_GET['mech_user'] ?? 0);
$stateFilter = (string) ($_GET['state'] ?? 'all');

/* ---------- Données ---------- */
$rows = [];
foreach ($mechUsers as $mu) {
    if ($mechFilter && (int) $mu['id'] !== $mechFilter) continue;
    $byDay = attendance_by_day((int) $mu['id'], $from, $to);
    for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
        $d = date('Y-m-d', $ts);
        $punches = $byDay[$d] ?? [];
        $calc = $punches ? compute_day($punches, $d, $settings) : [
            'worked' => 0, 'break_time' => 0, 'delay' => 0, 'incomplete' => 0, 'n_in' => 0, 'n_out' => 0,
        ];
        $present = $calc['n_in'] > 0;
        if ($stateFilter === 'present' && !$present) continue;
        if ($stateFilter === 'absent' && $present) continue;
        if ($stateFilter === 'late' && $calc['delay'] <= 0) continue;
        $inTimes = []; $outTimes = [];
        foreach ($punches as $p) {
            $hm = date('H:i', strtotime($p['clocked_at']));
            if ($p['clock_type'] === 'IN') $inTimes[] = $hm; else $outTimes[] = $hm;
        }
        $rows[] = [
            'date' => $d, 'mech' => $mu['mech_name'] ?: $mu['full_name'],
            'present' => $present, 'delay' => $calc['delay'],
            'ins' => implode(' / ', $inTimes), 'outs' => implode(' / ', $outTimes),
            'worked' => $calc['worked'], 'break' => $calc['break_time'],
            'n' => count($punches), 'incomplete' => $calc['incomplete'],
        ];
    }
}
usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['mech'], $b['mech']));

$exportQuery = http_build_query(['export' => 'csv', 'from' => $from, 'to' => $to, 'mech_user' => $mechFilter, 'state' => $stateFilter]);

$pageTitle = t('att.history_title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-clock-history me-2 text-danger"></i><?= e(t('att.history_title')) ?></h1>
    <div class="subtitle"><?= count($rows) ?></div>
  </div>
  <a class="btn btn-success" href="?<?= e($exportQuery) ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i><?= e(t('att.export_csv')) ?></a>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end filter-form">
      <div class="col-md-2">
        <label class="form-label small"><?= e(t('common.from')) ?></label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" aria-label="<?= e(t('common.from')) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small"><?= e(t('common.to')) ?></label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" aria-label="<?= e(t('common.to')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small"><?= e(t('att.mechanic')) ?></label>
        <select name="mech_user" class="form-select form-select-sm" aria-label="<?= e(t('att.mechanic')) ?>">
          <option value=""><?= e(t('att.all_mechanics')) ?></option>
          <?php foreach ($mechUsers as $mu): ?>
            <option value="<?= (int) $mu['id'] ?>" <?= $mechFilter === (int) $mu['id'] ? 'selected' : '' ?>><?= e($mu['mech_name'] ?: $mu['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small"><?= e(t('common.status')) ?></label>
        <select name="state" class="form-select form-select-sm" aria-label="<?= e(t('common.status')) ?>">
          <option value="all" <?= $stateFilter === 'all' ? 'selected' : '' ?>><?= e(t('att.filter_all')) ?></option>
          <option value="present" <?= $stateFilter === 'present' ? 'selected' : '' ?>><?= e(t('att.filter_present')) ?></option>
          <option value="absent" <?= $stateFilter === 'absent' ? 'selected' : '' ?>><?= e(t('att.filter_absent')) ?></option>
          <option value="late" <?= $stateFilter === 'late' ? 'selected' : '' ?>><?= e(t('att.filter_late')) ?></option>
        </select>
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-primary w-100 filter-btn"><i class="bi bi-funnel me-1" aria-hidden="true"></i><span><?= e(t('common.filter')) ?></span></button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('common.date')) ?></th><th><?= e(t('att.mechanic')) ?></th><th><?= e(t('common.status')) ?></th>
          <th><?= e(t('att.in1')) ?> / <?= e(t('att.in2')) ?></th><th><?= e(t('att.out1')) ?> / <?= e(t('att.out2')) ?></th>
          <th><?= e(t('att.worked')) ?></th><th><?= e(t('att.delay')) ?></th><th><?= e(t('att.break_time')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="text-nowrap fw-semibold"><?= fmt_date($r['date']) ?></td>
            <td><?= e($r['mech']) ?></td>
            <td>
              <?php if ($r['present']): ?>
                <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i><?= e(t('att.present')) ?></span>
                <?php if ($r['incomplete']): ?><span class="badge text-bg-warning ms-1"><?= e(t('att.incomplete')) ?></span><?php endif; ?>
              <?php else: ?>
                <span class="badge text-bg-secondary"><?= e(t('att.absent')) ?></span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($r['ins'] ?: '—') ?></td>
            <td class="small"><?= e($r['outs'] ?: '—') ?></td>
            <td class="fw-semibold"><?= $r['worked'] ? fmt_duration($r['worked']) : '—' ?></td>
            <td>
              <?php if ($r['delay'] > 0): ?>
                <span class="badge text-bg-warning"><?= fmt_duration($r['delay']) ?></span>
              <?php elseif ($r['present']): ?>
                <span class="badge text-bg-success"><i class="bi bi-check"></i></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= $r['break'] ? fmt_duration($r['break']) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
