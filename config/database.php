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

function fetch_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function fetch_one(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function execute(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function last_insert_id(): int
{
    return (int) db()->lastInsertId();
}
