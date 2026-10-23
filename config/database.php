<?php
/**
 * Connexion à la base de données (PDO).
 * Ajustez ces constantes si vos identifiants XAMPP diffèrent.
 */

declare(strict_types=1);

define('DB_HOST', (string) env('DB_HOST', '127.0.0.1'));
define('DB_NAME', (string) env('DB_NAME', 'garage_db1'));
define('DB_USER', (string) env('DB_USER', 'root'));
define('DB_PASS', (string) env('DB_PASS', ''));
define('DB_CHARSET', (string) env('DB_CHARSET', 'utf8mb4'));

/**
 * Retourne l'unique instance PDO de l'application.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Aligne le fuseau horaire de la session MySQL sur celui de l'application.
            // On évite une valeur nommée qui dépend des tables de fuseau MySQL
            // (souvent absentes sur XAMPP) ; SYSTEM s'appuie sur le paramétrage OS.
            $pdo->exec("SET time_zone = 'SYSTEM'");
        } catch (PDOException $e) {
            http_response_code(500);
            if (APP_DEBUG) {
                exit('Erreur de connexion à la base de données : '
                    . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                    . '<br>Vérifiez <code>config/database.php</code> et importez <code>database.sql</code>.');
            }
            exit('Service momentanément indisponible.');
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


