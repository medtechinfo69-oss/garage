<?php
/**
 * Bootstrap de sécurité : session durcie, en-têtes, langue,
 * fonctions de protection d'accès. À inclure en PREMIÈRE ligne
 * de chaque page publique ou privée :
 *
 *   require_once __DIR__ . '/includes/auth.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

/* ---------- Session sécurisée ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_name('GARAGE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // expiration d'inactivité
    $lifetime = SESSION_LIFETIME;
    if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $lifetime) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

app_init_language();
apply_app_timezone();
sync_mysql_timezone();

/* ---------- Changement de langue (GET ?lang=fr|en) ----------
 * Recharge la page courante SANS le paramètre ?lang, en conservant les
 * autres paramètres GET. Le chemin est dérivé de REQUEST_URI (et non de
 * SCRIPT_NAME) pour ne jamais dupliquer la base URL dans la redirection. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && isset($_GET['lang'])
    && is_string($_GET['lang'])
    && preg_match('/^(fr|en)$/', $_GET['lang'])
) {
    if ($_GET['lang'] !== current_lang()) {
        set_app_language($_GET['lang']);
    }
    $target = app_url(current_script_path());
    $q = $_GET;
    unset($q['lang']);
    if ($q !== []) {
        $target .= '?' . http_build_query($q);
    }
    header('Location: ' . $target, true, 303);
    exit;
}

/* ---------- En-têtes de sécurité ---------- */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

/* ---------- Gestion des erreurs ---------- */
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}
set_exception_handler(function (Throwable $e): void {
    error_log('[garage] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px">'
        . '<h1 style="color:#c0392b">Erreur serveur</h1><p>Une erreur inattendue est survenue. Elle a été journalisée.</p>'
        . '<p><a href="' . e(app_url()) . '">Retour à l\'accueil</a></p></body>';
    exit;
});
