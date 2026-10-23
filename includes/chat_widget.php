<?php
/**
 * Widget de chat flottant (cercle en bas à droite, design PROS AUTO).
 * Deux vues : liste des contacts -> conversation (bouton retour repensé).
 * SYNCHRONISATION AUTOMATIQUE PERMANENTE : le badge de non-lus et l'état
 * en ligne se mettent à jour tout seuls (polling), sans aucune action.
 * Double coche verte ✓✓ quand le correspondant a lu vos messages.
 * API : actions/chat_api.php (JSON + CSRF).
 */
if (!defined('APP_INIT') || !function_exists('can_chat_role') || !can_chat_role()) {
    return;
}
$chatMe = current_user();
$chatCsrf = csrf_token();

$chatContactsJs = [];
$chatDb = isset($db) && ($db instanceof PDO) ? $db : null;
if ($chatDb === null) {
    try { $chatDb = db(); } catch (Throwable $chatE) { $chatDb = null; }
}
if ($chatDb instanceof PDO) {
    try {
        users_schema_ensure($chatDb);
        chat_tables_ensure($chatDb);
        foreach (chat_get_contacts($chatDb, (int) $chatMe['id'], (string) ($chatMe['role'] ?? '')) as $chatC) {
            $chatId = (int) $chatC['id'];
            $chatContactsJs[$chatId] = [
                'id' => $chatId,
                'username' => (string) $chatC['username'],
                'name' => ((string) ($chatC['nom_complet'] ?? '')) ?: (string) $chatC['username'],
                'role' => (string) $chatC['role'],
                'online' => (bool) $chatC['is_online'],
                'unread' => (int) $chatC['unread'],
            ];
        }
    } catch (Throwable $chatE) {
        error_log('chat_widget contacts: ' . $chatE->getMessage());
        $chatContactsJs = [];
    }
}

$chatTotalUnread = 0;
foreach ($chatContactsJs as $c) {
    $chatTotalUnread += (int) $c['unread'];
}

$chatContactsHtml = '';
if (!empty($chatContactsJs)) {
    $items = [];
    foreach ($chatContactsJs as $id => $c) {
        $online = (bool) ($c['online'] ?? false);
        $unread = (int) ($c['unread'] ?? 0);
        $role = ($c['role'] === 'admin') ? 'Admin' : 'Mécanicien';
        $initial = mb_strtoupper(mb_substr((string) $c['name'], 0, 1, 'UTF-8'), 'UTF-8');
        $items[] = '<button type="button" class="contact-item" data-peer-id="' . $id . '" data-name="' . e($c['name']) . '" data-online="' . ($online ? '1' : '0') . '">'
            . '<span class="contact-avatar">' . e($initial) . '<span class="dot' . ($online ? ' online' : '') . '"></span></span>'
            . '<span class="contact-info"><span class="name">' . e($c['name']) . '</span>'
            . '<span class="status">' . ($online ? 'En ligne' : 'Hors ligne') . '</span></span>'
            . '<span class="contact-role">' . e($role) . '</span>'
            . '<span class="contact-unread' . ($unread > 0 ? '' : ' hide') . '">' . ($unread > 0 ? (string) $unread : '') . '</span>'
            . '</button>';
    }
    $chatContactsHtml = implode('', $items);
} else {
    $chatContactsHtml = '<div class="contact-empty">Aucun contact autorisé</div>';
}
?>
<style><?php
// CSS inline : certains hébergeurs (InfinityFree) renvoient 403 sur le fichier .css
$chatCssFile = __DIR__ . '/../assets/css/chat_widget.css';
if (!is_file($chatCssFile)) { $chatCssFile = __DIR__ . '/chat_widget.css'; }
if (is_file($chatCssFile)) { echo str_replace('</', '<\/', (string) file_get_contents($chatCssFile)); }
?></style>
<div id="chatBtn" title="Ouvrir le chat" aria-label="Ouvrir le chat">
    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="currentColor" stroke="none" />
        <circle cx="12" cy="13" r="2.5" fill="none" stroke="currentColor" stroke-width="2" />
    </svg>
    <span
        class="chat-unread-badge<?= $chatTotalUnread > 0 ? ' show' : '' ?>"><?= $chatTotalUnread > 0 ? (int) $chatTotalUnread : '' ?></span>
</div>
<div id="chatPanel" class="chat-view-contacts">
    <div id="chatPanelHeader">
        <button type="button" id="chatBackBtn" class="chat-back-btn" title="Retour aux contacts" aria-label="Retour aux contacts">
            <i class="bi bi-arrow-left"></i><span>Contacts</span>
        </button>
        <div class="flex-grow-1 min-width-0">
            <div class="panel-title" id="panelTitle">Messagerie <strong>PROS AUTO</strong></div>
            <div class="panel-sub" id="panelSub">Sélectionnez un contact pour discuter</div>
        </div>
        <div class="panel-actions">
            <button type="button" id="chatCloseBtn" title="Fermer" aria-label="Fermer le chat">&times;</button>
        </div>
    </div>

    <div id="chatContactList"><?= $chatContactsHtml ?></div>

    <div id="chatConversationView">
        <div id="chatMsgList"></div>
        <div id="chatComposer">
            <textarea id="chatInput" rows="1" placeholder="Écrivez un message…" maxlength="5000" disabled></textarea>
            <button class="btn-send" id="chatSendBtn" disabled aria-label="Envoyer">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22 2L11 13M22 2L15 22L11 13M22 2L7 22L2 22L22 2z" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linejoin="round" />
                </svg>
            </button>
        </div>
    </div>

    <script>
        (function () {
            var Btn = document.getElementById('chatBtn');
            var Panel = document.getElementById('chatPanel');
            var ContactList = document.getElementById('chatContactList');
            var MsgList = document.getElementById('chatMsgList');
            var Input = document.getElementById('chatInput');
            var SendBtn = document.getElementById('chatSendBtn');
            var PanelTitle = document.getElementById('panelTitle');
            var PanelSub = document.getElementById('panelSub');
            var CloseBtn = document.getElementById('chatCloseBtn');
            var BackBtn = document.getElementById('chatBackBtn');
            var UnreadBadge = Btn.querySelector('.chat-unread-badge');

            var CSRF = <?= json_encode($chatCsrf) ?>;
            var API = <?= json_encode(app_url('actions/chat_api.php')) ?>;
            /* Transport de secours : la page courante (?chat_api=...) quand
               l'hébergeur intercepte les appels vers actions/ (InfinityFree). */
            var ApiBase = location.pathname + location.search;
            var ApiQ = ApiBase.indexOf('?') === -1 ? '?' : (ApiBase.charAt(ApiBase.length - 1) === '?' ? '' : '&');
            var API_FALLBACK = ApiBase + ApiQ + 'chat_api=';
            var apiBlocked = false;

            function apiGet(qs) {
                var url = API_FALLBACK + qs;
                return fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
                    .then(function (r) {
                        var ct = (r.headers.get('content-type') || '');
                        if (!r.ok || ct.indexOf('application/json') === -1) {
                            apiBlocked = true;
                            return fetch(API + '?action=' + qs, { headers: { 'X-Requested-With': 'fetch' } })
                                .then(function (r2) { return r2.ok ? r2.json() : null; });
                        }
                        return r.json();
                    });
            }

            function apiSend(body) {
                var go = function (url) {
                    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: body })
                        .then(function (r) {
                            var ct = (r.headers.get('content-type') || '');
                            if (!r.ok || ct.indexOf('application/json') === -1) {
                                apiBlocked = true;
                                return fetch(API + '?action=send', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' }, body: body })
                                    .then(function (r2) { return r2.ok ? r2.json() : { ok: false, error: 'Erreur serveur.' }; });
                            }
                            return r.json();
                        });
                };
                return go(API_FALLBACK + 'send');
            }
            var DEFAULT_TITLE = 'Messagerie <strong>PROS AUTO</strong>';
            var DEFAULT_SUB = 'Sélectionnez un contact pour discuter';
            var peer = null;
            var peerName = '';
            var msgs = [];
            var after = 0;
            var readUntil = 0;        // dernier message envoyé lu par le correspondant
            var sending = false;
            var syncTimer = null;
            var stickBottom = true;

            function esc(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            /* ===== Coches de lecture : ✓ envoyé / ✓✓ vert lu ===== */
            function ticksHtml(m) {
                if (!m.mine) return '';
                var read = m.id <= readUntil;
                return '<svg class="ticks' + (read ? ' read' : '') + '" viewBox="0 0 20 12">'
                    + '<path d="M1 6.5L4.5 10L11 2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                    + '<path d="M8 6.5L11.5 10L18 2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
                    + '</svg>';
            }

            function isNearBottom() {
                return MsgList.scrollTop + MsgList.clientHeight >= MsgList.scrollHeight - 60;
            }

            function renderMsgs(forceBottom) {
                if (!msgs.length) {
                    MsgList.innerHTML = '<div style="text-align:center;padding:28px 18px;color:#909090;font-size:.78rem;">'
                        + '<i class="bi bi-chat-square-dots" style="font-size:1.6rem;display:block;margin-bottom:8px;opacity:.5;"></i>'
                        + 'Aucun message. Commencez la conversation.</div>';
                    return;
                }
                var stay = forceBottom === true || stickBottom;
                MsgList.innerHTML = msgs.map(function (m) {
                    return '<div class="msg-bubble ' + (m.mine ? 'mine' : 'other') + '">' + esc(m.text)
                        + '<div class="msg-meta">'
                        + '<span class="msg-time">' + esc(String(m.time || '')) + '</span>'
                        + ticksHtml(m)
                        + '</div></div>';
                }).join('');
                if (stay) MsgList.scrollTop = MsgList.scrollHeight;
            }

            MsgList.addEventListener('scroll', function () { stickBottom = isNearBottom(); });

            /* ===== Mise à jour du badge + liste des contacts depuis la synchro ===== */
            function applyContacts(contacts) {
                if (!contacts) return;
                var total = 0;
                contacts.forEach(function (c) { total += (int0(c.unread)); });
                if (total > 0) {
                    UnreadBadge.textContent = total > 99 ? '99+' : String(total);
                    UnreadBadge.classList.add('show');
                } else {
                    UnreadBadge.textContent = '';
                    UnreadBadge.classList.remove('show');
                }
                // badge non-lus dans la liste de contacts (crée les contacts manquants)
                contacts.forEach(function (c) {
                    var item = ContactList.querySelector('.contact-item[data-peer-id="' + c.id + '"]');
                    if (!item) {
                        var empty = ContactList.querySelector('.contact-empty');
                        if (empty) empty.remove();
                        var nm = String(c.name || '');
                        item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'contact-item';
                        item.setAttribute('data-peer-id', c.id);
                        item.setAttribute('data-name', nm);
                        item.innerHTML = '<span class="contact-avatar">' + esc(nm.charAt(0).toUpperCase()) + '<span class="dot"></span></span>'
                            + '<span class="contact-info"><span class="name">' + esc(nm) + '</span><span class="status"></span></span>'
                            + '<span class="contact-role">' + (c.role === 'admin' ? 'Admin' : 'Mécanicien') + '</span>'
                            + '<span class="contact-unread hide"></span>';
                        ContactList.appendChild(item);
                    }
                    var badge = item.querySelector('.contact-unread');
                    var dot = item.querySelector('.dot');
                    if (badge) {
                        if (c.unread > 0) { badge.textContent = c.unread; badge.classList.remove('hide'); }
                        else { badge.textContent = ''; badge.classList.add('hide'); }
                    }
                    if (dot) dot.classList.toggle('online', !!c.online);
                    var st = item.querySelector('.status');
                    if (st) st.textContent = c.online ? 'En ligne' : 'Hors ligne';
                    item.setAttribute('data-online', c.online ? '1' : '0');
                });
            }

            function int0(v) { var n = parseInt(v, 10); return isNaN(n) ? 0 : n; }

            function openConversation(id, name, online) {
                peer = id; peerName = name;
                Panel.classList.remove('chat-view-contacts');
                Panel.classList.add('chat-in-conversation');
                PanelTitle.innerHTML = esc(name);
                PanelSub.textContent = online ? 'En ligne — réponses instantanées' : 'Hors ligne — il verra votre message à sa connexion';
                Input.disabled = false; SendBtn.disabled = true;
                after = 0; msgs = []; readUntil = 0; stickBottom = true;
                renderMsgs(true);
                syncNow(true);
                setTimeout(function () { Input.focus(); }, 60);
            }

            function backToContacts() {
                peer = null; peerName = '';
                Panel.classList.add('chat-view-contacts');
                Panel.classList.remove('chat-in-conversation');
                PanelTitle.innerHTML = DEFAULT_TITLE;
                PanelSub.textContent = DEFAULT_SUB;
                Input.disabled = true; SendBtn.disabled = true;
                Input.value = ''; fixHeight();
            }

            /* ===== SYNCHRONISATION AUTOMATIQUE =====
               Un seul appel réunit : contacts + non-lus + nouveaux messages
               + accusé de lecture. Tourné en continu, même panneau fermé. */
            function syncNow(force) {
                apiGet('sync&peer=' + (peer || 0) + '&after=' + after)
                    .then(function (d) {
                        if (!d || !d.ok) return;
                        applyContacts(d.contacts);
                        if (typeof d.read_until === 'number') {
                            var changed = d.read_until > readUntil;
                            readUntil = Math.max(readUntil, d.read_until);
                            if (changed && msgs.length) renderMsgs();
                        }
                        if (d.messages && d.messages.length) {
                            var newOnes = d.messages.filter(function (m) { return m.id > after; });
                            if (newOnes.length) {
                                var wasBottom = isNearBottom();
                                d.messages.forEach(function (m) { if (m.id > after) { msgs.push(m); after = m.id; } });
                                if (peer && (wasBottom || newOnes.some(function (m) { return m.mine; }))) {
                                    stickBottom = true;
                                }
                                renderMsgs();
                            }
                        }
                    })
                    .catch(function () { });
            }

            function startSync() { if (!syncTimer) { syncNow(true); syncTimer = setInterval(syncNow, 3000); } }
            function stopSync() { if (syncTimer) { clearInterval(syncTimer); syncTimer = null; } }

            function showNewIndicator() {
                var old = document.getElementById('chatNewMsgs');
                if (old) return;
                var pill = document.createElement('button');
                pill.type = 'button';
                pill.id = 'chatNewMsgs';
                pill.className = 'chat-new-pill';
                pill.textContent = '↓ Nouveaux messages';
                MsgList.parentElement.style.position = 'relative';
                MsgList.parentElement.appendChild(pill);
                pill.addEventListener('click', function () {
                    stickBottom = true;
                    MsgList.scrollTop = MsgList.scrollHeight;
                    pill.remove();
                });
            }

            function sendMsg(text) {
                if (!peer || sending || !text.trim()) return;
                sending = true; SendBtn.disabled = true;
                var body = 'action=send&peer_id=' + peer + '&message=' + encodeURIComponent(text.trim()) + '&csrf_token=' + encodeURIComponent(CSRF);
                apiSend(body)
                    .then(function (d) {
                        if (d && d.ok) {
                            after = d.id;
                            msgs.push({ id: d.id, mine: true, text: text.trim(), time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) });
                            Input.value = ''; fixHeight();
                            stickBottom = true;
                            renderMsgs(true);
                            syncNow(true);
                        } else {
                            alert((d && d.error) || 'Erreur d\'envoi.');
                        }
                        sending = false; inputEmpty();
                    })
                    .catch(function () { sending = false; inputEmpty(); });
            }

            function inputEmpty() {
                SendBtn.disabled = sending || !Input.value.trim() || !peer;
            }

            function fixHeight() { Input.style.height = 'auto'; Input.style.height = Math.min(Input.scrollHeight, 110) + 'px'; }

            ContactList.addEventListener('click', function (e) {
                var item = e.target.closest('.contact-item');
                if (!item) return;
                openConversation(parseInt(item.getAttribute('data-peer-id'), 10), item.getAttribute('data-name'), item.getAttribute('data-online') === '1');
            });

            Input.addEventListener('input', function () { fixHeight(); inputEmpty(); });
            Input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(Input.value); } });
            SendBtn.addEventListener('click', function () { sendMsg(Input.value); });

            Btn.addEventListener('click', function (e) {
                e.stopPropagation();
                Panel.classList.toggle('open');
                if (Panel.classList.contains('open')) {
                    if (!Panel.classList.contains('chat-in-conversation')) Panel.classList.add('chat-view-contacts');
                    startSync();
                } else { stopSync(); }
            });
            CloseBtn.addEventListener('click', function () { Panel.classList.remove('open'); stopSync(); });
            BackBtn.addEventListener('click', backToContacts);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && Panel.classList.contains('open')) {
                    if (Panel.classList.contains('chat-in-conversation')) { backToContacts(); }
                    else { Panel.classList.remove('open'); stopSync(); }
                }
            });
            document.addEventListener('click', function (e) {
                if (Panel.classList.contains('open') && !Panel.contains(e.target) && !Btn.contains(e.target)) {
                    Panel.classList.remove('open'); stopSync();
                }
            });

            // SYNCHRO PERMANENTE : le badge se met à jour même panneau fermé
            startSync();
        })();
    </script>
</div>
