<?php
declare(strict_types=1);

$path = '/var/www/html/config.php';
$wwwroot = trim((string)getenv('MOODLE_WWWROOT'));
$reverseproxy = (string)getenv('MOODLE_REVERSE_PROXY') === '1';
$sslproxy = (string)getenv('MOODLE_SSL_PROXY') === '1';

if (!is_file($path) || !preg_match('~^https?://[^\s/]+(?:/.*)?$~', $wwwroot)) {
    fwrite(STDERR, "Configuración de runtime inválida.\n");
    exit(1);
}

$content = (string)file_get_contents($path);
$content = preg_replace(
    '~^\$CFG->wwwroot\s*=\s*.*?;\s*$~m',
    '$CFG->wwwroot = ' . var_export(rtrim($wwwroot, '/'), true) . ';',
    $content,
    1,
    $wwwrootReplacements
);
if ($wwwrootReplacements !== 1) {
    fwrite(STDERR, "No se encontró CFG->wwwroot en config.php.\n");
    exit(1);
}

$content = preg_replace(
    '~(?:\r?\n)+// BEGIN MOODLE_CONSOLIDATION_RUNTIME.*?// END MOODLE_CONSOLIDATION_RUNTIME\r?\n~s',
    "\n",
    $content
);
$block = "\n// BEGIN MOODLE_CONSOLIDATION_RUNTIME\n" .
    '$CFG->reverseproxy = ' . ($reverseproxy ? 'true' : 'false') . ";\n" .
    '$CFG->sslproxy = ' . ($sslproxy ? 'true' : 'false') . ";\n" .
    '$managedconfig = __DIR__ . \'/.managed-config.php\';' . "\n" .
    'if (!is_readable($managedconfig)) {' . "\n" .
    "    throw new RuntimeException('Falta la configuración administrada.');\n" .
    '}' . "\n" .
    'require($managedconfig);' . "\n" .
    "// END MOODLE_CONSOLIDATION_RUNTIME\n";
$needle = "require_once(__DIR__ . '/lib/setup.php');";
$position = strpos($content, $needle);
if ($position === false) {
    fwrite(STDERR, "No se encontró la carga de lib/setup.php.\n");
    exit(1);
}
$content = substr($content, 0, $position) . $block .
    substr($content, $position);

$temporary = $path . '.runtime.partial';
if (file_put_contents($temporary, $content, LOCK_EX) === false ||
        !rename($temporary, $path)) {
    fwrite(STDERR, "No fue posible actualizar config.php.\n");
    exit(1);
}
chmod($path, 0640);
