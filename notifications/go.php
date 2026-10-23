<?php
/**
 * Redirection d'une notification vers son lien, en marquant la notification
 * comme lue. Usage : /notifications/go.php?id=N
 * Sécurité : la notification doit appartenir à l'utilisateur connecté ; le lien
 * est validé (chemin interne uniquement, jamais une URL absolue/externe).
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$u = current_user();

$row = $id > 0
    ? fetch_one("SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1", [$id, (int) $u['id']])
    : null;

if (!$row) {
    redirect('notifications/index.php');
}

// Marquer comme lue au clic
execute("UPDATE notifications SET is_read = 1 WHERE id = ?", [$id]);

// Lien interne uniquement : on retire tout préfixe absolu et on refuse les URL externes
$link = trim((string) ($row['link'] ?? ''));
if ($link === '' || preg_match('~^[a-z][a-z0-9+.\-]*:|^//~i', $link)) {
    redirect('notifications/index.php');
}
$link = ltrim($link, '/');
$link = preg_replace('~^(\.\./)+~', '', $link) ?? $link;

redirect($link);
