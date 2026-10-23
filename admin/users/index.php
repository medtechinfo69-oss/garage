<?php
require_once __DIR__ . '/../../includes/auth.php';
require_admin();

$me = current_user();
$mechRows = fetch_all("SELECT id, full_name FROM mechanics ORDER BY full_name");

/* ---------- POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) ($_POST['id'] ?? 0);

    if ($action === 'approve_ip') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId > 0 && approve_ip_request($requestId, (int) $me['id'])) {
            $row = fetch_one("SELECT user_id, ip_address FROM mechanic_ip_approvals WHERE id = ? LIMIT 1", [$requestId]);
            if ($row) {
                log_activity('ip_approved', 'users', (int) $row['user_id'], 'IP approuvée : ' . $row['ip_address']);
                flash('success', 'IP approuvée pour le mécanicien.');
            }
        } else {
            flash('danger', 'Impossible d\'approuver cette IP.');
        }
        redirect('admin/users/index.php');
    }

    if ($action === 'reject_ip') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($requestId > 0 && reject_ip_request($requestId, (int) $me['id'], $reason !== '' ? $reason : 'Rejected by admin')) {
            $row = fetch_one("SELECT user_id, ip_address FROM mechanic_ip_approvals WHERE id = ? LIMIT 1", [$requestId]);
            if ($row) {
                log_activity('ip_rejected', 'users', (int) $row['user_id'], 'IP refusée : ' . $row['ip_address']);
                flash('warning', 'Demande d\'IP refusée.');
            }
        } else {
            flash('danger', 'Impossible de refuser cette IP.');
        }
        redirect('admin/users/index.php');
    }

    if ($action === 'delete') {
        if ($uid === (int) $me['id']) {
            flash('danger', t('users.cannot_delete_self'));
        } else {
            $u = fetch_one("SELECT * FROM users WHERE id = ?", [$uid]);
            if ($u) {
                execute("DELETE FROM users WHERE id = ?", [$uid]);
                log_activity('user_deleted', 'users', $uid, t('common.delete') . ' : ' . $u['username']);
                flash('success', t('flash.deleted'));
            }
        }
        redirect('admin/users/index.php');
    }

    if ($action === 'reset_password') {
        $newPass = (string) ($_POST['new_password'] ?? '');
        if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $newPass)) {
            flash('danger', t('error.weak_password'));
        } else {
            execute("UPDATE users SET password = ? WHERE id = ?", [password_hash($newPass, PASSWORD_DEFAULT), $uid]);
            log_activity('user_password_reset', 'users', $uid, t('users.reset_password') . ' #' . $uid);
            flash('success', t('flash.updated'));
        }
        redirect('admin/users/index.php');
    }

    if ($action === 'save') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'mechanic');
        $mechanicId = (int) ($_POST['mechanic_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $newPass = (string) ($_POST['password'] ?? '');

        $errors = [];
        if ($fullName === '' || $username === '' || $email === '') {
            $errors[] = t('error.required');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('error.invalid_email');
        }
        if (!in_array($role, ['admin', 'mechanic'], true)) {
            $role = 'mechanic';
        }
        if ($mechanicId && !fetch_one("SELECT id FROM mechanics WHERE id = ?", [$mechanicId])) {
            $mechanicId = 0;
        }
        if ($role === 'mechanic' && !$mechanicId) {
            $errors[] = t('users.mechanic_required');
        }
        if ($uid) {
            $dupU = fetch_one("SELECT id FROM users WHERE username = ? AND id <> ?", [$username, $uid]);
            if ($dupU) $errors[] = t('error.unique_username');
            $dupE = fetch_one("SELECT id FROM users WHERE email = ? AND id <> ?", [$email, $uid]);
            if ($dupE) $errors[] = t('error.unique_email');
            if ($newPass !== '' && !preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $newPass)) {
                $errors[] = t('error.weak_password');
            }
        } else {
            if (fetch_one("SELECT id FROM users WHERE username = ?", [$username])) $errors[] = t('error.unique_username');
            if (fetch_one("SELECT id FROM users WHERE email = ?", [$email])) $errors[] = t('error.unique_email');
            if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $newPass)) {
                $errors[] = t('error.weak_password');
            }
        }

        if (!$errors) {
            if ($uid) {
                if ($newPass !== '') {
                    execute(
                        "UPDATE users SET full_name=?, username=?, email=?, role=?, mechanic_id=?, is_active=?, password=? WHERE id=?",
                        [$fullName, $username, $email, $role, $role === 'mechanic' ? $mechanicId : null, $isActive,
                         password_hash($newPass, PASSWORD_DEFAULT), $uid]
                    );
                } else {
                    execute(
                        "UPDATE users SET full_name=?, username=?, email=?, role=?, mechanic_id=?, is_active=? WHERE id=?",
                        [$fullName, $username, $email, $role, $role === 'mechanic' ? $mechanicId : null, $isActive, $uid]
                    );
                }
                log_activity('user_updated', 'users', $uid, t('common.update') . ' : ' . $username);
                flash('success', t('flash.updated'));
            } else {
                execute(
                    "INSERT INTO users (full_name, username, email, password, role, mechanic_id, is_active) VALUES (?,?,?,?,?,?,?)",
                    [$fullName, $username, $email, password_hash($newPass, PASSWORD_DEFAULT), $role,
                     $role === 'mechanic' ? $mechanicId : null, $isActive]
                );
                $uid = last_insert_id();
                log_activity('user_created', 'users', $uid, t('common.add') . ' : ' . $username . ' (' . role_label($role) . ')');
                flash('success', t('flash.saved'));
            }
            redirect('admin/users/index.php');
        }
        // re-affichage
        $formErrors = $errors;
        $formOld = ['full_name' => $fullName, 'username' => $username, 'email' => $email, 'role' => $role, 'mechanic_id' => $mechanicId, 'is_active' => $isActive, 'id' => $uid];
    }
}
if (!isset($formErrors)) {
    $formErrors = [];
    $formOld = null;
}

$rows = fetch_all(
    "SELECT u.*, m.full_name mech_name
     FROM users u LEFT JOIN mechanics m ON m.id = u.mechanic_id
     ORDER BY u.role, u.full_name"
);

$pendingIpRequests = pending_ip_requests();
$approvedIpRows = fetch_all(
    "SELECT a.*, u.full_name AS user_name, u.username, m.full_name AS mech_name
     FROM mechanic_ip_approvals a
     INNER JOIN users u ON u.id = a.user_id
     LEFT JOIN mechanics m ON m.id = u.mechanic_id
     WHERE a.is_active = 1
     ORDER BY u.full_name, a.ip_address"
);

$pageTitle = t('users.title');
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-person-badge me-2 text-danger"></i><?= e(t('users.title')) ?></h1>
    <div class="subtitle"><?= count($rows) ?></div>
  </div>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#userModal"><i class="bi bi-plus-lg me-1"></i><?= e(t('users.add')) ?></button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th><?= e(t('users.full_name')) ?></th><th><?= e(t('users.username')) ?></th><th><?= e(t('clients.email')) ?></th>
          <th><?= e(t('users.role')) ?></th><th><?= e(t('users.mechanic_link')) ?></th>
          <th><?= e(t('common.status')) ?></th><th><?= e(t('users.last_login')) ?></th>
          <th class="text-end"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $u): ?>
          <tr>
            <td class="fw-semibold">
              <?= e($u['full_name']) ?>
              <?php if ((int) $u['id'] === (int) $me['id']): ?><span class="badge text-bg-light border ms-1"><?= e(t('users.you')) ?></span><?php endif; ?>
            </td>
            <td><?= e($u['username']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><?= role_badge($u['role']) ?></td>
            <td><?= e($u['mech_name'] ?: '—') ?></td>
            <td>
              <?php if ($u['is_active']): ?>
                <span class="badge text-bg-success"><?= e(t('users.active')) ?></span>
              <?php else: ?>
                <span class="badge text-bg-secondary"><?= e(t('users.inactive')) ?></span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= fmt_datetime($u['last_login_at']) ?></td>
            <td class="text-end text-nowrap">
              <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#userModal"
                      data-id="<?= (int) $u['id'] ?>" data-full_name="<?= e($u['full_name']) ?>"
                      data-username="<?= e($u['username']) ?>" data-email="<?= e($u['email']) ?>"
                      data-role="<?= e($u['role']) ?>" data-mechanic_id="<?= (int) $u['mechanic_id'] ?>"
                      data-active="<?= (int) $u['is_active'] ?>">
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#passModal"
                      data-id="<?= (int) $u['id'] ?>" data-name="<?= e($u['username']) ?>" title="<?= e(t('users.reset_password')) ?>">
                <i class="bi bi-key"></i>
              </button>
              <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                <form class="d-inline" method="post" data-confirm="<?= e(t('users.delete_msg')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Demandes d'approbation d'IP</h5>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Mécanicien</th>
            <th>IP</th>
            <th>Notes</th>
            <th>Demandé le</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$pendingIpRequests): ?>
            <tr><td colspan="5" class="text-muted text-center py-3">Aucune demande d'approbation en attente.</td></tr>
          <?php else: ?>
            <?php foreach ($pendingIpRequests as $row): ?>
              <tr>
                <td><?= e($row['user_name'] ?: $row['username']) ?></td>
                <td><code><?= e($row['ip_address']) ?></code></td>
                <td><?= e($row['notes'] ?: '—') ?></td>
                <td><?= e($row['approved_at']) ?></td>
                <td class="text-end text-nowrap">
                  <form method="post" class="d-inline" onsubmit="return confirm('Approuver cette IP pour le mécanicien ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="approve_ip">
                    <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Approuver</button>
                  </form>
                  <form method="post" class="d-inline ms-1" onsubmit="return confirm('Refuser cette demande d\'IP ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject_ip">
                    <input type="hidden" name="request_id" value="<?= (int) $row['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg"></i> Refuser</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>IP approuvées</h5>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Mécanicien</th>
            <th>IP</th>
            <th>Notes</th>
            <th>Approuvée le</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$approvedIpRows): ?>
            <tr><td colspan="4" class="text-muted text-center py-3">Aucune IP approuvée pour le moment.</td></tr>
          <?php else: ?>
            <?php foreach ($approvedIpRows as $row): ?>
              <tr>
                <td><?= e($row['user_name'] ?: $row['username']) ?></td>
                <td><code><?= e($row['ip_address']) ?></code></td>
                <td><?= e($row['notes'] ?: '—') ?></td>
                <td><?= e($row['approved_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modale utilisateur -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="uId" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="uModalTitle"><i class="bi bi-person-plus me-2"></i><?= e(t('users.add')) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($formErrors): ?>
            <?= banner('danger', '', t('common.form_errors'), $formErrors, null, false, 'app-banner--sm mb-0') ?>
          <?php endif; ?>
          <div class="mb-2">
            <label class="form-label required"><?= e(t('users.full_name')) ?></label>
            <input type="text" class="form-control" name="full_name" id="uName" value="<?= e($formOld['full_name'] ?? '') ?>" required maxlength="100">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label required"><?= e(t('users.username')) ?></label>
              <input type="text" class="form-control" name="username" id="uUsername" value="<?= e($formOld['username'] ?? '') ?>" required maxlength="50">
            </div>
            <div class="col-6">
              <label class="form-label required"><?= e(t('clients.email')) ?></label>
              <input type="email" class="form-control" name="email" id="uEmail" value="<?= e($formOld['email'] ?? '') ?>" required maxlength="120">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label"><?= e(t('users.role')) ?></label>
              <select class="form-select" name="role" id="uRole">
                <option value="admin"><?= e(t('role.admin')) ?></option>
                <option value="mechanic"><?= e(t('role.mechanic')) ?></option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label"><?= e(t('users.mechanic_link')) ?></label>
              <select class="form-select" name="mechanic_id" id="uMechanic">
                <option value=""><?= e(t('common.none')) ?></option>
                <?php foreach ($mechRows as $m): ?>
                  <option value="<?= (int) $m['id'] ?>"><?= e($m['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label" for="uPass"><?= e(t('users.password')) ?>
              <?php if ($formOld && $formOld['id']): ?><span class="text-muted small">(<?= e(t('common.optional')) ?>)</span><?php endif; ?>
            </label>
            <input type="password" class="form-control" id="uPass" name="password" maxlength="100" autocomplete="new-password">
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="is_active" id="uActive" checked>
            <label class="form-check-label" for="uActive"><?= e(t('users.active')) ?></label>
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

<!-- Modale mot de passe -->
<div class="modal fade" id="passModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" id="pId" value="0">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-key me-2"></i><?= e(t('users.reset_password')) ?> — <span id="pName"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label" for="pPass"><?= e(t('users.new_password')) ?></label>
          <input type="password" class="form-control" id="pPass" name="new_password" required minlength="8" autocomplete="new-password">
          <div class="form-text"><?= e(t('users.password_hint')) ?></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= e(t('common.cancel')) ?></button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-bs-target="#userModal"][data-id]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('uId').value = btn.dataset.id;
    document.getElementById('uName').value = btn.dataset.fullName;
    document.getElementById('uUsername').value = btn.dataset.username;
    document.getElementById('uEmail').value = btn.dataset.email;
    document.getElementById('uRole').value = btn.dataset.role;
    document.getElementById('uMechanic').value = btn.dataset.mechanicId;
    document.getElementById('uActive').checked = btn.dataset.active === '1';
    document.getElementById('uPass').value = '';
    document.getElementById('uModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i><?= e(t('users.edit_title')) ?> : ' + btn.dataset.username;
  });
});
document.querySelectorAll('[data-bs-target="#passModal"][data-id]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('pId').value = btn.dataset.id;
    document.getElementById('pName').textContent = btn.dataset.name;
    document.getElementById('pPass').value = '';
  });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
