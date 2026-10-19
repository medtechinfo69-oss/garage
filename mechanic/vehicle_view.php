<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_mechanic()) {
    redirect('admin/dashboard.php');
}

$u = current_user();
$mechId = (int) $u['mechanic_id'];
$id = (int) ($_GET['id'] ?? 0);

// Un mécanicien ne voit QUE les véhicules qui lui sont attribués
$vehicle = fetch_one(
    "SELECT v.*, c.first_name, c.last_name, c.phone, c.email
     FROM vehicles v JOIN clients c ON c.id = v.client_id
     WHERE v.id = ? AND v.mechanic_id = ?",
    [$id, $mechId]
);
if (!$vehicle) {
    flash('danger', t('error.unauthorized'));
    redirect('mechanic/vehicles.php');
}

$repair = fetch_one(
    "SELECT r.* FROM repairs r
     WHERE r.vehicle_id = ? ORDER BY FIELD(r.status, 'en_cours', 'terminee'), r.id DESC LIMIT 1",
    [$id]
);
$parts = $repair ? fetch_all("SELECT * FROM repair_parts WHERE repair_id = ? ORDER BY id", [$repair['id']]) : [];
$partsTotal = array_sum(array_map(fn($p) => (float) $p['total'], $parts));

$lastAssignment = fetch_one(
    "SELECT * FROM vehicle_assignments WHERE vehicle_id = ? ORDER BY id DESC LIMIT 1",
    [$id]
);
$lastInspection = fetch_one(
    "SELECT * FROM inspections WHERE vehicle_id = ? ORDER BY id DESC LIMIT 1",
    [$id]
);

/* ---------- POST : update réparation + terminer ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'update');

    if ($vehicle['status'] !== 'en_reparation' && $action !== 'view_only') {
        flash('danger', t('error.invalid_status', e(status_label($vehicle['status'])), e(status_label('repare'))));
        redirect('mechanic/vehicle_view.php?id=' . $id);
    }

    if ($action === 'finish') {
        $comment = trim((string) ($_POST['finish_comment'] ?? ''));
        [$ok, $msg] = change_vehicle_status($id, 'repare', $comment !== '' ? $comment : null, $mechId);
        if ($ok && $repair) {
            execute("UPDATE repairs SET status = 'terminee', end_date = CURDATE(), works_done = COALESCE(NULLIF(?, ''), works_done), comments = COALESCE(NULLIF(?, ''), comments) WHERE id = ?",
                [$comment, $comment, (int) $repair['id']]);
        }
        flash($ok ? 'success' : 'danger', $msg);
        redirect('mechanic/vehicles.php');
    }

    if ($action === 'update') {
        if ($repair) {
            $worksDone = trim((string) ($_POST['works_done'] ?? ''));
            $labor = max(0, (float) str_replace(',', '.', (string) ($_POST['labor_cost'] ?? 0)));
            $comments = trim((string) ($_POST['comments'] ?? ''));
            execute(
                "UPDATE repairs SET works_done = NULLIF(?, ''), labor_cost = ?, comments = NULLIF(?, '') WHERE id = ?",
                [$worksDone, $labor, $comments, (int) $repair['id']]
            );
            $removePart = (int) ($_POST['remove_part'] ?? 0);
            if ($removePart > 0) {
                execute("DELETE FROM repair_parts WHERE id = ? AND repair_id = ?", [$removePart, (int) $repair['id']]);
            }
            $newDesc = trim((string) ($_POST['new_part_desc'] ?? ''));
            $newQty = max(0, (float) str_replace(',', '.', (string) ($_POST['new_part_qty'] ?? 1)));
            $newPrice = max(0, (float) str_replace(',', '.', (string) ($_POST['new_part_price'] ?? 0)));
            if ($newDesc !== '') {
                execute(
                    "INSERT INTO repair_parts (repair_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)",
                    [(int) $repair['id'], mb_substr($newDesc, 0, 255), $newQty, $newPrice, $newQty * $newPrice]
                );
            }
            log_activity('repair_mech_update', 'repairs', (int) $repair['id'], $u['full_name'] . ' : ' . t('common.update') . ' #' . $id);
            flash('success', t('flash.updated'));
        }
        redirect('mechanic/vehicle_view.php?id=' . $id);
    }
}

$canWork = $vehicle['status'] === 'en_reparation' && $repair;
$pageTitle = $vehicle['license_plate'];
require __DIR__ . '/../includes/header.php';
?>

<?= vehicle_hero($vehicle, [
    'eyebrow' => t('mech.my_vehicles'),
    'footer'  => [
        ['icon' => 'bi-person-workspace', 'text' => t('mech.dashboard') . ' — ' . $u['full_name']],
        ['icon' => 'bi-calendar3', 'text' => fmt_date_long(date('Y-m-d'))],
    ],
]) ?>

<div class="vh-actions">
  <?php if ($canWork): ?>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#finishModal">
      <i class="bi bi-check2-circle me-1"></i><?= e(t('mech.finish')) ?>
    </button>
  <?php endif; ?>
  <a href="vehicles.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<div class="row g-4 vehicle-view-grid">
  <div class="col-lg-4 vehicle-diagnostic-column">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-clipboard-pulse me-2"></i><?= e(t('mech.diagnostic')) ?></div>
      <div class="card-body">
        <?php if ($lastInspection): ?>
          <div class="small text-muted mb-1"><?= e(t('inspections.date')) ?> : <?= fmt_date($lastInspection['inspection_date']) ?></div>
          <p class="mb-2"><?= nl2br(e($lastInspection['problem_description'])) ?></p>
          <?php if ($lastInspection['initial_diagnostic']): ?>
            <hr class="my-2">
            <div class="small text-muted mb-1"><?= e(t('inspections.diagnostic')) ?></div>
            <?= nl2br(e($lastInspection['initial_diagnostic'])) ?>
          <?php endif; ?>
          <?php if ($lastInspection['parts_to_check']): ?>
            <hr class="my-2">
            <div class="small text-muted mb-1"><?= e(t('inspections.parts_check')) ?></div>
            <?= nl2br(e($lastInspection['parts_to_check'])) ?>
          <?php endif; ?>
        <?php else: ?>
          <div class="text-muted small"><?= e(t('common.no_results')) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($lastAssignment && $lastAssignment['instructions']): ?>
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-chat-left-text me-2"></i><?= e(t('mech.instructions')) ?></div>
        <div class="card-body"><?= nl2br(e($lastAssignment['instructions'])) ?>
          <div class="mt-2"><?= priority_badge($lastAssignment['priority']) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($repair && $repair['works_planned']): ?>
      <div class="card">
        <div class="card-header"><i class="bi bi-list-check me-2"></i><?= e(t('mech.planned_works')) ?></div>
        <div class="card-body"><?= nl2br(e($repair['works_planned'])) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8 vehicle-repair-column">
    <?php if ($canWork): ?>
      <div class="card">
        <div class="card-header"><i class="bi bi-wrench me-2"></i><?= e(t('repairs.update')) ?></div>
        <div class="card-body">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="mb-3">
              <label class="form-label" for="works_done"><?= e(t('mech.works_done_label')) ?></label>
              <textarea class="form-control" id="works_done" name="works_done" rows="3" placeholder="<?= e(t('mech.works_ph')) ?>"><?= e($repair['works_done'] ?? '') ?></textarea>
            </div>
            <div class="row g-2 mb-3">
              <div class="col-md-4">
                <label class="form-label" for="labor_cost"><?= e(t('mech.labor_label')) ?><?= e(currency()) ?><?= e(t('mech.label_end')) ?></label>
                <input type="number" step="0.01" min="0" class="form-control" id="labor_cost" name="labor_cost" value="<?= e(number_format((float) $repair['labor_cost'], 2, '.', '')) ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="comments"><?= e(t('repairs.comments')) ?></label>
              <textarea class="form-control" id="comments" name="comments" rows="2"><?= e($repair['comments'] ?? '') ?></textarea>
            </div>

            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th><?= e(t('mech.parts_label')) ?></th>
                    <th style="width:80px;"><?= e(t('common.quantity')) ?></th>
                    <th style="width:110px;"><?= e(t('common.unit_price')) ?></th>
                    <th style="width:120px;" class="text-end"><?= e(t('common.total')) ?></th>
                    <th style="width:44px;"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($parts as $p): ?>
                    <tr>
                      <td><?= e($p['description']) ?></td>
                      <td><?= num_short((float) $p['quantity']) ?></td>
                      <td><?= money((float) $p['unit_price']) ?></td>
                      <td class="text-end fw-semibold"><?= money((float) $p['total']) ?></td>
                      <td class="text-center">
                        <input type="hidden" name="remove_part" value="0">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-part" data-id="<?= (int) $p['id'] ?>"><i class="bi bi-x-lg"></i></button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$parts): ?>
                    <tr><td colspan="5" class="text-center text-muted small"><?= e(t('common.no_results')) ?></td></tr>
                  <?php endif; ?>
                </tbody>
                <tfoot>
                  <tr>
                    <th class="text-end"><?= e(t('repairs.parts_cost')) ?></th>
                    <th colspan="3"></th>
                    <th class="text-end fw-bold"><?= money($partsTotal) ?></th>
                  </tr>
                </tfoot>
              </table>
            </div>

            <div class="row g-2 align-items-end mb-3">
              <div class="col-md-5">
                <label class="form-label" for="new_part_desc"><?= e(t('repairs.part_add')) ?></label>
                <input type="text" class="form-control" id="new_part_desc" name="new_part_desc" maxlength="255">
              </div>
              <div class="col-md-2">
                <label class="form-label" for="new_part_qty"><?= e(t('common.quantity')) ?></label>
                <input type="number" step="0.01" min="0" class="form-control" id="new_part_qty" name="new_part_qty" value="1">
              </div>
              <div class="col-md-2">
                <label class="form-label" for="new_part_price"><?= e(t('common.unit_price')) ?></label>
                <input type="number" step="0.01" min="0" class="form-control" id="new_part_price" name="new_part_price" value="0">
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center repair-total">
              <div class="fs-6">
                <?= e(t('repairs.total_cost')) ?> :
                <strong class="fs-5"><?= money($partsTotal + (float) $repair['labor_cost']) ?></strong>
              </div>
              <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('repairs.update')) ?></button>
            </div>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-header"><i class="bi bi-wrench-adjustable me-2"></i><?= e(t('repairs.view')) ?></div>
        <div class="card-body">
          <?php if ($repair): ?>
            <?php if ($repair['works_done']): ?>
              <div class="mb-3">
                <div class="small text-muted"><?= e(t('mech.works_label')) ?></div>
                <?= nl2br(e($repair['works_done'])) ?>
              </div>
            <?php endif; ?>
            <table class="table table-sm repair-detail-table">
              <tr><th class="text-muted"><?= e(t('mech.labor_label')) ?><?= e(currency()) ?><?= e(t('mech.label_end')) ?></th>
                  <td class="text-end fw-semibold"><?= money((float) $repair['labor_cost']) ?></td></tr>
              <tr><th class="text-muted"><?= e(t('repairs.parts_cost')) ?></th>
                  <td class="text-end fw-semibold"><?= money($partsTotal) ?></td></tr>
              <tr class="table-light"><th><?= e(t('repairs.total_cost')) ?></th>
                  <th class="text-end fs-5"><?= money($partsTotal + (float) $repair['labor_cost']) ?></th></tr>
            </table>
            <?php if ($repair['comments']): ?>
              <div class="small text-muted"><?= e(t('repairs.comments')) ?> : <?= nl2br(e($repair['comments'])) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted"><?= e(t('common.no_results')) ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modale terminer -->
<div class="modal fade" id="finishModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="finish">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-check2-circle me-2 text-success"></i><?= e(t('mech.finish_confirm_title')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p><?= e(sprintf(t('mech.finish_confirm_msg'), e($vehicle['license_plate']))) ?></p>
          <label class="form-label" for="finish_comment"><?= e(t('mech.finish_comment')) ?></label>
          <textarea class="form-control" id="finish_comment" name="finish_comment" rows="2"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-success"><?= e(t('mech.finish')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.remove-part').forEach(function (btn) {
  btn.addEventListener('click', function () {
    if (confirm('<?= e(t('parts.delete_confirm')) ?>')) {
      document.querySelector('input[name="remove_part"]').value = btn.dataset.id;
    }
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
