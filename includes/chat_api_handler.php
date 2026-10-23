<?php
/**
 * Logique de l'API du chat, factorisée pour deux transports :
 *  1. actions/chat_api.php (endpoint AJAX classique) ;
 *  2. LA PAGE COURANTE via ?chat_api=... (transport de secours).
 * Sécurité : rôles + CSRF, anti-spam, validation de l'existence du correspondant.
 * Les paires autorisées sont définies dans includes/chat.php (chat_pair_allowed) :
 *   - admin <-> tout mécanicien
 *   - mechanic <-> admin
 * Toute autre paire est refusée ici.
 */
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

function chat_api_handle(PDO $db, string $action): void
{
    // Guard JSON : jette tout output parasite et coupe l'affichage des erreurs
    while (ob_get_level() > 0) { @ob_end_clean(); }
    @ini_set('display_errors', '0');
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    if (!can_chat_role()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Accès refusé.']);
        return;
    }

    $me = current_user();
    $meId = (int) $me['id'];
    $meRole = (string) ($me['role'] ?? '');
    chat_tables_ensure($db);
    users_schema_ensure($db);
    chat_touch_presence($db, $meId);
    chat_maybe_purge($db);

    switch ($action) {
        case 'status':
            $contacts = [];
            foreach (chat_get_contacts($db, $meId, $meRole) as $c) {
                $contacts[] = [
                    'id'       => (int) $c['id'],
                    'username' => $c['username'],
                    'name'     => ($c['nom_complet'] ?: $c['username']),
                    'role'     => $c['role'],
                    'online'   => (bool) $c['is_online'],
                    'unread'   => (int) $c['unread'],
                ];
            }
            echo json_encode(['ok' => true, 'me' => $meId, 'contacts' => $contacts]);
            break;

        case 'messages':
            $peerId = (int) ($_GET['peer'] ?? 0);
            $afterId = (int) ($_GET['after'] ?? 0);
            if ($peerId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Correspondant invalide.']);
                break;
            }
            if (!chat_peer_allowed($db, $peerId, $meId, $meRole)) {
                log_activity('CHAT_ACCESS_DENIED', 'chat', null, "user {$meId} -> peer {$peerId}");
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Conversation non autorisée.']);
                break;
            }
            chat_mark_read($db, $meId, $peerId);
            $receipt = chat_read_receipt($db, $meId, $peerId);
            echo json_encode([
                'ok'         => true,
                'messages'   => chat_fetch_messages($db, $meId, $peerId, $afterId),
                'read_until' => $receipt['read_until'],
            ]);
            break;

        /* Synchro globale automatique (polling silencieux) :
           contacts + non-lus + derniers messages de la conversation ouverte. */
        case 'sync':
            $peerId = (int) ($_GET['peer'] ?? 0);
            $afterId = (int) ($_GET['after'] ?? 0);
            $contacts = [];
            foreach (chat_get_contacts($db, $meId, $meRole) as $c) {
                $contacts[] = [
                    'id'       => (int) $c['id'],
                    'name'     => ((string) ($c['nom_complet'] ?? '')) ?: (string) $c['username'],
                    'role'     => $c['role'],
                    'online'   => (bool) $c['is_online'],
                    'unread'   => (int) $c['unread'],
                ];
            }
            $payload = ['ok' => true, 'contacts' => $contacts];
            if ($peerId > 0 && chat_peer_allowed($db, $peerId, $meId, $meRole)) {
                chat_touch_presence($db, $meId);
                if ($afterId === 0) {
                    chat_mark_read($db, $meId, $peerId);
                }
                $receipt = chat_read_receipt($db, $meId, $peerId);
                $payload['messages']   = chat_fetch_messages($db, $meId, $peerId, $afterId);
                $payload['read_until'] = $receipt['read_until'];
            }
            echo json_encode($payload);
            break;

        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Jeton de sécurité invalide.']);
                break;
            }
            $sentToken = (string) ($_POST['csrf_token'] ?? '');
            if ($sentToken === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $sentToken)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Jeton de sécurité invalide.']);
                break;
            }
            $peerId = (int) ($_POST['peer_id'] ?? 0);
            $text = trim((string) ($_POST['message'] ?? ''));
            if ($peerId <= 0 || $text === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Message vide ou correspondant invalide.']);
                break;
            }
            if (!chat_peer_allowed($db, $peerId, $meId, $meRole)) {
                log_activity('CHAT_ACCESS_DENIED', 'chat', null, "send {$meId} -> {$peerId}");
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Conversation non autorisée.']);
                break;
            }
            chat_messages_schema_ensure($db);

            $id = chat_send_message($db, $meId, $peerId, $text);
            if ($id === -1) {
                http_response_code(429);
                echo json_encode(['ok' => false, 'error' => 'Trop rapide, patientez un instant.']);
                break;
            }
            if ($id === 0) {
                echo json_encode(['ok' => false, 'error' => 'Impossible d\'enregistrer le message. Veuillez réessayer.']);
                break;
            }
            echo json_encode(['ok' => true, 'id' => $id]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Action inconnue.']);
    }
}