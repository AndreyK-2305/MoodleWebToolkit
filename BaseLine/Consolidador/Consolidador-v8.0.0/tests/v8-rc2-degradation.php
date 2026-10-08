<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return strtolower($value); }
    public static function strlen(string $value): int { return strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-backup-users.php');
require_once(__DIR__ . '/../scripts/phase6-effective-inventory.php');
require_once(__DIR__ . '/../scripts/phase6-degradation.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');

function rc2d_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC2_DEGRADATION_FAILED ' . $message);
    }
}
function rc2d_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) { rc2d_remove($item->getPathname()); }
    rmdir($path);
}
function rc2d_block(callable $action, string $needle): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc2d_check(str_contains($error->getMessage(), $needle),
            $needle . ' produjo: ' . $error->getMessage());
    }
}
function rc2d_command(array $command): void {
    $process = proc_open($command, [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('No inició fixture tar.'); }
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    rc2d_check($exit === 0, 'fixture tar: ' . $stdout . $stderr);
}

$root = sys_get_temp_dir() . '/v8-rc2-degradation-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    $missinghash = str_repeat('a', 40);
    $presenthash = sha1('present');
    $filesxml = '<files>' .
        '<file id="10"><contextid>501</contextid><component>mod_label</component>' .
        '<filearea>intro</filearea><filename>image.png</filename>' .
        '<filesize>10</filesize><contenthash>' . $missinghash . '</contenthash>' .
        '<repositorytype>$@NULL@$</repositorytype><reference>$@NULL@$</reference></file>' .
        '<file id="11"><contextid>502</contextid><component>assignsubmission_file</component>' .
        '<filearea>submission_files</filearea><filename>tarea 1.docx</filename>' .
        '<filesize>7</filesize><contenthash>' . $presenthash . '</contenthash>' .
        '<repositorytype>$@NULL@$</repositorytype><reference>$@NULL@$</reference></file>' .
        '<file id="12"><contextid>1</contextid><component>url</component>' .
        '<filearea>content</filearea><filename>external.url</filename>' .
        '<filesize>1</filesize><contenthash>' . str_repeat('b', 40) . '</contenthash>' .
        '<repositorytype>url</repositorytype><reference>https://example.test</reference></file>' .
        '</files>';
    $members = [
        'files.xml' => ['name'=>'files.xml','size'=>strlen($filesxml)],
        'files/' . substr($presenthash, 0, 2) . '/' . $presenthash =>
            ['name'=>'files/' . substr($presenthash, 0, 2) . '/' . $presenthash,'size'=>7],
    ];
    $items = p6_degradation_file_items(
        $filesxml,
        $members,
        'COURSE-PREGRADO-ABCDEF123456',
        'pregrado',
        ['integrity_policy' => 'known_degraded']
    );
    rc2d_check(count($items) === 1 && $items[0]['file_id'] === 10 &&
        $items[0]['action'] === 'omit_known_missing' && !$items[0]['blocking'],
        'missing payload conocido no fue clasificado con evidencia');

    $work = $root . '/apply';
    mkdir($work, 0770, true);
    file_put_contents($work . '/files.xml', $filesxml);
    $plan = [
        'schema_version'=>'1.1', 'phase'=>'6-course-degradation-plan',
        'analyzer_version'=>P6_DEGRADATION_ANALYZER_VERSION,
        'status'=>'warnings_detected', 'blocking_errors'=>0,
        'items'=>$items,
    ];
    $applied = p6_apply_degradation_plan_to_extracted_backup($work, $plan);
    $after = (string)file_get_contents($work . '/files.xml');
    rc2d_check($applied['omitted_file_ids'] === [10] &&
        !str_contains($after, 'id="10"') && str_contains($after, 'id="11"'),
        'Fase 13 no retiró únicamente el file_id aprobado');
    $blockedplan = $plan;
    $blockedplan['status'] = 'blocking';
    $blockedplan['blocking_errors'] = 1;
    rc2d_block(static fn() =>
        p6_apply_degradation_plan_to_extracted_backup($work, $blockedplan),
        'DEGRADATION_PLAN_NOT_APPROVED');

    $modules = [
        1 => ['module_key'=>'forum|one','modname'=>'forum'],
        2 => ['module_key'=>'forum|two','modname'=>'forum'],
    ];
    $historical = [
        ['activity_key'=>'forum|legacy','source_user_id'=>7,'subject'=>'A'],
        ['activity_key'=>'forum|legacy','source_user_id'=>8,'subject'=>'B'],
    ];
    $structural = [
        ['_source_module_id'=>1,'activity_key'=>'forum|one','source_user_id'=>7,'subject'=>'A'],
    ];
    rc2d_block(static function() use ($historical, $structural, $modules): void {
        $evidence = ['structural_projection'=>0,'structural_projection_relations'=>[],
            'aliases_blocked'=>0,'blocked'=>[]];
        p6_structural_relation_projection(
            $historical, 'forum_discussions', $structural, $modules, $evidence
        );
    }, 'MODULE_RELATION_STRUCTURAL_MISMATCH');
    $accepted = [
        'items'=>[['type'=>'structural_mismatch','relation'=>'forum_discussions',
            'action'=>'delegate_to_moodle_native_restore','blocking'=>false]],
    ];
    $evidence = ['structural_projection'=>0,'structural_projection_relations'=>[],
        'aliases_blocked'=>0,'blocked'=>[]];
    $projected = p6_structural_relation_projection(
        $historical, 'forum_discussions', $structural, $modules, $evidence, $accepted
    );
    rc2d_check(count($projected) === 1 &&
        $evidence['structural_projection_relations'][0]['status'] === 'warning_accepted',
        'mismatch explícitamente aprobado no quedó como warning auditable');

    $archiveDir = $root . '/archive source';
    mkdir($archiveDir . '/files/' . substr($presenthash, 0, 2), 0770, true);
    file_put_contents($archiveDir . '/files.xml', $filesxml);
    file_put_contents($archiveDir . '/moodle_backup.xml',
        '<moodle_backup><information><original_course_contextid>1</original_course_contextid></information></moodle_backup>');
    mkdir($archiveDir . '/activities', 0770, true);
    file_put_contents($archiveDir . '/activities/index.xml', '<activities/>');
    file_put_contents($archiveDir . '/files/' . substr($presenthash, 0, 2) . '/' .
        $presenthash, 'present');
    $tar = $root . '/curso con espacios.mbz';
    rc2d_command(['tar', '--create', '--file', $tar, '--directory', $archiveDir, '.']);
    $index = p6_degradation_archive_index($tar);
    rc2d_check($index['type'] === 'tar' && isset($index['members']['files.xml']) &&
        p6_degradation_read_member($tar, $index, 'files.xml') === $filesxml,
        'inspección tar selectiva o ruta con espacios falló');

    $inventory = [
        'course'=>[], 'counts'=>['activities'=>0,'enrolments'=>0,
            'course_role_assignments'=>0,'module_files'=>0],
        'modules_by_type'=>[], 'modules'=>[], 'enrolments'=>[], 'roles'=>[],
        'relations'=>[
            'activity_completions'=>[], 'assignment_submissions'=>[],
            'assignment_grades'=>[], 'forum_discussions'=>[], 'forum_posts'=>[],
            'files'=>[], 'course_completions'=>[],
        ],
    ];
    $archiveSha = hash_file('sha256', $tar);
    $pregradoIntegrity = [
        'source_id' => 'pregrado', 'integrity_policy' => 'known_degraded',
        'operator' => 'fixture', 'evidence' => 'fixture evidence',
        'reason' => 'fixture source is known degraded',
    ];
    $built = p6_build_course_degradation_plan(
        $tar, $archiveSha, $inventory, 'pregrado', 77,
        'COURSE-PREGRADO-ABCDEF123456', $root . '/cache', [], $pregradoIntegrity
    );
    rc2d_check($built['status'] === 'warnings_detected' &&
        $built['missing_payloads'] === 1 && $built['blocking_errors'] === 0 &&
        $built['metrics']['cache_hit'] === false,
        'Fase 12 no produjo degradation-plan no bloqueante: ' .
            json_encode($built, JSON_UNESCAPED_UNICODE));
    $builtAgain = p6_build_course_degradation_plan(
        $tar, $archiveSha, $inventory, 'pregrado', 77,
        'COURSE-PREGRADO-ABCDEF123456', $root . '/cache', [], $pregradoIntegrity
    );
    rc2d_check($builtAgain['metrics']['cache_hit'] === true,
        'reanudar no reutilizó el cache XML sellado');

    $phase6 = $root . '/phase6';
    mkdir($phase6 . '/course-degradation-plans', 0770, true);
    $manifestpath = $phase6 . '/batch_manifest.json';
    p5_write_json($manifestpath, ['schema_version'=>'1.1', 'batch_id'=>'fixture',
        'status'=>'BATCH_READY', 'worker_course_precheck'=>true]);
    $manifestsha = hash_file('sha256', $manifestpath);
    $built['batch_manifest_sha256'] = $manifestsha;
    $built['worker_course_precheck'] = true;
    $planpath = $phase6 .
        '/course-degradation-plans/plan-course-pregrado-abcdef123456.json';
    p5_write_json($planpath, $built);
    $plansha = hash_file('sha256', $planpath);
    rc2d_block(static fn() => p6_load_course_degradation_plan(
        $phase6, 'COURSE-PREGRADO-ABCDEF123456', str_repeat('0', 64), $manifestsha
    ), 'DEGRADATION_COURSE_PLAN_SEAL_INVALID');
    $loaded = p6_load_course_degradation_plan(
        $phase6,
        'COURSE-PREGRADO-ABCDEF123456',
        $plansha,
        $manifestsha
    );
    rc2d_check($loaded['missing_payloads'] === 1,
        'el worker no aceptó su plan sellado de precheck por curso');

    echo "V8_RC2_DEGRADATION_OK missing=1 approved=omit structural=native " .
        "unknown=blocked worker_seal=verified cache=reused original=immutable\n";
} finally {
    rc2d_remove($root);
}
