<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$editing = $id > 0;
$client = $editing ? fetch_one("SELECT * FROM clients WHERE id = ?", [$id]) : null;
if ($editing && !$client) {
    flash('danger', t('error.not_found'));
    redirect('admin/clients/index.php');
}

$errors = [];
$old = [
    'civility' => 'Monsieur', 'last_name' => '', 'first_name' => '',
    'phone' => '', 'phone2' => '', 'email' => '', 'address' => '',
    'postal_code' => '', 'city' => '', 'notes' => '',
];
if ($client) {
    foreach ($old as $k => $_) {
        $old[$k] = (string) ($client[$k] ?? '');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    foreach ($old as $k => $v) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    if (!in_array($old['civility'], ['Monsieur', 'Madame'], true)) {
        $old['civility'] = 'Monsieur';
    }

    // validation serveur
    if ($old['last_name'] === '' || $old['first_name'] === '') {
        $errors[] = t('error.required');
    }
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('error.invalid_email');
    }
    if ($old['phone'] !== '' && !preg_match('/^[0-9+\s().\-]{5,30}$/', $old['phone'])) {
        $errors[] = t('clients.phone') . ' : ' . t('error.invalid_input');
    }
    if ($old['phone2'] !== '' && !preg_match('/^[0-9+\s().\-]{5,30}$/', $old['phone2'])) {
        $errors[] = t('clients.phone2') . ' : ' . t('error.invalid_input');
    }
    if ($old['postal_code'] !== '' && !preg_match('/^[0-9A-Za-z\-\s]{3,10}$/', $old['postal_code'])) {
        $errors[] = t('clients.postal_code') . ' : ' . t('error.invalid_input');
    }

    if (!$errors) {
        if ($editing) {
            execute(
                "UPDATE clients SET civility=?, last_name=?, first_name=?, phone=?, phone2=?, email=?, address=?, postal_code=?, city=?, notes=? WHERE id=?",
                [$old['civility'], $old['last_name'], $old['first_name'], $old['phone'] ?: null, $old['phone2'] ?: null,
                 $old['email'] ?: null, $old['address'] ?: null, $old['postal_code'] ?: null, $old['city'] ?: null, $old['notes'] ?: null, $id]
            );
            log_activity('client_updated', 'clients', $id, t('log.client_updated', trim($old['first_name'] . ' ' . $old['last_name'])));
            flash('success', t('flash.updated'));
        } else {
            execute(
                "INSERT INTO clients (civility, last_name, first_name, phone, phone2, email, address, postal_code, city, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$old['civility'], $old['last_name'], $old['first_name'], $old['phone'] ?: null, $old['phone2'] ?: null,
                 $old['email'] ?: null, $old['address'] ?: null, $old['postal_code'] ?: null, $old['city'] ?: null, $old['notes'] ?: null]
            );
            $newId = last_insert_id();
            log_activity('client_created', 'clients', $newId, t('log.client_created', trim($old['first_name'] . ' ' . $old['last_name'])));
            flash('success', t('flash.saved'));
        }
        redirect('admin/clients/index.php');
    }
}

$pageTitle = $editing ? t('clients.edit_title') : t('clients.add');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-<?= $editing ? 'pencil' : 'person-plus' ?> me-2 text-danger"></i>
      <?= e($editing ? t('clients.edit_title') : t('clients.add')) ?>
    </h1>
  </div>
  <a href="<?= e(app_url('admin/clients/index.php')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i><?= e(t('common.back')) ?></a>
</div>

<?php if ($errors): ?>
  <?= banner('danger', '', t('common.form_errors'), $errors) ?>
<?php endif; ?>

<div class="card form-narrow">
  <div class="card-body">
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label"><?= e(t('clients.civility')) ?></label>
          <select name="civility" class="form-select">
            <option value="Monsieur" <?= $old['civility'] === 'Monsieur' ? 'selected' : '' ?>><?= e(t('civ.monsieur')) ?></option>
            <option value="Madame" <?= $old['civility'] === 'Madame' ? 'selected' : '' ?>><?= e(t('civ.madame')) ?></option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label required" for="last_name"><?= e(t('clients.last_name')) ?></label>
          <input type="text" class="form-control" id="last_name" name="last_name" value="<?= e($old['last_name']) ?>" maxlength="80" required>
        </div>
        <div class="col-md-4">
          <label class="form-label required" for="first_name"><?= e(t('clients.first_name')) ?></label>
          <input type="text" class="form-control" id="first_name" name="first_name" value="<?= e($old['first_name']) ?>" maxlength="80" required>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="phone"><?= e(t('clients.phone')) ?></label>
          <input type="text" class="form-control" id="phone" name="phone" value="<?= e($old['phone']) ?>" maxlength="30" placeholder="22 333 444">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="phone2"><?= e(t('clients.phone2')) ?></label>
          <input type="text" class="form-control" id="phone2" name="phone2" value="<?= e($old['phone2']) ?>" maxlength="30">
        </div>
        <div class="col-md-4">
          <label class="form-label" for="email"><?= e(t('clients.email')) ?></label>
          <input type="email" class="form-control" id="email" name="email" value="<?= e($old['email']) ?>" maxlength="120">
        </div>
        <div class="col-md-8">
          <label class="form-label" for="address"><?= e(t('clients.address')) ?></label>
          <input type="text" class="form-control" id="address" name="address" value="<?= e($old['address']) ?>" maxlength="255">
        </div>
        <div class="col-md-2">
          <label class="form-label" for="postal_code"><?= e(t('clients.postal_code')) ?></label>
          <input type="text" class="form-control" id="postal_code" name="postal_code" value="<?= e($old['postal_code']) ?>" maxlength="10">
        </div>
        <div class="col-md-2">
          <label class="form-label" for="city"><?= e(t('clients.city')) ?></label>
          <input type="text" class="form-control" id="city" name="city" value="<?= e($old['city']) ?>" maxlength="80">
        </div>
        <div class="col-12">
          <label class="form-label" for="notes"><?= e(t('common.notes')) ?></label>
          <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($old['notes']) ?></textarea>
        </div>
      </div>
      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></button>
        <a href="<?= e(app_url('admin/clients/index.php')) ?>" class="btn btn-secondary"><?= e(t('common.cancel')) ?></a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
