<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$client = fetch_one("SELECT * FROM clients WHERE id = ?", [$id]);
if (!$client) {
    flash('danger', t('error.not_found'));
    redirect('admin/clients/index.php');
}
$vehicles = fetch_all(
    "SELECT v.*, m.full_name mech_name FROM vehicles v
     LEFT JOIN mechanics m ON m.id = v.mechanic_id
     WHERE v.client_id = ? ORDER BY v.created_at DESC",
    [$id]
);
$devisList = fetch_all("SELECT * FROM devis WHERE client_id = ? ORDER BY id DESC LIMIT 5", [$id]);
$facturesList = fetch_all("SELECT * FROM factures WHERE client_id = ? ORDER BY id DESC LIMIT 5", [$id]);

$pageTitle = t('clients.view_title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-person-vcard me-2 text-danger"></i><?= e(t('clients.view_title')) ?> :
      <?= e(civility_label($client['civility']) . ' ' . client_name($client)) ?>
    </h1>
  </div>
  <div class="d-flex gap-2 flex-wrap action-row">
    <a href="form.php?id=<?= (int) $client['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil me-1"></i><span class="btn-label"><?= e(t('common.edit')) ?></span></a>
    <a href="<?= e(app_url('admin/vehicles/form.php?client_id=' . (int) $client['id'])) ?>" class="btn btn-primary"><i class="bi bi-car-front me-1"></i><span class="btn-label"><?= e(t('vehicles.add')) ?></span></a>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><span class="btn-label"><?= e(t('common.back')) ?></span></a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><i class="bi bi-person-vcard me-2"></i><?= e(t('clients.view_title')) ?></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th class="text-muted" style="width:45%"><?= e(t('clients.civility')) ?></th><td><?= e(civility_label($client['civility'])) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.last_name')) ?></th><td><?= e($client['last_name']) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.first_name')) ?></th><td><?= e($client['first_name']) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.phone')) ?></th><td><?= e($client['phone'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.phone2')) ?></th><td><?= e($client['phone2'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.email')) ?></th><td><?= e($client['email'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.address')) ?></th><td><?= e($client['address'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.postal_code')) . ' / ' . e(t('clients.city')) ?></th>
              <td><?= e(trim($client['postal_code'] . ' ' . $client['city']) ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('clients.created')) ?></th><td><?= fmt_datetime($client['created_at']) ?></td></tr>
        </table>
        <?php if ($client['notes']): ?>
          <?= banner('neutral', $client['notes'], t('common.notes'), [], null, false, 'app-banner--sm mt-3 mb-0') ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-car-front me-2"></i><?= e(sprintf(t('clients.vehicles_of'), count($vehicles))) ?></span>
        <a class="btn btn-sm btn-primary" href="<?= e(app_url('admin/vehicles/form.php?client_id=' . (int) $client['id'])) ?>"><i class="bi bi-plus-lg me-1"></i><?= e(t('vehicles.add')) ?></a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th><?= e(t('vehicles.plate')) ?></th><th><?= e(t('common.brand')) ?></th>
              <th><?= e(t('common.model')) ?></th><th><?= e(t('common.year')) ?></th>
              <th><?= e(t('common.mechanic')) ?></th><th><?= e(t('common.status')) ?></th>
              <th class="text-end"><?= e(t('common.actions')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$vehicles): ?>
              <tr><td colspan="7" class="text-center text-muted py-3"><?= e(t('common.no_results')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($vehicles as $v): ?>
              <tr>
                <td class="fw-semibold"><?= e($v['license_plate']) ?></td>
                <td><?= e($v['brand']) ?></td>
                <td><?= e($v['model']) ?></td>
                <td><?= e($v['year'] ?: '—') ?></td>
                <td><?= e($v['mech_name'] ?: '—') ?></td>
                <td><?= status_badge($v['status']) ?></td>
                <td class="text-end">
                  <a class="btn btn-outline-primary" href="<?= e(app_url('admin/vehicles/view.php?id=' . (int) $v['id'])) ?>"><i class="bi bi-eye"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-file-earmark-text me-2"></i><?= e(t('nav.devis')) ?></div>
          <div class="table-responsive"><table class="table table-sm mb-0">
              <?php if (!$devisList): ?><tr><td class="text-muted text-center py-3"><?= e(t('common.no_results')) ?></td></tr><?php endif; ?>
              <?php foreach ($devisList as $d): ?>
                <tr>
                  <td><a class="fw-semibold text-decoration-none" href="../devis/view.php?id=<?= (int) $d['id'] ?>"><?= e($d['quote_number']) ?></a></td>
                  <td class="text-muted small"><?= fmt_date($d['devis_date']) ?></td>
                  <td><?= doc_status_badge('devis', $d['status']) ?></td>
                  <td class="text-end small fw-semibold"><?= money((float) $d['total_ttc']) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header"><i class="bi bi-receipt me-2"></i><?= e(t('nav.factures')) ?></div>
          <div class="table-responsive"><table class="table table-sm mb-0">
              <?php if (!$facturesList): ?><tr><td class="text-muted text-center py-3"><?= e(t('common.no_results')) ?></td></tr><?php endif; ?>
              <?php foreach ($facturesList as $f): ?>
                <tr>
                  <td><a class="fw-semibold text-decoration-none" href="../factures/view.php?id=<?= (int) $f['id'] ?>"><?= e($f['invoice_number']) ?></a></td>
                  <td class="text-muted small"><?= fmt_date($f['invoice_date']) ?></td>
                  <td><?= doc_status_badge('facture', $f['status']) ?></td>
                  <td class="text-end small fw-semibold"><?= money((float) $f['total_ttc']) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
