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
require_once __DIR__ . '/chat.php';

ensure_mechanic_ip_approvals_table();
ensure_chat_tables();

function current_session_fingerprint(): string
{
    $ip = substr(client_ip(), 0, 45);
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $salt = (string) env('APP_SECRET', 'garage-application-secret-change-me');
    return hash_hmac('sha256', $ip . '|' . $ua, $salt);
}

/* ---------- Sécurité du contexte HTTP ---------- */
$hostHeader = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
if ($hostHeader !== '' && preg_match('/[^a-z0-9.-]+/', str_replace(':', '', $hostHeader)) === 1) {
    http_response_code(400);
    exit('Bad Request');
}
if (FORCE_HTTPS && !request_is_https() && !str_contains($hostHeader, 'localhost') && !str_contains($hostHeader, '127.0.0.1') && !str_contains($hostHeader, '[::1]')) {
    $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $httpsUrl, true, 301);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------- Session sécurisée ---------- */
if (session_status() === PHP_SESSION_NONE) {
    $secureCookie = request_is_https();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secureCookie ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cache_limiter', 'nocache');
    if (PHP_VERSION_ID >= 80100) {
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
    }

    session_name('GARAGE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $lifetime = SESSION_LIFETIME;
    if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $lifetime) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly'], true);
        }
        session_destroy();
        session_start();
    }

    if (isset($_SESSION['user_id']) && !empty($_SESSION['security_fingerprint'])) {
        $expected = $_SESSION['security_fingerprint'];
        $actual = current_session_fingerprint();
        if (!hash_equals($expected, $actual)) {
            $_SESSION = [];
            session_regenerate_id(true);
            session_destroy();
            session_start();
            header('Location: ' . app_url('auth/login.php'), true, 303);
            exit;
        }
    }
    $_SESSION['last_activity'] = time();
    $_SESSION['security_fingerprint'] = current_session_fingerprint();
}

if (is_logged_in() && is_mechanic() && !ip_approved_for_user(current_user_id(), client_ip())) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly'], true);
    }
    session_destroy();
    session_start();
    $_SESSION['app_lang'] = current_lang();
    header('Location: ' . app_url('auth/login.php?reason=ip_unapproved'), true, 303);
    exit;
}

app_init_language();
apply_app_timezone();
sync_mysql_timezone();

/* ---------- Transport de secours pour l'API du chat ----------
 * Certains hébergeurs (InfinityFree) interceptent les requêtes AJAX vers
 * actions/chat_api.php avec leur mur anti-robot JS (la réponse est du HTML,
 * pas du JSON), ce qui casse le chat. On propose alors le même endpoint
 * via la page courante : ?chat_api=sync&peer=N&after=N — la page PHP est
 * déjà exécutée, donc le mur anti-robot ne s'applique pas. */
if (isset($_GET['chat_api']) && is_logged_in() && can_chat_role()) {
    $chatApiAction = (string) $_GET['chat_api'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        foreach (['send' => null] as $k => $v) { /* merge POST body into $_GET */ }
        foreach ($_POST as $k => $v) { if (!isset($_GET[$k])) { $_GET[$k] = $v; } }
    }
    require_once __DIR__ . '/chat_api_handler.php';
    chat_api_handle(db(), $chatApiAction);
    exit;
}

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
header_remove('X-Powered-By');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
if (request_is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' 'unsafe-eval'; font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests");

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
