<?php
/**
 * Page racine : redirige vers la connexion ou le tableau de bord.
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(home_url());
}
redirect('auth/login.php');
