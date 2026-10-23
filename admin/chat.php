<?php
/**
 * Page de chat administrateur ↔ mécanicien.
 * Accès restreint aux administrateurs uniquement.
 * Le widget flottant (bas à droite) fournit l'interface de chat réelle.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$db = db();

$me = current_user();
$meId = (int) $me['id'];
$meRole = (string) ($me['role'] ?? '');

// Traitement de l'envoi de message depuis le formulaire classique
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    if (!csrf_verify()) {
        http_response_code(403);
        exit('Jeton de sécurité invalide.');
    }
    $peerId = (int) ($_POST['peer_id'] ?? 0);
    $text = trim((string) ($_POST['message'] ?? ''));
    if ($peerId <= 0 || $text === '') {
        header('Location: chat.php');
        exit;
    }
    if (!chat_peer_allowed($db, $peerId, $meId, $meRole)) {
        http_response_code(403);
        exit('Conversation non autorisée.');
    }
    chat_messages_schema_ensure($db);
    $id = chat_send_message($db, $meId, $peerId, $text);
    if ($id > 0) {
        header('Location: chat.php?peer=' . $peerId);
        exit;
    }
    header('Location: chat.php');
    exit;
}

$contacts = [];
$selectedPeerId = 0;
$messages = [];
$selectedPeer = null;

try {
    $contacts = chat_get_contacts($db, $meId, 'admin');
} catch (Throwable $e) {
    error_log('admin/chat.php: ' . $e->getMessage());
}

if (isset($_GET['peer'])) {
    $selectedPeerId = (int) $_GET['peer'];
}

if ($selectedPeerId > 0) {
    $messages = chat_fetch_messages($db, $meId, $selectedPeerId);
    chat_mark_read($db, $meId, $selectedPeerId);
    $peerRow = fetch_one("SELECT id, full_name, username, mechanic_id, role FROM users WHERE id = ? LIMIT 1", [$selectedPeerId]);
    if ($peerRow) { $selectedPeer = $peerRow; }
}

$pageTitle = t('chat.title');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
    <div>
        <h1><i class="bi bi-chat-dots me-2 text-danger"></i><?= e(t('chat.title')) ?></h1>
        <div class="subtitle"><?= e(t('chat.admin_feature')) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-people"></i>
        <span class="mb-0"><?= e(t('chat.mecanicien_contacts')) ?></span>
    </div>
    <div class="card-body">
        <?php if (!$contacts): ?>
            <div class="alert alert-info"><?= e(t('chat.no_contacts')) ?></div>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($contacts as $c): ?>
                    <?php
                        $cid = (int) $c['id'];
                        $active = $cid === $selectedPeerId;
                        $online = !empty($c['is_online']);
                    ?>
                    <li class="list-group-item d-flex align-items-center gap-3 <?= $active ? 'bg-danger bg-opacity-10' : '' ?>">
                        <div class="position-relative">
                            <span class="avatar" style="width:44px;height:44px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:#fff;background:#2563eb;">
                                <?= strtoupper(substr((string) ($c['nom_complet'] ?: $c['username']), 0, 1)) ?>
                            </span>
                            <?php if ($online): ?>
                                <span class="position-absolute bottom-0 end-0 bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:12px;height:12px;font-size:8px;">
                                    <i class="bi bi-check"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold"><?= e($c['nom_complet'] ?: $c['username']) ?></div>
                            <div class="text-muted small"><?= e($c['role'] === 'mechanic' ? t('role.mechanic') : $c['role']) ?></div>
                        </div>
                        <a href="<?= e(app_url('admin/chat.php?peer=' . $cid)) ?>" class="btn btn-sm <?= $active ? 'btn-danger' : 'btn-outline-danger' ?>">
                            <i class="bi bi-chat-dots"></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-clock-history"></i>
        <span class="mb-0"><?= e(t('chat.history')) ?></span>
    </div>
    <div class="card-body">
        <?php if ($selectedPeer): ?>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="avatar" style="width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:#fff;background:#2563eb;">
                    <?= strtoupper(substr((string) $selectedPeer['full_name'], 0, 1)) ?>
                </span>
                <div>
                    <div class="fw-semibold"><?= e($selectedPeer['full_name']) ?></div>
                    <div class="text-muted small"><?= e($selectedPeer['username']) ?></div>
                </div>
            </div>

            <div class="border rounded p-3 bg-light" style="max-height:320px;overflow-y:auto;">
                <?php if (!$messages): ?>
                    <div class="text-muted small"><?= e(t('chat.empty')) ?></div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="mb-2 <?= $msg['mine'] ? 'text-end' : 'text-start' ?>">
                            <div class="<?= $msg['mine'] ? 'bg-danger bg-opacity-10 text-dark rounded-3 px-3 py-2' : 'bg-white text-dark rounded-3 px-3 py-2 shadow-sm' ?>">
                                <?= nl2br(e($msg['text'])) ?>
                                <div class="small text-muted mt-1"><?= e($msg['time']) ?></div>
                                <?php if ($msg['mine']): ?>
                                    <div class="small text-muted"><?= e(t('chat.you')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form method="post" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="peer_id" value="<?= (int) $selectedPeerId ?>">
                <div class="input-group">
                    <textarea name="message" class="form-control" rows="1" placeholder="<?= e(t('chat.write_here')) ?>" required></textarea>
                    <button class="btn btn-danger" type="submit"><i class="bi bi-send"></i></button>
                </div>
            </form>
        <?php else: ?>
            <div class="text-muted small"><?= e(t('chat.select_contact')) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>