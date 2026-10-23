<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$counts = [
    'clients'  => (int) fetch_one("SELECT COUNT(*) c FROM clients")['c'],
    'vehicles' => (int) fetch_one("SELECT COUNT(*) c FROM vehicles")['c'],
    'mech'     => (int) fetch_one("SELECT COUNT(*) c FROM mechanics WHERE is_active = 1")['c'],
];

$statusCounts = [];
foreach (VEHICLE_STATUSES as $st) {
    $statusCounts[$st] = (int) fetch_one("SELECT COUNT(*) c FROM vehicles WHERE status = ?", [$st])['c'];
}

// mécaniciens présents / absents aujourd'hui
$mechUsers = active_mechanic_users();
$presentToday = 0;
$presenceRows = [];
foreach ($mechUsers as $mu) {
    $p = presence_status((int) $mu['id']);
    $presenceRows[] = $p;
    if ($p['status'] === 'present' || $p['status'] === 'break') {
        $presentToday++;
    }
}
$absentToday = count($mechUsers) - $presentToday;

// réparations par mois (année en cours : les 12 mois, Jan. → Déc.)
$year = (int) date('Y');
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
    <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
      <span class="stat-icon bg-soft-blue"><i class="bi bi-person-gear"></i></span>
      <div class="flex-grow-1 stat-main">
        <div class="stat-value"><?= $counts['mech'] ?></div>
        <div class="stat-label"><?= e(t('dash.mechanics')) ?></div>
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
    </div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Véhicules par état -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-pie-chart me-2"></i><?= e(t('dash.vehicles_by_status')) ?></div>
      <div class="card-body d-flex flex-column flex-sm-row align-items-center gap-4">
        <svg width="120" height="120" viewBox="0 0 120 120" role="img" aria-label="donut">
          <circle class="donut-track" cx="60" cy="60" r="40" fill="none" stroke="#eef1f5" stroke-width="18"/>
          <?= $segments ?>
          <text class="donut-total" x="60" y="64" text-anchor="middle" font-size="20" font-weight="700" fill="#22303f"><?= $totalV ?></text>
        </svg>
        <div class="chart-legend flex-grow-1"><?= $donutLegend ?></div>
      </div>
    </div>
  </div>
  <!-- Réparations par mois (12 mois de l'année) -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-bar-chart me-2"></i><?= e(t('dash.repairs_by_month')) ?> — <?= $year ?></span>
        <span class="badge text-bg-light border"><?= (int) $totalYear ?></span>
      </div>
      <div class="card-body">
        <div class="repairs-sub text-muted small mb-2"><?= e(fmt_month_year(date('Y-m-01'))) ?> · <?= e(t('dash.repairs_by_month')) ?></div>
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
