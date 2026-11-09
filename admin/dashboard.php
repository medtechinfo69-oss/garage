<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$counts = [
    'clients'  => (int) fetch_one("SELECT COUNT(*) c FROM clients")['c'],
    'vehicles' => (int) fetch_one("SELECT COUNT(*) c FROM vehicles")['c'],
    'mech'     => (int) fetch_one("SELECT COUNT(*) c FROM mechanics WHERE is_active = 1")['c'],
];

// Une seule requête groupée au lieu d'un COUNT par statut
$statusCounts = array_fill_keys(VEHICLE_STATUSES, 0);
foreach (fetch_all("SELECT status, COUNT(*) c FROM vehicles GROUP BY status") as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int) $row['c'];
    }
}

// mécaniciens présents / absents aujourd'hui et pointages complets
$mechUsers = active_mechanic_users();
$presentToday = 0;
$currentlyWorking = 0;
$onBreak = 0;
$presenceRows = [];

// Pointages d'aujourd'hui : détection des présences du jour et des journées complètes (1 seule requête)
$todayInsByUser = [];
$attendanceComplete = 0;
try {
    $completeIds = [];
    $attRows = fetch_all(
        "SELECT user_id, SUM(clock_type = 'IN') AS ins, SUM(clock_type = 'OUT') AS outs
         FROM attendance WHERE day_date = CURDATE() GROUP BY user_id"
    );
    foreach ($attRows as $r) {
        $uid = (int) $r['user_id'];
        if ((int) $r['ins'] > 0) {
            $todayInsByUser[$uid] = true;
        }
        if ((int) $r['ins'] > 0 && (int) $r['outs'] > 0) {
            $completeIds[$uid] = true;
        }
    }
    $attendanceComplete = count($completeIds);
} catch (Throwable $e) {}

foreach ($mechUsers as $mu) {
    $uid = (int) $mu['id'];
    $p = presence_status($uid);
    $presenceRows[] = $p;
    if (!empty($todayInsByUser[$uid])) {
        $presentToday++;
    }
    if ($p['status'] === 'present') {
        $currentlyWorking++;
    } elseif ($p['status'] === 'break') {
        $onBreak++;
    }
}
$absentToday = max(0, $counts['mech'] - $presentToday);

// Année courante (utilisée plus bas pour le CA et le graphique)
$year = (int) date('Y');

/* ---- Cartes business : factures impayées, CA du mois, pointages incomplets ---- */
// Factures impayées : brouillon/envoyée (rien payé) ou partiellement payée
$unpaid = fetch_one(
    "SELECT COUNT(*) c, COALESCE(SUM(total_ttc - amount_paid), 0) due FROM factures
     WHERE status IN ('brouillon','envoyee','partiellement_payee')"
) ?: ['c' => 0, 'due' => 0];
$unpaidCount = (int) ($unpaid['c'] ?? 0);
$unpaidDue   = (float) ($unpaid['due'] ?? 0);

// CA du mois : somme encaissée (amount_paid) sur les factures du mois courant
$revenueMonth = (float) (fetch_one(
    "SELECT COALESCE(SUM(amount_paid), 0) s FROM factures
     WHERE YEAR(invoice_date) = ? AND MONTH(invoice_date) = ? AND status <> 'annulee'",
    [$year, (int) date('n')]
)['s'] ?? 0);

// Pointages incomplets aujourd'hui : réutilise le détecteur d'anomalies du module Pointage
$anomsToday = detect_anomalies(date('Y-m-d'), date('Y-m-d'));
$attendanceIssues = count($anomsToday);


// réparations par mois (année en cours : les 12 mois, Jan. → Déc.)
$months = [];
for ($m = 1; $m <= 12; $m++) {
    $months[sprintf('%04d-%02d', $year, $m)] = [
        'label' => month_short_name($m),
        'count' => 0,
    ];
}
foreach (fetch_all("SELECT DATE_FORMAT(end_date, '%Y-%m') m, COUNT(*) c FROM repairs
                    WHERE end_date IS NOT NULL AND YEAR(end_date) = ?
                    GROUP BY m", [$year]) as $r) {
    if (isset($months[$r['m']])) {
        $months[$r['m']]['count'] = (int) $r['c'];
    }
}
$maxMonth = max(1, ...array_column($months, 'count'));
$totalYear = array_sum(array_column($months, 'count'));
$curMk = date('Y-m');

/* ---- Donut SVG : véhicules par état ---- */
$donutColors = [
    'initial'       => '#94a3b8',
    'en_cours'      => '#f59e0b',
    'en_reparation' => '#3b82f6',
    'repare'        => '#22c55e',
    'livre'         => '#14b8a6',
    'annule'        => '#ef4444',
];
$totalV = max(1, array_sum($statusCounts));
$segments = '';
$offset = 0;
$donutLegend = '';
$r = 40;
$circ = 2 * M_PI * $r;
foreach (VEHICLE_STATUSES as $st) {
    $v = $statusCounts[$st];
    if ($v > 0) {
        $frac = $v / $totalV;
        $len = $circ * $frac;
        $segments .= '<circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="' . $donutColors[$st]
            . '" stroke-width="18" stroke-dasharray="' . $len . ' ' . ($circ - $len)
            . '" stroke-dashoffset="' . (-$offset) . '" transform="rotate(-90 60 60)"/>';
        $offset += $len;
    }
    $donutLegend .= '<div class="d-flex align-items-center gap-2 small mb-1">'
        . '<span class="dot" style="background:' . $donutColors[$st] . '"></span>'
        . '<span class="flex-grow-1">' . e(status_label($st)) . '</span>'
        . '<strong>' . $v . '</strong></div>';
}

/* ---- Barres : réparations par mois (année en cours) ---- */
$bars = '';
foreach ($months as $mk => $mv) {
    $h = (int) round($mv['count'] / $maxMonth * 100);
    $h = max($mv['count'] > 0 ? 8 : 0, $h); // toujours un minimum visible quand > 0
    $isCur = ($mk === $curMk);
    $tipTitle = $mv['label'] . ' ' . $year . ' : ' . $mv['count'];
    $bars .= '<div class="bar-col' . ($isCur ? ' is-current' : '') . '" tabindex="0" role="img" aria-label="' . e($tipTitle) . '" title="' . e($tipTitle) . '" data-count="' . (int) $mv['count'] . '">'
        . '<div class="bar-tip" aria-hidden="true"><strong>' . (int) $mv['count'] . '</strong><small>' . e($mv['label'] . ' ' . $year) . ($isCur ? ' · ' . e(t('dash.this_month')) : '') . '</small></div>'
        . '<div class="bar-value' . ((int) $mv['count'] === 0 ? ' is-zero' : '') . '">' . $mv['count'] . '</div>'
        . '<div class="bar-track"><div class="bar" style="height:' . $h . '%"></div></div>'
        . '<div class="bar-label">' . e($mv['label']) . '</div></div>';
}

$pageTitle = t('dash.title');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-speedometer2 me-2 text-danger"></i><?= e(t('dash.title')) ?></h1>
    <div class="subtitle"><?= e(fmt_date_long(date('Y-m-d'))) ?></div>
  </div>
</div>

<!-- Cartes statistiques -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-purple"><i class="bi bi-people"></i></span>
      <div><div class="stat-value"><?= $counts['clients'] ?></div><div class="stat-label"><?= e(t('dash.clients')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-gray"><i class="bi bi-car-front"></i></span>
      <div><div class="stat-value"><?= $counts['vehicles'] ?></div><div class="stat-label"><?= e(t('dash.vehicles')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-orange"><i class="bi bi-hourglass-split"></i></span>
      <div><div class="stat-value"><?= $statusCounts['en_cours'] ?></div><div class="stat-label"><?= e(t('dash.pending')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-blue"><i class="bi bi-gear"></i></span>
      <div><div class="stat-value"><?= $statusCounts['en_reparation'] ?></div><div class="stat-label"><?= e(t('dash.in_repair')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-green"><i class="bi bi-wrench"></i></span>
      <div><div class="stat-value"><?= $statusCounts['repare'] ?></div><div class="stat-label"><?= e(t('dash.repaired')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-teal"><i class="bi bi-box-seam"></i></span>
      <div><div class="stat-value"><?= $statusCounts['livre'] ?></div><div class="stat-label"><?= e(t('dash.delivered')) ?></div></div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-4 stat-col-wide">
    <a class="card stat-card h-100 text-decoration-none" href="<?= e(app_url('admin/attendance/index.php')) ?>" title="<?= e(t('att.title')) ?>">
      <div class="card-body d-flex align-items-center gap-3">
        <span class="stat-icon bg-soft-blue"><i class="bi bi-person-gear"></i></span>
        <div class="flex-grow-1 stat-main">
          <div class="stat-value"><?= $counts['mech'] ?></div>
          <div class="stat-label"><?= e(t('dash.mechanics')) ?></div>
          <?php if ($currentlyWorking > 0 || $onBreak > 0): ?>
            <div class="small text-primary fw-semibold">
              <?= $currentlyWorking ?> <?= e(t('att.present')) ?><?= $onBreak > 0 ? ' · ' . $onBreak . ' ' . e(t('att.on_break')) : '' ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="stat-presence">
          <div class="stat-presence-item">
            <div class="stat-value text-success"><?= $presentToday ?></div>
            <div class="stat-label"><?= e(t('dash.present_today')) ?></div>
          </div>
          <div class="stat-presence-item">
            <div class="stat-value text-danger"><?= $absentToday ?></div>
            <div class="stat-label"><?= e(t('dash.absent_today')) ?></div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <a class="card stat-card h-100 text-decoration-none" href="<?= e(app_url('admin/factures/index.php')) ?>">
      <div class="card-body d-flex align-items-center gap-3">
        <span class="stat-icon bg-soft-red"><i class="bi bi-cash-coin"></i></span>
        <div class="flex-grow-1 stat-main">
          <div class="stat-value"><?= $unpaidCount ?></div>
          <div class="stat-label"><?= e(t('dash.unpaid_invoices')) ?></div>
          <?php if ($unpaidCount > 0): ?>
            <div class="small text-danger fw-semibold"><?= e(sprintf(t('dash.unpaid_amount'), money($unpaidDue))) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-green"><i class="bi bi-graph-up-arrow"></i></span>
      <div class="flex-grow-1 stat-main">
        <div class="stat-value text-truncate" title="<?= e(money($revenueMonth)) ?>"><?= e(money($revenueMonth)) ?></div>
        <div class="stat-label"><?= e(t('dash.month_revenue')) ?></div>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <a class="card stat-card h-100 text-decoration-none" href="<?= e(app_url('admin/attendance/anomalies.php')) ?>">
      <div class="card-body d-flex align-items-center gap-3">
        <span class="stat-icon bg-soft-orange"><i class="bi bi-alarm"></i></span>
        <div class="flex-grow-1 stat-main">
          <div class="stat-value"><?= $attendanceIssues ?></div>
          <div class="stat-label"><?= e(t('dash.attendance_issues')) ?></div>
          <?php if ($attendanceIssues > 0): ?>
            <div class="small text-warning fw-semibold"><?= e(t('dash.attendance_issues_hint')) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-teal"><i class="bi bi-clipboard-check"></i></span>
      <div class="flex-grow-1 stat-main">
        <div class="stat-value"><?= $attendanceComplete ?></div>
        <div class="stat-label"><?= e(t('dash.attendance_complete')) ?></div>
        <?php if ($attendanceComplete > 0): ?>
          <div class="small text-success fw-semibold"><?= e(t('dash.attendance_complete_hint')) ?></div>
        <?php endif; ?>
      </div>
    </div></div>
  </div>
</div>

<?php
/* ---- Stacked bar segments for status widget ---- */
$stackedSegments = '';
$statusPills = '';
$sumVehicles = array_sum($statusCounts);
$totalVehicles = max(1, $sumVehicles);
foreach (VEHICLE_STATUSES as $st) {
    $v = $statusCounts[$st];
    $pct = $sumVehicles > 0 ? round($v / $sumVehicles * 100, 1) : 0;
    $col = $donutColors[$st];
    if ($v > 0) {
        $stackedSegments .= '<div class="vsb-seg" style="width:' . $pct . '%;background:' . $col . ';" title="' . e(status_label($st)) . ': ' . $v . ' (' . $pct . '%)"></div>';
    }
    $statusPills .= '<div class="vsp-pill' . ($v === 0 ? ' vsp-zero' : '') . '">'
        . '<span class="vsp-dot" style="background:' . $col . ';"></span>'
        . '<span class="vsp-name">' . e(status_label($st)) . '</span>'
        . '<span class="vsp-count">' . $v . '</span>'
        . '<span class="vsp-pct">' . ($sumVehicles > 0 ? $pct . '%' : '0%') . '</span>'
        . '</div>';
}
if ($stackedSegments === '') {
    $stackedSegments = '<div class="vsb-seg-empty" style="width:100%;" title="' . e(t('dash.no_activity')) . '"></div>';
}

/* ---- SVG trend polyline for repairs chart ---- */
$sparkW = 600; $sparkH = 60;
$monthValues = array_values(array_column($months, 'count'));
$sparkMax = max(1, ...$monthValues);
$sparkPoints = [];
foreach ($monthValues as $i => $val) {
    $x = round($i / 11 * $sparkW, 1);
    $y = round($sparkH - ($val / $sparkMax * ($sparkH - 6)) - 3, 1);
    $sparkPoints[] = $x . ',' . $y;
}
$sparkPolyline = implode(' ', $sparkPoints);
$sparkFill = $sparkPolyline . ' ' . $sparkW . ',' . $sparkH . ' 0,' . $sparkH;

/* ---- Trend vs last month ---- */
$curMonthIdx  = (int) date('n') - 1;
$prevMonthIdx = $curMonthIdx > 0 ? $curMonthIdx - 1 : 0;
$curVal  = $monthValues[$curMonthIdx] ?? 0;
$prevVal = $monthValues[$prevMonthIdx] ?? 0;
$trendPct = $prevVal > 0 ? round(($curVal - $prevVal) / $prevVal * 100) : ($curVal > 0 ? 100 : 0);
$trendUp  = $trendPct >= 0;
$avgMonth = $totalYear > 0 ? round($totalYear / max(1, (int) date('n')), 1) : 0;
?>

<div class="row g-3 mb-4">
  <!-- Véhicules par état — stacked bar redesign -->
  <div class="col-xl-5 col-lg-6">
    <div class="card h-100 dash-status-card">
      <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span class="vsp-header-title"><i class="bi bi-layers me-2 text-danger"></i><?= e(t('dash.vehicles_by_status')) ?></span>
        <span class="badge rounded-pill vsp-total-badge"><?= $sumVehicles ?> <?= e(t('dash.vehicles')) ?></span>
      </div>
      <div class="card-body">
        <!-- Stacked progress bar -->
        <div class="vsb-wrap" role="img" aria-label="<?= e(t('dash.vehicles_by_status')) ?>">
          <div class="vsb-track">
            <?= $stackedSegments ?>
          </div>
        </div>
        <!-- Status pills grid -->
        <div class="vsp-grid">
          <?= $statusPills ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Réparations par mois — immersive analytics card -->
  <div class="col-xl-7 col-lg-6">
    <div class="card h-100 dash-repairs-card">
      <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span><i class="bi bi-bar-chart-line me-2"></i><?= e(t('dash.repairs_by_month')) ?> — <?= $year ?></span>
        <div class="d-flex align-items-center gap-2">
          <?php if ($curMonthIdx > 0): ?>
            <span class="dash-trend <?= $trendUp ? 'trend-up' : 'trend-down' ?>">
              <i class="bi bi-arrow-<?= $trendUp ? 'up' : 'down' ?>-right"></i>
              <?= abs($trendPct) ?>%
            </span>
          <?php endif; ?>
          <span class="badge text-bg-light border fw-semibold"><?= (int) $totalYear ?> <?= e(t('dash.repairs_by_month')) ?></span>
        </div>
      </div>
      <!-- Mini stats strip -->
      <div class="dash-repairs-strip">
        <div class="drs-item">
          <span class="drs-val"><?= $curVal ?></span>
          <span class="drs-label"><?= e(t('dash.this_month')) ?></span>
        </div>
        <div class="drs-sep"></div>
        <div class="drs-item">
          <span class="drs-val"><?= $avgMonth ?></span>
          <span class="drs-label">avg / month</span>
        </div>
        <div class="drs-sep"></div>
        <div class="drs-item">
          <span class="drs-val"><?= (int) $totalYear ?></span>
          <span class="drs-label"><?= $year ?> total</span>
        </div>
        <!-- SVG sparkline trend -->
        <div class="drs-spark ms-auto">
          <svg viewBox="0 0 <?= $sparkW ?> <?= $sparkH ?>" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="sparkGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="<?= $trendUp ? '#CC2A22' : '#94a3b8' ?>" stop-opacity=".25"/>
                <stop offset="100%" stop-color="<?= $trendUp ? '#CC2A22' : '#94a3b8' ?>" stop-opacity="0"/>
              </linearGradient>
            </defs>
            <polygon points="<?= $sparkFill ?>" fill="url(#sparkGrad)"/>
            <polyline points="<?= $sparkPolyline ?>" fill="none" stroke="<?= $trendUp ? '#CC2A22' : '#94a3b8' ?>" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
          </svg>
        </div>
      </div>
      <div class="card-body pt-2">
        <div class="bars-12 d-flex align-items-stretch gap-1 gap-sm-2">
          <?= $bars ?>
        </div>
        <?php if ($totalYear === 0): ?>
          <div class="text-muted small text-center mt-2"><?= e(t('dash.no_activity')) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
