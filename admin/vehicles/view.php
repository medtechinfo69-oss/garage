<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$vehicle = fetch_one(
    "SELECT v.*, c.first_name, c.last_name, c.phone AS c_phone, c.email AS c_email,
            m.full_name AS mech_name
     FROM vehicles v
     JOIN clients c ON c.id = v.client_id
     LEFT JOIN mechanics m ON m.id = v.mechanic_id
     WHERE v.id = ?",
    [$id]
);
if (!$vehicle) {
    flash('danger', t('error.not_found'));
    redirect('admin/vehicles/index.php');
}

/* ---------- Actions d'état (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') !== '') {
    verify_csrf();
    $action = (string) $_POST['action'];
    $comment = trim((string) ($_POST['comment'] ?? ''));
    if ($action === 'deliver') {
        [$ok, $msg] = change_vehicle_status($id, 'livre', $comment !== '' ? $comment : null);
        flash($ok ? 'success' : 'danger', $msg);
    } elseif ($action === 'cancel') {
        [$ok, $msg] = change_vehicle_status($id, 'annule', $comment !== '' ? $comment : null);
        flash($ok ? 'success' : 'danger', $msg);
    }
    redirect('admin/vehicles/view.php?id=' . $id);
}

$client = [
    'civility' => 'Monsieur', 'first_name' => $vehicle['first_name'], 'last_name' => $vehicle['last_name'],
    'phone' => $vehicle['c_phone'], 'email' => $vehicle['c_email'],
];
$inspections = fetch_all(
    "SELECT i.*, m.full_name mech_name FROM inspections i LEFT JOIN mechanics m ON m.id = i.mechanic_id
     WHERE i.vehicle_id = ? ORDER BY i.id DESC",
    [$id]
);
$repairs = fetch_all(
    "SELECT r.*, m.full_name mech_name,
            (SELECT COALESCE(SUM(total),0) FROM repair_parts p WHERE p.repair_id = r.id) parts_total
     FROM repairs r LEFT JOIN mechanics m ON m.id = r.mechanic_id
     WHERE r.vehicle_id = ? ORDER BY r.id DESC",
    [$id]
);
$history = fetch_all(
    "SELECT h.*, m.full_name mech_name, u.full_name user_name
     FROM vehicle_status_history h
     LEFT JOIN mechanics m ON m.id = h.mechanic_id
     LEFT JOIN users u ON u.id = h.user_id
     WHERE h.vehicle_id = ? ORDER BY h.id DESC",
    [$id]
);

// date de sortie = dernier passage à 'livre'
$exitRow = fetch_one("SELECT changed_at FROM vehicle_status_history WHERE vehicle_id = ? AND status = 'livre' ORDER BY id DESC LIMIT 1", [$id]);

$lastAssignment = fetch_one(
    "SELECT a.*, m.full_name mech_name FROM vehicle_assignments a LEFT JOIN mechanics m ON m.id = a.mechanic_id
     WHERE a.vehicle_id = ? ORDER BY a.id DESC LIMIT 1",
    [$id]
);

$pageTitle = t('vehicles.view_title');
require __DIR__ . '/../../includes/header.php';
?>

<?= vehicle_hero($vehicle, [
    'eyebrow' => t('vehicles.view_title'),
    'since'   => $history ? t('vehicles.since') . ' ' . fmt_datetime($history[0]['changed_at']) : '',
    'footer'  => [
        ['icon' => 'bi-calendar3', 'text' => t('common.entry_date') . ' : ' . fmt_date($vehicle['entry_date'])],
        ['icon' => 'bi-box-seam', 'text' => t('vehicles.exit_date') . ' : ' . ($exitRow ? fmt_datetime($exitRow['changed_at']) : t('vehicles.no_exit_date'))],
    ],
]) ?>

<div class="vh-actions">
    <?php if (in_array($vehicle['status'], ['initial', 'en_cours'], true)): ?>
      <a href="../inspections/form.php?vehicle_id=<?= (int) $vehicle['id'] ?>" class="btn btn-warning"><i class="bi bi-clipboard-check me-1"></i><?= e(t('vehicles.create_inspection')) ?></a>
    <?php endif; ?>
    <?php if ($vehicle['status'] === 'en_cours'): ?>
      <a href="../assignments/form.php?vehicle_id=<?= (int) $vehicle['id'] ?>" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i><?= e(t('vehicles.assign')) ?></a>
    <?php endif; ?>
    <?php if ($vehicle['status'] === 'repare'): ?>
      <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#deliverModal"><i class="bi bi-box-seam me-1"></i><?= e(t('vehicles.deliver_title')) ?></button>
    <?php endif; ?>
    <?php if (!in_array($vehicle['status'], ['livre', 'annule'], true)): ?>
      <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-x-octagon me-1"></i><?= e(t('vehicles.cancel_title')) ?></button>
    <?php endif; ?>
    <a href="form.php?id=<?= (int) $vehicle['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil me-1"></i><?= e(t('common.edit')) ?></a>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-car-front me-2"></i><?= e(t('vehicles.view_title')) ?></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><th class="text-muted" style="width:45%"><?= e(t('vehicles.client')) ?></th>
              <td><a class="text-decoration-none" href="../clients/view.php?id=<?= (int) $vehicle['client_id'] ?>"><?= e(civility_label('Monsieur')) . ' ' . e(trim($vehicle['first_name'] . ' ' . $vehicle['last_name'])) ?></a></td></tr>
          <tr><th class="text-muted"><?= e(t('vehicles.plate')) ?></th><td class="fw-semibold"><?= e($vehicle['license_plate']) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.brand')) ?> / <?= e(t('common.model')) ?></th><td><?= e(trim($vehicle['brand'] . ' ' . $vehicle['model'])) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.year')) ?></th><td><?= e($vehicle['year'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.vin')) ?></th><td><?= e($vehicle['vin'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.mileage')) ?></th><td><?= $vehicle['mileage'] !== null ? number_format((float) $vehicle['mileage']) . ' km' : '—' ?></td></tr>
          <tr><th class="text-muted"><?= e(t('common.fuel')) ?> / <?= e(t('common.color')) ?></th><td><?= e(fuel_label($vehicle['fuel'])) ?> / <?= e($vehicle['color'] ?: '—') ?></td></tr>
          <tr><th class="text-muted"><?= e(t('vehicles.entry_date')) ?></th><td><?= fmt_date($vehicle['entry_date']) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('vehicles.exit_date')) ?></th><td><?= $exitRow ? fmt_datetime($exitRow['changed_at']) : e(t('vehicles.no_exit_date')) ?></td></tr>
          <tr><th class="text-muted"><?= e(t('vehicles.mechanic')) ?></th><td><?= e($vehicle['mech_name'] ?: '—') ?></td></tr>
        </table>
        <?php if ($vehicle['notes']): ?>
          <?= banner('neutral', $vehicle['notes'], t('common.notes'), [], null, false, 'app-banner--sm mt-3 mb-0') ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2"></i><?= e(t('vehicles.status_history')) ?></div>
      <div class="card-body">
        <?php if (!$history): ?>
          <div class="text-muted small"><?= e(t('common.no_results')) ?></div>
        <?php else: ?>
          <?php foreach ($history as $h): ?>
            <div class="timeline-item">
              <span class="timeline-dot" style="border-color:<?= ['initial' => '#94a3b8', 'en_cours' => '#f59e0b', 'en_reparation' => '#3b82f6', 'repare' => '#22c55e', 'livre' => '#14b8a6', 'annule' => '#ef4444'][$h['status']] ?>;"></span>
              <div>
                <div class="small">
                  <?= status_badge($h['status']) ?>
                  <?php if ($h['previous_status']): ?>
                    <span class="text-muted">← <?= e(status_label($h['previous_status'])) ?></span>
                  <?php endif; ?>
                </div>
                <div class="small text-muted">
                  <?= fmt_datetime($h['changed_at']) ?>
                  · <?= e($h['user_name'] ?: '—') ?>
                  <?php if ($h['mech_name']): ?> · <i class="bi bi-wrench"></i> <?= e($h['mech_name']) ?> <?php endif; ?>
                </div>
                <?php if ($h['comment']): ?>
                  <div class="small mt-1 fst-italic text-secondary">« <?= e($h['comment']) ?> »</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <?php if ($lastAssignment): ?>
      <div class="card mb-3 border-start border-3 border-primary">
        <div class="card-body py-3">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="small text-muted"><i class="bi bi-person-plus me-1"></i><?= e(t('assign.latest_inspection') === '' ? '' : t('nav.assignments')) ?> — <?= fmt_date($lastAssignment['assigned_date']) ?> <?= fmt_time($lastAssignment['assigned_time']) ?></div>
              <div class="fw-semibold"><?= e($lastAssignment['mech_name']) ?> <?= priority_badge($lastAssignment['priority']) ?></div>
            </div>
          </div>
          <?php if ($lastAssignment['instructions']): ?>
            <div class="small mt-2 text-secondary"><?= nl2br(e($lastAssignment['instructions'])) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-clipboard-check me-2"></i><?= e(t('vehicles.inspections')) ?> (<?= count($inspections) ?>)</div>
      <div class="table-responsive">
        <?php if (!$inspections): ?>
          <div class="text-muted small text-center py-3"><?= e(t('common.no_results')) ?></div>
        <?php else: ?>
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th><?= e(t('common.date')) ?></th><th><?= e(t('inspections.problem')) ?></th><th><?= e(t('common.mechanic')) ?></th><th class="text-end"><?= e(t('common.actions')) ?></th></tr></thead>
            <tbody>
              <?php foreach ($inspections as $ins): ?>
                <tr>
                  <td class="text-nowrap"><?= fmt_date($ins['inspection_date']) ?></td>
                  <td><span class="text-truncate d-inline-block" style="max-width:280px;" title="<?= e($ins['problem_description']) ?>"><?= e(mb_strimwidth($ins['problem_description'], 0, 70, '…')) ?></span></td>
                  <td><?= e($ins['mech_name'] ?: '—') ?></td>
                  <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="../inspections/view.php?id=<?= (int) $ins['id'] ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-wrench me-2"></i><?= e(t('vehicles.repairs')) ?> (<?= count($repairs) ?>)</div>
      <div class="table-responsive">
        <?php if (!$repairs): ?>
          <div class="text-muted small text-center py-3"><?= e(t('common.no_results')) ?></div>
        <?php else: ?>
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th><?= e(t('common.mechanic')) ?></th><th><?= e(t('repairs.works_done')) ?></th><th><?= e(t('repairs.total_cost')) ?></th><th><?= e(t('repairs.status')) ?></th><th class="text-end"><?= e(t('common.actions')) ?></th></tr></thead>
            <tbody>
              <?php foreach ($repairs as $r): ?>
                <tr>
                  <td><?= e($r['mech_name'] ?: '—') ?></td>
                  <td><span class="text-truncate d-inline-block" style="max-width:240px;" title="<?= e($r['works_done'] ?? '') ?>"><?= e(mb_strimwidth((string) ($r['works_done'] ?? $r['works_planned'] ?? ''), 0, 60, '…')) ?></span></td>
                  <td class="fw-semibold"><?= money((float) $r['parts_total'] + (float) $r['labor_cost']) ?></td>
                  <td>
                    <span class="badge text-bg-<?= $r['status'] === 'terminee' ? 'success' : 'primary' ?>">
                      <?= e($r['status'] === 'terminee' ? t('repairs.done') : t('repairs.in_progress')) ?>
                    </span>
                  </td>
                  <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="../repairs/view.php?id=<?= (int) $r['id'] ?>"><i class="bi bi-eye"></i></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modale livraison -->
<div class="modal fade" id="deliverModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="deliver">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-box-seam me-2 text-success"></i><?= e(t('vehicles.deliver_title')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p><?= e(t('vehicles.deliver_msg')) ?></p>
          <label class="form-label" for="deliverComment"><?= e(t('vehicles.deliver_comment')) ?></label>
          <textarea class="form-control" id="deliverComment" name="comment" rows="2"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i><?= e(t('common.confirm')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modale annulation -->
<div class="modal fade" id="cancelModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-x-octagon me-2 text-danger"></i><?= e(t('vehicles.cancel_title')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p><?= e(t('vehicles.cancel_msg')) ?></p>
          <label class="form-label" for="cancelComment"><?= e(t('vehicles.cancel_comment')) ?></label>
          <textarea class="form-control" id="cancelComment" name="comment" rows="2"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('common.confirm')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
