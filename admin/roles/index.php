<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$me = current_user();

/* ---------- POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $r = fetch_one("SELECT * FROM user_roles WHERE id = ?", [$rid]);
        if ($r) {
            $key = (string) $r['role_key'];
            if ($key === 'admin') {
                flash('danger', t('roles.protected'));
            } else {
                $used = fetch_one("SELECT COUNT(*) c FROM users WHERE role = ?", [$key]);
                if ($used && (int) $used['c'] > 0) {
                    flash('danger', t('roles.in_use'));
                } else {
                    execute("DELETE FROM user_roles WHERE id = ?", [$rid]);
                    log_activity('role_deleted', 'roles', $rid, t('common.delete') . ' : ' . $r['role_label']);
                    flash('success', t('flash.deleted'));
                }
            }
        }
        redirect('admin/roles/index.php');
    }

    if ($action === 'toggle') {
        $r = fetch_one("SELECT * FROM user_roles WHERE id = ?", [$rid]);
        if ($r) {
            // Le rôle « admin » ne peut jamais être désactivé (verrouillage total).
            if ((string) $r['role_key'] === 'admin') {
                flash('danger', t('roles.protected'));
                redirect('admin/roles/index.php');
            }
            $newVal = (int) $r['is_active'] ? 0 : 1;
            execute("UPDATE user_roles SET is_active = ? WHERE id = ?", [$newVal, $rid]);
            flash('success', t('flash.updated'));
        }
        redirect('admin/roles/index.php');
    }

    if ($action === 'save') {
        $key = strtolower(trim((string) ($_POST['role_key'] ?? '')));
        $key = preg_replace('/[^a-z0-9_]/', '_', $key) ?? '';
        $label = trim((string) ($_POST['role_label'] ?? ''));
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

        // Rôles système : la clé est figée (compatibilité du code), seul le
        // libellé (et l'accès admin pour les rôles non-admin) peut changer.
        $current = $rid ? fetch_one("SELECT * FROM user_roles WHERE id = ?", [$rid]) : null;
        $currentKey = $current ? (string) $current['role_key'] : '';
        $isSystem = ($currentKey === 'admin');
        if ($isSystem) {
            $key = $currentKey;
            if ($currentKey === 'admin') $isAdmin = 1;
        }

        $errors = [];
        if ($key === '' || $label === '') {
            $errors[] = t('error.required');
        } elseif (!preg_match('/^[a-z0-9_]{2,40}$/', $key)) {
            $errors[] = t('roles.invalid_key');
        } elseif (!$rid && $key === 'admin') {
            $errors[] = t('roles.protected');
        }

        if (!$errors) {
            if ($rid) {
                // Le rôle « admin » doit toujours conserver les droits d'administration.
                if ($currentKey === 'admin') $isAdmin = 1;
                execute("UPDATE user_roles SET role_key=?, role_label=?, is_admin=? WHERE id=?", [$key, $label, $isAdmin, $rid]);
                // Rôle système : on garde la table de rôles synchronisée avec les libellés figés existants
                log_activity('role_updated', 'roles', $rid, t('common.update') . ' : ' . $label);
                flash('success', t('flash.updated'));
            } else {
                if (fetch_one("SELECT id FROM user_roles WHERE role_key = ?", [$key])) {
                    $errors[] = t('roles.duplicate');
                } else {
                    execute("INSERT INTO user_roles (role_key, role_label, is_admin, is_active) VALUES (?,?,?,1)", [$key, $label, $isAdmin]);
                    log_activity('role_created', 'roles', last_insert_id(), t('common.add') . ' : ' . $label);
                    flash('success', t('flash.saved'));
                }
            }
        }

        if ($errors) {
            $formErrors = $errors;
            $formOld = ['role_key' => $key, 'role_label' => $label, 'is_admin' => $isAdmin, 'id' => $rid];
        }
    }
}
if (!isset($formErrors)) {
    $formErrors = [];
    $formOld = null;
}

$rows = fetch_all("SELECT * FROM user_roles ORDER BY is_admin DESC, role_label");

$pageTitle = t('roles.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-shield-plus me-2 text-danger"></i><?= e(t('roles.title')) ?></h1>
    <div class="subtitle"><?= count($rows) ?></div>
  </div>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#roleModal"><i class="bi bi-plus-lg me-1"></i><?= e(t('roles.add')) ?></button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('roles.label')) ?></th>
          <th><?= e(t('roles.key')) ?></th>
          <th><?= e(t('roles.admin_access')) ?></th>
          <th><?= e(t('users.role')) ?></th>
          <th class="text-end"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php $protected = ((string) $r['role_key'] === 'admin'); ?>
          <tr>
            <td class="fw-semibold"><?= e($r['role_label']) ?></td>
            <td><code><?= e($r['role_key']) ?></code></td>
            <td>
              <?php if ((int) $r['is_admin']): ?>
                <span class="badge text-bg-success"><?= e(t('roles.yes')) ?></span>
              <?php else: ?>
                <span class="badge text-bg-secondary"><?= e(t('roles.no')) ?></span>
              <?php endif; ?>
            </td>
            <td><?= role_badge((string) $r['role_key']) ?></td>
            <td class="text-end text-nowrap">
              <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#roleModal"
                      data-id="<?= (int) $r['id'] ?>" data-role-key="<?= e($r['role_key']) ?>" data-role_key="<?= e($r['role_key']) ?>"
                      data-role-label="<?= e($r['role_label']) ?>" data-role_label="<?= e($r['role_label']) ?>"
                      data-is-admin="<?= (int) $r['is_admin'] ?>" data-is_admin="<?= (int) $r['is_admin'] ?>"
                      data-protected="<?= $protected ? 1 : 0 ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <?php if (!$protected): ?>
                <form class="d-inline role-delete-form" method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button type="button" class="btn btn-outline-danger btn-delete-role"
                          data-title="<?= e(t('common.delete_confirm_title')) ?>"
                          data-message="<?= e(t('roles.delete_msg')) ?>"
                          data-label="<?= e($r['role_label']) ?>"
                          title="<?= e(t('common.delete')) ?>">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modale rôle -->
<div class="modal fade" id="roleModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="rId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="rModalTitle"><i class="bi bi-shield-plus me-2"></i><?= e(t('roles.add')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($formErrors): ?>
            <?= banner('danger', '', t('common.form_errors'), $formErrors, null, false, 'app-banner--sm mb-0') ?>
          <?php endif; ?>
          <div class="mb-2">
            <label class="form-label required"><?= e(t('roles.label')) ?></label>
            <input type="text" class="form-control" name="role_label" id="rLabel" value="<?= e($formOld['role_label'] ?? '') ?>" required maxlength="60">
            <div class="form-text"><?= e(t('roles.label_hint')) ?></div>
          </div>
          <div class="mb-2">
            <label class="form-label required"><?= e(t('roles.key')) ?></label>
            <input type="text" class="form-control" name="role_key" id="rKey" value="<?= e($formOld['role_key'] ?? '') ?>" required maxlength="40" pattern="[a-z0-9_]{2,40}">
            <div class="form-text" id="rKeyHint"><?= e(t('roles.key_hint')) ?></div>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_admin" id="rAdmin" <?= !empty($formOld['is_admin']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="rAdmin"><?= e(t('roles.admin_access')) ?></label>
            <div class="form-text"><?= e(t('roles.admin_access_hint')) ?></div>
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
var rKeyHintDefault = document.getElementById('rKeyHint').textContent;
document.querySelectorAll('[data-bs-target="#roleModal"][data-id]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var locked = btn.dataset.protected === '1';
    var keyInput = document.getElementById('rKey');
    var roleKey = btn.dataset.roleKey || btn.dataset.role_key || '';
    var roleLabel = btn.dataset.roleLabel || btn.dataset.role_label || '';
    var isAdmin = (btn.dataset.isAdmin === '1' || btn.dataset.is_admin === '1');

    document.getElementById('rId').value = btn.dataset.id || '0';
    document.getElementById('rLabel').value = roleLabel;
    keyInput.value = roleKey;
    keyInput.readOnly = locked;
    keyInput.classList.toggle('bg-light', locked);
    document.getElementById('rKeyHint').textContent = locked ? <?= json_encode(t('roles.key_locked')) ?> : rKeyHintDefault;
    var adminSwitch = document.getElementById('rAdmin');
    adminSwitch.checked = isAdmin;
    adminSwitch.disabled = (roleKey === 'admin');
    document.getElementById('rModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i><?= e(t('roles.edit_title')) ?> : ' + roleLabel;
  });
});

var modalEl = document.getElementById('roleModal');
if (modalEl) {
  modalEl.addEventListener('show.bs.modal', function (event) {
    var trigger = event.relatedTarget;
    if (!trigger || !trigger.dataset || !trigger.dataset.id) {
      document.getElementById('rId').value = '0';
      document.getElementById('rLabel').value = '';
      var keyInput = document.getElementById('rKey');
      keyInput.value = '';
      keyInput.readOnly = false;
      keyInput.classList.remove('bg-light');
      document.getElementById('rKeyHint').textContent = rKeyHintDefault;
      var adminSwitch = document.getElementById('rAdmin');
      adminSwitch.checked = false;
      adminSwitch.disabled = false;
      document.getElementById('rModalTitle').innerHTML = '<i class="bi bi-shield-plus me-2"></i><?= e(t('roles.add')) ?>';
    }
  });
}

// Forcer la confirmation avant toute suppression de rôle
document.querySelectorAll('.btn-delete-role').forEach(function (btn) {
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var form = btn.closest('form');
    if (!form) return;
    var title = btn.getAttribute('data-title') || <?= json_encode(t('common.delete_confirm_title')) ?>;
    var baseMsg = btn.getAttribute('data-message') || <?= json_encode(t('roles.delete_msg')) ?>;
    var label = btn.getAttribute('data-label') || '';
    var message = label ? (baseMsg + ' (' + label + ')') : baseMsg;

    if (typeof showConfirmPopup === 'function') {
      showConfirmPopup(
        title,
        message,
        function () {
          form.submit();
        },
        function () {
          // Annulation : ne rien faire, le rôle n'est pas supprimé
        },
        <?= json_encode(t('common.delete')) ?>,
        <?= json_encode(t('common.cancel')) ?>,
        'danger'
      );
    } else if (window.confirm(message)) {
      form.submit();
    }
  });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
