<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_mechanic()) {
    redirect('admin/dashboard.php');
}

$u = current_user();
$mechId = (int) $u['mechanic_id'];

$rows = fetch_all(
    "SELECT v.*, c.first_name, c.last_name,
            (SELECT problem_description FROM inspections i WHERE i.vehicle_id = v.id ORDER BY i.id DESC LIMIT 1) problem,
            (SELECT a.priority FROM vehicle_assignments a WHERE a.vehicle_id = v.id ORDER BY a.id DESC LIMIT 1) priority
     FROM vehicles v
     JOIN clients c ON c.id = v.client_id
     WHERE v.mechanic_id = ?
     ORDER BY FIELD(v.status, 'en_reparation', 'repare', 'en_cours', 'initial', 'livre', 'annule'), v.entry_date",
    [$mechId]
);

$pageTitle = t('mech.my_vehicles');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-car-front me-2 text-danger"></i><?= e(t('mech.my_vehicles')) ?></h1>
    <div class="subtitle"><?= count($rows) ?></div>
  </div>
  <a href="dashboard.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('nav.my_vehicles')) ?></th><th><?= e(t('common.client')) ?></th>
          <th><?= e(t('mech.problem')) ?></th><th><?= e(t('mech.entry')) ?></th>
          <th><?= e(t('mech.priority')) ?></th><th><?= e(t('common.status')) ?></th>
          <th class="text-end"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><?= e(t('mech.no_vehicles')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $v): ?>
          <tr>
            <td><a class="fw-semibold text-decoration-none" href="vehicle_view.php?id=<?= (int) $v['id'] ?>"><?= e($v['license_plate']) ?></a>
              <div class="small text-muted"><?= e(trim($v['brand'] . ' ' . $v['model'])) ?></div></td>
            <td><?= e(trim($v['first_name'] . ' ' . $v['last_name'])) ?></td>
            <td><span class="text-truncate d-inline-block" style="max-width:240px;" title="<?= e($v['problem'] ?? '') ?>"><?= e(mb_strimwidth((string) ($v['problem'] ?? ''), 0, 55, '…')) ?></span></td>
            <td class="text-nowrap"><?= fmt_date($v['entry_date']) ?></td>
            <td><?= $v['priority'] ? priority_badge($v['priority']) : '—' ?></td>
            <td><?= status_badge($v['status']) ?></td>
            <td class="text-end">
              <?php if (in_array($v['status'], ['en_reparation', 'repare', 'en_cours'], true)): ?>
                <a class="btn btn-primary" href="vehicle_view.php?id=<?= (int) $v['id'] ?>"><i class="bi bi-tools me-1"></i><?= e(t('common.view')) ?></a>
              <?php else: ?>
                <a class="btn btn-outline-primary" href="vehicle_view.php?id=<?= (int) $v['id'] ?>"><i class="bi bi-eye"></i></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
