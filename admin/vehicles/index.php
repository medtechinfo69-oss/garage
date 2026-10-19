<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

/* ---------- Suppression (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $v = fetch_one("SELECT * FROM vehicles WHERE id = ?", [$id]);
    if ($v) {
        execute("DELETE FROM vehicles WHERE id = ?", [$id]);
        log_activity('vehicle_deleted', 'vehicles', $id, t('log.vehicle_deleted', vehicle_label($v)));
        flash('success', t('flash.deleted'));
    }
    redirect('admin/vehicles/index.php');
}

/* ---------- Filtres ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= " AND (v.license_plate LIKE ? OR v.vin LIKE ? OR v.brand LIKE ? OR v.model LIKE ?
                     OR CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.phone LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if (in_array($status, VEHICLE_STATUSES, true)) {
    $where .= " AND v.status = ?";
    $params[] = $status;
}

$total = (int) (fetch_one("SELECT COUNT(*) c FROM vehicles v JOIN clients c ON c.id = v.client_id WHERE $where", $params)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$rows = fetch_all(
    "SELECT v.*, c.first_name, c.last_name, m.full_name AS mech_name
     FROM vehicles v
     JOIN clients c ON c.id = v.client_id
     LEFT JOIN mechanics m ON m.id = v.mechanic_id
     WHERE $where
     ORDER BY v.created_at DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

$pageTitle = t('vehicles.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-car-front me-2 text-danger"></i><?= e(t('vehicles.title')) ?></h1>
    <div class="subtitle"><?= $total ?> <?= e(t('common.of')) ?> <?= $total ?></div>
  </div>
  <a href="<?= e(app_url('admin/vehicles/form.php')) ?>" class="btn btn-danger"><i class="bi bi-plus-lg me-1"></i><?= e(t('vehicles.add')) ?></a>
</div>

<div class="card">
  <div class="card-body">
    <form method="get" class="mb-3 filter-form filter-form--inline">
      <input type="search" name="q" class="form-control form-control-sm filter-search"
             placeholder="<?= e(t('search.placeholder')) ?>" value="<?= e($q) ?>" aria-label="<?= e(t('common.search')) ?>">
      <select name="status" class="form-select form-select-sm filter-status" aria-label="<?= e(t('common.status')) ?>">
        <option value=""><?= e(t('common.all')) ?> — <?= e(t('common.status')) ?></option>
        <?php foreach (VEHICLE_STATUSES as $st): ?>
          <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="d-flex gap-2 filter-actions">
        <button type="submit" class="btn btn-sm btn-primary filter-btn"><i class="bi bi-search me-1" aria-hidden="true"></i><span><?= e(t('common.search')) ?></span></button>
        <a href="<?= e(app_url('admin/vehicles/index.php')) ?>" class="btn btn-sm btn-outline-secondary filter-reset" title="<?= e(t('common.reset')) ?>" aria-label="<?= e(t('common.reset')) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th><th><?= e(t('vehicles.plate')) ?></th><th><?= e(t('vehicles.client')) ?></th>
            <th><?= e(t('common.brand')) ?> / <?= e(t('common.model')) ?></th>
            <th><?= e(t('common.mileage')) ?></th><th><?= e(t('common.entry_date')) ?></th>
            <th><?= e(t('common.mechanic')) ?></th><th><?= e(t('common.status')) ?></th>
            <th class="text-end"><?= e(t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $v): ?>
            <tr>
              <td class="text-muted"><?= (int) $v['id'] ?></td>
              <td class="fw-semibold"><?= e($v['license_plate']) ?></td>
              <td><a class="text-decoration-none" href="../clients/view.php?id=<?= (int) $v['client_id'] ?>"><?= e(trim($v['first_name'] . ' ' . $v['last_name'])) ?></a></td>
              <td><?= e(trim($v['brand'] . ' ' . $v['model'])) ?></td>
              <td><?= $v['mileage'] !== null ? number_format((float) $v['mileage']) . ' km' : '—' ?></td>
              <td><?= fmt_date($v['entry_date']) ?></td>
              <td><?= e($v['mech_name'] ?: '—') ?></td>
              <td><?= status_badge($v['status']) ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-outline-primary" href="view.php?id=<?= (int) $v['id'] ?>" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                <a class="btn btn-outline-warning" href="form.php?id=<?= (int) $v['id'] ?>" title="<?= e(t('common.edit')) ?>"><i class="bi bi-pencil"></i></a>
                <form class="d-inline" method="post" data-confirm="<?= e(t('vehicles.delete_warn')) ?>" data-confirm-title="<?= e(t('common.delete_confirm_title')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger" title="<?= e(t('common.delete')) ?>"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="mt-3">
        <ul class="pagination pagination-sm mb-0 justify-content-center">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
