<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$forcedVehicleId = (int) ($_GET['vehicle_id'] ?? 0);
$forcedInspectionId = (int) ($_GET['inspection_id'] ?? 0);

/* ---------- POST : enregistrer l'affectation ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
    $inspectionId = (int) ($_POST['inspection_id'] ?? 0);
    $mechanicId = (int) ($_POST['mechanic_id'] ?? 0);
    $aDate = (string) ($_POST['assigned_date'] ?? date('Y-m-d'));
    $aTime = (string) ($_POST['assigned_time'] ?? date('H:i'));
    $priority = (string) ($_POST['priority'] ?? 'normale');
    $instructions = trim((string) ($_POST['instructions'] ?? ''));

    $errors = [];
    $vehicle = $vehicleId ? fetch_one("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]) : null;
    if (!$vehicle) {
        $errors[] = t('error.required') . ' : ' . t('assign.vehicle');
    }
    if ($vehicle && $vehicle['status'] !== 'en_cours') {
        $errors[] = t('error.invalid_status', e(status_label($vehicle['status'])), e(status_label('en_reparation')));
    }
    if (!$mechanicId || !fetch_one("SELECT id FROM mechanics WHERE id = ?", [$mechanicId])) {
        $errors[] = t('error.required') . ' : ' . t('assign.mechanic');
    }
    if (!in_array($priority, ['basse', 'normale', 'haute', 'urgente'], true)) {
        $priority = 'normale';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $aDate)) {
        $aDate = date('Y-m-d');
    }
    if (!preg_match('/^\d{2}:\d{2}/', $aTime)) {
        $aTime = date('H:i');
    }
    $inspection = null;
    if ($inspectionId) {
        $inspection = fetch_one("SELECT * FROM inspections WHERE id = ? AND vehicle_id = ?", [$inspectionId, $vehicleId]);
    }
    if (!$inspection) {
        $inspection = fetch_one("SELECT * FROM inspections WHERE vehicle_id = ? ORDER BY id DESC LIMIT 1", [$vehicleId]);
    }

    if (!$errors) {
        $db = db();
        try {
            $db->beginTransaction();
            execute(
                "INSERT INTO vehicle_assignments (vehicle_id, inspection_id, mechanic_id, assigned_date, assigned_time, priority, instructions, assigned_by)
                 VALUES (?,?,?,?,?,?,?,?)",
                [$vehicleId, $inspection['id'] ?? null, $mechanicId, $aDate, $aTime, $priority, $instructions !== '' ? $instructions : null, current_user_id()]
            );
            // Étape 2 du workflow : état « En réparation » + historisation
            [$ok, $msg] = change_vehicle_status($vehicleId, 'en_reparation', $instructions !== '' ? $instructions : null, $mechanicId);
            if (!$ok) {
                throw new RuntimeException($msg);
            }
            // créer la réparation associée si aucune n'existe
            $repair = fetch_one("SELECT id FROM repairs WHERE vehicle_id = ? AND status = 'en_cours'", [$vehicleId]);
            if (!$repair) {
                execute(
                    "INSERT INTO repairs (vehicle_id, inspection_id, client_id, mechanic_id, diagnostic, works_planned, start_date, created_by)
                     VALUES (?,?,?,?,?,?,?,?)",
                    [
                        $vehicleId, $inspection['id'] ?? null, (int) $vehicle['client_id'], $mechanicId,
                        $inspection['initial_diagnostic'] ?? ($inspection['problem_description'] ?? null),
                        $inspection['parts_to_check'] ?? null,
                        $aDate,
                        current_user_id(),
                    ]
                );
            } else {
                execute(
                    "UPDATE repairs SET mechanic_id = ?, start_date = COALESCE(start_date, ?) WHERE id = ?",
                    [$mechanicId, $aDate, (int) $repair['id']]
                );
            }
            $db->commit();
            log_activity('vehicle_assigned', 'assignments', $vehicleId, t('log.assigned', $vehicle['license_plate'], mech_name($mechanicId)));
            flash('success', t('flash.saved') . ' — ' . $msg);
            redirect('admin/vehicles/view.php?id=' . $vehicleId);
        } catch (Throwable $ex) {
            $db->rollBack();
            flash('danger', $ex->getMessage() ?: t('flash.error_generic'));
            redirect('admin/assignments/form.php?vehicle_id=' . $vehicleId);
        }
    }
} else {
    $errors = [];
}

$eligible = fetch_all(
    "SELECT v.*, c.first_name, c.last_name FROM vehicles v JOIN clients c ON c.id = v.client_id
     WHERE v.status = 'en_cours' ORDER BY v.created_at DESC"
);
$allInCourse = fetch_all("SELECT v.*, c.first_name, c.last_name FROM vehicles v JOIN clients c ON c.id = v.client_id WHERE v.status = 'en_cours'");
$mechanics = fetch_all("SELECT id, full_name FROM mechanics WHERE is_active = 1 ORDER BY full_name");
$inspectionsOf = [];
if ($forcedVehicleId) {
    $inspectionsOf = fetch_all("SELECT id, problem_description, inspection_date FROM inspections WHERE vehicle_id = ? ORDER BY id DESC", [$forcedVehicleId]);
}

$pageTitle = t('assign.add');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-person-plus me-2 text-danger"></i><?= e(t('assign.add')) ?></h1>
    <div class="subtitle"><?= e(t('assign.eligible')) ?></div>
  </div>
  <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<?php if (!empty($errors)): ?>
  <?= banner('danger', '', t('common.form_errors'), $errors) ?>
<?php endif; ?>
<?php if (!$eligible): ?>
  <?= banner('info', t('assign.no_vehicles')) ?>
<?php endif; ?>

<div class="card form-narrow">
  <div class="card-body">
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-7">
          <label class="form-label required" for="vehicle_id"><?= e(t('assign.vehicle')) ?></label>
          <select class="form-select" id="vehicle_id" name="vehicle_id" required>
            <option value=""><?= e(t('common.all')) ?>…</option>
            <?php foreach ($eligible as $v): ?>
              <option value="<?= (int) $v['id'] ?>" <?= (int) ($forcedVehicleId ?: 0) === (int) $v['id'] ? 'selected' : '' ?>>
                <?= e($v['license_plate']) ?> — <?= e(trim($v['brand'] . ' ' . $v['model'])) ?> — <?= e(trim($v['first_name'] . ' ' . $v['last_name'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label" for="inspection_id"><?= e(t('assign.inspection')) ?></label>
          <select class="form-select" id="inspection_id" name="inspection_id">
            <option value=""><?= e(t('assign.latest_inspection')) ?></option>
            <?php foreach ($inspectionsOf as $ins): ?>
              <option value="<?= (int) $ins['id'] ?>" <?= (int) ($forcedInspectionId ?: 0) === (int) $ins['id'] ? 'selected' : '' ?>>
                #<?= (int) $ins['id'] ?> — <?= e(mb_strimwidth($ins['problem_description'], 0, 50, '…')) ?> (<?= fmt_date($ins['inspection_date']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label required" for="mechanic_id"><?= e(t('assign.mechanic')) ?></label>
          <select class="form-select" id="mechanic_id" name="mechanic_id" required>
            <option value=""><?= e(t('common.all')) ?>…</option>
            <?php foreach ($mechanics as $m): ?>
              <option value="<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label required" for="assigned_date"><?= e(t('assign.date')) ?></label>
          <input type="date" class="form-control" id="assigned_date" name="assigned_date" value="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label required" for="assigned_time"><?= e(t('assign.time')) ?></label>
          <input type="time" class="form-control" id="assigned_time" name="assigned_time" value="<?= e(date('H:i')) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="priority"><?= e(t('assign.priority')) ?></label>
          <select class="form-select" id="priority" name="priority">
            <?php foreach (['basse', 'normale', 'haute', 'urgente'] as $p): ?>
              <option value="<?= $p ?>" <?= ($p === 'normale') ? 'selected' : '' ?>><?= e(t('priority.' . $p)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label" for="instructions"><?= e(t('assign.instructions')) ?></label>
          <textarea class="form-control" id="instructions" name="instructions" rows="3"><?= e($_POST['instructions'] ?? '') ?></textarea>
        </div>
      </div>
      <?= banner('info', t('assign.confirm_msg', '…', '…'), null, [], null, false, 'app-banner--sm mt-3 mb-0') ?>
      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i><?= e(t('common.save')) ?></button>
        <a href="index.php" class="btn btn-secondary"><?= e(t('common.cancel')) ?></a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
