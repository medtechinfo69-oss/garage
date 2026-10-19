<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

/* ---------- Suppression (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $client = fetch_one("SELECT * FROM clients WHERE id = ?", [$id]);
    if ($client) {
        $nbVeh = (int) fetch_one("SELECT COUNT(*) c FROM vehicles WHERE client_id = ?", [$id])['c'];
        if ($nbVeh > 0) {
            flash('danger', sprintf(t('clients.delete_warn'), $nbVeh));
        } else {
            execute("DELETE FROM clients WHERE id = ?", [$id]);
            log_activity('client_deleted', 'clients', $id, t('log.client_deleted', client_name($client)));
            flash('success', t('flash.deleted'));
        }
    }
    redirect('admin/clients/index.php');
}

/* ---------- Recherche ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$perPage = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= " AND (CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR c.phone2 LIKE ? OR c.email LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$total = (int) (fetch_one("SELECT COUNT(*) c FROM clients c WHERE $where", $params)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$rows = fetch_all(
    "SELECT c.*, (SELECT COUNT(*) FROM vehicles v WHERE v.client_id = c.id) nb_vehicles
     FROM clients c WHERE $where
     ORDER BY c.last_name, c.first_name
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

$pageTitle = t('clients.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-people me-2 text-danger"></i><?= e(t('clients.title')) ?></h1>
    <div class="subtitle"><?= $total ?> <?= e(t('clients.title')) ?></div>
  </div>
  <a href="<?= e(app_url('admin/clients/form.php')) ?>" class="btn btn-danger"><i class="bi bi-plus-lg me-1"></i><?= e(t('clients.add')) ?></a>
</div>

<div class="card">
  <div class="card-body">
    <form method="get" class="row g-2 mb-3 filter-form">
      <div class="col-md-8">
        <input type="search" name="q" class="form-control form-control-sm" placeholder="<?= e(t('clients.search_ph')) ?>" value="<?= e($q) ?>" aria-label="<?= e(t('clients.search_ph')) ?>">
      </div>
      <div class="col-md-4 d-flex gap-2 filter-actions">
        <button type="submit" class="btn btn-sm btn-primary filter-btn"><i class="bi bi-search me-1" aria-hidden="true"></i><span><?= e(t('common.search')) ?></span></button>
        <a href="<?= e(app_url('admin/clients/index.php')) ?>" class="btn btn-sm btn-outline-secondary filter-reset" title="<?= e(t('common.reset')) ?>" aria-label="<?= e(t('common.reset')) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th><th><?= e(t('clients.last_name')) ?></th><th><?= e(t('clients.first_name')) ?></th>
            <th><?= e(t('clients.phone')) ?></th><th><?= e(t('clients.email')) ?></th>
            <th><?= e(t('clients.city')) ?></th><th><?= e(t('vehicles.title')) ?></th>
            <th class="text-end"><?= e(t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $c): ?>
            <tr>
              <td class="text-muted"><?= (int) $c['id'] ?></td>
              <td class="fw-semibold"><?= e($c['last_name']) ?></td>
              <td><?= e($c['first_name']) ?></td>
              <td><?= e($c['phone'] ?: '—') ?></td>
              <td><?= e($c['email'] ?: '—') ?></td>
              <td><?= e($c['city'] ?: '—') ?></td>
              <td><span class="badge text-bg-light border"><?= (int) $c['nb_vehicles'] ?></span></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-outline-primary" href="view.php?id=<?= (int) $c['id'] ?>" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
                <a class="btn btn-outline-warning" href="form.php?id=<?= (int) $c['id'] ?>" title="<?= e(t('common.edit')) ?>"><i class="bi bi-pencil"></i></a>
                <form class="d-inline" method="post" data-confirm="<?= e(t('common.delete_confirm_msg')) ?>" data-confirm-title="<?= e(t('common.delete_confirm_title')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
