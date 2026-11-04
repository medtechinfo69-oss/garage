<?php
/**
 * Profilage rapide : exécute une page en CLI et affiche le nombre de requêtes SQL
 * et le temps de rendu. Usage : php tools/query_count.php admin/dashboard.php [user_id]
 */
$page = $argv[1] ?? 'admin/dashboard.php';
$userId = (int) ($argv[2] ?? 1);
$root = dirname(__DIR__);
chdir($root);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/garage1/' . $page;
$_SERVER['SCRIPT_NAME'] = '/garage1/' . $page;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTP_USER_AGENT'] = 'cli-profiler';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once $root . '/includes/auth.php';
$_SESSION['user_id'] = $userId;
$_SESSION['security_fingerprint'] = current_session_fingerprint();
$_SESSION['last_activity'] = time();

$GLOBALS['qc'] = 0;
$GLOBALS['ql'] = [];

class ProfilingStatement extends PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        $GLOBALS['qc']++;
        $GLOBALS['ql'][] = preg_replace('/\s+/', ' ', substr($this->queryString, 0, 100));
        return parent::execute($params);
    }
}

db()->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['ProfilingStatement', []]);

$t0 = microtime(true);
ob_start();
try {
    require $root . '/' . $page;
} catch (Throwable $e) {
    $err = $e->getMessage();
}
$html = ob_get_clean();
$ms = round((microtime(true) - $t0) * 1000);

echo "== $page (user $userId) ==" . PHP_EOL;
echo "Temps     : {$ms} ms" . PHP_EOL;
echo "Sortie    : " . strlen($html) . " octets" . PHP_EOL;
echo "Requêtes  : {$GLOBALS['qc']}" . PHP_EOL;
if (isset($err)) echo "ERREUR    : $err" . PHP_EOL;

$counts = array_count_values($GLOBALS['ql']);
arsort($counts);
$i = 0;
foreach ($counts as $sql => $n) {
    if (++$i > 8) break;
    echo str_pad((string) $n, 4, ' ', STR_PAD_LEFT) . "x  " . $sql . PHP_EOL;
}
