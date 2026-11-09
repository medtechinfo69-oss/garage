<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/countries.php';

$keys = [
    'company_name', 'company_address', 'company_phone', 'company_phone_cc', 'company_email',
    'company_web', 'company_tax_id', 'email_from',
    'default_tva', 'expected_in_time', 'normal_daily_hours',
    'break_threshold_minutes', 'currency', 'app_timezone',
    'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'smtp_from',
];
$old = [];
foreach ($keys as $k) {
    $old[$k] = (string) (get_setting($k, '') ?? '');
}

/* ---------- Migration automatique : secrets stockés en clair ----------
 * Les versions précédentes enregistraient le mot de passe SMTP en clair.
 * On le chiffre ici (AES-256-GCM) dès l'ouverture de la page, sans attendre
 * un nouvel enregistrement du formulaire. */
if ($old['smtp_pass'] !== '' && !str_starts_with($old['smtp_pass'], 'enc:v1:')) {
    try {
        $encrypted = app_encrypt_secret($old['smtp_pass']);
        execute(
            "UPDATE settings SET setting_value = ? WHERE setting_key = 'smtp_pass'",
            [$encrypted]
        );
        $old['smtp_pass'] = $encrypted;
        clear_settings_cache();
        log_activity('settings_secret_encrypted', 'settings', null, 'Mot de passe SMTP chiffré en base');
    } catch (Throwable $e) {
        error_log('smtp_pass encryption: ' . $e->getMessage());
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    if (($_POST['clear_cache'] ?? '') === '1') {
        clear_settings_cache();
        flash('success', t('settings.cache_cleared'));
        redirect('admin/settings.php');
    }
    // Vider le chat interne : suppression de tous les messages (les conversations repartent de zéro).
    if (($_POST['clear_chat'] ?? '') === '1') {
        try {
            db()->exec('TRUNCATE TABLE chat_messages');
            db()->exec('TRUNCATE TABLE chat_presence');
            log_activity('chat_cleared', 'chat', null, 'Chat interne vidé par un administrateur');
            flash('success', t('settings.chat_cleared'));
        } catch (Throwable $e) {
            error_log('clear_chat: ' . $e->getMessage());
            flash('danger', t('flash.error_generic'));
        }
        redirect('admin/settings.php');
    }
    // Réinitialisation de l'application : purge de l'historique et du journal.
    // Note : TRUNCATE TABLE cause un commit implicite en MySQL — on utilise
    // DELETE FROM à la place pour rester dans une transaction contrôlée.
    if (($_POST['reset_app'] ?? '') === '1') {
        try {
            $counts = [];
            foreach (['attendance', 'vehicle_status_history', 'activity_logs'] as $tbl) {
                $counts[$tbl] = (int) fetch_one("SELECT COUNT(*) c FROM `$tbl`")['c'];
            }
            db()->beginTransaction();
            foreach (['attendance', 'vehicle_status_history', 'activity_logs'] as $tbl) {
                db()->exec("DELETE FROM `$tbl`");
            }
            db()->commit();
            log_activity('app_reset', 'settings', null, sprintf(
                'Réinitialisation : %d pointages, %d historiques, %d entrées de journal',
                $counts['attendance'], $counts['vehicle_status_history'], $counts['activity_logs']
            ));
            flash('success', t('settings.reset_done'));
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('reset_app: ' . $e->getMessage());
            flash('danger', t('flash.error_generic'));
        }
        redirect('admin/settings.php');
    }
    foreach ($keys as $k) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    // Le mot de passe SMTP n'est réécrit que s'il est saisi (sinon on garde l'actuel).
    // Il est stocké CHIFFRÉ (AES-256-GCM) : jamais en clair dans la base.
    if (($_POST['smtp_pass'] ?? '') === '') {
        $old['smtp_pass'] = (string) get_setting('smtp_pass', '');
    } else {
        $old['smtp_pass'] = app_encrypt_secret($old['smtp_pass']);
    }
    // Téléphone : indicatif pays choisi (ISO) → « +216 71 234 567 » complet
    $cc = $old['company_phone_cc'];
    $dial = phone_dial_for((string) $cc);
    if ($dial === '') {
        $cc = '';
    }
    $old['company_phone_cc'] = (string) $cc;
    $old['company_phone'] = $old['company_phone'] !== ''
        ? trim($dial . ' ' . $old['company_phone'])
        : '';
    $errors = [];
    if ($old['email_from'] !== '' && !filter_var($old['email_from'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = e(t('settings.email_from')) . ' : ' . t('error.invalid_email');
    }
    if ($old['smtp_from'] !== '' && !filter_var($old['smtp_from'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = e(t('settings.smtp_from')) . ' : ' . t('error.invalid_email');
    }
    if ($old['smtp_host'] === '' && $old['smtp_user'] !== '') {
        $errors[] = e(t('settings.smtp_host')) . ' : ' . t('error.required');
    }
    if ($old['smtp_port'] !== '' && !ctype_digit($old['smtp_port'])) {
        $errors[] = e(t('settings.smtp_port')) . ' : ' . t('error.invalid_input');
    }
    if (!in_array($old['smtp_secure'], ['', 'tls', 'ssl'], true)) {
        $old['smtp_secure'] = 'tls';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $old['expected_in_time'])) {
        $errors[] = e(t('settings.expected_in')) . ' : ' . t('error.invalid_input');
    }
    foreach (['default_tva', 'normal_daily_hours', 'break_threshold_minutes'] as $k) {
        if ($old[$k] !== '' && !preg_match('/^\d+(\.\d+)?$/', $old[$k])) {
            $errors[] = e($k) . ' : ' . t('error.invalid_input');
        }
    }
    if ($old['app_timezone'] === '') {
        $old['app_timezone'] = defined('DEFAULT_APP_TIMEZONE') ? DEFAULT_APP_TIMEZONE : 'UTC';
    }
    if (!in_array($old['app_timezone'], timezone_identifiers_list(), true)) {
        $errors[] = e(t('settings.timezone')) . ' : ' . t('error.invalid_input');
    }
    if (!$errors) {
        $st = db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($old as $k => $v) {
            $st->execute([$k, $v !== '' ? $v : null]);
        }
        log_activity('settings_saved', 'settings', null, t('settings.title'));
        flash('success', t('flash.settings_saved'));
        redirect('admin/settings.php');
    }
} else {
    $errors = [];
}

/* Téléphone : sépare l'indicatif pays (+216…) du numéro pour l'affichage. */
$phoneDial = '';
$phoneLocal = (string) $old['company_phone'];
foreach (phone_dial_codes() as $d) {
    if ($phoneLocal !== '' && strncasecmp($phoneLocal, $d, strlen($d)) === 0) {
        $phoneDial = $d;
        $phoneLocal = trim(substr($phoneLocal, strlen($d)));
        break;
    }
}
$phoneCc = '';
if ($phoneDial !== '') {
    $storedCc = (string) $old['company_phone_cc'];
    foreach (phone_countries() as $c) {
        if ($c['dial'] !== $phoneDial) {
            continue;
        }
        if ($c['code'] === $storedCc) {
            $phoneCc = $storedCc;   // pays enregistré : présélection exacte
            break;
        }
        if ($phoneCc === '') {
            $phoneCc = $c['code'];  // repli : premier pays de cet indicatif
        }
    }
}

$smtpPassSet = $old['smtp_pass'] !== '';
$pageTitle = t('settings.title');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-gear me-2 text-danger"></i><?= e(t('settings.title')) ?></h1>
  </div>
</div>

<?php if ($errors): ?>
  <?= banner('danger', '', t('common.form_errors'), $errors) ?>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="card form-narrow mb-3">
    <div class="card-header"><i class="bi bi-building me-2"></i><?= e(t('settings.company')) ?></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.company_name')) ?></label>
          <input type="text" class="form-control" name="company_name" value="<?= e($old['company_name']) ?>" maxlength="120">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.company_tax_id')) ?></label>
          <input type="text" class="form-control" name="company_tax_id" value="<?= e($old['company_tax_id']) ?>" maxlength="60">
        </div>
        <div class="col-12">
          <label class="form-label"><?= e(t('settings.company_address')) ?></label>
          <input type="text" class="form-control" name="company_address" value="<?= e($old['company_address']) ?>" maxlength="255">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.company_phone')) ?></label>
          <div class="input-group">
            <select class="form-select phone-cc-select" name="company_phone_cc" aria-label="<?= e(t('settings.phone_dial')) ?>" style="max-width: 210px;">
              <option value="" <?= $phoneCc === '' ? 'selected' : '' ?>><?= e(t('settings.phone_dial_ph')) ?></option>
              <?php foreach (phone_countries() as $c): ?>
                <option value="<?= e($c['code']) ?>" <?= $phoneCc === $c['code'] ? 'selected' : '' ?>><?= e($c['dial'] . ' ' . $c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="tel" class="form-control" name="company_phone" value="<?= e($phoneLocal) ?>" maxlength="30">
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.company_email')) ?></label>
          <input type="email" class="form-control" name="company_email" value="<?= e($old['company_email']) ?>" maxlength="120">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.company_web')) ?></label>
          <input type="text" class="form-control" name="company_web" value="<?= e($old['company_web']) ?>" maxlength="120">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.email_from')) ?></label>
          <input type="email" class="form-control" name="email_from" value="<?= e($old['email_from']) ?>" maxlength="120">
        </div>
      </div>
    </div>
  </div>

  <div class="card form-narrow mb-3">
    <div class="card-header"><i class="bi bi-envelope-paper me-2"></i><?= e(t('settings.smtp')) ?></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.smtp_host')) ?></label>
          <input type="text" class="form-control" name="smtp_host" value="<?= e($old['smtp_host']) ?>" placeholder="smtp.example.com" maxlength="120">
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= e(t('settings.smtp_port')) ?></label>
          <input type="number" min="1" max="65535" class="form-control" name="smtp_port" value="<?= e($old['smtp_port'] ?: '587') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= e(t('settings.smtp_secure')) ?></label>
          <select class="form-select" name="smtp_secure">
            <option value="tls" <?= ($old['smtp_secure'] ?: 'tls') === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
            <option value="ssl" <?= $old['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.smtp_user')) ?></label>
          <input type="email" class="form-control" name="smtp_user" value="<?= e($old['smtp_user']) ?>" placeholder="medi...@gmail.com" maxlength="120" autocomplete="off">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.smtp_pass')) ?></label>
          <input type="password" class="form-control" name="smtp_pass" value="" placeholder="<?= $smtpPassSet ? '••••••••••••' : e(t('settings.smtp_pass_ph')) ?>" maxlength="200" autocomplete="new-password">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= e(t('settings.smtp_from')) ?> <span class="text-muted small">(<?= e(t('common.optional')) ?>)</span></label>
          <input type="email" class="form-control" name="smtp_from" value="<?= e($old['smtp_from']) ?>" placeholder="<?= e($old['smtp_user'] ?: 'medi...@gmail.com') ?>" maxlength="120">
        </div>
      </div>
    </div>
  </div>

  <div class="card form-narrow mb-3">
    <div class="card-header"><i class="bi bi-sliders me-2"></i><?= e(t('settings.business')) ?></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label"><?= e(t('settings.default_tva')) ?></label>
          <input type="number" step="0.01" min="0" max="100" class="form-control" name="default_tva" value="<?= e($old['default_tva']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= e(t('settings.expected_in')) ?></label>
          <input type="time" class="form-control" name="expected_in_time" value="<?= e($old['expected_in_time']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= e(t('settings.normal_hours')) ?></label>
          <input type="number" step="0.5" min="1" max="16" class="form-control" name="normal_daily_hours" value="<?= e($old['normal_daily_hours']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= e(t('settings.break_threshold')) ?></label>
          <input type="number" min="0" max="240" class="form-control" name="break_threshold_minutes" value="<?= e($old['break_threshold_minutes']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= e(t('settings.currency')) ?></label>
          <input type="text" class="form-control" name="currency" value="<?= e($old['currency']) ?>" maxlength="5">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="app_timezone"><i class="bi bi-globe me-1"></i><?= e(t('settings.timezone')) ?></label>
          <select class="form-select" id="app_timezone" name="app_timezone">
            <?php
            $currentTz = $old['app_timezone'] !== '' ? $old['app_timezone'] : (defined('DEFAULT_APP_TIMEZONE') ? DEFAULT_APP_TIMEZONE : 'UTC');
            foreach (app_timezone_options() as $group => $list): ?>
              <optgroup label="<?= e($group) ?>">
                <?php foreach ($list as $tzId => $meta): ?>
                  <option value="<?= e($tzId) ?>" <?= $currentTz === $tzId ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <?php
          $previewTz = in_array($currentTz, timezone_identifiers_list(), true) ? $currentTz : date_default_timezone_get();
          try {
              $previewNow = new DateTime('now', new DateTimeZone($previewTz));
          } catch (Throwable $e) {
              $previewNow = new DateTime('now');
              $previewTz = date_default_timezone_get();
          }
          $previewOff = $previewNow->getOffset();
          $previewSign = $previewOff < 0 ? '-' : '+';
          $previewAbs = abs($previewOff);
          ?>
          <div class="form-text">
            <i class="bi bi-clock me-1"></i><?= e(t('settings.timezone_now')) ?> :
            <strong id="tz-now"><?= e($previewNow->format('d/m/Y H:i:s')) ?></strong>
            (<?= e($previewTz) ?>, UTC<?= e($previewSign . sprintf('%02d:%02d', intdiv($previewAbs, 3600), intdiv($previewAbs % 3600, 60))) ?>)
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="mb-4">
    <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i><?= e(t('common.save')) ?></button>
  </div>
</form>

  <div class="card form-narrow mb-3">
    <div class="card-header"><i class="bi bi-cloud me-2"></i><?= e(t('settings.system')) ?></div>
    <div class="card-body">
      <form method="post" class="m-0" id="clearCacheForm">
        <?= csrf_field() ?>
        <input type="hidden" name="clear_cache" value="1">
        <button type="submit" class="btn btn-outline-secondary w-100" id="clearCacheBtn">
          <i class="bi bi-arrow-repeat me-1"></i><?= e(t('settings.clear_cache')) ?>
        </button>
      </form>
    </div>
  </div>

  <div class="card form-narrow mb-3 border-danger-subtle">
    <div class="card-header text-danger"><i class="bi bi-chat-dots me-2"></i><?= e(t('settings.chat')) ?></div>
    <div class="card-body">
      <form method="post" class="m-0" id="clearChatForm">
        <?= csrf_field() ?>
        <input type="hidden" name="clear_chat" value="1">
        <button type="submit" class="btn btn-outline-danger w-100" id="clearChatBtn">
          <i class="bi bi-trash3 me-1"></i><?= e(t('settings.clear_chat')) ?>
        </button>
      </form>
    </div>
  </div>
  <div class="card form-narrow mb-3 border-danger-subtle">
    <div class="card-header text-danger"><i class="bi bi-arrow-counterclockwise me-2"></i><?= e(t('settings.reset_title')) ?></div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        <i class="bi bi-info-circle me-1"></i><?= e(t('settings.reset_desc')) ?>
      </p>
      <form method="post" class="m-0" id="resetAppForm">
        <?= csrf_field() ?>
        <input type="hidden" name="reset_app" value="1">
        <button type="submit" class="btn btn-danger w-100" id="resetAppBtn">
          <i class="bi bi-arrow-counterclockwise me-1"></i><?= e(t('settings.reset_btn')) ?>
        </button>
      </form>
    </div>
  </div>
<script>
(function() {
  // Affiche un popup de confirmation (template générique ou danger)
  function showConfirmPopup(title, message, onConfirm, onCancel, okLabel, cancelLabel, variant, icon) {
    var template = document.getElementById('confirmPopupTemplate');
    var newPopup = template.cloneNode(true);
    newPopup.id = 'confirmPopup';
    newPopup.style.display = 'flex';
    newPopup.removeAttribute('aria-hidden');
    document.body.appendChild(newPopup);

    var popup = newPopup;
    var isDanger = variant === 'danger';
    var card = popup.querySelector('.card');
    var iconEl = popup.querySelector('.icon i');
    var noteEl = popup.querySelector('.confirm-note');

    if (isDanger) {
      popup.className = 'confirm-popup-danger';
      iconEl.className = 'bi bi-trash3-fill';
      if (noteEl) noteEl.textContent = 'Cette action est immédiate et irréversible.';
    } else {
      popup.className = 'confirm-popup';
      iconEl.className = 'bi bi-arrow-repeat';
      if (noteEl) noteEl.textContent = 'Cette action sera appliquée immédiatement.';
    }

    var popupTitle = popup.querySelector('#confirmPopupTitle');
    var popupMessage = popup.querySelector('#confirmPopupMessage');
    if (popupTitle) popupTitle.textContent = title || '';
    if (popupMessage) popupMessage.textContent = message || '';
    popup.querySelector('[data-confirm-cancel]').textContent = cancelLabel || 'Annuler';
    popup.querySelector('[data-confirm-ok]').textContent = okLabel || 'Accepter';

    var okBtn = popup.querySelector('[data-confirm-ok]');
    var cancelBtn = popup.querySelector('[data-confirm-cancel]');

    function handleConfirm() {
      hideConfirmPopup();
      if (typeof onConfirm === 'function') {
        try { onConfirm(); } catch (e) { console.error(e); }
      }
    }

    function handleCancel() {
      hideConfirmPopup();
      if (typeof onCancel === 'function') {
        try { onCancel(); } catch (e) { console.error(e); }
      }
    }

    function hideConfirmPopup() {
      var p = document.getElementById('confirmPopup');
      if (p) p.remove();
      window.__confirm_onConfirm = null;
      window.__confirm_onCancel = null;
    }

    popup.querySelectorAll('[data-confirm-ok]').forEach(function(btn) { btn.removeEventListener('click', handleConfirm); });
    popup.querySelectorAll('[data-confirm-cancel]').forEach(function(btn) { btn.removeEventListener('click', handleCancel); });
    popup.removeEventListener('click', function(e) { if (e.target === popup) handleCancel(); });

    okBtn.addEventListener('click', handleConfirm);
    cancelBtn.addEventListener('click', handleCancel);
    popup.addEventListener('click', function(e) { if (e.target === popup) handleCancel(); });

    setTimeout(function() { okBtn.focus(); }, 100);
  }

  // Bind des actions confirmées : vider le cache, vider le chat
  function bindConfirmedAction(formId, buttonId, title, message, okLabel, variant, icon) {
    var form = document.getElementById(formId);
    var button = document.getElementById(buttonId);
    if (!form || !button) return;

    form.addEventListener('submit', function(e) {
      if (button.dataset.confirmed === '1') return;
      e.preventDefault();
      showConfirmPopup(
        title,
        message,
        function() {
          button.dataset.confirmed = '1';
          button.disabled = true;
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(button);
          } else {
            form.submit();
          }
        },
        function() {},
        okLabel || 'Accepter',
        'Annuler',
        variant || 'brand',
        icon || 'bi-arrow-repeat'
      );
    });
  }

  bindConfirmedAction('clearCacheForm', 'clearCacheBtn',
    'Vider le cache ?',
    'Les données temporaires seront supprimées et recréées automatiquement.',
    'Accepter',
    'brand',
    'bi-arrow-repeat'
  );

  bindConfirmedAction('clearChatForm', 'clearChatBtn',
    'Supprimer les messages de chat ?',
    'Tous les messages du chat interne seront supprimés définitivement. Cette action est irréversible.',
    'Accepter',
    'danger',
    'bi-trash3-fill'
  );

  bindConfirmedAction('resetAppForm', 'resetAppBtn',
    <?= json_encode(t('settings.reset_title')) ?>,
    <?= json_encode(t('settings.reset_confirm')) ?>,
    'Accepter',
    'danger',
    'bi-trash3-fill'
  );
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
