<?php
/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }

/**
 * Barre latérale gauche — menu selon le rôle.
 */
$u = current_user();
$current = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

function nav_item(string $url, string $icon, string $label, string $current, int $badge = 0): string
{
    $active = str_contains($current, $url) ? 'active' : '';
    $b = $badge > 0 ? '<span class="nav-badge">' . $badge . '</span>' : '';
    return '<a class="nav-link ' . $active . '" href="' . e(app_url($url)) . '" data-tip="' . e($label) . '" aria-label="' . e($label) . '">'
         . '<span class="nav-ico"><i class="bi bi-' . $icon . '"></i></span><span class="nav-txt">' . e($label) . '</span>' . $b . '</a>';
}

function nav_section(string $label, string $current): string
{
    return '<div class="nav-section"><span class="nav-section-txt">' . e($label) . '</span><span class="nav-section-dot" aria-hidden="true"></span></div>';
}
?>
<aside class="app-sidebar">
  <div class="sidebar-top">
    <span class="sidebar-top-label"><?= e(t('nav.menu')); ?></span>
    <button id="sidebarReduce" class="sidebar-rail-btn" type="button" aria-label="<?= e(t('common.reduce')); ?>" data-tip-expand="<?= e(t('nav.expand')); ?>" data-tip-reduce="<?= e(t('common.reduce')); ?>">
      <i class="bi bi-chevron-double-left"></i>
    </button>
  </div>
  <nav class="sidebar-nav">

    <?php if ($u && $u['role'] === 'admin'): ?>
      <?= nav_item('admin/dashboard.php', 'speedometer2', t('nav.dashboard'), $current) ?>

      <?= nav_section(t('nav.section_management'), $current) ?>
      <?= nav_item('admin/clients/index.php', 'people', t('nav.clients'), $current) ?>
      <?= nav_item('admin/vehicles/index.php', 'car-front', t('nav.vehicles'), $current) ?>
      <?= nav_item('admin/mechanics/index.php', 'person-gear', t('nav.mechanics'), $current) ?>

      <?= nav_section(t('nav.section_workshop'), $current) ?>
      <?= nav_item('admin/inspections/index.php', 'clipboard-check', t('nav.inspections'), $current) ?>
      <?= nav_item('admin/assignments/index.php', 'person-plus', t('nav.assignments'), $current) ?>
      <?= nav_item('admin/repairs/index.php', 'wrench-adjustable', t('nav.repairs'), $current) ?>
      <?= nav_item('admin/factures/index.php', 'receipt', t('nav.factures'), $current) ?>

      <?= nav_section(t('nav.section_time'), $current) ?>
      <?= nav_item('admin/attendance/index.php', 'clock', t('nav.attendance'), $current) ?>
      <?= nav_item('admin/attendance/history.php', 'clock-history', t('att.history'), $current) ?>
      <?= nav_item('admin/attendance/anomalies.php', 'exclamation-octagon', t('nav.anomalies'), $current) ?>

      <?= nav_section(t('nav.section_system'), $current) ?>
      <?= nav_item('admin/users/index.php', 'person-badge', t('nav.users'), $current) ?>
      <?= nav_item('admin/logs/index.php', 'journal-text', t('nav.logs'), $current) ?>
      <?= nav_item('admin/settings.php', 'gear', t('nav.settings'), $current) ?>

    <?php elseif ($u): ?>
      <?= nav_item('mechanic/dashboard.php', 'speedometer2', t('nav.dashboard'), $current) ?>

      <?= nav_section(t('nav.section_management'), $current) ?>
      <?= nav_item('mechanic/vehicles.php', 'car-front', t('nav.my_vehicles'), $current) ?>
      <?= nav_item('mechanic/repairs.php', 'wrench-adjustable', t('nav.my_repairs'), $current) ?>

      <?= nav_section(t('nav.section_time'), $current) ?>
      <?= nav_item('mechanic/attendance.php', 'clock', t('nav.attendance'), $current) ?>
    <?php endif; ?>
  </nav>
</aside>
