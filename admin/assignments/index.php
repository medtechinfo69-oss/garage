<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$rows = fetch_all(
    "SELECT a.*, v.license_plate, v.brand, v.model, v.status veh_status,
            m.full_name mech_name, u.full_name user_name, i.problem_description
     FROM vehicle_assignments a
     JOIN vehicles v ON v.id = a.vehicle_id
     JOIN mechanics m ON m.id = a.mechanic_id
     LEFT JOIN users u ON u.id = a.assigned_by
     LEFT JOIN inspections i ON i.id = a.inspection_id
     ORDER BY a.id DESC LIMIT 100"
);

$pageTitle = t('assign.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-person-plus me-2 text-danger"></i><?= e(t('assign.title')) ?></h1>
    <div class="subtitle"><?= count($rows) ?></div>
  </div>
  <a href="form.php" class="btn btn-danger"><i class="bi bi-plus-lg me-1"></i><?= e(t('assign.add')) ?></a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('common.date')) ?> / <?= e(t('common.time')) ?></th>
          <th><?= e(t('assign.vehicle')) ?></th><th><?= e(t('assign.mechanic')) ?></th>
          <th><?= e(t('assign.priority')) ?></th><th><?= e(t('common.status')) ?></th>
          <th><?= e(t('assign.instructions')) ?></th><th class="text-end"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $a): ?>
          <tr>
            <td class="text-nowrap"><?= fmt_date($a['assigned_date']) ?> <?= fmt_time($a['assigned_time']) ?></td>
            <td><a class="fw-semibold text-decoration-none" href="../vehicles/view.php?id=<?= (int) $a['vehicle_id'] ?>"><?= e($a['license_plate']) ?></a>
                <div class="small text-muted"><?= e(trim($a['brand'] . ' ' . $a['model'])) ?></div></td>
            <td><?= e($a['mech_name']) ?></td>
            <td><?= priority_badge($a['priority']) ?></td>
            <td><?= status_badge($a['veh_status']) ?></td>
            <td><span class="text-truncate d-inline-block" style="max-width:220px;" title="<?= e($a['instructions'] ?? '') ?>"><?= e(mb_strimwidth((string) ($a['instructions'] ?? ''), 0, 50, '…')) ?></span></td>
            <td class="text-end">
              <a class="btn btn-outline-primary" href="../vehicles/view.php?id=<?= (int) $a['vehicle_id'] ?>"><i class="bi bi-eye"></i></a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
