<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$editing = $id > 0;
$vehicle = $editing ? fetch_one("SELECT * FROM vehicles WHERE id = ?", [$id]) : null;
if ($editing && !$vehicle) {
    flash('danger', t('error.not_found'));
    redirect('admin/vehicles/index.php');
}
$clients = fetch_all("SELECT id, first_name, last_name FROM clients ORDER BY last_name, first_name");
$forcedClientId = (int) ($_GET['client_id'] ?? 0);

$old = [
    'client_id' => $forcedClientId, 'license_plate' => '', 'brand' => '', 'model' => '',
    'year' => '', 'vin' => '', 'mileage' => '', 'fuel' => '', 'color' => '',
    'entry_date' => date('Y-m-d'), 'notes' => '',
];
if ($vehicle) {
    foreach ($old as $k => $v) {
        $old[$k] = (string) ($vehicle[$k] ?? '');
    }
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    foreach ($old as $k => $v) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if ($old['client_id'] === '' || !is_numeric($old['client_id'])
        || !fetch_one("SELECT id FROM clients WHERE id = ?", [(int) $old['client_id']])) {
        $errors[] = t('error.required') . ' : ' . t('vehicles.client');
    }
    if ($old['license_plate'] === '' || $old['brand'] === '' || $old['model'] === '') {
        $errors[] = t('error.required');
    }
    // Marque / modèle : longueur max 50 (colonnes VARCHAR(50)) et
    // cohérence marque/modèle. Une valeur absente du catalogue reste
    // acceptée (saisie existante), mais le modèle doit appartenir à
    // la marque dès que celle-ci est connue.
    if (mb_strlen($old['brand']) > 50) {
        $errors[] = e(t('common.brand')) . ' ≤ 50';
        $old['brand'] = mb_substr($old['brand'], 0, 50);
    }
    if (mb_strlen($old['model']) > 50) {
        $errors[] = e(t('common.model')) . ' ≤ 50';
        $old['model'] = mb_substr($old['model'], 0, 50);
    }
    if ($old['brand'] !== '' && $old['model'] !== '') {
        $knownModels = vehicle_models($old['brand']);
        if ($knownModels !== []) {
            $ok = false;
            foreach ($knownModels as $m) {
                if (vehicle_catalog_key($m) === vehicle_catalog_key($old['model'])) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = e(t('common.model')) . ' : ' . t('vehicles.model_not_in_brand');
            }
        }
    }
    $plateExists = fetch_one("SELECT id FROM vehicles WHERE license_plate = ? AND id <> ?", [strtoupper($old['license_plate']), $id]);
    if ($plateExists) {
        $errors[] = t('error.unique_plate');
    }
    if ($old['vin'] !== '') {
        if (strlen($old['vin']) > 17) {
            $errors[] = e(t('common.vin')) . ' ≤ 17';
        }
        $vinExists = fetch_one("SELECT id FROM vehicles WHERE vin = ? AND id <> ?", [$old['vin'], $id]);
        if ($vinExists) {
            $errors[] = t('error.unique_vin');
        }
    }
    if ($old['year'] !== '' && (!ctype_digit($old['year']) || (int) $old['year'] < 1900 || (int) $old['year'] > (int) date('Y') + 1)) {
        $errors[] = e(t('common.year')) . ' : ' . t('error.invalid_input');
    }
    if ($old['mileage'] !== '' && !ctype_digit($old['mileage'])) {
        $errors[] = e(t('common.mileage')) . ' : ' . t('error.invalid_input');
    }
    if (!in_array($old['fuel'], ['', 'Essence', 'Diesel', 'Electrique', 'Hybride', 'GPL'], true)) {
        $old['fuel'] = '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['entry_date'])) {
        $old['entry_date'] = date('Y-m-d');
    }

    if (!$errors) {
        $data = [
            (int) $old['client_id'], strtoupper($old['license_plate']), $old['brand'], $old['model'],
            $old['year'] !== '' ? (int) $old['year'] : null,
            $old['vin'] !== '' ? strtoupper($old['vin']) : null,
            $old['mileage'] !== '' ? (int) $old['mileage'] : null,
            $old['fuel'] !== '' ? $old['fuel'] : null,
            $old['color'] !== '' ? $old['color'] : null,
            $old['entry_date'],
            $old['notes'] !== '' ? $old['notes'] : null,
        ];
        if ($editing) {
            execute(
                "UPDATE vehicles SET client_id=?, license_plate=?, brand=?, model=?, year=?, vin=?, mileage=?, fuel=?, color=?, entry_date=?, notes=? WHERE id=?",
                array_merge($data, [$id])
            );
            log_activity('vehicle_updated', 'vehicles', $id, t('log.vehicle_updated', $old['brand'] . ' ' . $old['model'] . ' ' . $old['license_plate']));
            flash('success', t('flash.updated'));
            redirect('admin/vehicles/view.php?id=' . $id);
        } else {
            execute(
                "INSERT INTO vehicles (client_id, license_plate, brand, model, year, vin, mileage, fuel, color, entry_date, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                $data
            );
            $newId = last_insert_id();
            execute("INSERT INTO vehicle_status_history (vehicle_id, status, previous_status, user_id) VALUES (?, 'initial', NULL, ?)", [$newId, current_user_id()]);
            log_activity('vehicle_created', 'vehicles', $newId, t('log.vehicle_created', $old['brand'] . ' ' . $old['model'] . ' ' . $old['license_plate']));
            flash('success', t('flash.saved'));
            redirect('admin/vehicles/view.php?id=' . $newId);
        }
    }
}

/* ---------- Catalogue Marque / Modèle pour les listes déroulantes ----------
 * - $brands : toutes les marques (référentiel + celles déjà en base), sans doublon ;
 * - $models : uniquement les modèles de la marque actuellement retenue
 *   ($old['brand'], qui peut venir d'un POST invalide). Le modèle courant
 *   est toujours réinjecté afin qu'une fiche existante reste affichée.
 */
$brands = vehicle_brands();
$models = vehicle_models_with($old['brand'], $old['model']);

$pageTitle = $editing ? t('vehicles.edit_title') : t('vehicles.add');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-<?= $editing ? 'pencil' : 'car-front' ?> me-2 text-danger"></i>
      <?= e($editing ? t('vehicles.edit_title') : t('vehicles.add')) ?>
    </h1>
  </div>
  <a href="<?= e(app_url($editing ? 'admin/vehicles/view.php?id=' . $id : 'admin/vehicles/index.php')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<?php if ($errors): ?>
  <?= banner('danger', '', t('common.form_errors'), $errors) ?>
<?php endif; ?>

<div class="card form-narrow">
  <div class="card-body">
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label required" for="client_id"><?= e(t('vehicles.client')) ?></label>
          <select class="form-select" id="client_id" name="client_id" required>
            <option value=""><?= e(t('common.all')) ?>…</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $old['client_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label required" for="license_plate"><?= e(t('vehicles.plate')) ?></label>
          <input type="text" class="form-control text-uppercase" id="license_plate" name="license_plate" value="<?= e($old['license_plate']) ?>" maxlength="20" required placeholder="ABC 123">
        </div>
        <div class="col-md-3">
          <label class="form-label" for="entry_date"><?= e(t('vehicles.entry_date')) ?></label>
          <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= e($old['entry_date']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label required" for="brand"><?= e(t('common.brand')) ?></label>
          <?= vcombo_html('brand', 'brand', $old['brand'], $brands, t('vehicles.brand_search'), t('vehicles.no_brand_found')) ?>
        </div>
        <div class="col-md-4">
          <label class="form-label required" for="model"><?= e(t('common.model')) ?></label>
          <?= vcombo_html(
                'model', 'model', $old['model'], $models,
                t('vehicles.model_search'), t('vehicles.no_model_found'),
                $old['brand'] === '',
                $old['brand'] === '' ? t('vehicles.pick_brand_first') : t('vehicles.pick_model_hint'),
                'brand'
              ) ?>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="year"><?= e(t('common.year')) ?></label>
          <input type="number" class="form-control" id="year" name="year" value="<?= e($old['year']) ?>" min="1900" max="<?= (int) date('Y') + 1 ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="vin"><?= e(t('common.vin')) ?></label>
          <input type="text" class="form-control text-uppercase" id="vin" name="vin" value="<?= e($old['vin']) ?>" maxlength="17">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="mileage"><?= e(t('common.mileage')) ?> (km)</label>
          <input type="number" class="form-control" id="mileage" name="mileage" value="<?= e($old['mileage']) ?>" min="0">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="fuel"><?= e(t('common.fuel')) ?></label>
          <select class="form-select" id="fuel" name="fuel">
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach (['Essence', 'Diesel', 'Electrique', 'Hybride', 'GPL'] as $f): ?>
              <option value="<?= $f ?>" <?= $old['fuel'] === $f ? 'selected' : '' ?>><?= e(fuel_label($f)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="color"><?= e(t('common.color')) ?></label>
          <input type="text" class="form-control" id="color" name="color" value="<?= e($old['color']) ?>" maxlength="40">
        </div>
        <div class="col-12">
          <label class="form-label" for="notes"><?= e(t('common.notes')) ?></label>
          <textarea class="form-control" id="notes" name="notes" rows="2"><?= e($old['notes']) ?></textarea>
        </div>
      </div>
      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></button>
        <a href="<?= e(app_url('admin/vehicles/index.php')) ?>" class="btn btn-secondary"><?= e(t('common.cancel')) ?></a>
      </div>
    </form>
  </div>
</div>

<?php /* Catalogue marques => modèles consommé par app.js (vcombo).
        JSON échappé (JSON_HEX_*) : aucune injection possible. */ ?>
<script type="application/json" id="vcombo-data"><?= vehicle_catalog_json() ?></script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
