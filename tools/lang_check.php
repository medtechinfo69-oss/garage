<?php
/**
 * Vérifie que chaque clé de traduction utilisée dans le code existe dans fr.php et en.php.
 * Usage : php tools/lang_check.php
 */
$root = dirname(__DIR__);
$fr = require $root . '/includes/lang/fr.php';
$en = require $root . '/includes/lang/en.php';

$used = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    $path = $file->getPathname();
    if (!preg_match('/\.php$/', $path))
        continue;
    if (str_contains($path, '\\includes\\lang\\') || str_contains($path, '/includes/lang/'))
        continue;
    $src = (string) file_get_contents($path);
    if (preg_match_all("/\\bt\\(\\s*'([a-z0-9_.]+)'/i", $src, $m)) {
        foreach ($m[1] as $k)
            $used[$k] = true;
    }
}

$short = str_replace($root . DIRECTORY_SEPARATOR, '', __FILE__);
echo "Clés utilisées : " . count($used) . " | fr: " . count($fr) . " | en: " . count($en) . PHP_EOL;

$missingFr = $missingEn = [];
foreach (array_keys($used) as $k) {
    if (!array_key_exists($k, $fr))
        $missingFr[] = $k;
    if (!array_key_exists($k, $en))
        $missingEn[] = $k;
}
sort($missingFr);
sort($missingEn);
echo "Manquantes FR (" . count($missingFr) . "): " . implode(', ', $missingFr) . PHP_EOL;
echo "Manquantes EN (" . count($missingEn) . "): " . implode(', ', $missingEn) . PHP_EOL;

$unusedFr = array_values(array_diff(array_keys($fr), array_keys($used)));
$unusedEn = array_values(array_diff(array_keys($en), array_keys($used)));
// Une clé peut être utilisée dynamiquement (ex. t('status.' . $s)) : on ne signale
// comme orpheline qu'une clé dont la FAMILLE entière n'apparaît nulle part.
$strip = static function (array $keys) use ($used): array {
    $out = [];
    foreach ($keys as $k) {
        $pos = strrpos($k, '.');
        $prefix = $pos === false ? '' : substr($k, 0, $pos + 1);
        foreach (array_keys($used) as $u) {
            if ($prefix !== '' && str_starts_with($u, $prefix)) continue 2;
        }
        $out[] = $k;
    }
    return $out;
};
$unusedFr = $strip($unusedFr);
$unusedEn = $strip($unusedEn);
sort($unusedFr);
sort($unusedEn);
echo "Non utilisées FR (" . count($unusedFr) . "): " . implode(', ', $unusedFr) . PHP_EOL;
echo "Non utilisées EN (" . count($unusedEn) . "): " . implode(', ', $unusedEn) . PHP_EOL;
