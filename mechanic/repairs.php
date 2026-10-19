<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_mechanic()) {
    redirect('admin/dashboard.php');
}

$u = current_user();
$mechId = (int) $u['mechanic_id'];

$rows = fetch_all(
    "SELECT r.*, v.license_plate, v.brand, v.model, c.first_name, c.last_name,
            (SELECT COALESCE(SUM(total),0) FROM repair_parts p WHERE p.repair_id = r.id) parts_total
     FROM repairs r
     JOIN vehicles v ON v.id = r.vehicle_id
     JOIN clients c ON c.id = r.client_id
     WHERE r.mechanic_id = ?
     ORDER BY r.id DESC LIMIT 100",
    [$mechId]
);

$pageTitle = t('mech.my_repairs');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-wrench-adjustable me-2 text-danger"></i><?= e(t('mech.my_repairs')) ?></h1>
    <div class="subtitle"><?= count($rows) ?></div>
  </div>
  <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('repairs.vehicle')) ?></th><th><?= e(t('common.client')) ?></th>
          <th><?= e(t('repairs.works_done')) ?></th>
          <th><?= e(t('repairs.start')) ?></th><th><?= e(t('repairs.end')) ?></th>
          <th><?= e(t('repairs.total_cost')) ?></th><th><?= e(t('repairs.status')) ?></th>
          <th class="text-end"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><a class="fw-semibold text-decoration-none" href="vehicle_view.php?id=<?= (int) $r['vehicle_id'] ?>"><?= e($r['license_plate']) ?></a>
              <div class="small text-muted"><?= e(trim($r['brand'] . ' ' . $r['model'])) ?></div></td>
            <td><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></td>
            <td><span class="text-truncate d-inline-block" style="max-width:240px;" title="<?= e($r['works_done'] ?? '') ?>"><?= e(mb_strimwidth((string) ($r['works_done'] ?? ''), 0, 55, '…')) ?></span></td>
            <td class="text-nowrap"><?= fmt_date($r['start_date']) ?></td>
            <td class="text-nowrap"><?= fmt_date($r['end_date']) ?></td>
            <td class="fw-semibold"><?= money((float) $r['parts_total'] + (float) $r['labor_cost']) ?></td>
            <td>
              <span class="badge text-bg-<?= $r['status'] === 'terminee' ? 'success' : 'primary' ?>">
                <?= e($r['status'] === 'terminee' ? t('repairs.done') : t('repairs.in_progress')) ?>
              </span>
            </td>
            <td class="text-end">
              <a class="btn btn-outline-primary" href="vehicle_view.php?id=<?= (int) $r['vehicle_id'] ?>"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
