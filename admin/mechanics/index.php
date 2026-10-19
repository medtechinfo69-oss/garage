<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

/* ---------- Suppression (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $m = fetch_one("SELECT * FROM mechanics WHERE id = ?", [$id]);
    if ($m) {
        $nbActive = (int) fetch_one("SELECT COUNT(*) c FROM vehicles WHERE mechanic_id = ? AND status = 'en_reparation'", [$id])['c'];
        if ($nbActive > 0) {
            flash('danger', t('error.invalid_input') . ' — ' . $nbActive . ' « ' . t('status.en_reparation') . ' »');
        } else {
            execute("DELETE FROM mechanics WHERE id = ?", [$id]);
            log_activity('mechanic_deleted', 'mechanics', $id, t('common.delete') . ' : ' . $m['full_name']);
            flash('success', t('flash.deleted'));
        }
    }
    redirect('admin/mechanics/index.php');
}

/* ---------- Création / modification (POST) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();
    $mid = (int) ($_POST['id'] ?? 0);
    $data = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'specialty' => trim((string) ($_POST['specialty'] ?? '')),
        'hire_date' => (string) ($_POST['hire_date'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    $errors = [];
    if ($data['full_name'] === '') {
        $errors[] = t('error.required');
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('error.invalid_email');
    }
    if ($data['hire_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['hire_date'])) {
        $data['hire_date'] = '';
    }
    if (!$errors) {
        if ($mid) {
            execute(
                "UPDATE mechanics SET full_name=?, phone=?, email=?, specialty=?, hire_date=?, is_active=? WHERE id=?",
                [$data['full_name'], $data['phone'] ?: null, $data['email'] ?: null, $data['specialty'] ?: null,
                 $data['hire_date'] ?: null, $data['is_active'], $mid]
            );
            log_activity('mechanic_updated', 'mechanics', $mid, t('common.update') . ' : ' . $data['full_name']);
            flash('success', t('flash.updated'));
        } else {
            execute(
                "INSERT INTO mechanics (full_name, phone, email, specialty, hire_date, is_active) VALUES (?,?,?,?,?,?)",
                [$data['full_name'], $data['phone'] ?: null, $data['email'] ?: null, $data['specialty'] ?: null,
                 $data['hire_date'] ?: null, $data['is_active']]
            );
            $mid = last_insert_id();
            log_activity('mechanic_created', 'mechanics', $mid, t('log.mechanic_created', $data['full_name']));
            flash('success', t('flash.saved'));
        }
        redirect('admin/mechanics/index.php');
    }
    // re-affiche avec erreurs
    $formErrors = $errors;
    $formOld = $data;
} else {
    $formErrors = [];
    $formOld = null;
}

$rows = fetch_all(
    "SELECT m.*, u.id user_id, u.username, u.email user_email, u.is_active user_active,
            (SELECT COUNT(*) FROM vehicles v WHERE v.mechanic_id = m.id AND v.status = 'en_reparation') nb_active
     FROM mechanics m
     LEFT JOIN users u ON u.mechanic_id = m.id
     ORDER BY m.full_name"
);

$editingRow = null;
$pageTitle = t('nav.mechanics');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-person-gear me-2 text-danger"></i><?= e(t('nav.mechanics')) ?></h1>
    <div class="subtitle"><?= count($rows) ?></div>
  </div>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#mechModal"><i class="bi bi-plus-lg me-1"></i><?= e(t('users.add')) ?></button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('users.full_name')) ?></th><th><?= e(t('clients.phone')) ?></th><th><?= e(t('clients.email')) ?></th>
          <th><?= e(t('users.mechanic_link')) ?> → <?= e(t('nav.users')) ?></th><th><?= e(t('status.en_reparation')) ?></th>
          <th><?= e(t('common.status')) ?></th><th class="text-end"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><?= e(t('common.no_results')) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $m): ?>
          <tr>
            <td class="fw-semibold"><?= e($m['full_name']) ?>
              <div class="small text-muted"><?= e($m['specialty'] ?: '') ?></div></td>
            <td><?= e($m['phone'] ?: '—') ?></td>
            <td><?= e($m['email'] ?: '—') ?></td>
            <td>
              <?php if ($m['user_id']): ?>
                <span class="badge text-bg-light border"><?= e($m['username']) ?></span>
                <div class="small text-muted"><?= e($m['user_email']) ?></div>
              <?php else: ?>
                <span class="badge text-bg-warning"><?= e(t('common.none')) ?></span>
              <?php endif; ?>
            </td>
            <td><span class="badge text-bg-primary"><?= (int) $m['nb_active'] ?></span></td>
            <td>
              <?php if ($m['is_active']): ?>
                <span class="badge text-bg-success"><?= e(t('users.active')) ?></span>
              <?php else: ?>
                <span class="badge text-bg-secondary"><?= e(t('users.inactive')) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <button class="btn btn-outline-warning" type="button"
                      data-bs-toggle="modal" data-bs-target="#mechModal"
                      data-id="<?= (int) $m['id'] ?>" data-name="<?= e($m['full_name']) ?>"
                      data-phone="<?= e($m['phone'] ?? '') ?>" data-email="<?= e($m['email'] ?? '') ?>"
                      data-specialty="<?= e($m['specialty'] ?? '') ?>" data-hire_date="<?= e($m['hire_date'] ?? '') ?>"
                      data-active="<?= (int) $m['is_active'] ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <form class="d-inline" method="post" data-confirm="<?= e(t('common.delete_confirm_msg')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modale création / édition mécanicien -->
<div class="modal fade" id="mechModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="mechForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="mechId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="mechModalTitle"><i class="bi bi-person-plus me-2"></i><?= e(t('users.add')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if (!empty($formErrors)): ?>
            <?= banner('danger', '', t('common.form_errors'), $formErrors, null, false, 'app-banner--sm mb-0') ?>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label required"><?= e(t('users.full_name')) ?></label>
            <input type="text" class="form-control" name="full_name" id="mechName" value="<?= e($formOld['full_name'] ?? '') ?>" required maxlength="100">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label"><?= e(t('clients.phone')) ?></label>
              <input type="text" class="form-control" name="phone" id="mechPhone" value="<?= e($formOld['phone'] ?? '') ?>" maxlength="30">
            </div>
            <div class="col-6">
              <label class="form-label"><?= e(t('clients.email')) ?></label>
              <input type="email" class="form-control" name="email" id="mechEmail" value="<?= e($formOld['email'] ?? '') ?>" maxlength="120">
            </div>
            <div class="col-6">
              <label class="form-label"><?= e(t('mechanics.specialty')) ?></label>
              <input type="text" class="form-control" name="specialty" id="mechSpecialty" value="<?= e($formOld['specialty'] ?? '') ?>" maxlength="100">
            </div>
            <div class="col-6">
              <label class="form-label"><?= e(t('mechanics.hire_date')) ?></label>
              <input type="date" class="form-control" name="hire_date" id="mechHire" value="<?= e($formOld['hire_date'] ?? '') ?>">
            </div>
          </div>
          <div class="form-check form-switch mt-3">
            <input class="form-check-input" type="checkbox" name="is_active" id="mechActive" checked>
            <label class="form-check-label" for="mechActive"><?= e(t('users.active')) ?></label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-bs-target="#mechModal"]').forEach(function (btn) {
  if (!btn.dataset.id) return;
  btn.addEventListener('click', function () {
    document.getElementById('mechId').value = btn.dataset.id;
    document.getElementById('mechName').value = btn.dataset.name;
    document.getElementById('mechPhone').value = btn.dataset.phone;
    document.getElementById('mechEmail').value = btn.dataset.email;
    document.getElementById('mechSpecialty').value = btn.dataset.specialty;
    document.getElementById('mechHire').value = btn.dataset.hireDate;
    document.getElementById('mechActive').checked = btn.dataset.active === '1';
    document.getElementById('mechModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i><?= e(t('common.edit')) ?> : ' + btn.dataset.name;
  });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
