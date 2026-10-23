<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

/* ---------- Suppression (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $f = fetch_one("SELECT * FROM factures WHERE id = ?", [$id]);
    if ($f) {
        execute("DELETE FROM factures WHERE id = ?", [$id]);
        log_activity('facture_deleted', 'factures', $id, t('common.delete') . ' ' . $f['invoice_number']);
        flash('success', t('flash.document_deleted'));
    }
    redirect('admin/factures/index.php');
}

/* ---------- Filtres ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$clientId = (int) ($_GET['client_id'] ?? 0);
$statusF = (string) ($_GET['status'] ?? '');
$from = (string) ($_GET['from'] ?? '');
$to = (string) ($_GET['to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= " AND (f.invoice_number LIKE ? OR d.quote_number LIKE ? OR v.license_plate LIKE ? OR v.vin LIKE ? OR CONCAT(c.first_name,' ',c.last_name) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($clientId) { $where .= " AND f.client_id = ?"; $params[] = $clientId; }
if (in_array($statusF, ['brouillon', 'envoyee', 'payee', 'partiellement_payee', 'annulee'], true)) { $where .= " AND f.status = ?"; $params[] = $statusF; }
if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND f.invoice_date >= ?"; $params[] = $from; }
if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= " AND f.invoice_date <= ?"; $params[] = $to; }

$total = (int) (fetch_one(
    "SELECT COUNT(*) c FROM factures f
     LEFT JOIN devis d ON d.id = f.devis_id
     LEFT JOIN vehicles v ON v.id = f.vehicle_id
     JOIN clients c ON c.id = f.client_id WHERE $where", $params)['c'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$rows = fetch_all(
    "SELECT f.*, d.quote_number, v.license_plate, c.first_name, c.last_name, c.email
     FROM factures f
     LEFT JOIN devis d ON d.id = f.devis_id
     LEFT JOIN vehicles v ON v.id = f.vehicle_id
     JOIN clients c ON c.id = f.client_id
     WHERE $where
     ORDER BY f.id DESC
     LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
$clients = fetch_all("SELECT id, first_name, last_name FROM clients ORDER BY last_name");

$pageTitle = t('facture.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-receipt me-2 text-danger"></i><?= e(t('facture.title')) ?></h1>
    <div class="subtitle"><?= $total ?></div>
  </div>
  <a href="form.php" class="btn btn-danger"><i class="bi bi-plus-lg me-1"></i><?= e(t('facture.add')) ?></a>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end filter-form">
      <div class="col-md-3">
        <label class="form-label small"><?= e(t('facture.number')) ?> / <?= e(t('devis.number')) ?></label>
        <input type="search" name="q" class="form-control form-control-sm" value="<?= e($q) ?>" placeholder="<?= e(t('facture.search_ph')) ?>" aria-label="<?= e(t('facture.search_ph')) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small"><?= e(t('devis.client')) ?></label>
        <select name="client_id" class="form-select form-select-sm" aria-label="<?= e(t('devis.client')) ?>">
          <option value=""><?= e(t('common.all')) ?></option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $clientId === (int) $c['id'] ? 'selected' : '' ?>><?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small"><?= e(t('facture.status')) ?></label>
        <select name="status" class="form-select form-select-sm status-select" aria-label="<?= e(t('facture.status')) ?>">
          <option value=""><?= e(t('common.all')) ?></option>
          <?php foreach (['brouillon', 'envoyee', 'payee', 'annulee'] as $st): ?>
            <option value="<?= $st ?>" <?= $statusF === $st ? 'selected' : '' ?>><?= e(t('facture.' . $st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small"><?= e(t('common.from')) ?></label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" aria-label="<?= e(t('common.from')) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small"><?= e(t('common.to')) ?></label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" aria-label="<?= e(t('common.to')) ?>">
      </div>
      <div class="col-md-12 col-lg-1 d-flex gap-2 filter-actions">
        <button type="submit" class="btn btn-sm btn-primary filter-btn filter-btn-icon" title="<?= e(t('common.filter')) ?>" aria-label="<?= e(t('common.filter')) ?>"><i class="bi bi-funnel" aria-hidden="true"></i><span class="d-lg-none"><?= e(t('common.filter')) ?></span></button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary filter-reset" title="<?= e(t('common.reset')) ?>" aria-label="<?= e(t('common.reset')) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('facture.number')) ?></th><th><?= e(t('facture.devis_ref')) ?></th>
          <th><?= e(t('devis.client')) ?></th><th><?= e(t('devis.vehicle')) ?></th>
          <th><?= e(t('facture.date')) ?></th><th><?= e(t('facture.due_date')) ?></th>
          <th><?= e(t('facture.status')) ?></th><th class="text-end"><?= e(t('common.total_ttc')) ?></th>
          <th class="text-end"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $f): ?>
          <tr>
            <td class="fw-semibold"><a class="text-decoration-none" href="view.php?id=<?= (int) $f['id'] ?>"><?= e($f['invoice_number']) ?></a></td>
            <td class="text-muted small"><?= e($f['quote_number'] ?: '—') ?></td>
            <td><?= e(trim($f['first_name'] . ' ' . $f['last_name'])) ?></td>
            <td><?= e($f['license_plate'] ?: '—') ?></td>
            <td class="text-nowrap"><?= fmt_date($f['invoice_date']) ?></td>
            <td class="text-nowrap"><?= fmt_date($f['due_date']) ?></td>
            <td><?= doc_status_badge('facture', $f['status']) ?></td>
            <td class="text-end fw-semibold"><?= money((float) $f['total_ttc']) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-outline-primary" href="view.php?id=<?= (int) $f['id'] ?>" title="<?= e(t('common.view')) ?>"><i class="bi bi-eye"></i></a>
              <a class="btn btn-outline-secondary" href="pdf.php?id=<?= (int) $f['id'] ?>" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
              <form class="d-inline" method="post" onclick="event.preventDefault(); showConfirmPopup(<?= json_encode(t('common.delete_confirm_title')) ?>, <?= json_encode(t('facture.delete_msg')) ?>, () => { document.getElementById('deleteForm').submit(); }, () => { /* annulé */ })">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit" class="btn btn-outline-danger" title="<?= e(t('common.delete')) ?>" style="display:none;"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white">
      <ul class="pagination pagination-sm mb-0 justify-content-center">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <li class="page-item <?= $i === $page ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $i ?>&q=<?= urlencode($q) ?>&client_id=<?= $clientId ?>&status=<?= urlencode($statusF) ?>&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
