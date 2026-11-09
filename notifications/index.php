<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$u = current_user();

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    $filter = 'all';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all') {
        execute("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$u['id']]);
        flash('success', t('notif.all_marked_read'));
    }
    if ($action === 'mark_read') {
        $notifId = (int) ($_POST['notification_id'] ?? 0);
        if ($notifId > 0) {
            execute("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$notifId, $u['id']]);
            flash('success', t('notif.marked_read'));
        }
    }
    redirect('notifications/index.php' . ($filter !== 'all' ? '?filter=' . rawurlencode($filter) : ''));
}

$where = 'WHERE user_id = ?';
$params = [(int) $u['id']];
if ($filter === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($filter === 'read') {
    $where .= ' AND is_read = 1';
}

$pageNotifs = fetch_all(
    "SELECT * FROM notifications $where ORDER BY id DESC",
    $params
);
$icons = ['info' => 'bi-info-circle-fill text-primary', 'success' => 'bi-check-circle-fill text-success', 'warning' => 'bi-exclamation-triangle-fill text-warning', 'danger' => 'bi-x-octagon-fill text-danger'];

$pageTitle = t('notif.title');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title notif-page-header">
  <div>
    <h1><i class="bi bi-bell me-2 text-danger"></i><?= e(t('notif.title')) ?></h1>
    <div class="subtitle text-muted"><?= e(t('notif.history_kept')) ?></div>
  </div>
  <div class="notif-toolbar-actions">
    <div class="notif-filter-group" role="group" aria-label="Notification filters">
      <a href="<?= e(app_url('notifications/index.php?filter=all')) ?>" class="notif-filter-btn <?= $filter === 'all' ? 'active' : '' ?>"><?= e(t('notif.filter_all')) ?></a>
      <a href="<?= e(app_url('notifications/index.php?filter=unread')) ?>" class="notif-filter-btn <?= $filter === 'unread' ? 'active' : '' ?>"><?= e(t('notif.filter_unread')) ?></a>
      <a href="<?= e(app_url('notifications/index.php?filter=read')) ?>" class="notif-filter-btn <?= $filter === 'read' ? 'active' : '' ?>"><?= e(t('notif.filter_read')) ?></a>
    </div>
    <form method="post" class="d-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all">
      <button type="submit" class="btn notif-mark-all">
        <i class="bi bi-check2-all me-2"></i><?= e(t('notif.mark_all')) ?>
      </button>
    </form>
  </div>
</div>

<div class="card shadow-sm border-0">
  <div class="card-body p-0">
    <?php if (!$pageNotifs): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
        <div><?= e($filter === 'all' ? t('notif.no_notifs') : t('notif.no_notifs_in_filter')) ?></div>
        <?php if ($filter !== 'all'): ?>
          <div class="mt-3">
            <a href="<?= e(app_url('notifications/index.php?filter=all')) ?>" class="btn btn-primary btn-sm">
              <i class="bi bi-list-check me-1"></i>Afficher tout l'historique
            </a>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php foreach ($pageNotifs as $n): ?>
        <div class="notif-row d-flex gap-3 px-4 py-3 border-bottom align-items-start <?= $n['is_read'] ? 'notif-row--read' : 'notif-row--unread' ?>">
          <i class="bi <?= $icons[$n['type']] ?? 'bi-bell' ?> fs-4 mt-1"></i>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between align-items-center gap-3">
              <strong class="small fw-semibold"><?= e($n['title']) ?></strong>
              <div class="d-flex align-items-center gap-2">
                <span class="badge rounded-pill <?= $n['is_read'] ? 'text-bg-secondary' : 'text-bg-danger' ?>">
                  <?= e($n['is_read'] ? t('notif.read') : t('notif.unread')) ?>
                </span>
                <span class="small text-muted"><?= fmt_datetime($n['created_at']) ?></span>
              </div>
            </div>
            <div class="small text-body-secondary mt-1"><?= e($n['message']) ?></div>
            <?php $link = (string) ($n['link'] ?? ''); ?>
            <?php if ($link !== ''): ?>
              <div class="mt-2 d-flex align-items-center gap-2">
                <a class="small text-decoration-none fw-semibold" href="<?= e(app_url('notifications/go.php') . '?id=' . (int) $n['id']) ?>">
                  <i class="bi bi-arrow-right me-1"></i><?= e(t('common.details')) ?>
                </a>
                <?php if (!$n['is_read']): ?>
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_read">
                    <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                    <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">
                      <i class="bi bi-check-circle me-1"></i><?= e(t('notif.mark_read')) ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <?php if (!$n['is_read']): ?>
                <form method="post" class="d-inline mt-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">
                    <i class="bi bi-check-circle me-1"></i><?= e(t('notif.mark_read')) ?>
                  </button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
