<?php
/**
 * Configuration générale de l'application.
 * Ne stockez ICI aucun secret sensible critique : les identifiants
 * MySQL se trouvent dans config/database.php.
 */

declare(strict_types=1);

/* ---------- Environnement ---------- */
define('APP_DEBUG', true); // passer à false en production

/* ---------- Base URL ----------
 * Laisser vide ('') pour une détection automatique (recommandé).
 * Fixer manuellement si besoin, ex. '/garage' (sans slash final).
 */
define('BASE_URL', '');

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
    if ($docRoot !== '' && str_starts_with($rootFs, $docRoot)) {
        $base = substr($rootFs, strlen($docRoot));
        return rtrim($base, '/');
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
