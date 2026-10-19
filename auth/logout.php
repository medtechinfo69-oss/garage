<?php
/**
 * Déconnexion : invalidation de la session courante.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    log_activity('logout', 'auth', null, t('log.logout'));
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// petite session propre pour conserver la langue
session_name('GARAGE_SESSION');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['app_lang'] = $_COOKIE['garage_lang'] ?? 'fr';

header('Location: ' . app_url('auth/login.php'));
exit;
