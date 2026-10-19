<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

/* ---------- Suppression (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $ins = fetch_one("SELECT * FROM inspections WHERE id = ?", [$id]);
    if ($ins) {
        execute("DELETE FROM inspections WHERE id = ?", [$id]);
        log_activity('inspection_deleted', 'inspections', $id, t('common.delete') . ' #' . $id);
        flash('success', t('flash.deleted'));
    }
    redirect('admin/inspections/index.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= " AND (i.problem_description LIKE ? OR v.license_plate LIKE ? OR v.vin LIKE ? OR CONCAT(c.first_name,' ',c.last_name) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$total = (int) (fetch_one("SELECT COUNT(*) c FROM inspections i JOIN vehicles v ON v.id = i.vehicle_id JOIN clients c ON c.id = i.client_id WHERE $where", $params)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$rows = fetch_all(
    "SELECT i.*, v.license_plate, v.brand, v.model, v.status veh_status,
            c.first_name, c.last_name, m.full_name mech_name
     FROM inspections i
     JOIN vehicles v ON v.id = i.vehicle_id
     JOIN clients c ON c.id = i.client_id
     LEFT JOIN mechanics m ON m.id = i.mechanic_id
     WHERE $where
     ORDER BY i.id DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

$pageTitle = t('inspections.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-clipboard-check me-2 text-danger"></i><?= e(t('inspections.title')) ?></h1>
    <div class="subtitle"><?= $total ?></div>
  </div>
  <a href="form.php" class="btn btn-danger"><i class="bi bi-plus-lg me-1"></i><?= e(t('inspections.add')) ?></a>
</div>

<div class="card">
  <div class="card-body">
    <form method="get" class="row g-2 mb-3 filter-form">
      <div class="col-md-6">
        <input type="search" name="q" class="form-control form-control-sm"
               placeholder="<?= e(t('nav.search_ph')) ?>" value="<?= e($q) ?>" aria-label="<?= e(t('common.search')) ?>">
      </div>
      <div class="col-md-6 d-flex gap-2 filter-actions">
        <button type="submit" class="btn btn-sm btn-primary filter-btn"><i class="bi bi-search me-1" aria-hidden="true"></i><span><?= e(t('common.search')) ?></span></button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary filter-reset" title="<?= e(t('common.reset')) ?>" aria-label="<?= e(t('common.reset')) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th><th><?= e(t('inspections.vehicle')) ?></th><th><?= e(t('common.client')) ?></th>
            <th><?= e(t('inspections.type')) ?></th><th><?= e(t('inspections.problem')) ?></th>
            <th><?= e(t('common.status')) ?></th><th><?= e(t('common.date')) ?></th>
            <th class="text-end"><?= e(t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="text-muted"><?= (int) $r['id'] ?></td>
              <td><a class="text-decoration-none fw-semibold" href="../vehicles/view.php?id=<?= (int) $r['vehicle_id'] ?>"><?= e($r['license_plate']) ?></a>
                  <div class="small text-muted"><?= e(trim($r['brand'] . ' ' . $r['model'])) ?></div></td>
              <td><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
              <td><?= e($r['vehicle_type'] ?: '—') ?></td>
              <td><span class="text-truncate d-inline-block" style="max-width:260px;" title="<?= e($r['problem_description']) ?>"><?= e(mb_strimwidth($r['problem_description'], 0, 60, '…')) ?></span></td>
              <td><?= status_badge($r['veh_status']) ?></td>
              <td class="text-nowrap"><?= fmt_date($r['inspection_date']) ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-outline-primary" href="view.php?id=<?= (int) $r['id'] ?>" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                <a class="btn btn-outline-warning" href="form.php?id=<?= (int) $r['id'] ?>" title="<?= e(t('common.edit')) ?>"><i class="bi bi-pencil"></i></a>
                <form class="d-inline" method="post" data-confirm="<?= e(t('common.delete_confirm_msg')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
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
              <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($q) ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
