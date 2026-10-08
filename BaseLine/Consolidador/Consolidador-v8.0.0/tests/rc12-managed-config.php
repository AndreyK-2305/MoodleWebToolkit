<?php
declare(strict_types=1);

function rc12_config_run(string $mode, string $file, array $options = []): array {
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../docker/managed-config.php') .
        ' ' . $mode . ' --input=' . escapeshellarg($file);
    foreach ($options as $name => $value) {
        $cmd .= ' --' . $name . '=' . escapeshellarg((string)$value);
    }
    $cmd .= ' 2>&1';
    $out = [];
    exec($cmd, $out, $exit);
    return [$exit, implode("\n", $out)];
}
function rc12_config_assert(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('RC12_MANAGED_CONFIG_FAILED ' . $label); }
}
$path = tempnam(sys_get_temp_dir(), 'rc12-config-');
try {
    file_put_contents($path, json_encode(['schema_version' => '1.0',
        'settings' => (object)['theme' => 'classic', 'allowaccountssameemail' => 0]],
        JSON_THROW_ON_ERROR));
    [$code, $message] = rc12_config_run('validate', $path);
    rc12_config_assert($code !== 0 && str_contains($message, 'SHARED_EMAIL_TARGET_POLICY_BLOCKED'),
        'rechaza desactivación');
    [$code, $migrated] = rc12_config_run('ensure-shared-email', $path);
    $document = json_decode($migrated, true, 512, JSON_THROW_ON_ERROR);
    rc12_config_assert($code === 0 && $document['settings']['allowaccountssameemail'] === 1 &&
        $document['settings']['theme'] === 'classic', 'migra y conserva otros ajustes');
    file_put_contents($path, $migrated . "\n");
    [$code, $identical] = rc12_config_run('ensure-shared-email', $path);
    rc12_config_assert($code === 0 && $identical . "\n" === file_get_contents($path),
        'reintento sin cambiar bytes');
    [$code, $compiled] = rc12_config_run('compile', $path);
    rc12_config_assert($code === 0 && str_contains($compiled, '$CFG->allowaccountssameemail = 1;') &&
        str_contains($compiled, '$CFG->theme ='), 'compilación explícita');
    [$code, $themePolicy] = rc12_config_run('ensure-theme-policy', $path, [
        'theme'=>'academi', 'allow-course-themes'=>'1',
    ]);
    $themeDocument = json_decode($themePolicy, true, 512, JSON_THROW_ON_ERROR);
    rc12_config_assert($code === 0 &&
        $themeDocument['settings']['theme'] === 'academi' &&
        $themeDocument['settings']['allowcoursethemes'] === 1 &&
        $themeDocument['settings']['allowaccountssameemail'] === 1,
        'política de themes atómica conserva otros ajustes');
    file_put_contents($path, $themePolicy . "\n");
    [$code, $themeIdentical] = rc12_config_run('ensure-theme-policy', $path, [
        'theme'=>'academi', 'allow-course-themes'=>'1',
    ]);
    rc12_config_assert($code === 0 && $themeIdentical . "\n" === file_get_contents($path),
        'política de themes idempotente');
    [$code, $message] = rc12_config_run('ensure-theme-policy', $path, [
        'theme'=>'../../academi', 'allow-course-themes'=>'1',
    ]);
    rc12_config_assert($code !== 0 && str_contains($message, 'THEME_POLICY_INVALID'),
        'rechaza theme inseguro');
    file_put_contents($path, json_encode(['schema_version' => '1.0', 'settings' => (object)[]]));
    [$code, $migrated] = rc12_config_run('ensure-shared-email', $path);
    rc12_config_assert($code === 0 && json_decode($migrated, true)['settings']['allowaccountssameemail'] === 1,
        'migra RC11 sin ajustes');
    echo "RC12_MANAGED_CONFIG_OK migration=1 retry=idempotent policy=1\n";
} finally {
    unlink($path);
}
