<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

/* ---------- Export CSV ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    $module = (string) ($_GET['module'] ?? '');
    $where = '1=1';
    $params = [];
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND DATE(created_at) >= ?"; $params[] = $from; }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= " AND DATE(created_at) <= ?"; $params[] = $to; }
    if ($module) { $where .= " AND module = ?"; $params[] = $module; }
    $rows = fetch_all("SELECT * FROM activity_logs WHERE $where ORDER BY id DESC LIMIT 5000", $params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="journal_activite.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [t('csv.date'), t('csv.user'), t('csv.action'), t('csv.module'), t('csv.record_id'), t('csv.description'), t('csv.ip')], ',', '"', '\\');
    foreach ($rows as $r) {
        $user = $r['username'] ?? ($r['user_id'] ?? '—');
        $recordId = $r['record_id'] ?? null;
        $description = $r['description'] ?? ($r['details'] ?? '');
        fputcsv($out, [$r['created_at'], $user, $r['action'], $r['module'], $recordId, $description, $r['ip_address']], ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/* ---------- Filtres ---------- */
$module = (string) ($_GET['module'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = '1=1';
$params = [];
if ($module) { $where .= " AND module = ?"; $params[] = $module; }
if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND DATE(created_at) >= ?"; $params[] = $from; }
if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= " AND DATE(created_at) <= ?"; $params[] = $to; }

$total = (int) (fetch_one("SELECT COUNT(*) c FROM activity_logs WHERE $where", $params)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$rows = fetch_all("SELECT * FROM activity_logs WHERE $where ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$modules = fetch_all("SELECT DISTINCT module FROM activity_logs ORDER BY module");

$exportQuery = http_build_query(['export' => 'csv', 'module' => $module, 'from' => $from, 'to' => $to]);

$pageTitle = t('logs.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-journal-text me-2 text-danger"></i><?= e(t('logs.title')) ?></h1>
    <div class="subtitle"><?= $total ?></div>
  </div>
  <a class="btn btn-success" href="?<?= e($exportQuery) ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i><?= e(t('logs.export_csv')) ?></a>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end filter-form">
      <div class="col-md-3">
        <label class="form-label small"><?= e(t('logs.module')) ?></label>
        <select name="module" class="form-select form-select-sm" aria-label="<?= e(t('logs.module')) ?>">
          <option value=""><?= e(t('common.all')) ?></option>
          <?php foreach ($modules as $m): ?>
            <option value="<?= e($m['module']) ?>" <?= $module === $m['module'] ? 'selected' : '' ?>><?= e($m['module']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small"><?= e(t('common.from')) ?></label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" aria-label="<?= e(t('common.from')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small"><?= e(t('common.to')) ?></label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" aria-label="<?= e(t('common.to')) ?>">
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-sm btn-primary w-100 filter-btn"><i class="bi bi-funnel me-1" aria-hidden="true"></i><span><?= e(t('common.filter')) ?></span></button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead>
        <tr>
          <th><?= e(t('common.date')) ?></th><th><?= e(t('logs.user')) ?></th>
          <th><?= e(t('logs.action')) ?></th><th><?= e(t('logs.module')) ?></th>
          <th><?= e(t('logs.record')) ?></th><th>Description</th><th><?= e(t('logs.ip')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <?php $user = $r['username'] ?? ($r['user_id'] ?? '—'); $recordId = $r['record_id'] ?? null; $description = $r['description'] ?? ($r['details'] ?? ''); ?>
          <tr>
            <td class="text-nowrap"><?= fmt_datetime($r['created_at']) ?></td>
            <td><?= e((string) $user) ?></td>
            <td><code><?= e($r['action']) ?></code></td>
            <td><span class="badge text-bg-light border"><?= e($r['module']) ?></span></td>
            <td><?= $recordId ?: '—' ?></td>
            <td><span class="text-truncate d-inline-block" style="max-width:360px;" title="<?= e($description) ?>"><?= e($description) ?></span></td>
            <td class="text-muted"><?= e($r['ip_address']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white">
      <nav>
        <ul class="pagination pagination-sm mb-0 justify-content-center">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= $i ?>&module=<?= urlencode($module) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
