<?php

/**
 * Fonctions utilitaires de l'application :
 * i18n, affichage, sécurité (CSRF, échappement), pointage,
 * documents (devis/factures), notifications, journalisation...
 */

declare(strict_types=1);

/* Interdit l'accès direct à ce fichier (inclusion uniquement). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(403); exit('Accès direct interdit.'); }


require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/vehicle_catalog.php';

/* ==========================================================
 * 1. INTÉRFACES (FR / EN)
 * ========================================================== */

function current_lang(): string
{
    return $_SESSION['app_lang'] ?? 'fr';
}

function set_app_language(string $lang): void
{
    if (in_array($lang, ['fr', 'en'], true)) {
        $_SESSION['app_lang'] = $lang;
        setcookie('garage_lang', $lang, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/** Initialise la langue (session > cookie > défaut). */
function app_init_language(): void
{
    if (isset($_SESSION['app_lang']) && in_array($_SESSION['app_lang'], ['fr', 'en'], true)) {
        return;
    }
    if (isset($_COOKIE['garage_lang']) && in_array($_COOKIE['garage_lang'], ['fr', 'en'], true)) {
        $_SESSION['app_lang'] = $_COOKIE['garage_lang'];
    } else {
        $_SESSION['app_lang'] = 'fr';
    }
}

/** Charge une fois le fichier de langue courant. */
function &lang_table(): array
{
    static $table = null;
    if ($table === null) {
        $file = __DIR__ . '/lang/' . current_lang() . '.php';
        $table = is_file($file) ? require $file : require __DIR__ . '/lang/fr.php';
    }
    return $table;
}

/** Traduit une clé. t('key') ou t('key', $arg1, $arg2) avec %s. */
function t(string $key, mixed ...$params): string
{
    $table = lang_table();
    $text = $table[$key] ?? $table['common.' . $key] ?? $key;
    if ($params) {
        $text = vsprintf($text, $params);
    }
    return $text;
}

/* ==========================================================
 * 2. AFFICHAGE / SORTIE
 * ========================================================== */

/** Échappement XSS. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_url(string $path = ''): string
{
    return app_base_url() . '/' . ltrim($path, '/');
}

/**
 * URL d'un asset avec cache-busting (?v=timestamp du fichier).
 * Évite qu'un navigateur garde une vieille version de app.js / CSS
 * après une modification (symptôme : « [object HTMLInputElement] » alors
 * que le correctif est bien présent sur le disque).
 */
function asset_url(string $path): string
{
    $url  = app_url($path);
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $v    = is_file($file) ? (string) filemtime($file) : null;
    return $v === null ? $url : $url . '?v=' . $v;
}

/** Chemin du script courant, relatif à la base URL (ex. 'admin/clients/index.php'). */
function current_script_path(): string
{
    $base = app_base_url();
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/');
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base));
    }
    return ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

/** URL de bascule FR/EN qui conserve le chemin + tous les paramètres GET courants. */
function lang_switch_url(string $lang): string
{
    $target = app_url(current_script_path());
    $q = $_GET;
    $q['lang'] = $lang;
    return $target . '?' . http_build_query($q);
}

function flash(string $type, string $message, ?array $link = null): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'link' => $link];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Icône Bootstrap associée à un type de bannière. */
function banner_icon(string $type): string
{
    return [
        'success' => 'check-circle-fill',
        'danger'  => 'x-octagon-fill',
        'warning' => 'exclamation-triangle-fill',
        'info'    => 'info-circle-fill',
        'neutral' => 'info-circle',
    ][$type] ?? 'info-circle-fill';
}

/**
 * Construit une bannière (design system .app-banner).
 *
 * @param string      $type    success | danger | warning | info | neutral
 * @param string      $message texte déjà traduit
 * @param string|null $title   titre optionnel (mis en avant au-dessus du texte)
 * @param string[]    $errors  liste d'erreurs (affiche une puce par élément)
 * @param array|null  $link    ['url' => ..., 'label' => ...] lien d'action
 * @param bool        $dismiss affiche le bouton de fermeture
 * @param string      $extra   classes CSS additionnelles
 */
function banner(
    string $type,
    string $message = '',
    ?string $title = null,
    array $errors = [],
    ?array $link = null,
    bool $dismiss = false,
    string $extra = ''
): string {
    $type  = in_array($type, ['success', 'danger', 'warning', 'info', 'neutral'], true) ? $type : 'info';
    $extra = trim('app-banner app-banner--' . $type . ' app-banner--enter ' . $extra);

    $html = '<div class="' . e($extra) . '" role="alert">'
          . '<span class="app-banner__ico" aria-hidden="true"><i class="bi bi-' . e(banner_icon($type)) . '"></i></span>'
          . '<div class="app-banner__body">';

    if ($title !== null && $title !== '') {
        $html .= '<div class="app-banner__title">' . e($title) . '</div>';
    }

    $body = '';
    if ($message !== '') {
        $body .= '<div class="app-banner__text">' . nl2br(e($message)) . '</div>';
    }
    if ($errors) {
        $body .= '<ul class="app-banner__list app-banner__text">';
        foreach ($errors as $er) {
            $body .= '<li>' . nl2br(e((string) $er)) . '</li>';
        }
        $body .= '</ul>';
    }
    $html .= $body . '</div>';

    if (!empty($link['url']) && !empty($link['label'])) {
        $html .= '<a class="app-banner__action" href="' . e(app_url((string) $link['url']))
              . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>'
              . e((string) $link['label']) . '</a>';
    }
    if ($dismiss) {
        $html .= '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . e(t('common.close')) . '"></button>';
    }

    return $html . '</div>';
}

/** Écho une bannière (raccourci). */
function show_banner(string $type, string $message = '', ?string $title = null, array $errors = [], ?array $link = null, string $extra = ''): void
{
    echo banner($type, $message, $title, $errors, $link, false, $extra);
}

/** Affiche les messages flash une fois (design system .app-banner). */
function render_flashes(): void
{
    foreach (get_flashes() as $f) {
        $type = (string) $f['type'];
        echo '<div class="alert alert-' . e($type) . ' alert-dismissible fade show flash-alert app-banner app-banner--'
           . e(in_array($type, ['success', 'danger', 'warning', 'info', 'neutral'], true) ? $type : 'info')
           . ' app-banner--enter" role="alert">'
           . '<span class="app-banner__ico" aria-hidden="true"><i class="bi bi-' . e(banner_icon($type)) . '"></i></span>'
           . '<div class="app-banner__body"><div class="app-banner__text">' . nl2br(e((string) $f['message'])) . '</div></div>';
        if (!empty($f['link']['url']) && !empty($f['link']['label'])) {
            echo '<a class="app-banner__action" href="' . e(app_url((string) $f['link']['url'])) . '" target="_blank" rel="noopener">'
               . '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>' . e((string) $f['link']['label']) . '</a>';
        }
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . e(t('common.close')) . '"></button></div>';
    }
}

/* ==========================================================
 * 3. SÉCURITÉ
 * ========================================================== */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Vérifie le jeton CSRF d'une requête POST (sinon 403). */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Jeton de sécurité invalide ou expiré. Revenez en arrière, rechargez la page et réessayez. (CSRF)');
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/* ---------- Protection anti force brute ---------- */

function login_blocked(string $ip): ?int
{
    // Fenêtre calculée côté SQL (NOW()) pour éviter tout décalage PHP/MySQL
    $row = fetch_one(
        "SELECT COUNT(*) c FROM login_attempts
         WHERE ip_address = ? AND success = 0 AND attempted_at >= (NOW() - INTERVAL " . LOGIN_WINDOW_SECONDS . " SECOND)",
        [$ip]
    );
    $fails = (int) ($row['c'] ?? 0);
    return $fails >= LOGIN_MAX_ATTEMPTS ? ($fails - LOGIN_MAX_ATTEMPTS + 1) * 3 : null; // minutes restantes approximatives
}

function record_login_attempt(string $ip, bool $success): void
{
    execute("INSERT INTO login_attempts (ip_address, success) VALUES (?, ?)", [$ip, $success ? 1 : 0]);
    if ($success) {
        // nettoie les échecs de cette IP après un succès
        execute("DELETE FROM login_attempts WHERE ip_address = ? AND success = 0", [$ip]);
    }
    // purge des lignes anciennes
    execute("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
}

/* ---------- Uploads sécurisés ---------- */

/**
 * Valide et enregistre un fichier.
 * Retour : [ok: bool, message: string, path: ?string]
 */
function upload_file(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [true, 'ok', null]; // fichier optionnel absent
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, t('error.upload_error'), null];
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return [false, t('error.upload_too_big'), null];
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_UPLOADS, true)) {
        return [false, t('error.upload_bad_ext'), null];
    }
    // vérification du MIME réel
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $okMimes = [
        'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'], 'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];
    if (!isset($okMimes[$ext]) || !in_array($mime, $okMimes[$ext], true)) {
        return [false, t('error.upload_bad_mime'), null];
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        return [false, t('error.upload_move'), null];
    }
    return [true, 'ok', $name];
}

function upload_url(?string $file): ?string
{
    return $file ? app_url('assets/uploads/' . rawurlencode($file)) : null;
}

/* ==========================================================
 * 4. UTILISATEUR / RÔLES
 * ========================================================== */

function current_user(): ?array
{
    static $cachedId = null;
    static $cachedUser = null;

    $id = (int) ($_SESSION['user_id'] ?? 0);

    // Le cache doit rester synchronisé avec la session courante : à la connexion,
    // $_SESSION['user_id'] est renseigné APRÈS un premier appel à current_user()
    // (via is_logged_in() en tête de auth/login.php). Sans ce contrôle, l'utilisateur
    // restait « null » pour le reste de la requête : redirection d'accueil erronée
    // (admin renvoyé vers le tableau de bord mécanicien) et journalisation de la
    // connexion sans user_id.
    if ($cachedId !== $id) {
        $cachedId   = $id;
        $cachedUser = $id > 0
            ? fetch_one("SELECT * FROM users WHERE id = ? AND is_active = 1", [$id])
            : null;

        if ($id > 0 && $cachedUser === null) {
            unset($_SESSION['user_id']); // compte désactivé entre-temps
        }
    }

    return $cachedUser;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function is_mechanic(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'mechanic';
}

function current_user_id(): int
{
    $u = current_user();
    return $u ? (int) $u['id'] : 0;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('auth/login.php');
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit(t('error.admin_only'));
    }
}

/** Page d'accueil selon le rôle. */
function home_url(): string
{
    $u = current_user();
    return ($u && $u['role'] === 'admin') ? 'admin/dashboard.php' : 'mechanic/dashboard.php';
}

/* ==========================================================
 * 5. PARAMÈTRES
 * ========================================================== */

function get_setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (fetch_all("SELECT setting_key, setting_value FROM settings") as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    $v = $cache[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function company_info(): array
{
    return [
        'name'    => (string) get_setting('company_name', COMPANY_NAME),
        'address' => (string) get_setting('company_address', COMPANY_ADDRESS),
        'phone'   => (string) get_setting('company_phone', COMPANY_PHONE),
        'email'   => (string) get_setting('company_email', COMPANY_EMAIL),
        'web'     => (string) get_setting('company_web', COMPANY_WEB),
        'tax_id'  => (string) get_setting('company_tax_id', COMPANY_TAX_ID),
    ];
}

function att_settings(): array
{
    return [
        'expected_in'  => (string) get_setting('expected_in_time', EXPECTED_IN_TIME),
        'normal_hours' => (float) get_setting('normal_daily_hours', (string) NORMAL_DAILY_HOURS),
        'break_min'    => (int) get_setting('break_threshold_minutes', (string) BREAK_THRESHOLD_MIN),
    ];
}

function currency(): string
{
    return (string) get_setting('currency', CURRENCY);
}

/* ---------- Fuseau horaire global ---------- */

/** Fuseau horaire actif de l'application (réglage DB > défaut config). */
function app_timezone(): string
{
    static $tz = null;
    if ($tz !== null) {
        return $tz;
    }
    $fallback = defined('DEFAULT_APP_TIMEZONE') ? DEFAULT_APP_TIMEZONE : 'UTC';
    try {
        $stored = get_setting('app_timezone', $fallback);
    } catch (Throwable $e) {
        $stored = $fallback;
    }
    $stored = trim((string) $stored);
    if ($stored === '' || !in_array($stored, timezone_identifiers_list(), true)) {
        $stored = $fallback;
    }
    $tz = $stored;
    return $tz;
}

function apply_app_timezone(): void
{
    date_default_timezone_set(app_timezone());
}

/** Aligne la session MySQL sur le fuseau PHP (décalage explicite, compatible XAMPP). */
function sync_mysql_timezone(): void
{
    try {
        $off = (new DateTime('now'))->getOffset();
        $sign = $off < 0 ? '-' : '+';
        $abs = abs($off);
        $mysqlOff = sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
        db()->exec("SET time_zone = '" . $mysqlOff . "'");
    } catch (Throwable $e) {
        // Repli silencieux : MySQL reste sur SYSTEM.
    }
}

/** Liste des fuseaux avec décalage UTC courant, groupés par région. */
function app_timezone_options(): array
{
    static $opts = null;
    if ($opts !== null) {
        return $opts;
    }
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $opts = [];
    foreach (timezone_identifiers_list() as $id) {
        try {
            $tz = new DateTimeZone($id);
        } catch (Throwable $e) {
            continue;
        }
        $off = $tz->getOffset($now);
        $sign = $off < 0 ? '-' : '+';
        $abs = abs($off);
        $label = sprintf(
            '(UTC%s%02d:%02d) %s',
            $sign,
            intdiv($abs, 3600),
            intdiv($abs % 3600, 60),
            str_replace('_', ' ', $id)
        );
        $group = str_contains($id, '/') ? substr($id, 0, (int) strpos($id, '/')) : 'Other';
        $opts[$group][$id] = ['offset' => $off, 'label' => $label];
    }
    ksort($opts);
    foreach ($opts as &$list) {
        uasort($list, static fn($a, $b) => $a['offset'] <=> $b['offset'] ?: strcmp($a['label'], $b['label']));
    }
    unset($list);
    return $opts;
}

/* ==========================================================
 * 6. JOURNALISATION & NOTIFICATIONS
 * ========================================================== */

function log_activity(string $action, string $module, ?int $recordId, string $description): void
{
    $u = current_user();
    try {
        execute(
            "INSERT INTO activity_logs (user_id, username, action, module, record_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $u['id'] ?? null,
                $u['username'] ?? null,
                $action, $module, $recordId,
                mb_substr($description, 0, 500),
                client_ip(),
            ]
        );
    } catch (Throwable $ex) {
        // le journal ne doit jamais bloquer l'application
    }
}

/** Notifie un utilisateur (déduplication par ref). */
function notify_user(int $userId, string $title, string $message, string $type = 'info', ?string $link = null, ?string $ref = null): void
{
    if ($ref !== null) {
        $exists = fetch_one("SELECT id FROM notifications WHERE user_id = ? AND ref = ?", [$userId, $ref]);
        if ($exists) {
            return;
        }
    }
    execute(
        "INSERT INTO notifications (user_id, title, message, type, ref, link) VALUES (?, ?, ?, ?, ?, ?)",
        [$userId, $title, mb_substr($message, 0, 500), $type, $ref, $link]
    );
}

/** Notifie tous les administrateurs actifs. */
function notify_admins(string $title, string $message, string $type = 'info', ?string $link = null, ?string $ref = null): void
{
    foreach (fetch_all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1") as $a) {
        notify_user((int) $a['id'], $title, $message, $type, $link, $ref);
    }
}

function unread_notifications(int $userId): array
{
    return fetch_all(
        "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 10",
        [$userId]
    );
}

function unread_count(int $userId): int
{
    $r = fetch_one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0", [$userId]);
    return (int) ($r['c'] ?? 0);
}

/* ==========================================================
 * 7. LIBELLÉS & BADGES
 * ========================================================== */

const VEHICLE_STATUSES = ['initial', 'en_cours', 'en_reparation', 'repare', 'livre', 'annule'];

function status_label(string $status): string
{
    return t('status.' . $status);
}

/** Badge HTML coloré selon l'état du véhicule. */
function status_badge(string $status): string
{
    $class = match ($status) {
        'initial'        => 'text-bg-secondary',
        'en_cours'       => 'text-bg-warning',
        'en_reparation'  => 'text-bg-primary',
        'repare'         => 'text-bg-success',
        'livre'          => 'text-bg-info',
        'annule'         => 'text-bg-danger',
        default          => 'text-bg-secondary',
    };
    $icon = match ($status) {
        'initial'       => 'circle',
        'en_cours'      => 'hourglass-split',
        'en_reparation' => 'gear',
        'repare'        => 'wrench',
        'livre'         => 'box-seam',
        'annule'        => 'x-octagon',
    };
    return '<span class="badge ' . $class . '"><i class="bi bi-' . $icon . ' me-1"></i>'
         . e(status_label($status)) . '</span>';
}

function priority_badge(string $p): string
{
    $map = [
        'basse'    => ['secondary', t('priority.basse')],
        'normale'  => ['info', t('priority.normale')],
        'haute'    => ['warning', t('priority.haute')],
        'urgente'  => ['danger', t('priority.urgente')],
    ];
    [$c, $l] = $map[$p] ?? $map['normale'];
    return '<span class="badge text-bg-' . $c . '">' . e($l) . '</span>';
}

function fuel_label(?string $f): string
{
    if (!$f) {
        return '—';
    }
    $map = [
        'Essence'    => t('fuel.essence'),
        'Diesel'     => t('fuel.diesel'),
        'Electrique' => t('fuel.electrique'),
        'Hybride'    => t('fuel.hybride'),
        'GPL'        => t('fuel.gpl'),
    ];
    return $map[$f] ?? $f;
}

function civility_label(string $c): string
{
    return $c === 'Madame' ? t('civ.madame') : t('civ.monsieur');
}

function client_name(?array $client): string
{
    if (!$client) {
        return '—';
    }
    return trim($client['first_name'] . ' ' . $client['last_name']);
}

function vehicle_label(?array $v): string
{
    if (!$v) {
        return '—';
    }
    return trim($v['brand'] . ' ' . $v['model']) . ' — ' . $v['license_plate'];
}

/**
 * Bandeau « héros » d'un véhicule, commun à TOUTES les pages de détail.
 * Le thème de couleur, l'icône et l'animation du point d'état suivent
 * le statut du véhicule (voir .vh--<status> dans assets/css/style.css).
 *
 * @param array $v    Ligne véhicule (client attendu en first_name / last_name)
 * @param array $opt  eyebrow : sur-titre · since : « depuis le … »
 *                    footer  : [['icon' => 'bi-…', 'text' => '…'], …]
 */
function vehicle_hero(array $v, array $opt = []): string
{
    $status = (string) ($v['status'] ?? 'initial');

    // [icône, point d'état animé] — animé uniquement si le véhicule est en cours
    $themes = [
        'initial'       => ['bi-circle',          false],
        'en_cours'      => ['bi-hourglass-split', true],
        'en_reparation' => ['bi-gear',            true],
        'repare'        => ['bi-wrench',          false],
        'livre'         => ['bi-box-seam',        false],
        'annule'        => ['bi-x-octagon',       false],
    ];
    [$icon, $pulse] = $themes[$status] ?? $themes['initial'];

    /* ---- Puces d informations (uniquement les données disponibles) ----
       Mécanicien volontairement exclu de la fiche véhicule. */
    $client = trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
    $phone  = trim((string) ($v['c_phone'] ?? $v['phone'] ?? ''));

    $chip = static function (string $icon, string $text, string $extra = ''): string {
        return '<span class="vh-chip' . $extra . '"><i class="bi ' . $icon . '"></i>'
            . e($text) . '</span>';
    };

    $chips = '';
    if ($client !== '') {
        $chips .= $chip('bi-person', $client);
    }
    if ($phone !== '') {
        $chips .= $chip('bi-telephone', $phone);
    }
    if (!empty($v['year'])) {
        $chips .= $chip('bi-calendar3', (string) $v['year']);
    }
    if (($v['mileage'] ?? null) !== null && $v['mileage'] !== '') {
        $chips .= $chip('bi-speedometer2', number_format((float) $v['mileage']) . ' km');
    }
    if (!empty($v['vin'])) {
        $chips .= $chip('bi-upc-scan', (string) $v['vin'], ' vh-chip--mono');
    }

    /* ---- Pied du bandeau ---- */
    $footer = '';
    foreach ((array) ($opt['footer'] ?? []) as $item) {
        $footer .= '<span class="vh-foot"><i class="bi ' . e((string) ($item['icon'] ?? 'bi-dot')) . '"></i>'
            . e((string) ($item['text'] ?? '')) . '</span>';
    }

    $since = trim((string) ($opt['since'] ?? ''));

    $html = '<section class="vh vh--' . e($status) . ($pulse ? ' vh--pulse' : '') . '">'
        . '<span class="vh-accent" aria-hidden="true"></span>'
        . '<div class="vh-main">'
        . '<span class="vh-icon" aria-hidden="true"><i class="bi bi-car-front-fill"></i></span>'
        . '<div class="vh-copy">'
        . '<div class="vh-eyebrow">' . e((string) ($opt['eyebrow'] ?? t('vehicles.title'))) . '</div>'
        . '<div class="vh-title">'
        . '<h1>' . e(trim((string) $v['brand'] . ' ' . $v['model'])) . '</h1>'
        . '<span class="vh-plate">' . e((string) $v['license_plate']) . '</span>'
        . '</div>'
        . ($chips !== '' ? '<div class="vh-meta">' . $chips . '</div>' : '')
        . '</div>'
        . '<div class="vh-status">'
        . '<span class="vh-status-pill"><span class="vh-status-dot" aria-hidden="true"></span>'
        . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
        . '<span>' . e(status_label($status)) . '</span></span>'
        . ($since !== '' ? '<span class="vh-status-since">' . e($since) . '</span>' : '')
        . '</div>'
        . '</div>'
        . ($footer !== '' ? '<div class="vh-footer">' . $footer . '</div>' : '')
        . '</section>';

    return $html;
}

/* ---------- Catalogue marques / modèles (formulaire véhicule) ----------
 *
 * Le projet ne dispose d'aucune table de référence marques/modèles :
 * `vehicles.brand` et `vehicles.model` sont des colonnes texte libres.
 * On construit donc le catalogue affiché dans le formulaire en fusionnant :
 *   1. la liste de référence (includes/vehicle_catalog.php) ;
 *   2. les couples marque/modèle RÉELLEMENT présents dans `vehicles`.
 * Dédupliqué sans tenir compte de la casse, trié, et mis en cache
 * pour la durée de la requête. Si la table est vide ou inaccessible,
 * la liste de référence seule est renvoyée (le formulaire reste utilisable).
 */

/** Clé de comparaison insensible à la casse + espaces normalisés. */
function vehicle_catalog_key(?string $value): string
{
    $v = mb_strtolower(trim((string) $value), 'UTF-8');
    return (string) preg_replace('/\s+/u', ' ', $v);
}

/**
 * Catalogue complet marque => modèles (fusion référence + base).
 * @return array<string, string[]>
 */
function vehicle_brand_catalog(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // 1. Liste de référence : on conserve le libellé d'origine (BMW,
    //    Mercedes-Benz, Dacia…) et les modèles, dédupliqués sans tenir
    //    compte de la casse puis triés.
    $raw = [];
    foreach (vehicle_reference_catalog() as $label => $models) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }
        $key = vehicle_catalog_key($label);
        $raw[$key] = ['label' => $label, 'models' => []];
        foreach ($models as $m) {
            $m = trim((string) $m);
            if ($m !== '') {
                $raw[$key]['models'][vehicle_catalog_key($m)] = $m;
            }
        }
    }

    // 2. Valeurs réellement présentes dans la base : tout ce qui a déjà
    //    été saisi est réinjecté dans les listes (aucune perte de donnée,
    //    aucune donnée fictive). Marque inconnue du référentiel => conservée.
    try {
        $rows = fetch_all(
            "SELECT DISTINCT brand, model FROM vehicles
             WHERE brand <> '' OR model <> ''
             ORDER BY brand, model"
        );
        foreach ($rows as $r) {
            $brand = trim((string) ($r['brand'] ?? ''));
            $model = trim((string) ($r['model'] ?? ''));
            if ($brand === '') {
                continue;
            }
            $key = vehicle_catalog_key($brand);
            if (!isset($raw[$key])) {
                $raw[$key] = ['label' => $brand, 'models' => []];
            }
            if ($model !== '') {
                $raw[$key]['models'][vehicle_catalog_key($model)] = $model;
            }
        }
    } catch (Throwable $e) {
        error_log('[garage] catalogue véhicules : ' . $e->getMessage());
    }

    // 3. Tri final (marques et modèles) — aucune clé dupliquée.
    $out = [];
    foreach ($raw as $key => $b) {
        natcasesort($b['models']);
        $out[$key] = ['label' => $b['label'], 'models' => array_values($b['models'])];
    }
    uasort($out, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

    $cache = $out;
    return $cache;
}

/** Liste des marques disponibles (sans doublon, triée). @return string[] */
function vehicle_brands(): array
{
    return array_values(array_map(
        static fn(array $b): string => $b['label'],
        vehicle_brand_catalog()
    ));
}

/**
 * Modèles disponibles pour une marque.
 * @return string[] vide si la marque est inconnue
 */
function vehicle_models(?string $brand): array
{
    $key = vehicle_catalog_key($brand);
    if ($key === '') {
        return [];
    }
    $catalog = vehicle_brand_catalog();
    return $catalog[$key]['models'] ?? [];
}

/** La marque existe-t-elle dans le catalogue ? */
function vehicle_brand_exists(?string $brand): bool
{
    $key = vehicle_catalog_key($brand);
    return $key !== '' && isset(vehicle_brand_catalog()[$key]);
}

/**
 * Modèles d'une marque, garantis d'inclure la valeur fournie
 * (pour qu'une fiche existante affichée garde toujours son modèle).
 * @return string[]
 */
function vehicle_models_with(?string $brand, ?string $keep = null): array
{
    $models = vehicle_models($brand);
    $keep   = trim((string) $keep);
    if ($keep !== '') {
        $found = false;
        foreach ($models as $m) {
            if (vehicle_catalog_key($m) === vehicle_catalog_key($keep)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $models[] = $keep;
            natcasesort($models);
            $models = array_values($models);
        }
    }
    return $models;
}

/** Données JSON (JSON_UNESCAPED_UNICODE) pour le widget JS. */
function vehicle_catalog_json(): string
{
    $out = [];
    foreach (vehicle_brand_catalog() as $b) {
        $out[$b['label']] = $b['models'];
    }
    return (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Champ « liste déroulante avec recherche » (solution native, sans
 * dépendance externe) : recherche au clavier + liste scrollable +
 * sélection unique.
 *
 * Le nom du champ reste `name="…"`, donc l'enregistrement, la
 * modification et la validation du formulaire fonctionnent exactement
 * comme avant (une liste <select> classique est fournie en repli si
 * JavaScript est désactivé).
 *
 * @param string      $id          identifiant du champ
 * @param string      $name         nom POST (brand / model)
 * @param string      $value       valeur actuellement enregistrée
 * @param string[]    $options     options proposées
 * @param string      $searchLabel libellé de la barre de recherche
 * @param string      $emptyLabel  message quand aucun résultat
 * @param bool        $disabled    champ désactivé (modèle sans marque)
 * @param string      $hint        texte d'aide sous le champ
 */
function vcombo_html(
    string $id,
    string $name,
    string $value,
    array $options,
    string $searchLabel,
    string $emptyLabel,
    bool $disabled = false,
    string $hint = '',
    string $dependsOn = ''
): string {
    $value = trim($value);

    $html = '<div class="vcombo' . ($disabled ? ' is-disabled' : '') . '"'
        . ' data-vcombo data-vcombo-name="' . e($name) . '"'
        . ' data-vcombo-empty="' . e($emptyLabel) . '"'
        . ($dependsOn !== '' ? ' data-vcombo-depends="' . e($dependsOn) . '"' : '')
        . ' data-vcombo-count="' . count($options) . '">';

    // Barre de recherche : elle porte la valeur sélectionnée ET le filtre.
    // C'est elle qui porte name="…" : un seul champ est envoyé dans le POST
    // (exactement comme avant) et, sans JavaScript, le champ reste un simple
    // champ texte — aucune saisie n'est donc perdue.
    $html .= '<div class="vcombo-control">'
        . '<input type="text" class="form-control" name="' . e($name) . '" id="' . e($id) . '"'
        . ' value="' . e($value) . '"'
        . ' autocomplete="off" autocapitalize="off" spellcheck="false"'
        . ' role="combobox" aria-expanded="false" aria-autocomplete="list"'
        . ' aria-controls="' . e($id) . '_list"'
        . ' placeholder="' . e($searchLabel) . '"'
        . ' maxlength="50" required'
        . ($disabled ? ' disabled' : '') . '>'
        . '<i class="bi bi-chevron-down vcombo-caret" aria-hidden="true"></i>'
        . '</div>';

    // Liste déroulante scrollable
    $html .= '<div class="vcombo-menu" hidden>'
        . '<ul class="vcombo-list" id="' . e($id) . '_list" role="listbox"></ul>'
        . '<div class="vcombo-empty" hidden></div>'
        . '</div>';

    $html .= '</div>';

    if ($hint !== '') {
        $html .= '<div class="form-text">' . e($hint) . '</div>';
    }

    return $html;
}

function mech_name(int $id): string
{
    static $cache = [];
    if (!isset($cache[$id])) {
        $m = fetch_one("SELECT full_name FROM mechanics WHERE id = ?", [$id]);
        $cache[$id] = $m['full_name'] ?? '—';
    }
    return $cache[$id];
}

function num_short(float|int|string $n): string
{
    return number_format((float) $n, 2, ',', ' ');
}

function money(float|string $n): string
{
    return number_format((float) $n, 2, ',', ' ') . ' ' . currency();
}

function fmt_date(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

function fmt_time(?string $t): string
{
    return $t ? date('H:i', strtotime($t)) : '—';
}

function fmt_datetime(?string $dt): string
{
    return $dt ? date('d/m/Y H:i', strtotime($dt)) : '—';
}

function fmt_duration(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h . 'h' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
}

/* ---------- Noms localisés (jours / mois) ---------- */

/** Libellé du jour selon la langue courante (1 = lundi … 7 = dimanche). */
function day_name(int $isoDay): string
{
    static $names = null;
    if ($names === null) {
        $names = current_lang() === 'fr'
            ? [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']
            : [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    }
    return $names[$isoDay] ?? '';
}

/** Libellé complet du mois (1..12). */
function month_name(int $m): string
{
    static $names = null;
    if ($names === null) {
        $names = current_lang() === 'fr'
            ? [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre']
            : [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    }
    return $names[$m] ?? '';
}

/** Abréviation du mois (1..12) : « Jan. », « Fév. » / « Jan », « Feb ». */
function month_short_name(int $m): string
{
    static $names = null;
    if ($names === null) {
        $names = current_lang() === 'fr'
            ? [1 => 'Jan.', 'Fév.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sep.', 'Oct.', 'Nov.', 'Déc.']
            : [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    }
    return $names[$m] ?? '';
}

/** Date longue localisée : « lundi 16 septembre 2026 » / « Monday, 16 September 2026 ». */
function fmt_date_long(?string $d): string
{
    if (!$d || ($ts = strtotime($d)) === false) {
        return '—';
    }
    return current_lang() === 'fr'
        ? mb_strtolower(day_name((int) date('N', $ts))) . ' ' . date('j', $ts) . ' '
            . mb_strtolower(month_name((int) date('n', $ts))) . ' ' . date('Y', $ts)
        : day_name((int) date('N', $ts)) . ', ' . date('j', $ts) . ' '
            . month_name((int) date('n', $ts)) . ' ' . date('Y', $ts);
}

/** Mois + année localisés : « septembre 2026 » / « September 2026 ». */
function fmt_month_year(?string $d): string
{
    if (!$d || ($ts = strtotime($d)) === false) {
        return '—';
    }
    return current_lang() === 'fr'
        ? mb_strtolower(month_name((int) date('n', $ts))) . ' ' . date('Y', $ts)
        : month_name((int) date('n', $ts)) . ' ' . date('Y', $ts);
}

/** Identifiant de locale pour le JS (formatage des nombres). */
function locale_id(): string
{
    return current_lang() === 'fr' ? 'fr-FR' : 'en-GB';
}

function role_label(string $role): string
{
    return $role === 'admin' ? t('role.admin') : t('role.mechanic');
}

function role_badge(string $role): string
{
    $c = $role === 'admin' ? 'danger' : 'primary';
    return '<span class="badge text-bg-' . $c . '">' . e(role_label($role)) . '</span>';
}

/* ==========================================================
 * 8. CHANGEMENT D'ÉTAT DU VÉHICULE
 * ========================================================== */

/** Transitions autorisées : nouvel état => états d'origine possibles. */
function allowed_transitions(): array
{
    return [
        'en_cours'      => ['initial'],
        'en_reparation' => ['en_cours'],
        'repare'        => ['en_reparation'],
        'livre'         => ['repare'],
        'annule'        => ['initial', 'en_cours', 'en_reparation', 'repare'],
    ];
}

function is_valid_transition(string $from, string $to): bool
{
    $map = allowed_transitions();
    return isset($map[$to]) && in_array($from, $map[$to], true);
}

/**
 * Change l'état d'un véhicule avec contrôle des transitions,
 * écriture dans l'historique, notifications et journal.
 * Retour : [ok: bool, message: string]
 */
function change_vehicle_status(int $vehicleId, string $to, ?string $comment, ?int $mechanicId = null): array
{
    $v = fetch_one("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
    if (!$v) {
        return [false, t('error.not_found')];
    }
    if (!is_valid_transition($v['status'], $to)) {
        return [false, t('error.invalid_status', e(status_label($v['status'])), e(status_label($to)))];
    }
    $u = current_user();
    $db = db();
    $ownTx = !$db->inTransaction();
    try {
        if ($ownTx) {
            $db->beginTransaction();
        }
        execute(
            "INSERT INTO vehicle_status_history (vehicle_id, status, previous_status, comment, mechanic_id, user_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$vehicleId, $to, $v['status'], $comment, $mechanicId, $u['id'] ?? null]
        );
        $mechCol = ($to === 'en_reparation' || $to === 'repare') ? $mechanicId : ($to === 'livre' ? null : $v['mechanic_id']);
        execute("UPDATE vehicles SET status = ?, mechanic_id = ? WHERE id = ?", [$to, $mechCol, $vehicleId]);
        if ($ownTx) {
            $db->commit();
        }
    } catch (Throwable $ex) {
        if ($ownTx) {
            $db->rollBack();
        }
        return [false, t('error.db_error')];
    }

    $label = vehicle_label($v);
    // journal
    $msgs = [
        'en_cours'      => t('log.inspection_created', e($label)),
        'en_reparation' => t('log.assigned', e($label), e(mech_name((int) $mechanicId))),
        'repare'        => t('log.repair_done', e($label)),
        'livre'         => t('log.delivered', e($label)),
        'annule'        => t('log.cancelled', e($label)),
    ];
    log_activity('status_' . $to, 'vehicles', $vehicleId, $msgs[$to] ?? $to);

    // notifications
    if ($to === 'en_reparation' && $mechanicId) {
        $mu = fetch_one("SELECT id FROM users WHERE mechanic_id = ? AND is_active = 1", [$mechanicId]);
        if ($mu) {
            notify_user(
                (int) $mu['id'],
                t('notif.new_assignment'),
                t('notif.new_assignment_msg', e($label)),
                'warning', 'mechanic/vehicles.php',
                'assign_' . $vehicleId . '_' . $mechanicId . '_' . date('Ymd')
            );
        }
    }
    if ($to === 'repare') {
        notify_admins(t('notif.repair_done'), t('notif.repair_done_msg', e($label)), 'success', 'admin/vehicles/index.php', 'repaired_' . $vehicleId . '_' . date('Ymd'));
    }
    if ($to === 'livre') {
        if ($v['mechanic_id']) {
            $mu = fetch_one("SELECT id FROM users WHERE mechanic_id = ? AND is_active = 1", [$v['mechanic_id']]);
            if ($mu) {
                notify_user((int) $mu['id'], t('notif.delivered'), t('notif.delivered_msg', e($label)), 'info', 'mechanic/repairs.php', 'delivered_' . $vehicleId . '_' . date('Ymd'));
            }
        }
    }
    return [true, t('flash.status_changed', e(status_label($to)))];
}

/* ==========================================================
 * 9. POINTAGE / TEMPS TRAVAILLÉ
 * ========================================================== */

function today_punches(int $userId): array
{
    return fetch_all(
        "SELECT * FROM attendance WHERE user_id = ? AND day_date = CURDATE() ORDER BY id",
        [$userId]
    );
}

/**
 * Vérifie si le type de pointage est autorisé.
 * Retour : [allowed: bool, must_be: 'IN'|'OUT'|null, message: string]
 */
function clock_check(int $userId): array
{
    $punches = today_punches($userId);
    if (!$punches) {
        return [true, 'IN', t('att.must_in_first')];
    }
    $last = end($punches);
    if ($last['clock_type'] === 'IN') {
        return [true, 'OUT', t('att.already_in')];
    }

    // Une sortie clôt definitively la journée. Une première sortie laisse
    // toutefois la place à la reprise après une pause (ENTRÉE 2).
    $inCount = count(array_filter($punches, fn($p) => $p['clock_type'] === 'IN'));
    $outCount = count(array_filter($punches, fn($p) => $p['clock_type'] === 'OUT'));
    if ($outCount >= 2) {
        return [false, 'IN', t('att.closed_for_today')];
    }

    return [true, 'IN', t('att.can_enter')];
}

/** Enregistre un pointage (heure exacte du serveur). */
function clock_in_out(int $userId, string $type): array
{
    if (!in_array($type, ['IN', 'OUT'], true)) {
        return [false, t('error.invalid_input')];
    }
    $check = clock_check($userId);
    if (!$check[0] || $check[1] !== $type) {
        return [false, $check[2]];
    }
    $u = fetch_one("SELECT * FROM users WHERE id = ?", [$userId]);
    if (!$u) {
        return [false, t('error.not_found')];
    }
    $now = date('Y-m-d H:i:s');
    execute(
        "INSERT INTO attendance (user_id, mechanic_id, clock_type, clocked_at, day_date) VALUES (?, ?, ?, ?, ?)",
        [$userId, $u['mechanic_id'] ?? null, $type, $now, date('Y-m-d')]
    );
    log_activity($type === 'IN' ? 'clock_in' : 'clock_out', 'attendance', null,
        t('log.clocked', $type === 'IN' ? t('att.entry') : t('att.exit'), date('H:i:s')));
    return [true, $type === 'IN' ? t('flash.clocked_in', date('H:i')) : t('flash.clocked_out', date('H:i'))];
}

/**
 * Calcule le temps travaillé d'une journée à partir des pointages.
 * Retour : [worked, break_time, delay, incomplete, first_in, last_out, n_in, n_out]
 */
function compute_day(array $punches, string $dayDate, array $s): array
{
    $worked = 0;
    $breaks = 0;
    $incomplete = 0;
    $inAt = null;
    $prevOut = null;

    foreach ($punches as $p) {
        if ($p['clock_type'] === 'IN') {
            if ($inAt === null) {
                $inAt = $p['clocked_at'];
            }
        } else {
            if ($inAt !== null) {
                $worked += max(0, strtotime($p['clocked_at']) - strtotime($inAt));
                $inAt = null;
            }
            $prevOut = $p['clocked_at'];
        }
    }
    if ($inAt !== null) {
        // entrée non clôturée : comptée jusqu'à maintenant
        $worked += max(0, time() - strtotime($inAt));
        $incomplete = 1;
    }
    // pauses : intervalles entre une sortie et l'entrée suivante
    $prevOutAt = null;
    foreach ($punches as $p) {
        if ($p['clock_type'] === 'OUT') {
            $prevOutAt = strtotime($p['clocked_at']);
        } elseif ($prevOutAt !== null) {
            $gap = strtotime($p['clocked_at']) - $prevOutAt;
            if ($gap >= $s['break_min'] * 60) {
                $breaks += $gap;
            }
            $prevOutAt = null;
        }
    }
    // $punches peut être VIDE (jour sans pointage) : ne jamais indexer [0] sans contrôle.
    $firstIn = (isset($punches[0]) && $punches[0]['clock_type'] === 'IN') ? $punches[0]['clocked_at'] : null;
    $delay = 0;
    if ($firstIn !== null) {
        $delay = max(0, strtotime($firstIn) - strtotime($dayDate . ' ' . $s['expected_in']));
    }
    $nIn = count(array_filter($punches, fn($p) => $p['clock_type'] === 'IN'));
    $nOut = count(array_filter($punches, fn($p) => $p['clock_type'] === 'OUT'));
    $overtime = max(0, $worked - (int) round($s['normal_hours'] * 3600));

    return [
        'worked'     => $worked,
        'break_time' => $breaks,
        'delay'      => $delay,
        'overtime'   => $overtime,
        'incomplete' => $incomplete,
        'first_in'   => $firstIn,
        'n_in'       => $nIn,
        'n_out'      => $nOut,
    ];
}

/** Pointages d'un utilisateur sur une période, regroupés par jour. */
function attendance_by_day(int $userId, string $from, string $to): array
{
    $rows = fetch_all(
        "SELECT * FROM attendance WHERE user_id = ? AND day_date BETWEEN ? AND ? ORDER BY id",
        [$userId, $from, $to]
    );
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['day_date']][] = $r;
    }
    return $grouped;
}

/**
 * Présence actuelle d'un utilisateur.
 * Retour : [status: 'present'|'break'|'absent', since: ?string, delay: int, worked: int]
 */
function presence_status(int $userId): array
{
    $s = att_settings();
    $punches = today_punches($userId);
    if (!$punches) {
        return ['status' => 'absent', 'since' => null, 'delay' => 0, 'worked' => 0];
    }
    $day = compute_day($punches, date('Y-m-d'), $s);
    $last = end($punches);
    $outCount = count(array_filter($punches, fn($p) => $p['clock_type'] === 'OUT'));
    if ($last['clock_type'] === 'OUT') {
        // La première sortie ouvre une pause ; la deuxième ferme la journée.
        return [
            'status' => $outCount === 1 ? 'break' : 'absent',
            'since' => $last['clocked_at'],
            'delay' => $day['delay'],
            'worked' => $day['worked'],
        ];
    }
    // Une entrée, même après une pause, remet immédiatement en présence.
    return [
        'status' => 'present',
        'since' => $last['clocked_at'],
        'delay' => $day['delay'],
        'worked' => $day['worked'],
    ];
}

/** Lundi de la semaine contenant $date (par défaut aujourd'hui). */
function monday_of(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    $day = (int) date('N', $ts); // 1 = lundi
    return date('Y-m-d', $ts - ($day - 1) * 86400);
}

function week_days(string $monday): array
{
    $out = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($monday . " +$i days"));
        $out[] = $d;
    }
    return $out;
}

/* ---------- Détection des anomalies ---------- */
/**
 * Détecte les anomalies de pointage sur une période.
 * Retour : liste de [user_id, mechanic_name, date, type, detail]
 */
function detect_anomalies(string $from, string $to): array
{
    $anoms = [];
    $s = att_settings();
    $users = fetch_all("SELECT * FROM users WHERE role = 'mechanic' AND is_active = 1");
    $names = [];
    foreach (fetch_all("SELECT id, full_name FROM mechanics") as $m) {
        $names[(int) $m['id']] = $m['full_name'];
    }

    foreach ($users as $u) {
        $byDay = attendance_by_day((int) $u['id'], $from, $to);
        foreach ($byDay as $day => $punches) {
            $types = array_column($punches, 'clock_type');
            // sortie sans entrée (début par OUT)
            if ($types && $types[0] === 'OUT') {
                $anoms[] = ['user_id' => (int) $u['id'], 'mech' => $names[$u['mechanic_id']] ?? $u['full_name'], 'date' => $day, 'type' => 'out_no_in', 'detail' => $punches[0]['clocked_at']];
            }
            // entrées / sorties consécutives
            for ($i = 1; $i < count($types); $i++) {
                if ($types[$i] === $types[$i - 1]) {
                    $anoms[] = [
                        'user_id' => (int) $u['id'],
                        'mech' => $names[$u['mechanic_id']] ?? $u['full_name'],
                        'date' => $day,
                        'type' => $types[$i] === 'IN' ? 'double_in' : 'double_out',
                        'detail' => $punches[$i]['clocked_at'],
                    ];
                }
            }
            // entrée sans sortie (se termine par IN)
            if ($types && end($types) === 'IN') {
                $anoms[] = ['user_id' => (int) $u['id'], 'mech' => $names[$u['mechanic_id']] ?? $u['full_name'], 'date' => $day, 'type' => 'in_no_out', 'detail' => end($punches)['clocked_at']];
            }
        }
        // journée incomplète : IN + OUT mais total très faible
        foreach ($byDay as $day => $punches) {
            $d = compute_day($punches, $day, $s);
            if ($d['n_in'] >= 1 && $d['n_out'] >= 1 && $d['worked'] > 0 && $d['worked'] < 3600) {
                $anoms[] = ['user_id' => (int) $u['id'], 'mech' => $names[$u['mechanic_id']] ?? $u['full_name'], 'date' => $day, 'type' => 'incomplete', 'detail' => fmt_duration($d['worked'])];
            }
        }
    }

    // pointage oublié : jour ouvré (lun-sam) passé, aucun pointage
    $today = date('Y-m-d');
    $cursor = strtotime($from);
    $end = strtotime($to);
    $minDate = date('Y-m-d', strtotime('-14 days'));
    while ($cursor <= $end) {
        $day = date('Y-m-d', $cursor);
        $dow = (int) date('N', $cursor);
        if ($day < $minDate || $day >= $today || $dow > 6) {
            $cursor = strtotime('+1 day', $cursor);
            continue;
        }
        foreach ($users as $u) {
            $n = fetch_one("SELECT COUNT(*) c FROM attendance WHERE user_id = ? AND day_date = ?", [$u['id'], $day]);
            if ((int) $n['c'] === 0) {
                $anoms[] = ['user_id' => (int) $u['id'], 'mech' => $names[$u['mechanic_id']] ?? $u['full_name'], 'date' => $day, 'type' => 'forgot', 'detail' => ''];
            }
        }
        $cursor = strtotime('+1 day', $cursor);
    }

    usort($anoms, fn($a, $b) => strcmp($b['date'], $a['date']));
    return $anoms;
}

/* ==========================================================
 * 10. DEVIS / FACTURES
 * ========================================================== */

/** Prochain numéro de document (DEV-2026-0001 / FAC-2026-0001). */
function next_doc_number(string $type): string
{
    $prefix = $type === 'devis' ? 'DEV' : 'FAC';
    $table = $type === 'devis' ? 'devis' : 'factures';
    $col = $type === 'devis' ? 'quote_number' : 'invoice_number';
    $year = date('Y');
    $row = fetch_one(
        "SELECT MAX(CAST(SUBSTRING_INDEX($col, '-', -1) AS UNSIGNED)) AS n FROM $table WHERE $col LIKE ?",
        [$prefix . '-' . $year . '-%']
    );
    $n = (int) ($row['n'] ?? 0) + 1;
    return sprintf('%s-%d-%04d', $prefix, (int) $year, $n);
}

/** Valide le format et l'unicité d'un numéro de document. */
function validate_doc_number(string $type, string $num): bool
{
    $prefix = $type === 'devis' ? 'DEV' : 'FAC';
    $table = $type === 'devis' ? 'devis' : 'factures';
    if (!preg_match('/^' . $prefix . '-\d{4}-\d{4}$/', $num)) {
        return false;
    }
    $col = $type === 'devis' ? 'quote_number' : 'invoice_number';
    $row = fetch_one("SELECT COUNT(*) c FROM $table WHERE $col = ?", [$num]);
    return (int) $row['c'] === 0;
}

/**
 * Calcule les totaux d'un document à partir de ses lignes.
 * items: [desc, qty, price, tva_rate]
 * Retour : [ht, tva, ttc, lines: [[line_ht, line_tva]]]
 */
function calc_doc_totals(array $items, bool $useDiscount = true): array
{
    $ht = 0.0;
    $discount = 0.0;
    $tva = 0.0;
    $lines = [];
    foreach ($items as $it) {
        $qty = (float) ($it['quantity'] ?? 1);
        $price = (float) ($it['unit_price'] ?? 0);
        $disc = $useDiscount ? max(0, min(100, (float) ($it['discount_pct'] ?? 0))) : 0;
        $rate = max(0, (float) ($it['tva_rate'] ?? 0));
        $brut = $qty * $price;
        $d = $brut * $disc / 100;
        $lineHt = $brut - $d;
        $lineTva = $lineHt * $rate / 100;
        $ht += $lineHt;
        $discount += $d;
        $tva += $lineTva;
        $lines[] = [round($lineHt, 2), round($lineTva, 2)];
    }
    return [
        'ht' => round($ht, 2),
        'discount' => round($discount, 2),
        'tva' => round($tva, 2),
        'ttc' => round($ht + $tva, 2),
        'lines' => $lines,
    ];
}

function read_doc_items(array $post, string $prefix): array
{
    $desc = array_values((array) ($post[$prefix . '_desc'] ?? []));
    $qty = array_values((array) ($post[$prefix . '_qty'] ?? []));
    $price = array_values((array) ($post[$prefix . '_price'] ?? []));
    $disc = array_values((array) ($post[$prefix . '_discount'] ?? []));
    $tva = array_values((array) ($post[$prefix . '_tva'] ?? []));
    $items = [];
    for ($i = 0; $i < max(count($desc), count($qty), count($price)); $i++) {
        $d = trim((string) ($desc[$i] ?? ''));
        if ($d === '' && (float) ($price[$i] ?? 0) == 0.0) {
            continue; // ligne vide
        }
        $items[] = [
            'description'  => $d !== '' ? $d : t('doc.line_default_desc'),
            'quantity'     => max(0, (float) ($qty[$i] ?? 1)),
            'unit_price'   => max(0, (float) ($price[$i] ?? 0)),
            'discount_pct' => max(0, min(100, (float) ($disc[$i] ?? 0))),
            'tva_rate'     => max(0, min(100, (float) ($tva[$i] ?? (float) get_setting('default_tva', (string) DEFAULT_TVA)))),
        ];
    }
    return $items;
}

/**
 * Enregistre les lignes d'un devis/facture et recalcul les totaux.
 * $table : 'devis' ou 'facture'
 */
function save_doc_items(string $table, int $docId, array $items): array
{
    $itemsTable = $table . '_items';
    $fk = $table . 'id';
    // la table réelle : devis_items(fk devis_id) / facture_items(fk facture_id)
    $fkCol = $table === 'devis' ? 'devis_id' : 'facture_id';
    $useDiscount = false;
    $tot = calc_doc_totals($items, $useDiscount);
    foreach ($items as $i => $it) {
        [$lineHt, $lineTva] = $tot['lines'][$i];
        $discountValue = $useDiscount ? $it['discount_pct'] : 0;
        execute(
            "INSERT INTO $itemsTable ($fkCol, position, description, quantity, unit_price, discount_pct, tva_rate, line_total, line_tva)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $docId,
                $i + 1,
                mb_substr($it['description'], 0, 255),
                $it['quantity'],
                $it['unit_price'],
                $discountValue,
                $it['tva_rate'],
                $lineHt,
                $lineTva,
            ]
        );
    }
    $totalsCol = $table === 'devis' ? 'devis' : 'factures';
    execute(
        "UPDATE $totalsCol SET total_ht = ?, total_discount = ?, total_tva = ?, total_ttc = ? WHERE id = ?",
        [$tot['ht'], $useDiscount ? $tot['discount'] : 0, $tot['tva'], $tot['ttc'], $docId]
    );
    return $tot;
}

function fetch_doc_items(string $table, int $docId): array
{
    $itemsTable = $table . '_items';
    $fkCol = $table === 'devis' ? 'devis_id' : 'facture_id';
    return fetch_all("SELECT * FROM $itemsTable WHERE $fkCol = ? ORDER BY position", [$docId]);
}

function doc_status_badge(string $type, string $status): string
{
    $map = [
        'brouillon'   => 'secondary',
        'envoye'      => 'info',
        'envoyee'     => 'info',
        'accepte'     => 'success',
        'refuse'      => 'danger',
        'facture'     => 'primary',
        'payee'       => 'success',
        'partiellement_payee' => 'warning',
        'annulee'     => 'danger',
    ];
    $key = $type === 'devis' ? 'devis.' : 'facture.';
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">'
         . e(t($key . $status)) . '</span>';
}

/** Liste des utilisateurs mécaniciens actifs (pour sélecteurs). */
function active_mechanic_users(): array
{
    return fetch_all(
        "SELECT u.id, u.full_name, u.mechanic_id, m.full_name AS mech_name, m.specialty
         FROM users u LEFT JOIN mechanics m ON m.id = u.mechanic_id
         WHERE u.role = 'mechanic' AND u.is_active = 1 ORDER BY m.full_name"
    );
}
