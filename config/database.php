<?php
/**
 * Connexion à la base de données (PDO).
 * Ajustez ces constantes si vos identifiants XAMPP diffèrent.
 */

declare(strict_types=1);

define('DB_HOST', (string) env('DB_HOST', 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com'));
define('DB_PORT', (string) env('DB_PORT', '4000'));
define('DB_NAME', (string) env('DB_NAME', 'garage_db1'));
define('DB_USER', (string) env('DB_USER', '3AuwYvEEBBW1SG1.root'));
define('DB_PASS', (string) env('DB_PASS', '8ibLYGtHE8ZZcYp1'));
define('DB_CHARSET', (string) env('DB_CHARSET', 'utf8mb4'));
define('DB_SSL', env_bool('DB_SSL', true));

/**
 * Affiche une page explicative propre en cas d'échec de connexion MySQL sur l'hébergement / Vercel.
 */
function show_db_connection_error(PDOException $e, string $host, string $port, string $dbname, string $user): void
{
    http_response_code(500);
    $errorMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $hostSafe = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
    $portSafe = htmlspecialchars($port, ENT_QUOTES, 'UTF-8');
    $dbSafe = htmlspecialchars($dbname, ENT_QUOTES, 'UTF-8');
    $userSafe = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
    $isVercel = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || str_contains($_SERVER['HTTP_HOST'] ?? '', 'vercel.app');

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur de connexion base de données - PROS AUTO</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 2rem 1rem; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { max-width: 650px; width: 100%; background: #1e293b; border-radius: 12px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid #334155; }
        h1 { font-size: 1.4rem; color: #f87171; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
        p { color: #94a3b8; line-height: 1.6; margin-bottom: 1rem; font-size: 0.95rem; }
        .alert { background: #450a0a; border: 1px solid #991b1b; color: #fecaca; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-family: monospace; font-size: 0.85rem; word-break: break-all; }
        .config-box { background: #0f172a; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem; border: 1px solid #334155; }
        .config-box h2 { font-size: 0.95rem; color: #38bdf8; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 0.85rem; }
        th, td { padding: 0.4rem 0.5rem; text-align: left; border-bottom: 1px solid #1e293b; }
        th { color: #64748b; font-weight: normal; }
        td { color: #e2e8f0; }
        .steps { background: #1e293b; border-left: 4px solid #38bdf8; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .steps h3 { font-size: 1rem; color: #38bdf8; margin-bottom: 0.5rem; }
        .steps ol { padding-left: 1.25rem; color: #cbd5e1; font-size: 0.9rem; line-height: 1.6; }
        .steps li { margin-bottom: 0.4rem; }
        code { background: #334155; color: #38bdf8; padding: 2px 5px; border-radius: 4px; font-size: 0.85em; }
    </style>
</head>
<body>
    <div class="card">
        <h1>⚠️ Base de données inaccessible</h1>
        <p>L'application n'a pas pu se connecter au serveur MySQL avec les paramètres actuels :</p>
        
        <div class="alert">
            {$errorMsg}
        </div>

        <div class="config-box">
            <h2>Paramètres tentés</h2>
            <table>
                <tr><th>Hôte (DB_HOST)</th><td><code>{$hostSafe}</code></td></tr>
                <tr><th>Port (DB_PORT)</th><td><code>{$portSafe}</code></td></tr>
                <tr><th>Base (DB_NAME)</th><td><code>{$dbSafe}</code></td></tr>
                <tr><th>Utilisateur (DB_USER)</th><td><code>{$userSafe}</code></td></tr>
            </table>
        </div>

        <div class="steps">
            <h3>Comment résoudre ce problème sur Vercel :</h3>
            <ol>
                <li>Sur Vercel, il n'y a pas de serveur MySQL local (<code>127.0.0.1</code> ne fonctionne pas).</li>
                <li>Si vous utilisez <strong>InfinityFree</strong>, notez que leur offre gratuite <strong>bloque toutes les connexions MySQL distantes</strong>.</li>
                <li>Créez une base MySQL cloud gratuite (ex: <a href="https://tidbcloud.com" target="_blank" style="color: #38bdf8;">TiDB Cloud</a>, <a href="https://aiven.io" target="_blank" style="color: #38bdf8;">Aiven</a>, <a href="https://clever-cloud.com" target="_blank" style="color: #38bdf8;">Clever Cloud</a> ou <a href="https://railway.com" target="_blank" style="color: #38bdf8;">Railway</a>).</li>
                <li>Importez-y le fichier <code>database.sql</code> de votre projet.</li>
                <li>Dans votre projet Vercel : <strong>Settings &rarr; Environment Variables</strong>, ajoutez <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> (et <code>DB_SSL=true</code> si requis).</li>
                <li>Redéployez sur Vercel.</li>
            </ol>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Retourne l'unique instance PDO de l'application.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = DB_HOST;
        $port = DB_PORT;
        if (str_contains($host, ':')) {
            $parts = explode(':', $host, 2);
            $host = $parts[0];
            if (!empty($parts[1])) {
                $port = $parts[1];
            }
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            if (DB_SSL || env_bool('DB_SSL', false)) {
                $sslCa = defined('\Pdo\Mysql::ATTR_SSL_CA') ? \Pdo\Mysql::ATTR_SSL_CA : (defined('PDO::MYSQL_ATTR_SSL_CA') ? @constant('PDO::MYSQL_ATTR_SSL_CA') : null);
                $sslVerify = defined('\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? @constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') : null);

                if ($sslCa !== null) {
                    $options[$sslCa] = true;
                }
                if ($sslVerify !== null) {
                    $options[$sslVerify] = false;
                }
            }

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            try {
                $pdo->exec("SET time_zone = 'SYSTEM'");
            } catch (Throwable $tzEx) {
                // Ignore timezone setup error if restricted on cloud DB
            }
        } catch (PDOException $e) {
            show_db_connection_error($e, $host, $port, DB_NAME, DB_USER);
            exit;
        }
    }
    return $pdo;
}

/* ---------- Helpers SQL (toutes requêtes préparées) ---------- */

function is_missing_schema_error(PDOException $e): bool
{
    $m = strtolower($e->getMessage());
    return str_contains($m, 'doesn\'t exist')
        || str_contains($m, 'does not exist')
        || str_contains($m, 'unknown column')
        || str_contains($m, 'base table or view not found')
        || str_contains($m, 'table') && str_contains($m, 'not exist');
}

/**
 * Erreurs « de schéma » au sens large : colonne/table absente OU valeur hors
 * d'un ENUM. Sur les hébergements, la base importée peut avoir un schéma
 * plus ancien (ex. users.role en ENUM) : on préfère alors une erreur visible
 * (500) plutôt qu'une écriture silencieusement perdue.
 */
function is_schema_drift_error(PDOException $e): bool
{
    if (is_missing_schema_error($e)) return true;
    $code = (string) ($e->errorInfo[1] ?? '');
    $m = strtolower($e->getMessage());
    return $code === '1265' || str_contains($m, 'data truncated') || str_contains($m, 'incorrect string value');
}

function fetch_all(string $sql, array $params = []): array
{
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        if (is_missing_schema_error($e)) {
            error_log('[garage] schema missing on SELECT: ' . $e->getMessage());
            return [];
        }
        throw $e;
    }
}

function fetch_one(string $sql, array $params = [])
{
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    } catch (PDOException $e) {
        if (is_missing_schema_error($e)) {
            error_log('[garage] schema missing on SELECT ONE: ' . $e->getMessage());
            return null;
        }
        throw $e;
    }
}

function execute(string $sql, array $params = []): int
{
    try {
        $st = db()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    } catch (PDOException $e) {
        if (is_missing_schema_error($e)) {
            error_log('[garage] schema missing on EXECUTE: ' . $e->getMessage());
            return 0;
        }
        if (is_schema_drift_error($e)) {
            // Valeur rejetée par un ENUM ou un schéma obsolète : on rend l'erreur
            // visible plutôt que de laisser croire à une écriture réussie.
            error_log('[garage] schema drift on EXECUTE: ' . $e->getMessage() . ' | SQL: ' . $sql);
        }
        throw $e;
    }
}

function last_insert_id(): int
{
    return (int) db()->lastInsertId();
}

function ensure_mechanic_ip_approvals_table(): void
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS mechanic_ip_approvals (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                approved_by INT UNSIGNED NULL,
                approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(255) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_mechanic_ip_user (user_id, ip_address),
                INDEX idx_mechanic_ip_user (user_id),
                INDEX idx_mechanic_ip_active (is_active),
                CONSTRAINT fk_mechanic_ip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mechanic_ip_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('[garage] unable to create mechanic_ip_approvals table: ' . $e->getMessage());
    }
}

function ensure_chat_tables(): void
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS chat_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                sender_id INT UNSIGNED NOT NULL,
                receiver_id INT UNSIGNED NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_conv (sender_id, receiver_id, id),
                INDEX idx_receiver_unread (receiver_id, is_read),
                INDEX idx_created (created_at),
                CONSTRAINT fk_chat_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_chat_msg_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        db()->exec(
            "CREATE TABLE IF NOT EXISTS chat_presence (
                user_id INT UNSIGNED NOT NULL,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                INDEX idx_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('[garage] unable to create chat tables: ' . $e->getMessage());
    }
}

/** Crée les tables de sécurité (tentatives de connexion, autorisations IP) si elles n'existent pas. */
function ensure_security_tables(): void
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_success (ip_address, success),
                INDEX idx_attempted_at (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('[garage] unable to create login_attempts table: ' . $e->getMessage());
    }
}

function ensure_notifications_history_table(): void
{
    try {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                title VARCHAR(200) NOT NULL,
                message VARCHAR(500) NOT NULL,
                type ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
                ref VARCHAR(120) NULL,
                link VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_notif_user (user_id, is_read),
                INDEX idx_notif_ref (ref),
                CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $cols = db()->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
        $fields = array_flip($cols);

        if (!isset($fields['ref'])) {
            db()->exec("ALTER TABLE notifications ADD COLUMN ref VARCHAR(120) NULL AFTER type");
        }
        if (!isset($fields['link'])) {
            db()->exec("ALTER TABLE notifications ADD COLUMN link VARCHAR(255) NULL AFTER ref");
        }
        if (!isset($fields['is_read'])) {
            db()->exec("ALTER TABLE notifications ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER link");
        }

        $create = db()->query("SHOW CREATE TABLE notifications")->fetch(PDO::FETCH_ASSOC);
        $sqlText = (string) ($create['Create Table'] ?? '');

        if (stripos($sqlText, 'user_id') !== false && stripos($sqlText, 'on delete cascade') !== false) {
            try { db()->exec("ALTER TABLE notifications DROP FOREIGN KEY fk_notif_user"); } catch (Throwable $e) {}
            db()->exec("ALTER TABLE notifications MODIFY user_id INT UNSIGNED NULL");
            try {
                db()->exec("ALTER TABLE notifications ADD CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
            } catch (Throwable $e) {
                // Si le nom de contrainte est différent sur une ancienne base, on ignore et on laisse l'état existant.
            }
        }

        if (stripos($sqlText, 'user_id') !== false && stripos($sqlText, 'not null') !== false) {
            try { db()->exec("ALTER TABLE notifications MODIFY user_id INT UNSIGNED NULL"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        error_log('[garage] unable to ensure notification history retention: ' . $e->getMessage());
    }
}

