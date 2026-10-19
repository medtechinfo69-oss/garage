<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$inspection = fetch_one(
    "SELECT i.*, v.license_plate, v.brand, v.model, v.year, v.vin, v.mileage veh_mileage, v.status veh_status,
            c.first_name, c.last_name, c.civility, c.phone, c.email,
            m.full_name mech_name
     FROM inspections i
     JOIN vehicles v ON v.id = i.vehicle_id
     JOIN clients c ON c.id = i.client_id
     LEFT JOIN mechanics m ON m.id = i.mechanic_id
     WHERE i.id = ?",
    [$id]
);
if (!$inspection) {
    flash('danger', t('error.not_found'));
    redirect('admin/inspections/index.php');
}

$pageTitle = t('inspections.view_title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-clipboard-check me-2 text-danger"></i><?= e(t('inspections.view_title')) ?> #<?= (int) $inspection['id'] ?>
      — <?= e($inspection['license_plate']) ?>
    </h1>
    <div class="subtitle">
      <?= e(trim($inspection['brand'] . ' ' . $inspection['model'])) ?> · <?= e(trim($inspection['first_name'] . ' ' . $inspection['last_name'])) ?>
      · <?= e(t('common.date')) ?> : <?= fmt_date($inspection['inspection_date']) ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap action-row">
    <?php if ($inspection['veh_status'] === 'en_cours'): ?>
      <a href="../assignments/form.php?vehicle_id=<?= (int) $inspection['vehicle_id'] ?>&inspection_id=<?= (int) $inspection['id'] ?>" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i><span class="btn-label"><?= e(t('vehicles.assign')) ?></span></a>
    <?php endif; ?>
    <a href="form.php?id=<?= (int) $inspection['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil me-1"></i><span class="btn-label"><?= e(t('common.edit')) ?></span></a>
    <a href="../vehicles/view.php?id=<?= (int) $inspection['vehicle_id'] ?>" class="btn btn-outline-primary"><i class="bi bi-car-front me-1"></i><span class="btn-label"><?= e(t('vehicles.view_title')) ?></span></a>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><span class="btn-label"><?= e(t('common.back')) ?></span></a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-exclamation-circle me-2"></i><?= e(t('inspections.problem')) ?></div>
      <div class="card-body"><?= nl2br(e($inspection['problem_description'])) ?></div>
    </div>
    <?php if ($inspection['initial_diagnostic']): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-clipboard-pulse me-2"></i><?= e(t('inspections.diagnostic')) ?></div>
        <div class="card-body"><?= nl2br(e($inspection['initial_diagnostic'])) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($inspection['parts_to_check']): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-tools me-2"></i><?= e(t('inspections.parts_check')) ?></div>
        <div class="card-body"><?= nl2br(e($inspection['parts_to_check'])) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($inspection['observations']): ?>
      <div class="card">
        <div class="card-header"><i class="bi bi-eye me-2"></i><?= e(t('inspections.observations')) ?></div>
        <div class="card-body"><?= nl2br(e($inspection['observations'])) ?></div>
      </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-car-front me-2"></i><?= e(t('vehicles.view_title')) ?></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th class="text-muted"><?= e(t('vehicles.plate')) ?></th><td class="fw-semibold"><?= e($inspection['license_plate']) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.brand')) ?> / <?= e(t('common.model')) ?></th><td><?= e(trim($inspection['brand'] . ' ' . $inspection['model'])) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('inspections.type')) ?></th><td><?= e($inspection['vehicle_type'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.mileage')) ?></th><td><?= $inspection['mileage'] !== null ? number_format((float) $inspection['mileage']) . ' km' : '—' ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.vin')) ?></th><td><?= e($inspection['vin'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.status')) ?></th><td><?= status_badge($inspection['veh_status']) ?></td></tr>
        </table>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-person me-2"></i><?= e(t('common.client')) ?></div>
      <div class="card-body small">
        <div class="fw-semibold mb-1"><?= e(civility_label($inspection['civility']) . ' ' . trim($inspection['first_name'] . ' ' . $inspection['last_name'])) ?></div>
        <div><?= e($inspection['phone'] ?: '—') ?></div>
        <div><?= e($inspection['email'] ?: '—') ?></div>
      </div>
    </div>
    <?php if ($inspection['photo']): ?>
      <div class="card">
        <div class="card-header"><i class="bi bi-camera me-2"></i><?= e(t('inspections.photo')) ?></div>
        <div class="card-body text-center">
          <img src="<?= e(upload_url($inspection['photo'])) ?>" alt="photo" class="img-fluid rounded" style="max-height:300px;">
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
