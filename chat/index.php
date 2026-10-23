<?php
/**
 * Page de chat plein écran (admin ↔ mécanicien).
 * URL : /chat/index.php?conversation=N  (N = id du correspondant)
 * SYNCHRONISATION AUTOMATIQUE : messages + accusés de lecture rafraîchis
 * toutes les 3 s (polling ?action=sync) — aucune action manuelle requise.
 * Double coche verte ✓✓ quand le correspondant a lu vos messages.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!function_exists('can_chat_role') || !can_chat_role()) {
    http_response_code(403);
    exit('Accès refusé.');
}

$db = db();
$me = current_user();
$meId = (int) $me['id'];
$meRole = (string) ($me['role'] ?? '');
$csrf = csrf_token();

/* ---------- Envoi d'un message (formulaire classique) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $sentToken = (string) ($_POST['csrf_token'] ?? '');
    if ($sentToken === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $sentToken)) {
        http_response_code(403);
        exit('Jeton de sécurité invalide.');
    }
    $peerId = (int) ($_POST['peer_id'] ?? 0);
    $text = trim((string) ($_POST['message'] ?? ''));
    if ($peerId > 0 && $text !== '' && chat_peer_allowed($db, $peerId, $meId, $meRole)) {
        chat_messages_schema_ensure($db);
        chat_send_message($db, $meId, $peerId, $text);
    }
    redirect('chat/index.php?conversation=' . $peerId);
}

/* ---------- Données initiales ---------- */
try {
    $contacts = chat_get_contacts($db, $meId, $meRole);
} catch (Throwable $e) {
    error_log('chat/index.php contacts: ' . $e->getMessage());
    $contacts = [];
}

$convId = (int) ($_GET['conversation'] ?? 0);
$convPeer = null;
$messages = [];
$readUntil = 0;
if ($convId > 0) {
    foreach ($contacts as $c) {
        if ((int) $c['id'] === $convId) {
            $convPeer = $c;
            break;
        }
    }
    if ($convPeer) {
        try {
            $messages = chat_fetch_messages($db, $meId, $convId);
            chat_mark_read($db, $meId, $convId);
            $receipt = chat_read_receipt($db, $meId, $convId);
            $readUntil = (int) $receipt['read_until'];
        } catch (Throwable $e) {
            error_log('chat/index.php messages: ' . $e->getMessage());
        }
    }
}

$pageTitle = t('chat.title');
require __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
    <div>
        <h1><i class="bi bi-chat-dots me-2 text-danger"></i><?= e(t('chat.title')) ?></h1>
        <div class="subtitle">
            <?= $meRole === 'admin' ? e(t('chat.admin_feature')) : e(t('chat.mechanic_feature')) ?>
        </div>
    </div>
</div>

<div class="row g-3 chat-page-layout">

    <!-- ===== Liste des contacts ===== -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-people"></i>
                <span class="mb-0"><?= e(t('chat.mecanicien_contacts')) ?></span>
            </div>
            <div class="card-body p-0 chat-contacts-scroll">
                <?php if (!$contacts): ?>
                    <div class="alert alert-info m-3"><?= e(t('chat.no_contacts')) ?></div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($contacts as $c): ?>
                            <?php
                            $cid = (int) $c['id'];
                            $active = $cid === $convId;
                            $online = !empty($c['is_online']);
                            ?>
                            <li class="list-group-item p-0">
                                <a href="<?= e(app_url('chat/index.php?conversation=' . $cid)) ?>"
                                    class="d-flex align-items-center gap-3 px-3 py-3 text-decoration-none <?= $active ? 'bg-danger bg-opacity-10 fw-semibold' : '' ?>"
                                    style="color: inherit;">
                                    <span class="position-relative flex-shrink-0">
                                        <span class="avatar"
                                            style="width:44px;height:44px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:#fff;background:linear-gradient(135deg,#1A1A1A,#333);">
                                            <?= e(mb_strtoupper(mb_substr((string) ($c['nom_complet'] ?: $c['username']), 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                        </span>
                                        <?php if ($online): ?>
                                            <span class="position-absolute bottom-0 end-0 bg-success rounded-circle"
                                                style="width:12px;height:12px;border:2px solid #fff;"></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="flex-grow-1 min-w-0">
                                        <span class="d-block text-truncate"><?= e($c['nom_complet'] ?: $c['username']) ?></span>
                                        <span class="d-block small text-muted">
                                            <?= e($c['role'] === 'admin' ? t('role.admin') : t('role.mechanic')) ?>
                                            <?= $online ? ' — ' . e(t('chat.online')) : '' ?>
                                        </span>
                                    </span>
                                    <?php if ((int) ($c['unread'] ?? 0) > 0): ?>
                                        <span class="badge rounded-pill text-bg-danger flex-shrink-0"><?= (int) $c['unread'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== Conversation ===== -->
    <div class="col-lg-8">
        <div class="card h-100">
            <?php if (!$convPeer): ?>
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-chat-square-dots fs-1 d-block mb-3 opacity-50"></i>
                    <?= e(t('chat.select_contact')) ?>
                </div>
            <?php else: ?>
                <div class="card-header d-flex align-items-center gap-3">
                    <span class="avatar flex-shrink-0"
                        style="width:38px;height:38px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:#fff;background:linear-gradient(135deg,#1A1A1A,#333);">
                        <?= e(mb_strtoupper(mb_substr((string) ($convPeer['nom_complet'] ?: $convPeer['username']), 0, 1, 'UTF-8'), 'UTF-8')) ?>
                    </span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold"><?= e($convPeer['nom_complet'] ?: $convPeer['username']) ?></div>
                        <div class="small text-muted">
                            <?= e($convPeer['role'] === 'admin' ? t('role.admin') : t('role.mechanic')) ?>
                            <?= !empty($convPeer['is_online']) ? ' — <span class="text-success">' . e(t('chat.online')) . '</span>' : '' ?>
                        </div>
                    </div>
                </div>

                <div class="card-body chat-msgs-scroll" id="pageMsgList">
                    <?php if (!$messages): ?>
                        <div class="text-center text-muted py-4 small"><?= e(t('chat.empty')) ?></div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <div class="mb-2 d-flex <?= $msg['mine'] ? 'justify-content-end' : 'justify-content-start' ?>">
                                <div class="rounded-3 px-3 py-2 <?= $msg['mine'] ? 'text-white' : 'bg-white border shadow-sm' ?>"
                                    style="max-width: 78%; <?= $msg['mine'] ? 'background: linear-gradient(135deg,#CC2A22,#b8161c);' : '' ?>">
                                    <?= nl2br(e($msg['text'])) ?>
                                    <div class="small mt-1 d-flex align-items-center justify-content-end gap-1 <?= $msg['mine'] ? 'text-white-50' : 'text-muted' ?>">
                                        <span><?= e($msg['time']) ?><?= $msg['mine'] ? ' — ' . e(t('chat.you')) : '' ?></span>
                                        <?php if ($msg['mine']): ?>
                                            <svg class="ticks<?= (int) $msg['id'] <= $readUntil ? ' read' : '' ?>"
                                                viewBox="0 0 20 12" aria-label="<?= (int) $msg['id'] <= $readUntil ? 'Lu' : 'Envoyé' ?>">
                                                <path d="M1 6.5L4.5 10L11 2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                <path d="M8 6.5L11.5 10L18 2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="card-footer bg-white">
                    <form method="post" class="d-flex gap-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="send">
                        <input type="hidden" name="peer_id" value="<?= $convId ?>">
                        <textarea name="message" class="form-control" rows="1" placeholder="<?= e(t('chat.write_here')) ?>"
                            required style="max-height:110px;resize:none;"></textarea>
                        <button class="btn btn-danger flex-shrink-0" type="submit">
                            <i class="bi bi-send me-1"></i><?= e(t('chat.send')) ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($convPeer): ?>
<script>
    /* ===== SYNCHRONISATION AUTOMATIQUE de la page plein écran =====
       - nouveaux messages ajoutés sans recharger la page
       - accusés de lecture (double coche verte) mis à jour en direct
       - badges non-lus de la liste des contacts rafraîchis            */
    (function () {
        var list = document.getElementById('pageMsgList');
        if (!list) return;

        var peer = <?= (int) $convId ?>;
        var after = <?= (int) (end($messages)['id'] ?? 0) ?>;
        var readUntil = <?= (int) $readUntil ?>;
        var API = <?= json_encode(app_url('actions/chat_api.php')) ?>;

        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function isNearBottom() {
            return list.scrollTop + list.clientHeight >= list.scrollHeight - 60;
        }
        function scrollToBottom(force) {
            if (force || isNearBottom()) list.scrollTop = list.scrollHeight;
        }

        function ticksSvg(read) {
            return '<svg class="ticks' + (read ? ' read' : '') + '" viewBox="0 0 20 12">'
                + '<path d="M1 6.5L4.5 10L11 2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                + '<path d="M8 6.5L11.5 10L18 2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                + '</svg>';
        }

        function bubbleHtml(m) {
            var mine = !!m.mine;
            var read = m.id <= readUntil;
            var meta = '<span>' + esc(String(m.time || '')) + (mine ? ' — <?= e(t('chat.you')) ?>' : '') + '</span>'
                + (mine ? ticksSvg(read) : '');
            return '<div class="mb-2 d-flex ' + (mine ? 'justify-content-end' : 'justify-content-start') + '">'
                + '<div class="rounded-3 px-3 py-2 ' + (mine ? 'text-white page-bubble-me' : 'bg-white border shadow-sm') + '" '
                + 'style="max-width:78%;">'
                + esc(m.text).replace(/\n/g, '<br>')
                + '<div class="small mt-1 d-flex align-items-center justify-content-end gap-1 '
                + (mine ? 'text-white-50' : 'text-muted') + '">' + meta + '</div>'
                + '</div></div>';
        }

        function refreshTicks() {
            // marque les coches des messages lus (id <= readUntil) en vert
            list.querySelectorAll('.ticks:not(.read)').forEach(function (svg) {
                if (parseInt(svg.getAttribute('aria-label') === 'Envoyé' ? '0' : svg.getAttribute('data-id') || '0', 10) <= readUntil) {
                    svg.classList.add('read');
                }
            });
        }

        function sync() {
            fetch(API + '?action=sync&peer=' + peer + '&after=' + after, { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.ok) return;
                    // accusés de lecture : re-render des coches
                    if (typeof d.read_until === 'number' && d.read_until > readUntil) {
                        readUntil = d.read_until;
                        document.querySelectorAll('.ticks:not(.read)').forEach(function (svg) { svg.classList.add('read'); });
                    }
                    // nouveaux messages
                    if (d.messages && d.messages.length) {
                        var news = d.messages.filter(function (m) { return m.id > after; });
                        if (news.length) {
                            var wasBottom = isNearBottom();
                            var empty = list.querySelector('.text-center.text-muted');
                            if (empty) empty.remove();
                            news.forEach(function (m) { if (m.id > after) { list.insertAdjacentHTML('beforeend', bubbleHtml(m)); after = m.id; } });
                            scrollToBottom(wasBottom);
                        }
                    }
                    // badges non-lus de la liste des contacts (gauche)
                    if (d.contacts) {
                        d.contacts.forEach(function (c) {
                            var a = document.querySelector('a[href*="conversation=' + c.id + '"]');
                            if (!a) return;
                            var badge = a.querySelector('.badge');
                            if (c.unread > 0) {
                                if (badge) badge.textContent = c.unread;
                                else a.insertAdjacentHTML('beforeend', '<span class="badge rounded-pill text-bg-danger flex-shrink-0">' + c.unread + '</span>');
                            } else if (badge) {
                                badge.remove();
                            }
                        });
                    }
                })
                .catch(function () { });
        }

        scrollToBottom(true);
        setInterval(sync, 3000);
    })();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
