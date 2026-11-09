<?php
/**
 * Vercel Serverless Function Entrypoint / Router for PHP
 */

// Enable output buffering to prevent headers-already-sent issues with session_start
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedPath = parse_url($requestUri, PHP_URL_PATH);

// Clean up URI
$path = trim((string) $parsedPath, '/');

// Block sensitive directories and files (same as .htaccess)
if (
    preg_match('/(^|\/)\./', $path) ||
    preg_match('/^(config|storage|tests)(\/|$)/i', $path) ||
    preg_match('/\.(env|env\..+|sql|log|ini|bak|git|md)$/i', $path)
) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$rootDir = dirname(__DIR__);

// 1. Root / index
if ($path === '' || $path === 'index.php') {
    chdir($rootDir);
    require $rootDir . '/index.php';
    exit;
}

$targetFile = $rootDir . '/' . $path;

// 2. Direct match for PHP file (e.g. /auth/login.php)
if (is_file($targetFile) && pathinfo($targetFile, PATHINFO_EXTENSION) === 'php') {
    chdir(dirname($targetFile));
    require $targetFile;
    exit;
}

// 3. Extensionless URL (e.g. /auth/login -> /auth/login.php)
if (is_file($targetFile . '.php')) {
    chdir(dirname($targetFile));
    require $targetFile . '.php';
    exit;
}

// 4. Directory index (e.g. /admin -> /admin/index.php)
if (is_dir($targetFile) && is_file($targetFile . '/index.php')) {
    chdir($targetFile);
    require $targetFile . '/index.php';
    exit;
}

// 5. Fallback for static files if not matched by Vercel routes
if (is_file($targetFile)) {
    $mime = mime_content_type($targetFile) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($targetFile);
    exit;
}

http_response_code(404);
echo '404 Not Found';
