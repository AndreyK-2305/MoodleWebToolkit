<?php

$scenario = $argv[1] ?? '';
$pieces = match ($scenario) {
    'split-key' => [['pass', ''], ['word=private-value\n', '']],
    'split-value' => [['password=', ''], ['private-value\n', '']],
    'authorization' => [['Authori', ''], ['zation: Bearer private-value\n', '']],
    'url' => [['https://user:', ''], ['private-value@example.test/path\n', '']],
    'pem' => [['-----BE', ''], ['GIN PRIVATE KEY-----\n', ''], ['private-', ''], ['value\n', ''], ['-----END PRI', ''], ['VATE KEY-----\n', '']],
    'independent' => [['pass', 'Authori'], ['word=private-', 'zation: Bearer private-'], ['value\n', 'value\n']],
    default => [],
};
if ($scenario === 'burst') {
    for ($index = 0; $index < 8; $index++) {
        fwrite(STDOUT, str_repeat('x', 1023)."\n");
    }
    exit(0);
}
foreach ($pieces as [$stdout, $stderr]) {
    fwrite(STDOUT, str_replace('\\n', "\n", $stdout));
    fwrite(STDERR, str_replace('\\n', "\n", $stderr));
    fflush(STDOUT);
    fflush(STDERR);
    usleep(150_000);
}
exit($pieces === [] ? 2 : 0);
