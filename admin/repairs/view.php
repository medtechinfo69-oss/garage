<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$repair = fetch_one(
    "SELECT r.*, v.license_plate, v.brand, v.model, v.status veh_status,
            c.first_name, c.last_name, m.full_name mech_name
     FROM repairs r
     JOIN vehicles v ON v.id = r.vehicle_id
     JOIN clients c ON c.id = r.client_id
     LEFT JOIN mechanics m ON m.id = r.mechanic_id
     WHERE r.id = ?",
    [$id]
);
if (!$repair) {
    flash('danger', t('error.not_found'));
    redirect('admin/repairs/index.php');
}

/* ---------- POST : mise à jour (admin) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $worksDone = trim((string) ($_POST['works_done'] ?? ''));
    $worksPlanned = trim((string) ($_POST['works_planned'] ?? ''));
    $labor = max(0, (float) str_replace(',', '.', (string) ($_POST['labor_cost'] ?? 0)));
    $comments = trim((string) ($_POST['comments'] ?? ''));
    $endDate = (string) ($_POST['end_date'] ?? '');
    if ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = '';
    }

    $isNewPart = !empty($_POST['new_part_desc']);
    $newPartDesc = trim((string) ($_POST['new_part_desc'] ?? ''));
    $newPartQty = max(0, (float) str_replace(',', '.', (string) ($_POST['new_part_qty'] ?? 1)));
    $newPartPrice = max(0, (float) str_replace(',', '.', (string) ($_POST['new_part_price'] ?? 0)));
    $removePart = (int) ($_POST['remove_part'] ?? 0);

    execute(
        "UPDATE repairs SET works_done=?, works_planned=?, labor_cost=?, comments=?, end_date=COALESCE(NULLIF(?, ''), end_date) WHERE id=?",
        [$worksDone !== '' ? $worksDone : null, $worksPlanned !== '' ? $worksPlanned : null,
         $labor, $comments !== '' ? $comments : null, $endDate, $id]
    );
    if ($removePart > 0) {
        execute("DELETE FROM repair_parts WHERE id = ? AND repair_id = ?", [$removePart, $id]);
    }
    if ($isNewPart && $newPartDesc !== '') {
        execute(
            "INSERT INTO repair_parts (repair_id, description, quantity, unit_price, total) VALUES (?,?,?,?,?)",
            [$id, mb_substr($newPartDesc, 0, 255), $newPartQty, $newPartPrice, $newPartQty * $newPartPrice]
        );
    }
    log_activity('repair_updated', 'repairs', $id, t('log.facture_updated', 'R#' . $id));
    flash('success', t('flash.updated'));
    redirect('admin/repairs/view.php?id=' . $id);
}

/* The joined repair record above already contains the fields needed by the view. */
$parts = fetch_all("SELECT * FROM repair_parts WHERE repair_id = ? ORDER BY id", [$id]);
$partsTotal = array_sum(array_map(fn($p) => (float) $p['total'], $parts));

$pageTitle = t('repairs.view');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-wrench-adjustable me-2 text-danger"></i><?= e(t('repairs.view')) ?> #<?= (int) $repair['id'] ?>
      — <?= e($repair['license_plate']) ?>
      <span class="badge text-bg-<?= $repair['status'] === 'terminee' ? 'success' : 'primary' ?> ms-2">
        <?= e($repair['status'] === 'terminee' ? t('repairs.done') : t('repairs.in_progress')) ?>
      </span>
    </h1>
    <div class="subtitle">
      <?= e(trim($repair['brand'] . ' ' . $repair['model'])) ?> · <?= e(trim($repair['first_name'] . ' ' . $repair['last_name'])) ?>
      · <?= e(t('repairs.mechanic')) ?> : <?= e($repair['mech_name'] ?: '—') ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap action-row">
    <a href="../vehicles/view.php?id=<?= (int) $repair['vehicle_id'] ?>" class="btn btn-outline-primary"><i class="bi bi-car-front me-1"></i><span class="btn-label"><?= e(t('vehicles.view_title')) ?></span></a>
    <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><span class="btn-label"><?= e(t('common.back')) ?></span></a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-pencil-square me-2"></i><?= e(t('repairs.update')) ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label"><?= e(t('repairs.diagnostic')) ?></label>
            <textarea class="form-control" rows="2" readonly><?= e($repair['diagnostic'] ?? '') ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label" for="works_planned"><?= e(t('repairs.works_planned')) ?></label>
            <textarea class="form-control" id="works_planned" name="works_planned" rows="2"><?= e($repair['works_planned'] ?? '') ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label" for="works_done"><?= e(t('repairs.works_done')) ?></label>
            <textarea class="form-control" id="works_done" name="works_done" rows="2"><?= e($repair['works_done'] ?? '') ?></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label" for="labor_cost"><?= e(t('repairs.labor')) ?> (<?= e(currency()) ?>)</label>
              <input type="number" step="0.01" min="0" class="form-control" id="labor_cost" name="labor_cost" value="<?= e(number_format((float) $repair['labor_cost'], 2, '.', '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="end_date"><?= e(t('repairs.end')) ?></label>
              <input type="date" class="form-control" id="end_date" name="end_date" value="<?= e($repair['end_date'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="comments"><?= e(t('repairs.comments')) ?></label>
            <textarea class="form-control" id="comments" name="comments" rows="2"><?= e($repair['comments'] ?? '') ?></textarea>
          </div>
          <div class="row g-2 align-items-end mb-3">
            <div class="col-md-5">
              <label class="form-label" for="new_part_desc"><?= e(t('repairs.part_add')) ?></label>
              <input type="text" class="form-control" id="new_part_desc" name="new_part_desc" placeholder="<?= e(t('parts.new_desc_ph')) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label" for="new_part_qty"><?= e(t('common.quantity')) ?></label>
              <input type="number" step="0.01" min="0" class="form-control" id="new_part_qty" name="new_part_qty" value="1">
            </div>
            <div class="col-md-2">
              <label class="form-label" for="new_part_price"><?= e(t('common.unit_price')) ?></label>
              <input type="number" step="0.01" min="0" class="form-control" id="new_part_price" name="new_part_price" value="0">
            </div>
            <div class="col-md-3">
              <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg me-1"></i><?= e(t('common.add')) ?></button>
            </div>
          </div>
          <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('repairs.update')) ?></button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-box me-2"></i><?= e(t('repairs.parts')) ?></div>
      <div class="table-responsive">
        <?php if (!$parts): ?>
          <div class="text-muted small text-center py-3"><?= e(t('common.no_results')) ?></div>
        <?php else: ?>
          <table class="table table-sm mb-0">
            <thead><tr><th><?= e(t('common.description')) ?></th><th><?= e(t('common.quantity')) ?></th><th><?= e(t('common.unit_price')) ?></th><th><?= e(t('common.total')) ?></th><th></th></tr></thead>
            <tbody>
              <?php foreach ($parts as $p): ?>
                <tr>
                  <td><?= e($p['description']) ?></td>
                  <td><?= num_short((float) $p['quantity']) ?></td>
                  <td><?= money((float) $p['unit_price']) ?></td>
                  <td class="fw-semibold"><?= money((float) $p['total']) ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" data-confirm="<?= e(t('common.delete_confirm_msg')) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="remove_part" value="<?= (int) $p['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <th colspan="3" class="text-end"><?= e(t('repairs.parts_cost')) ?></th>
                <th colspan="2" class="fw-bold"><?= money($partsTotal) ?></th>
              </tr>
            </tfoot>
          </table>
        <?php endif; ?>
      </div>
      <div class="card-footer bg-white border-top-0">
        <div class="d-flex justify-content-between">
          <span><?= e(t('repairs.labor')) ?></span><strong><?= money((float) $repair['labor_cost']) ?></strong>
        </div>
        <div class="d-flex justify-content-between fw-bold fs-5 border-top pt-2 mt-2">
          <span><?= e(t('repairs.total_cost')) ?></span><span><?= money($partsTotal + (float) $repair['labor_cost']) ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
