<?php
/**
 * Chat interne administrateur <-> mécanicien pour l'application garage1.
 * Deux conversations autorisées (règle appliquée par le widget ET par l'API) :
 *   1. admin <-> tout mécanicien (l'administrateur reste le pivot du chat) ;
 *   2. mécanicien <-> admin (réponse).
 * Toute autre paire est refusée côté serveur, même si la requête est forgée.
 *
 * Base de données (MySQL) :
 *   - chat_messages : messages persistants ET chiffrés (AES-256-GCM).
 *   - chat_presence : présence « en ligne » (dernier ping < 60 s = en ligne).
 * Les tables sont créées automatiquement si elles n'existent pas.
 *
 * Sécurité :
 *   - Requêtes 100% préparées.
 *   - Texte stocké CHIFFR�? (AES-256-GCM) avec une clé dérivée de APP_SECRET_KEY.
 *   - Anti-spam (1 message / 500 ms), taille max 5000 caractères.
 *   - Contrôle strict des rôles côté serveur.
 *
 * Performance :
 *   - Index composites (sender + receiver + date).
 *   - Lecture groupée : une seule requête pour les contacts (pas de N+1).
 */
if (!defined('APP_INIT')) {
    http_response_code(403);
    exit('Accès direct interdit.');
}

// La clé APP_SECRET_KEY est chargée par config/config.php (déjà inclus avant ce fichier).
const CHAT_ONLINE_WINDOW = 60;      // secondes considérées « en ligne »
const CHAT_MAX_LENGTH    = 5000;    // caractères max par message
const CHAT_MIN_INTERVAL  = 0.5;     // délai minimum entre deux messages (s)
const CHAT_KEEP_DAYS     = 730;     // purge automatique au-delà de 2 ans

/** Crée les tables du chat si nécessaires. Retourne true si OK. */
function chat_tables_ensure(PDO $db): bool
{
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id INT UNSIGNED NOT NULL,
            receiver_id INT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_conv (sender_id, receiver_id, id),
            INDEX idx_receiver_unread (receiver_id, is_read),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS chat_presence (
            user_id INT UNSIGNED PRIMARY KEY,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Index ajoutés après coup sur les tables déjà existantes
        try { $db->exec("ALTER TABLE chat_messages ADD INDEX idx_created (created_at)"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE chat_presence ADD INDEX idx_seen (last_seen_at)"); } catch (Throwable $e) {}

        return true;
    } catch (Throwable $e) {
        error_log('chat_tables_ensure: ' . $e->getMessage());
        return false;
    }
}

/** Vérifie et répare le schéma de la table chat_messages. */
function chat_messages_schema_ensure(PDO $db): bool
{
    static $done = false;
    if ($done) { return true; }
    $done = true;

    try {
        $tables = $db->query("SHOW TABLES LIKE 'chat_messages'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($tables)) { return chat_tables_ensure($db); }

        $cols = $db->query('SHOW COLUMNS FROM chat_messages')->fetchAll(PDO::FETCH_ASSOC);
        $colInfo = [];
        foreach ($cols as $c) { $colInfo[$c['Field']] = $c; }

        // Ancien sch�ma (sender_user_id / receiver_user_id / conversation_id)
        // -> migration inline vers le sch�ma actuel (sender_id / receiver_id).
        if (isset($colInfo['sender_user_id']) && !isset($colInfo['sender_id'])) {
            // Les index/FK existants d�pendent des anciennes colonnes : on les retire d'abord.
            $fks = $db->query(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages'
                   AND REFERENCED_TABLE_NAME IS NOT NULL"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($fks as $fk) {
                try { $db->exec('ALTER TABLE chat_messages DROP FOREIGN KEY ' . $fk); } catch (Throwable $fkE) {}
            }
            try { $db->exec('ALTER TABLE chat_messages DROP COLUMN conversation_id'); } catch (Throwable $cE) {}
            $db->exec('ALTER TABLE chat_messages
                CHANGE COLUMN sender_user_id sender_id INT UNSIGNED NOT NULL,
                CHANGE COLUMN receiver_user_id receiver_id INT UNSIGNED NOT NULL');
            return chat_messages_schema_ensure($db) && true;
        }
        if (!isset($colInfo['sender_id']) || !isset($colInfo['receiver_id'])) {
            // Sch�ma incomplet et non migrable : on recr�e la table proprement.
            $db->exec('DROP TABLE IF EXISTS chat_messages');
            return chat_tables_ensure($db);
        }

        $idExtra = (string) ($colInfo['id']['Extra'] ?? '');
        $idKey   = (string) ($colInfo['id']['Key'] ?? '');

        if ($idKey !== 'PRI' || stripos($idExtra, 'auto_increment') === false) {
            // Détecte les IDs en double (MySQL 8+ refuse l'ALTER TABLE)
            $dupCount = (int) $db->query("SELECT COUNT(*) FROM (SELECT id FROM chat_messages WHERE id IS NOT NULL AND id > 0 GROUP BY id HAVING COUNT(*) > 1) dup")->fetchColumn();
            if ($dupCount > 0) {
                $maxId = (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM chat_messages')->fetchColumn();
                $next  = $maxId + 1;
                $dupIds = $db->query("SELECT id FROM chat_messages WHERE id IS NOT NULL AND id > 0 GROUP BY id HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($dupIds as $dupId) {
                    // Utiliser une requ�te pr�par�e au lieu d'une concat�nation brute.
                    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM chat_messages WHERE id = :id");
                    $stmt->bindValue(':id', (int) $dupId, PDO::PARAM_INT);
                    $stmt->execute();
                    $count = (int) ($stmt->fetch()['cnt'] ?? 0);
                    for ($i = 1; $i < $count; $i++) {
                        $upd = $db->prepare("UPDATE chat_messages SET id = :next WHERE id = :dup LIMIT 1");
                        $upd->bindValue(':next', $next, PDO::PARAM_INT);
                        $upd->bindValue(':dup', (int) $dupId, PDO::PARAM_INT);
                        $upd->execute();
                        $next++;
                    }
                }
            }
            $db->exec("DELETE FROM chat_messages WHERE id IS NULL OR id <= 0");
            if ($idKey === 'PRI') { $db->exec('ALTER TABLE chat_messages DROP PRIMARY KEY'); }
            $db->exec("ALTER TABLE chat_messages MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)");
        }

        $msgType = strtolower((string) ($colInfo['message']['Type'] ?? ''));
        if ($msgType !== 'text' && !str_starts_with($msgType, 'text')) {
            $db->exec("ALTER TABLE chat_messages MODIFY COLUMN message TEXT NOT NULL");
        }

        // Répare chat_presence : sans PRIMARY KEY sur user_id, chaque ping insère une nouvelle ligne
        foreach ($db->query('SHOW COLUMNS FROM chat_presence')->fetchAll(PDO::FETCH_ASSOC) as $pCol) {
            if (($pCol['Field'] ?? '') === 'user_id') {
                if (($pCol['Key'] ?? '') !== 'PRI') {
                    try { $db->exec('ALTER TABLE chat_presence DROP PRIMARY KEY'); } catch (Throwable $pE) {}
                    $db->exec('ALTER TABLE chat_presence MODIFY COLUMN user_id INT UNSIGNED NOT NULL, ADD PRIMARY KEY (user_id)');
                }
                break;
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('chat_messages_schema_ensure: ' . $e->getMessage());
        return false;
    }
}

/** Vérifie et répare automatiquement le schéma de la table users. */
function users_schema_ensure(PDO $db): bool
{
    static $done = false;
    if ($done) { return true; }
    $done = true;
    try {
        $cols  = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($cols, 'Field');

        if (!in_array('nom_complet', $names, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN nom_complet VARCHAR(150) NOT NULL DEFAULT '' AFTER username");
        }
        if (!in_array('is_active', $names, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER username");
        }

        $roleType = '';
        foreach ($cols as $c) {
            if (($c['Field'] ?? '') === 'role') { $roleType = (string) ($c['Type'] ?? ''); break; }
        }
        // Les r�les sont d�sormais dynamiques (table user_roles) : on ne force
        // plus users.role en ENUM, sinon tout r�le personnalis� serait �cras�.
        if ($roleType !== '' && stripos($roleType, 'enum(') === 0) {
            $db->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'mechanic'");
        }
        return true;
    } catch (Throwable $e) {
        error_log('users_schema_ensure: ' . $e->getMessage());
        return false;
    }
}

/** Dérive la clé de chiffrement depuis APP_SECRET_KEY. */
function chat_crypto_key(): ?string
{
    $secret = defined('APP_SECRET_KEY') ? (string) APP_SECRET_KEY : (function_exists('app_secret_key') ? app_secret_key() : '');
    if (strlen($secret) < 16) { return null; }
    return hash('sha256', 'chat-v1|' . $secret, true);
}

/**
 * Chiffrement symétrique générique (AES-256-GCM) pour les secrets stockés
 * en base (ex. mot de passe SMTP). Retourne le texte en clair si aucune clé
 * n'est configurée, afin de ne jamais casser l'application.
 */
function app_encrypt_secret(string $plain): string
{
    if ($plain === '') { return ''; }
    $key = chat_crypto_key();
    if ($key === null || !function_exists('openssl_encrypt')) { return $plain; }
    try {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { return $plain; }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    } catch (Throwable $e) {
        error_log('app_encrypt_secret: ' . $e->getMessage());
        return $plain;
    }
}

/** Déchiffre une valeur produite par app_encrypt_secret() (tolérant : clair accepté). */
function app_decrypt_secret(string $stored): string
{
    if (!str_starts_with($stored, 'enc:v1:')) { return $stored; }
    $key = chat_crypto_key();
    if ($key === null || !function_exists('openssl_decrypt')) { return ''; }
    try {
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false || strlen($raw) < 29) { return ''; }
        $plain = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16)
        );
        return $plain === false ? '' : $plain;
    } catch (Throwable $e) {
        error_log('app_decrypt_secret: ' . $e->getMessage());
        return '';
    }
}

/** Chiffre un message. */
function chat_encrypt(string $plain): string
{
    $key = chat_crypto_key();
    if ($key === null || !function_exists('openssl_encrypt')) { return $plain; }
    try {
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { return $plain; }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    } catch (Throwable $e) {
        error_log('chat_encrypt: ' . $e->getMessage());
        return $plain;
    }
}

/** Déchiffre un message. */
function chat_decrypt(string $stored): string
{
    if (!str_starts_with($stored, 'v1:')) { return $stored; }
    $key = chat_crypto_key();
    if ($key === null || !function_exists('openssl_decrypt')) { return '[message chiffré indisponible]'; }
    try {
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) { return '[message illisible]'; }
        $plain = openssl_decrypt(
            substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16)
        );
        return $plain === false ? '[message illisible]' : $plain;
    } catch (Throwable $e) {
        error_log('chat_decrypt: ' . $e->getMessage());
        return '[message illisible]';
    }
}

/** Anti-spam : vrai si l'utilisateur peut envoyer maintenant. */
function chat_can_send(int $userId): bool
{
    $key  = 'last_chat_send_' . $userId;
    $now  = microtime(true);
    $last = (float) ($_SESSION[$key] ?? 0);
    if ($last > 0 && ($now - $last) < CHAT_MIN_INTERVAL) { return false; }
    $_SESSION[$key] = $now;
    return true;
}

/** Rejoue une action après création des tables (fail-safe). */
function chat_retry(PDO $db, callable $action)
{
    if (!chat_tables_ensure($db)) { return null; }
    chat_messages_schema_ensure($db);
    try { return $action(); } catch (Throwable $e) { error_log('chat_retry: ' . $e->getMessage()); return null; }
}

/** Met à jour le ping de présence de l'utilisateur courant. */
function chat_touch_presence(PDO $db, int $userId): void
{
    try {
        $stmt = $db->prepare("INSERT INTO chat_presence (user_id, last_seen_at) VALUES (:u, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
        $stmt->execute(['u' => $userId]);
    } catch (Throwable $e) {
        if (chat_tables_ensure($db)) {
            try {
                $stmt = $db->prepare("INSERT INTO chat_presence (user_id, last_seen_at) VALUES (:u, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()");
                $stmt->execute(['u' => $userId]);
            } catch (Throwable $e2) { error_log('chat_touch_presence: ' . $e2->getMessage()); }
        }
    }
}

/** Rôles autorisés à utiliser le chat. */
function chat_allowed_roles(): array
{
    return ['admin', 'mechanic'];
}

/** Vrai si le rôle courant a le droit d'utiliser le chat. */
function can_chat_role(): bool
{
    $u = current_user();
    return $u !== null && in_array((string) ($u['role'] ?? ''), chat_allowed_roles(), true);
}

/** R�^GLE UNIQUE DU CHAT (admin <-> mechanic). */
function chat_pair_allowed(?string $meRole, ?string $peerRole): bool
{
    $roles = chat_allowed_roles();
    if (!in_array((string) $meRole, $roles, true) || !in_array((string) $peerRole, $roles, true)) { return false; }
    // Admin <-> Mechanic uniquement. Pas d'admin <-> admin, pas de mecanicien <-> mecanicien.
    return ($meRole === 'admin' && $peerRole === 'mechanic') || ($meRole === 'mechanic' && $peerRole === 'admin');
}
function chat_contact_roles(?string $meRole): array
{
    $out = [];
    foreach (chat_allowed_roles() as $peerRole) {
        if (chat_pair_allowed($meRole, $peerRole)) { $out[] = $peerRole; }
    }
    return $out;
}

/** Rôle d'un compte actif (null si inconnu / inactif). */
function chat_user_role(PDO $db, int $userId): ?string
{
    if ($userId <= 0) { return null; }
    static $cache = [];
    if (array_key_exists($userId, $cache)) { return $cache[$userId]; }
    try {
        $stmt = $db->prepare("SELECT role FROM users WHERE id = :id AND is_active = 1");
        $stmt->execute(['id' => $userId]);
        $role = $stmt->fetchColumn();
        $cache[$userId] = $role === false ? null : (string) $role;
    } catch (Throwable $e) { error_log('chat_user_role: ' . $e->getMessage()); $cache[$userId] = null; }
    return $cache[$userId];
}

/** Vérifie si la paire $senderId -> $peerId est autorisée (règle de chat_pair_allowed). */
function chat_peer_allowed(PDO $db, int $peerId, ?int $senderId = null, ?string $senderRole = null): bool
{
    if ($peerId <= 0) { return false; }
    if ($senderId !== null && $peerId === $senderId) { return false; }
    static $cache = [];
    $cacheKey = ($senderId ?? 0) . ':' . $peerId;
    if (array_key_exists($cacheKey, $cache)) { return $cache[$cacheKey]; }

    $load = function () use ($db, $peerId, $senderId, $senderRole) {
        $stmt = $db->prepare("SELECT role FROM users WHERE id = :id AND is_active = 1");
        $stmt->execute(['id' => $peerId]);
        $peerRole = $stmt->fetchColumn();
        if ($peerRole === false) { return false; }
        $myRole = $senderRole;
        if ($myRole === null && $senderId !== null) { $myRole = chat_user_role($db, $senderId); }
        return chat_pair_allowed($myRole, (string) $peerRole);
    };

    try { $cache[$cacheKey] = (bool) $load(); }
    catch (Throwable $e) { $cache[$cacheKey] = (bool) chat_retry($db, $load); }
    return $cache[$cacheKey];
}

/**
 * Liste des contacts autorisés pour $meId.
 * - admin      : tous les comptes actifs de rôle 'mechanic'
 * - mechanic   : les administrateurs uniquement
 */
function chat_get_contacts(PDO $db, int $meId, ?string $meRole = null): array
{
    $meRole = $meRole ?? (chat_user_role($db, $meId) ?? '');
    $contactRoles = chat_contact_roles($meRole);
    $roleSql = $contactRoles
        ? "u.role IN ('" . implode("','", $contactRoles) . "')"
        : '1 = 0';

    $load = function () use ($db, $meId, $roleSql) {
        $stmt = $db->prepare(
            "SELECT u.id, u.username, u.nom_complet, u.role,
                    (p.last_seen_at IS NOT NULL AND p.last_seen_at >= (NOW() - INTERVAL " . CHAT_ONLINE_WINDOW . " SECOND)) AS is_online,
                    p.last_seen_at,
                    (SELECT COUNT(*) FROM chat_messages m
                      WHERE m.sender_id = u.id AND m.receiver_id = :me AND m.is_read = 0) AS unread
             FROM users u
             LEFT JOIN chat_presence p ON p.user_id = u.id
             WHERE u.is_active = 1 AND {$roleSql} AND u.id <> :me2
             ORDER BY is_online DESC, unread DESC, u.nom_complet"
        );
        $stmt->execute(['me' => $meId, 'me2' => $meId]);
        return $stmt->fetchAll();
    };

    try { return $load(); }
    catch (Throwable $e) { return chat_retry($db, $load) ?? []; }
}

/**
 * Messages de la conversation entre $meId et $peerId.
 * $afterId = 0 : les 200 derniers (ordre chronologique).
 * $afterId > 0 : uniquement les nouveaux messages (polling rapide).
 */
function chat_fetch_messages(PDO $db, int $meId, int $peerId, int $afterId = 0): array
{
    $load = function () use ($db, $meId, $peerId, $afterId) {
        if ($afterId > 0) {
            $stmt = $db->prepare(
                "SELECT id, sender_id, message, is_read, created_at
                 FROM chat_messages
                 WHERE id > :after
                   AND ((sender_id = :me AND receiver_id = :peer)
                     OR (sender_id = :peer2 AND receiver_id = :me2))
                 ORDER BY id ASC
                 LIMIT 200"
            );
            $stmt->execute(['after' => $afterId, 'me' => $meId, 'peer' => $peerId, 'peer2' => $peerId, 'me2' => $meId]);
        } else {
            $stmt = $db->prepare(
                "SELECT id, sender_id, message, is_read, created_at
                 FROM chat_messages
                 WHERE (sender_id = :me AND receiver_id = :peer)
                    OR (sender_id = :peer2 AND receiver_id = :me2)
                 ORDER BY id DESC
                 LIMIT 200"
            );
            $stmt->execute(['me' => $meId, 'peer' => $peerId, 'peer2' => $peerId, 'me2' => $meId]);
        }
        return $stmt->fetchAll();
    };

    try { $rows = $load(); }
    catch (Throwable $e) { $rows = chat_retry($db, $load) ?? []; }

    if ($afterId === 0) { $rows = array_reverse($rows); }

    $messages = [];
    foreach ($rows as $row) {
        $messages[] = [
            'id'     => (int) $row['id'],
            'mine'   => (int) $row['sender_id'] === $meId,
            'unread' => !(int) $row['is_read'],
            'text'   => chat_decrypt((string) $row['message']),
            'time'   => $row['created_at'] ? date('d/m/Y H:i', strtotime((string) $row['created_at'])) : '',
        ];
    }
    return $messages;
}

/**
 * Insère un message CHIFFR�?. Retourne l'ID du message,
 * 0 en cas d'échec, -1 si l'anti-spam refuse l'envoi.
 */
function chat_send_message(PDO $db, int $senderId, int $receiverId, string $message): int
{
    $message = trim($message);
    if ($message === '' || $senderId === $receiverId) { return 0; }
    if (!chat_can_send($senderId)) { return -1; }

    $stored = chat_encrypt(mb_substr($message, 0, CHAT_MAX_LENGTH));
    $insert = function () use ($db, $senderId, $receiverId, $stored) {
        $stmt = $db->prepare(
            'INSERT INTO chat_messages (sender_id, receiver_id, message, is_read)
             VALUES (:s, :r, :m, 0)'
        );
        $stmt->execute(['s' => $senderId, 'r' => $receiverId, 'm' => $stored]);
        return (int) $db->lastInsertId();
    };

    try { return $insert(); }
    catch (Throwable $e) { error_log('chat_send_message: ' . $e->getMessage()); return (int) (chat_retry($db, $insert) ?? 0); }
}
/** �?tat de lecture des messages que J'AI envoyés à $peerId (double coche). */
function chat_read_receipt(PDO $db, int $meId, int $peerId): array
{
    $load = function () use ($db, $meId, $peerId) {
        $stmt = $db->prepare(
            'SELECT COALESCE(MAX(CASE WHEN is_read = 1 THEN id END), 0) AS read_until,
                    COALESCE(MAX(id), 0) AS last_id
             FROM chat_messages
             WHERE sender_id = :me AND receiver_id = :peer'
        );
        $stmt->execute(['me' => $meId, 'peer' => $peerId]);
        return $stmt->fetch() ?: ['read_until' => 0, 'last_id' => 0];
    };

    try { $row = $load(); }
    catch (Throwable $e) { $row = chat_retry($db, $load) ?? ['read_until' => 0, 'last_id' => 0]; }

    return [
        'read_until' => (int) ($row['read_until'] ?? 0),
        'last_id'    => (int) ($row['last_id'] ?? 0),
    ];
}

/** Marque comme lus les messages reçus d'un correspondant donné. */
function chat_mark_read(PDO $db, int $meId, int $peerId): void
{
    try {
        $stmt = $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE sender_id = :peer AND receiver_id = :me AND is_read = 0");
        $stmt->execute(['peer' => $peerId, 'me' => $meId]);
    } catch (Throwable $e) { error_log('chat_mark_read: ' . $e->getMessage()); }
}

/** Purge des messages trop anciens (exécutée au hasard). */
function chat_maybe_purge(PDO $db): void
{
    try {

        if (random_int(1, 200) !== 1) { return; }
        $stmt = $db->prepare('DELETE FROM chat_messages WHERE created_at < (NOW() - INTERVAL :d DAY)');
        $stmt->bindValue(':d', CHAT_KEEP_DAYS, PDO::PARAM_INT);
        $stmt->execute();
    } catch (Throwable $e) { error_log('chat_maybe_purge: ' . $e->getMessage()); }
}

