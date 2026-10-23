<?php
/**
 * Connexion : identifiant/identifiant-e-mail + mot de passe.
 * - password_verify()
 * - régénération d'ID de session après connexion
 * - anti force brute (login_attempts)
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(home_url());
}

$error = null;
$ip = client_ip();

// verrouillage anti force brute
$blocked = login_blocked($ip);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    if ($blocked) {
        $error = t('login.blocked');
    } else {
        $ident = trim((string) ($_POST['identity'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');

        if ($ident === '' || $pass === '') {
            $error = t('error.required');
        } else {
            $user = fetch_one(
                "SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1",
                [$ident, $ident]
            );
            if ($user && password_verify($pass, $user['password'])) {
                if ((int) $user['is_active'] !== 1) {
                    record_login_attempt($ip, false);
                    $error = t('login.disabled');
                } elseif ($user['role'] === 'mechanic' && !ip_approved_for_user((int) $user['id'], $ip)) {
                    request_ip_approval_for_user((int) $user['id'], $ip, 'Login request from unapproved IP');
                    notify_admins(
                        'Demande d\'approbation IP',
                        'Le mécanicien ' . $user['full_name'] . ' a tenté de se connecter depuis l\'IP ' . $ip . '.',
                        'warning',
                        'admin/users/index.php',
                        'mechanic_ip_pending_' . (int) $user['id'] . '_' . $ip
                    );
                    log_activity('login_pending_ip', 'auth', (int) $user['id'], 'Demande d\'approbation IP depuis ' . $ip);
                    record_login_attempt($ip, false);
                    $error = t('login.ip_not_approved');
                } else {
                    // ---- succès : nouvelle session ----
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['security_fingerprint'] = current_session_fingerprint();
                    execute("UPDATE users SET last_login_at = NOW() WHERE id = ?", [$user['id']]);
                    record_login_attempt($ip, true);
                    log_activity('login', 'auth', (int) $user['id'], t('log.login_ok'));
                    redirect(home_url());
                }
            } else {
                record_login_attempt($ip, false);
                $error = t('login.bad_credentials');
            }
        }
    }
}

$pageTitle = t('login.title');
?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(t('login.title')) ?> — PROS AUTO</title>
<link rel="icon" type="image/png" href="<?= e(asset_url('assets/images/logo.png')) ?>">
<link rel="stylesheet" href="<?= e(app_url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('assets/css/style.css')) ?>">
<script>
  (function () {
    try {
      var stored = localStorage.getItem('theme');
      var prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
      document.documentElement.setAttribute('data-theme', stored || (prefersLight ? 'light' : 'dark'));
    } catch (e) { document.documentElement.setAttribute('data-theme', 'dark'); }
  })();
</script>
</head>
<body class="login-page">
  <div class="login-shell">

    <!-- ---------- Panneau marque : logo, identité, modules ---------- -->
    <aside class="login-brand">
      <div class="login-brand-head">
        <span class="login-brand-mark">
          <img src="<?= e(asset_url('assets/images/logo.png')) ?>" alt="" class="login-brand-logo">
        </span>
        <div class="login-brand-text">
          <p class="login-brand-tagline">
            <span class="login-tagline-dot" aria-hidden="true"></span>
            <span class="login-tagline-text"><?= e(t('app.tagline')) ?></span>
          </p>
        </div>
      </div>

      <ul class="login-brand-list">
        <li><i class="bi bi-people" aria-hidden="true"></i><span><?= e(t('nav.clients')) ?></span></li>
        <li><i class="bi bi-car-front" aria-hidden="true"></i><span><?= e(t('nav.vehicles')) ?></span></li>
        <li><i class="bi bi-tools" aria-hidden="true"></i><span><?= e(t('nav.repairs')) ?></span></li>
        <li><i class="bi bi-receipt" aria-hidden="true"></i><span><?= e(t('nav.factures')) ?></span></li>
      </ul>
    </aside>

    <!-- ---------- Panneau formulaire ---------- -->
    <section class="login-panel">
      <header class="login-panel-head">
        <div class="login-top-actions">
          <details class="login-lang-details">
            <summary class="icon-btn login-icon-btn" aria-label="<?= e(t('nav.language')) ?>" title="<?= e(t('nav.language')) ?>">
              <i class="bi bi-translate" aria-hidden="true"></i>
              <span class="login-lang-badge"><?= e(strtoupper(current_lang())) ?></span>
            </summary>
            <div class="login-lang-menu" role="menu">
              <a href="<?= e(lang_switch_url('fr')) ?>" hreflang="fr" class="<?= current_lang() === 'fr' ? 'active' : '' ?>" role="menuitem"><span class="lang-code">FR</span><span>Français</span><?= current_lang() === 'fr' ? '<i class="bi bi-check-lg ms-auto"></i>' : '' ?></a>
              <a href="<?= e(lang_switch_url('en')) ?>" hreflang="en" class="<?= current_lang() === 'en' ? 'active' : '' ?>" role="menuitem"><span class="lang-code">EN</span><span>English</span><?= current_lang() === 'en' ? '<i class="bi bi-check-lg ms-auto"></i>' : '' ?></a>
            </div>
          </details>
          <button type="button" id="loginThemeToggle" class="icon-btn login-icon-btn" aria-label="<?= e(t('nav.theme')) ?>" title="<?= e(t('nav.theme')) ?>">
            <i class="bi theme-icon bi-moon" aria-hidden="true"></i>
          </button>
        </div>
      </header>

      <div class="login-card">
        <div class="login-card-head">
          <h2><?= e(t('login.title')) ?></h2>
          <p class="login-subtitle"><?= e(t('login.subtitle')) ?></p>
        </div>

        <?php if ($error): ?>
          <?= banner('danger', $error) ?>
        <?php endif; ?>

        <form method="post" action="<?= e(app_url('auth/login.php')) ?>" autocomplete="off" novalidate>
          <?= csrf_field() ?>

          <div class="login-group">
            <label class="form-label" for="identity"><?= e(t('login.username_ph')) ?></label>
            <div class="input-group login-field">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-person"></i></span>
              <input type="text" class="form-control" id="identity" name="identity"
                     value="<?= e($_POST['identity'] ?? '') ?>" required autofocus autocomplete="username">
            </div>
          </div>

          <div class="login-group">
            <label class="form-label" for="password"><?= e(t('login.password_ph')) ?></label>
            <div class="input-group login-field login-field--password">
              <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
              <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
              <button type="button" class="login-eye" id="loginEye" aria-controls="password" aria-pressed="false"
                      aria-label="<?= current_lang() === 'en' ? 'Show password' : 'Afficher le mot de passe' ?>"
                      title="<?= current_lang() === 'en' ? 'Show password' : 'Afficher le mot de passe' ?>">
                <i class="bi bi-eye" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn btn-login w-100">
            <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
            <span><?= e(t('login.submit')) ?></span>
          </button>
        </form>

        <p class="login-note">
          <i class="bi bi-shield-lock" aria-hidden="true"></i>
          <span><?= e(t('login.secure_note')) ?></span>
        </p>
      </div>
    </section>
  </div>

  <script>
  /* Thème sombre / clair sur la page login (même clé que l'app). */
  (function () {
    function current() {
      try { return localStorage.getItem('theme') || document.documentElement.getAttribute('data-theme') || 'dark'; }
      catch (e) { return document.documentElement.getAttribute('data-theme') || 'dark'; }
    }
    function paint() {
      var isLight = current() === 'light';
      var icon = document.querySelector('#loginThemeToggle .theme-icon');
      if (icon) { icon.className = 'bi theme-icon ' + (isLight ? 'bi-sun' : 'bi-moon'); }
    }
    var btn = document.getElementById('loginThemeToggle');
    if (btn) {
      btn.addEventListener('click', function () {
        var next = current() === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) {}
        paint();
      });
    }
    paint();
  })();
  </script>
  <script>
  /* Confort visuel : afficher / masquer le mot de passe (sans effet sur l'authentification). */
  (function () {
    var eye = document.getElementById('loginEye');
    var pwd = document.getElementById('password');
    if (!eye || !pwd) { return; }
    eye.addEventListener('click', function () {
      var show = pwd.type === 'password';
      pwd.type = show ? 'text' : 'password';
      eye.setAttribute('aria-pressed', show ? 'true' : 'false');
      var icon = eye.querySelector('i');
      if (icon) { icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye'; }
    });
  })();
  </script>
</body>
</html>
