<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$u = current_user();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all') {
        execute("UPDATE notifications SET is_read = 1 WHERE user_id = ?", [$u['id']]);
        flash('success', t('flash.updated'));
    }
    redirect('notifications/index.php');
}

$notifs = fetch_all("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100", [$u['id']]);
$icons = ['info' => 'bi-info-circle-fill text-primary', 'success' => 'bi-check-circle-fill text-success', 'warning' => 'bi-exclamation-triangle-fill text-warning', 'danger' => 'bi-x-octagon-fill text-danger'];

$pageTitle = t('notif.title');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
  <div>
    <h1><i class="bi bi-bell me-2 text-danger"></i><?= e(t('notif.title')) ?></h1>
  </div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="mark_all">
    <button class="btn btn-outline-primary btn-sm"><i class="bi bi-check2-all me-1"></i><?= e(t('notif.mark_all')) ?></button>
  </form>
</div>

<div class="card">
  <div class="card-body p-0">
    <?php if (!$notifs): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
        <?= e(t('notif.no_notifs')) ?>
      </div>
    <?php else: ?>
      <?php foreach ($notifs as $n): ?>
        <div class="d-flex gap-3 px-4 py-3 border-bottom <?= $n['is_read'] ? 'opacity-60' : 'bg-white' ?>">
          <i class="bi <?= $icons[$n['type']] ?? 'bi-bell' ?> fs-4 mt-1"></i>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between">
              <strong class="small"><?= e($n['title']) ?></strong>
              <span class="small text-muted"><?= fmt_datetime($n['created_at']) ?></span>
            </div>
            <div class="small"><?= e($n['message']) ?></div>
            <?php $link = (string) ($n['link'] ?? ''); ?>
            <?php if ($link !== ''): ?>
              <a class="small" href="<?= e(app_url('notifications/go.php') . '?id=' . (int) $n['id']) ?>"><i class="bi bi-arrow-right me-1"></i><?= e(t('common.details')) ?></a>
            <?php endif; ?>
          </div>
          <?php if (!$n['is_read']): ?>
            <span class="badge rounded-pill text-bg-danger align-self-start">•</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
