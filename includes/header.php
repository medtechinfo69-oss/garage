<?php
/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }

/**
 * En-tête commun : <head>, navbar supérieure.
 * Pré-requis : auth.php déjà inclus.
 */
$u = current_user();
$unread = $u ? unread_count((int) $u['id']) : 0;
$notifs = $u ? unread_notifications((int) $u['id']) : [];
$notifTypeIcon = ['info' => 'bi-info-circle-fill text-primary', 'success' => 'bi-check-circle-fill text-success', 'warning' => 'bi-exclamation-triangle-fill text-warning', 'danger' => 'bi-x-octagon-fill text-danger'];
?><!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle ?? t('app.title')) ?></title>
<link rel="icon" type="image/png" href="<?= e(app_url('assets/images/logo.png')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/vendor/bootstrap-icons/bootstrap-icons.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
<script>
  (function () {
    var stored = localStorage.getItem('theme');
    var prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    var theme = stored || (prefersLight ? 'light' : 'dark');
    document.documentElement.setAttribute('data-theme', theme);
  })();
</script>

</head>
<body class="app-body" data-locale="<?= e(locale_id()) ?>">

<nav class="navbar navbar-dark app-navbar sticky-top">
  <div class="container-fluid px-3">
    <button class="btn sidebar-toggler me-2 d-lg-none" type="button" aria-label="Menu"><i class="bi bi-list"></i></button>
    <a class="navbar-brand brand-block" href="<?= e(app_url(home_url())) ?>">
      <span class="brand-logo-wrap">
        <img src="<?= e(app_url('assets/images/logo.png')) ?>" alt="PROS AUTO" class="brand-logo">
      </span>
      <span class="brand-text d-none d-sm-flex">
        <span class="brand-name">PROS <strong>AUTO</strong></span>
        <span class="brand-tagline"><?= e(t('app.tagline')) ?></span>
      </span>
    </a>

    <div class="d-flex align-items-center gap-2 ms-auto">
      <!-- Langue : bouton icône seul -->
      <div class="dropdown">
        <button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                aria-label="<?= e(t('nav.language')); ?>" title="<?= e(t('nav.language')); ?>">
          <i class="bi bi-translate"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow icon-menu">
          <li><a class="dropdown-item<?= current_lang() === 'fr' ? ' active' : '' ?>" href="<?= e(lang_switch_url('fr')) ?>" hreflang="fr"><span class="lang-code">FR</span><span>Français</span><?php if (current_lang() === 'fr'): ?><i class="bi bi-check-lg ms-auto"></i><?php endif; ?></a></li>
          <li><a class="dropdown-item<?= current_lang() === 'en' ? ' active' : '' ?>" href="<?= e(lang_switch_url('en')) ?>" hreflang="en"><span class="lang-code">EN</span><span>English</span><?php if (current_lang() === 'en'): ?><i class="bi bi-check-lg ms-auto"></i><?php endif; ?></a></li>
        </ul>
      </div>

      <!-- Mode sombre / clair : icône seule -->
      <button id="themeToggle" class="icon-btn" type="button" aria-label="<?= e(t('nav.theme')); ?>" title="<?= e(t('nav.theme')); ?>">
        <i class="bi theme-icon bi-moon"></i>
      </button>


      <?php if ($u): ?>
      <!-- Notifications -->
      <div class="position-relative me-1">
        <a href="<?= e(app_url('notifications/index.php')) ?>" class="nav-link text-light position-relative px-2">
          <i class="bi bi-bell fs-5"></i>
          <?php if ($unread > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge"><?= $unread ?></span>
          <?php endif; ?>
        </a>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dropdown-head">
            <strong><?= e(t('nav.notifications')) ?></strong>
            <a class="small" href="<?= e(app_url('notifications/index.php')) ?>"><?= e(t('dash.view_all')) ?></a>
          </div>
          <?php if (!$notifs): ?>
            <div class="text-center text-muted py-4 small"><?= e(t('notif.no_notifs')) ?></div>
          <?php else: ?>
            <?php foreach ($notifs as $n): ?>
              <a class="notif-item d-flex gap-2 px-3 py-2 text-decoration-none text-body" href="<?= e($n['link'] ? app_url($n['link']) : app_url('notifications/index.php')) ?>">
                <i class="bi <?= $notifTypeIcon[$n['type']] ?? 'bi-bell' ?>"></i>
                <span>
                  <span class="d-block fw-semibold small"><?= e($n['title']) ?></span>
                  <span class="d-block small text-muted text-truncate"><?= e($n['message']) ?></span>
                  <span class="d-block small text-muted opacity-75"><?= fmt_datetime($n['created_at']) ?></span>
                </span>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Utilisateur -->
      <div class="dropdown">
        <a class="d-flex align-items-center gap-2 text-light text-decoration-none dropdown-toggle user-menu" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">
          <span class="user-avatar"><i class="bi bi-person-fill"></i></span>
          <span class="d-none d-md-inline">
            <span class="d-block small fw-semibold lh-1"><?= e($u['full_name']) ?></span>
            <span class="d-block small opacity-75 lh-1"><?= e(role_label($u['role'])) ?></span>
          </span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end shadow">
          <li><span class="dropdown-item-text small text-muted"><?= e($u['username']) ?></span></li>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="<?= e(app_url('notifications/index.php')) ?>"><i class="bi bi-bell me-2"></i><?= e(t('nav.notifications')) ?></a></li>
          <li><a class="dropdown-item text-danger" href="<?= e(app_url('auth/logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i><?= e(t('nav.logout')) ?></a></li>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="app-wrapper d-flex">
<?php require __DIR__ . '/sidebar.php'; ?>

<main class="flex-grow-1 main-content">
<div class="content-inner px-3 px-lg-4 py-4">
<?php render_flashes(); ?>
