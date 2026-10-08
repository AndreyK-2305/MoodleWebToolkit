<?php

$scenario = $argv[1] ?? '';
$pieces = match ($scenario) {
    'split-key' => [['pass', ''], ['word=private-value\n', '']],
    'split-value' => [['password=', ''], ['private-value\n', '']],
    'authorization' => [['Authori', ''], ['zation: Bearer private-value\n', '']],
    'url' => [['https://user:', ''], ['private-value@example.test/path\n', '']],
    'pem' => [['-----BE', ''], ['GIN PRIVATE KEY-----\n', ''], ['private-', ''], ['value\n', ''], ['-----END PRI', ''], ['VATE KEY-----\n', '']],
    'independent' => [['pass', 'Authori'], ['word=private-', 'zation: Bearer private-'], ['value\n', 'value\n']],
    'pending' => [['pass', ''], ['word=private-value', '']],
    'json' => [['{"client_', ''], ['secret":', ''], ['"private-value"}\n', '']],
    'cookie' => [['Set-Co', ''], ['okie: session=private-value; HttpOnly\n', '']],
    'unterminated-pem' => [['-----BEGIN PRIVATE KEY-----\n', ''], ['private-value', '']],
    'ambiguous-quoted' => [['password="\n', ''], ['private-value"\nvisible\n', '']],
    'ambiguous-structured' => [['credentials={\n', ''], ['"nested":"private-value"\n}\nvisible\n', '']],
    'ambiguous-empty' => [['password=\n\n', ''], ['private-value\nvisible\n', '']],
    'secret-failure' => [['pass', 'Authori'], ['word=private-value', 'zation: Bearer private-value']],
    default => [],
};
if ($scenario === 'unframed') {
    fwrite(STDOUT, 'password=');
    for ($index = 0; $index < 1000; $index++) {
        fwrite(STDOUT, str_repeat('private-value', 10));
    }
    exit(0);
}
if (in_array($scenario, ['limit-below', 'limit-equal', 'limit-over'], true)) {
    $size = match ($scenario) {
        'limit-below' => 1023, 'limit-equal' => 1024, default => 1025
    };
    fwrite(STDOUT, str_repeat('x', $size - 1)."\n");
    exit(0);
}
$held = in_array($scenario, ['held-after-limit', 'held-fragment'], true);
$marker = getenv('TMPDIR').'/ready-'.getenv('MOODLE_OPERATION_ID');
$release = getenv('TMPDIR').'/release-'.getenv('MOODLE_OPERATION_ID');
if ($scenario === 'held-fragment') {
    fwrite(STDOUT, 'pass');
    fwrite(STDERR, 'Authori');
    file_put_contents($marker, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($release) && microtime(true) < $deadline) {
        clearstatcache(true, $release);
        usleep(25_000);
    }
    if (! is_file($release)) {
        exit(3);
    }
    fwrite(STDOUT, "word=private-value\n");
    fwrite(STDERR, "zation: Bearer private-value\n");
}
if (in_array($scenario, ['burst', 'burst-both', 'held-after-limit', 'held-fragment'], true)) {
    $count = $scenario === 'burst' ? 8 : 256;
    for ($index = 0; $index < $count; $index++) {
        fwrite(STDOUT, str_repeat('x', 1023)."\n");
        if ($scenario !== 'burst') {
            fwrite(STDERR, str_repeat('y', 1023)."\n");
        }
    }
    if ($held && $scenario !== 'held-fragment') {
        file_put_contents($marker, 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($release) && microtime(true) < $deadline) {
            clearstatcache(true, $release);
            usleep(25_000);
        }
        if (! is_file($release)) {
            exit(3);
        }
    }
    file_put_contents(getenv('TMPDIR').'/completed-'.getenv('MOODLE_OPERATION_ID'), 'done');
    exit(0);
}
foreach ($pieces as [$stdout, $stderr]) {
    fwrite(STDOUT, str_replace('\\n', "\n", $stdout));
    fwrite(STDERR, str_replace('\\n', "\n", $stderr));
    fflush(STDOUT);
    fflush(STDERR);
    usleep(150_000);
}
exit($pieces === [] ? 2 : ($scenario === 'secret-failure' ? 1 : 0));
