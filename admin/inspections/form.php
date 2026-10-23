<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$editing = $id > 0;
$inspection = $editing ? fetch_one("SELECT * FROM inspections WHERE id = ?", [$id]) : null;
if ($editing && !$inspection) {
    flash('danger', t('error.not_found'));
    redirect('admin/inspections/index.php');
}
$forcedVehicleId = (int) ($_GET['vehicle_id'] ?? 0);

$clients = fetch_all("SELECT id, first_name, last_name FROM clients ORDER BY last_name, first_name");
$vehicles = fetch_all("SELECT v.*, c.first_name, c.last_name FROM vehicles v JOIN clients c ON c.id = v.client_id ORDER BY v.created_at DESC");
$mechanics = fetch_all("SELECT id, full_name FROM mechanics WHERE is_active = 1 ORDER BY full_name");

/* Données servant à l'aperçu dynamique du véhicule (panneau latéral). */
$vehiclePreview = [];
foreach ($vehicles as $v) {
    $vehiclePreview[(int) $v['id']] = [
        'plate'  => (string) $v['license_plate'],
        'model'  => trim($v['brand'] . ' ' . $v['model']),
        'year'   => $v['year'] !== null ? (string) $v['year'] : '',
        'client' => trim($v['first_name'] . ' ' . $v['last_name']),
        'vin'    => (string) ($v['vin'] ?? ''),
        'status' => (string) $v['status'],
        'badge'  => status_badge((string) $v['status']),
    ];
}

$old = [
    'vehicle_id' => $forcedVehicleId, 'mileage' => '',
    'inspection_date' => date('Y-m-d'), 'mechanic_id' => '',
    'problem_description' => '', 'initial_diagnostic' => '',
    'parts_to_check' => '', 'observations' => '', 'photo' => '',
];
if ($inspection) {
    foreach ($old as $k => $v) {
        $old[$k] = (string) ($inspection[$k] ?? '');
    }
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    foreach ($old as $k => $v) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    $vehicle = $old['vehicle_id'] !== '' ? fetch_one("SELECT * FROM vehicles WHERE id = ?", [(int) $old['vehicle_id']]) : null;
    if (!$vehicle) {
        $errors[] = t('error.required') . ' : ' . t('inspections.vehicle');
    }
    if ($old['problem_description'] === '') {
        $errors[] = t('error.required') . ' : ' . t('inspections.problem');
    }
    if ($old['mechanic_id'] === '' || !ctype_digit($old['mechanic_id'])) {
        $errors[] = t('error.required') . ' : ' . t('inspections.mechanic');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['inspection_date'])) {
        $old['inspection_date'] = date('Y-m-d');
    }
    if ($old['mileage'] !== '' && !ctype_digit($old['mileage'])) {
        $errors[] = e(t('inspections.mileage')) . ' : ' . t('error.invalid_input');
    }
    $old['mechanic_id'] = $old['mechanic_id'] !== '' && ctype_digit($old['mechanic_id']) ? (int) $old['mechanic_id'] : null;

    // photo
    $photoFile = $old['photo'];
    if (!empty($_FILES['photo']['name'])) {
        [$upOk, $upMsg, $upPath] = upload_file($_FILES['photo']);
        if (!$upOk) {
            $errors[] = $upMsg;
        } else {
            $photoFile = $upPath ?: $photoFile;
            if ($editing && $inspection['photo'] && $upPath && $inspection['photo'] !== $upPath && is_file(UPLOAD_DIR . '/' . $inspection['photo'])) {
                @unlink(UPLOAD_DIR . '/' . $inspection['photo']);
            }
        }
    }

    if (!$errors) {
        $client = fetch_one("SELECT id FROM clients WHERE id = ?", [$vehicle['client_id']]);
        if ($editing) {
            execute(
                "UPDATE inspections SET vehicle_id=?, mileage=?, inspection_date=?, mechanic_id=?,
                 problem_description=?, initial_diagnostic=?, parts_to_check=?, observations=?, photo=? WHERE id=?",
                [
                    (int) $vehicle['id'],
                    $old['mileage'] !== '' ? (int) $old['mileage'] : null, $old['inspection_date'], $old['mechanic_id'],
                    $old['problem_description'], $old['initial_diagnostic'] ?: null,
                    $old['parts_to_check'] ?: null, $old['observations'] ?: null, $photoFile ?: null, $id,
                ]
            );
            log_activity('inspection_updated', 'inspections', $id, t('common.update') . ' #' . $id . ' — ' . $vehicle['license_plate']);
            flash('success', t('flash.updated'));
        } else {
            execute(
                "INSERT INTO inspections (vehicle_id, client_id, mileage, inspection_date, mechanic_id,
                 problem_description, initial_diagnostic, parts_to_check, observations, photo, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [
                    (int) $vehicle['id'], (int) $client['id'],
                    $old['mileage'] !== '' ? (int) $old['mileage'] : null, $old['inspection_date'], $old['mechanic_id'],
                    $old['problem_description'], $old['initial_diagnostic'] ?: null,
                    $old['parts_to_check'] ?: null, $old['observations'] ?: null, $photoFile ?: null, current_user_id(),
                ]
            );
            $newId = last_insert_id();

            // Étape 1 du workflow : le véhicule passe à l'état « En cours »
            if ($vehicle['status'] === 'initial') {
                change_vehicle_status((int) $vehicle['id'], 'en_cours', $old['problem_description']);
            }
            // notification au mécanicien éventuellement affecté
            if ($old['mechanic_id']) {
                $mu = fetch_one("SELECT id FROM users WHERE mechanic_id = ? AND is_active = 1", [$old['mechanic_id']]);
                if ($mu) {
                    notify_user((int) $mu['id'], t('inspections.title'),
                        t('common.details') . ' : ' . $vehicle['brand'] . ' ' . $vehicle['model'] . ' ' . $vehicle['license_plate'],
                        'info', 'mechanic/vehicles.php');
                }
            }
            log_activity('inspection_created', 'inspections', $newId, t('log.inspection_created', $vehicle['brand'] . ' ' . $vehicle['model'] . ' ' . $vehicle['license_plate']));
            flash('success', t('flash.saved'));
        }
        redirect('admin/inspections/view.php?id=' . ($editing ? $id : $newId));
    }
}

$pageTitle = $editing ? t('inspections.edit_title') : t('inspections.add');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-<?= $editing ? 'pencil' : 'clipboard-check' ?> me-2 text-danger"></i>
      <?= e($editing ? t('inspections.edit_title') : t('inspections.add')) ?>
    </h1>
    <div class="subtitle"><?= e(t('inspections.required_hint')) ?></div>
  </div>
  <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<?php if ($errors): ?>
  <?= banner('danger', '', t('common.form_errors'), $errors) ?>
<?php endif; ?>
<?php if (!$mechanics): ?>
  <?= banner('warning', t('inspections.no_mechanic'), t('common.attention')) ?>
<?php endif; ?>
<?php if (!$editing): ?>
  <?= banner('info', t('inspections.vehicle_hint')) ?>
<?php endif; ?>

<div class="insp-layout">
 <div class="insp-main">
  <div class="card insp-card">
    <div class="insp-card-head">
      <span class="insp-head-ico"><i class="bi bi-clipboard2-pulse"></i></span>
      <span class="insp-head-txt">
        <strong><?= e($editing ? t('inspections.edit_title') : t('inspections.add')) ?></strong>
        <small><?= e(t('inspections.sec_vehicle')) ?> &middot; <?= e(t('inspections.sec_report')) ?> &middot; <?= e(t('inspections.sec_media')) ?></small>
      </span>
      <?php if ($editing): ?><span class="insp-head-tag">#<?= (int) $id ?></span><?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data" novalidate id="inspectionForm" autocomplete="off" data-chars-template="<?= e(t('inspections.chars', 0)) ?>">
      <?= csrf_field() ?>

      <section class="insp-section">
        <h2 class="insp-section-title"><span class="insp-section-ico"><i class="bi bi-car-front"></i></span><?= e(t('inspections.sec_vehicle')) ?></h2>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label required" for="vehicle_id"><?= e(t('inspections.vehicle')) ?></label>
            <div class="insp-control-icon">
              <i class="bi bi-car-front-fill"></i>
              <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                <option value=""><?= e(t('inspections.select_vehicle_ph')) ?></option>
                <?php foreach ($vehicles as $v): ?>
                  <option value="<?= (int) $v['id'] ?>" <?= (int) $old['vehicle_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['license_plate']) ?> — <?= e(trim($v['brand'] . ' ' . $v['model'])) ?> — <?= e(trim($v['first_name'] . ' ' . $v['last_name'])) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label required" for="inspection_date"><?= e(t('inspections.date')) ?></label>
            <div class="insp-control-icon">
              <i class="bi bi-calendar-event"></i>
              <input type="date" class="form-control" id="inspection_date" name="inspection_date" value="<?= e($old['inspection_date']) ?>" required>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="mileage"><?= e(t('inspections.mileage')) ?> (km)</label>
            <div class="insp-control-icon">
              <i class="bi bi-speedometer2"></i>
              <input type="number" class="form-control" id="mileage" name="mileage" value="<?= e($old['mileage']) ?>" min="0" step="1" placeholder="<?= e(t('inspections.mileage_ph')) ?>">
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label required" for="mechanic_id"><?= e(t('inspections.mechanic')) ?></label>
            <div class="insp-control-icon">
              <i class="bi bi-person-badge"></i>
              <select class="form-select" id="mechanic_id" name="mechanic_id" required<?= $mechanics ? '' : ' disabled' ?>>
                <option value=""><?= e(t('inspections.select_mech_ph')) ?></option>
                <?php foreach ($mechanics as $m): ?>
                  <option value="<?= (int) $m['id'] ?>" <?= (int) $old['mechanic_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-text"><i class="bi bi-info-circle me-1"></i><?= e(t('inspections.mech_hint')) ?></div>
          </div>
        </div>
      </section>
      <section class="insp-section">
        <h2 class="insp-section-title"><span class="insp-section-ico"><i class="bi bi-journal-text"></i></span><?= e(t('inspections.sec_report')) ?></h2>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label required" for="problem_description"><?= e(t('inspections.problem')) ?></label>
            <textarea class="form-control" id="problem_description" name="problem_description" rows="3" maxlength="1000" required data-counter placeholder="<?= e(t('inspections.problem_ph')) ?>"><?= e($old['problem_description']) ?></textarea>
            <div class="insp-counter" data-counter-for="problem_description"><?= e(t('inspections.chars', mb_strlen($old['problem_description']))) ?> / 1000</div>
          </div>
          <div class="col-12">
            <label class="form-label" for="initial_diagnostic"><?= e(t('inspections.diagnostic')) ?></label>
            <textarea class="form-control" id="initial_diagnostic" name="initial_diagnostic" rows="3" maxlength="1000" data-counter placeholder="<?= e(t('inspections.diagnostic_ph')) ?>"><?= e($old['initial_diagnostic']) ?></textarea>
            <div class="insp-counter" data-counter-for="initial_diagnostic"><?= e(t('inspections.chars', mb_strlen($old['initial_diagnostic']))) ?> / 1000</div>
          </div>
        </div>
      </section>

      <section class="insp-section">
        <h2 class="insp-section-title"><span class="insp-section-ico"><i class="bi bi-tools"></i></span><?= e(t('inspections.sec_details')) ?></h2>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="parts_to_check"><?= e(t('inspections.parts_check')) ?></label>
            <textarea class="form-control" id="parts_to_check" name="parts_to_check" rows="4" maxlength="1000" data-counter placeholder="<?= e(t('inspections.parts_ph')) ?>"><?= e($old['parts_to_check']) ?></textarea>
            <div class="insp-counter" data-counter-for="parts_to_check"><?= e(t('inspections.chars', mb_strlen($old['parts_to_check']))) ?> / 1000</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="observations"><?= e(t('inspections.observations')) ?></label>
            <textarea class="form-control" id="observations" name="observations" rows="4" maxlength="1000" data-counter placeholder="<?= e(t('inspections.obs_ph')) ?>"><?= e($old['observations']) ?></textarea>
            <div class="insp-counter" data-counter-for="observations"><?= e(t('inspections.chars', mb_strlen($old['observations']))) ?> / 1000</div>
          </div>
        </div>
      </section>

      <section class="insp-section">
        <h2 class="insp-section-title"><span class="insp-section-ico"><i class="bi bi-camera"></i></span><?= e(t('inspections.sec_media')) ?></h2>
        <div class="insp-drop" id="inspDrop">
          <input type="file" class="visually-hidden" id="photo" name="photo" accept=".jpg,.jpeg,.png,.webp">
          <i class="bi bi-cloud-arrow-up insp-drop-ico"></i>
          <div class="insp-drop-title"><?= e(t('inspections.photo_hint')) ?></div>
          <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="inspBrowse"><i class="bi bi-folder2-open me-1"></i><?= e(t('inspections.photo_browse')) ?></button>
          <div class="insp-drop-preview" id="inspDropPreview" hidden>
            <img src="" alt="" id="inspDropImg">
            <div class="insp-drop-meta">
              <span id="inspDropName"></span>
              <button type="button" class="btn btn-sm btn-outline-danger" id="inspDropClear"><i class="bi bi-trash3 me-1"></i><?= e(t('inspections.photo_remove')) ?></button>
            </div>
          </div>
        </div>
        <?php if ($old['photo']): ?>
          <div class="insp-current mt-3">
            <span class="insp-current-label"><?= e(t('inspections.photo_current')) ?></span>
            <img src="<?= e(upload_url($old['photo'])) ?>" alt="" class="insp-current-img">
          </div>
        <?php endif; ?>
      </section>

      <div class="insp-actions">
        <span class="insp-actions-hint"><i class="bi bi-shield-check me-1"></i><?= e(t('inspections.required_hint')) ?></span>
        <a href="index.php" class="btn btn-outline-secondary"><?= e(t('common.cancel')) ?></a>
        <button type="submit" class="btn btn-danger" id="inspSubmit">
          <span class="insp-btn-label"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></span>
          <span class="insp-btn-loading d-none"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span><?= e(t('inspections.saving')) ?></span>
        </button>
      </div>
    </form>
  </div>
 </div>

  <aside class="insp-side">
    <div class="card insp-panel">
      <div class="insp-panel-head"><i class="bi bi-speedometer2"></i><?= e(t('inspections.sec_summary')) ?></div>
      <div class="insp-panel-body">
        <div class="insp-panel-empty" id="inspPvEmpty">
          <i class="bi bi-car-front"></i>
          <span><?= e(t('inspections.select_vehicle_ph')) ?></span>
        </div>
        <div id="inspPvLive" hidden>
          <div class="insp-pv-plate"><i class="bi bi-upc-scan"></i><span id="inspPvPlate"></span></div>
          <div class="insp-pv-model" id="inspPvModel"></div>
          <div class="insp-pv-status" id="inspPvStatus"></div>
          <ul class="insp-pv-list">
            <li><i class="bi bi-person"></i><span id="inspPvClient"></span></li>
            <li><i class="bi bi-hash"></i><span id="inspPvVin"></span></li>
            <li><i class="bi bi-calendar3"></i><span id="inspPvYear"></span></li>
          </ul>
        </div>
      </div>
    </div>

    <div class="card insp-panel mt-3">
      <div class="insp-panel-head"><i class="bi bi-person-badge"></i><?= e(t('inspections.assigned_to')) ?></div>
      <div class="insp-panel-body">
        <div class="insp-panel-empty" id="inspMechEmpty"><?= e(t('inspections.select_mech_ph')) ?></div>
        <div class="insp-mech" id="inspMechLive" hidden>
          <span class="insp-mech-avatar" id="inspMechAvatar"></span>
          <span class="insp-mech-txt">
            <strong id="inspMechName"></strong>
            <small id="inspMechSpec"></small>
          </span>
        </div>
      </div>
    </div>
  </aside>
</div>

<script id="inspVehiclesData" type="application/json"><?= json_encode($vehiclePreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script src="<?= e(asset_url('assets/js/inspection-form.js')) ?>"></script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
