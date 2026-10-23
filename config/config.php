<?php
/**
 * Configuration générale de l'application.
 * Ne stockez ICI aucun secret sensible critique : les identifiants
 * MySQL se trouvent dans config/database.php.
 */

declare(strict_types=1);

/* ---------- Polyfills PHP 7.4 (hébergeurs mutualisés) ----------
   InfinityFree et beaucoup d'hébergeurs mutualisés restent sur PHP 7.4.
   L'application utilise des fonctions PHP 8 : on les émule ici AVANT tout
   le reste, sinon chaque page tombe en « Fatal error: undefined function ». */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($key === '') {
            continue;
        }

        if ((strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"')
            || (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        /* putenv() est désactivée sur certains hébergeurs mutualisés
           (directive disable_functions) : on ne doit JAMAIS en dépendre. */
        if (function_exists('putenv')) {
            @putenv($key . '=' . $value);
        }
    }
}

function app_runtime_env(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_starts_with($host, 'localhost:')
        || $host === '127.0.0.1' || str_starts_with($host, '127.0.0.1:')
        || $host === '::1' || str_starts_with($host, '[::1]:')) {
        return 'local';
    }

    return 'hosting';
}

function load_app_env_files(): void
{
    $runtime = app_runtime_env();
    $envFile = __DIR__ . '/../.env.' . $runtime;

    if (is_file($envFile)) {
        load_env_file($envFile);
        return;
    }

    $base = __DIR__ . '/../.env';
    if (is_file($base)) {
        load_env_file($base);
    }
}

load_app_env_files();

function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
    if ($value === false || $value === null) {
        return $default;
    }
    if (is_string($value) && $value !== '') {
        $lower = strtolower($value);
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;
        if ($lower === 'null') return null;
    }
    return $value;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env($key, $default);
    return is_bool($value) ? $value : (bool) $value;
}

function request_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === 'https') {
        return true;
    }
    $port = (string) ($_SERVER['SERVER_PORT'] ?? '');
    return $port === '443';
}

/* ---------- Environnement ---------- */
define('APP_ENV', (string) env('APP_ENV', app_runtime_env()));
define('APP_DEBUG', env_bool('APP_DEBUG', APP_ENV !== 'hosting'));
define('FORCE_HTTPS', env_bool('FORCE_HTTPS', APP_ENV === 'hosting'));

/* ---------- Base URL ----------
 * Laisser vide ('') pour une détection automatique (recommandé).
 * Fixer manuellement si besoin, ex. '/garage' (sans slash final).
 */
define('BASE_URL', (string) env('BASE_URL', ''));

/* ---------- Entreprise (valeurs par défaut) ----------
 * Ces valeurs peuvent être modifiées dans l'application :
 * Admin -> Paramètres (table "settings").
 */
define('COMPANY_NAME',    'PROS AUTO');
define('COMPANY_ADDRESS', '245 King Street West, Toronto, ON M5V 3K2, Canada');
define('COMPANY_PHONE',   '+1 (416) 555-0147');
define('COMPANY_EMAIL',   'info@prosauto.ca');
define('COMPANY_WEB',     'www.prosauto.ca');
define('COMPANY_TAX_ID',  'HST 123456789 RT0001');

/* ---------- Fichiers ---------- */
define('UPLOAD_DIR',      __DIR__ . '/../assets/uploads');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 Mo
define('ALLOWED_UPLOADS', ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx']);

/* ---------- Pointage ---------- */
define('EXPECTED_IN_TIME',     '08:00'); // heure théorique d'entrée
define('NORMAL_DAILY_HOURS',   8);       // heures normales / jour
define('BREAK_THRESHOLD_MIN',  30);      // pause minimale (minutes)

/* ---------- Documents ---------- */
define('DEFAULT_TVA', 13);    // taux de taxe par défaut au Canada (Ontario/HST)
define('CURRENCY', 'CAD');    // devise par défaut

/* ---------- Sécurité ---------- */
define('LOGIN_MAX_ATTEMPTS', 5);        // tentatives max ...
define('LOGIN_WINDOW_SECONDS', 900);    // ... sur 15 minutes
define('SESSION_LIFETIME', 7200);       // 2 heures sans activité

/* ---------- Divers ---------- */
define('DEFAULT_APP_TIMEZONE', 'America/Toronto');
define('APP_INIT', true);
if (!defined('APP_TIMEZONE_FALLBACK')) {
    define('APP_TIMEZONE_FALLBACK', DEFAULT_APP_TIMEZONE);
}
date_default_timezone_set(DEFAULT_APP_TIMEZONE);
mb_internal_encoding('UTF-8');

/* ---------- Détection automatique de la base URL ---------- */
function app_base_url(): string
{
    if (defined('BASE_URL') && BASE_URL !== '') {
        return rtrim(BASE_URL, '/');
    }

    /* Méthode fiable : la racine du projet est le dossier parent de
       config/ (chemin disque connu via __DIR__). On la compare à
       DOCUMENT_ROOT pour en déduire le préfixe URL — résultat correct
       quel que soit le niveau d'imbrication de la page courante
       (admin/clients/, admin/attendance/, etc.). */
    $rootFs  = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
    $docRoot = str_replace('\\', '/', (string) realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')));
    if ($docRoot !== '' && $rootFs !== '' && str_starts_with($rootFs, $docRoot)) {
        $base = substr($rootFs, strlen($docRoot));
        return rtrim($base, '/');
    }

    /* Fallback hébergement (DOCUMENT_ROOT = symlink, sous-dossier,
       alias Apache…) : on déduit la base depuis SCRIPT_NAME qui contient
       toujours le chemin URL réel du script exécuté. On cherche le segment
       du dossier projet (parent de config/, ex. 'garage1' ou 'htdocs')
       puis on tronque SCRIPT_NAME à ce segment inclus. */
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '' && $rootFs !== '') {
        $projectDir = basename($rootFs); // ex. garage1
        $pos = strrpos('/' . ltrim($script, '/'), '/' . $projectDir . '/');
        if ($pos !== false && $projectDir !== '' && $projectDir !== '/') {
            return rtrim(substr('/' . ltrim($script, '/'), 0, $pos + strlen($projectDir) + 1), '/');
        }
        // script à la racine du projet : base = dossier du script
        $dir = substr($script, 0, (int) strrpos($script, '/'));
        return rtrim($dir, '/');
    }

    /* Secours (DOCUMENT_ROOT indisponible, alias, CLI…) : déduction
       par le dossier courant — liste triée de la plus longue à la
       plus courte pour couvrir aussi les pages à deux niveaux. */
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $subs = [
        '/admin/attendance', '/admin/clients', '/admin/vehicles', '/admin/mechanics',
        '/admin/inspections', '/admin/assignments', '/admin/repairs', '/admin/devis',
        '/admin/factures', '/admin/users', '/admin/logs',
        '/notifications', '/mechanic', '/admin', '/auth',
    ];
    foreach ($subs as $sub) {
        if (str_ends_with($dir, $sub)) {
            return substr($dir, 0, -strlen($sub));
        }
    }
    return $dir === '/' ? '' : $dir;
}
