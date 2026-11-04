<?php
/**
 * Liste les fichiers PHP jamais référencés (candidats à la suppression).
 * Usage : php tools/orphans.php
 */
$root = dirname(__DIR__);
$skip = ['/includes/lang/', '/vendor/', '/tools/', '/assets/'];
$files = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!str_ends_with($p, '.php'))
        continue;
    foreach ($skip as $s) {
        if (str_contains($p, $s))
            continue 2;
    }
    $files[] = $p;
}

// Corpus : tous les .php + .htaccess + .js (références éventuelles vers un fichier)
$corpus = '';
foreach ($rii as $f) {
    $p = str_replace('\\', '/', $f->getPathname());
    if (!preg_match('/\.(php|js|css|html|htaccess|json)$/', $p))
        continue;
    if (str_contains($p, '/assets/vendor/'))
        continue;
    $corpus .= file_get_contents($p);
}

$orphans = [];
foreach ($files as $abs) {
    $rel = substr($abs, strlen(str_replace('\\', '/', $root)) + 1);
    $name = basename($rel);
    $dir = dirname($rel);
    // Un fichier est référencé s'il apparaît par son nom (avec ou sans préfixe de dossier)
    if (substr_count($corpus, $name) <= 1) { // 1 = sa propre déclaration n'existe pas -> seuil 1
        $orphans[] = $rel;
    }
}
foreach ($orphans as $o)
    echo $o . PHP_EOL;
echo '-- ' . count($orphans) . ' fichier(s) potentiellement orphelin(s) sur ' . count($files) . PHP_EOL;
