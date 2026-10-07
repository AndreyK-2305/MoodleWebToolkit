<?php
declare(strict_types=1);

/** Coherencia de la URL pública antes de materializar identidades. */
function oauth2_public_issues(
    string $wwwroot,
    string $expected,
    bool $reverseproxy,
    bool $sslproxy,
    string $callback
): array {
    $issues = [];
    $public = parse_url($wwwroot);
    $configured = parse_url($expected);
    if ($public === false || $configured === false ||
            ($public['scheme'] ?? '') !== 'https' ||
            ($configured['scheme'] ?? '') !== 'https' ||
            empty($public['host']) || empty($configured['host']) ||
            isset($public['user']) || isset($public['query']) || isset($public['fragment']) ||
            isset($configured['user']) || isset($configured['query']) ||
            isset($configured['fragment'])) {
        $issues[] = 'wwwroot y la URL pública esperada deben ser HTTPS sin credenciales, query ni fragment.';
    }
    if (rtrim($wwwroot, '/') !== rtrim($expected, '/')) {
        $issues[] = 'wwwroot/hostname público difiere de target.url.';
    }
    if (!$reverseproxy) {
        $issues[] = 'CFG->reverseproxy debe estar habilitado.';
    }
    if (!$sslproxy) {
        $issues[] = 'CFG->sslproxy debe estar habilitado.';
    }
    if ($callback !== rtrim($expected, '/') . '/admin/oauth2callback.php') {
        $issues[] = 'El callback OAuth no coincide con la URL pública esperada.';
    }
    return $issues;
}
