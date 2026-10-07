<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$root = sys_get_temp_dir() . '/consolidador rc21 refs ' . bin2hex(random_bytes(6));
$CFG = (object)[
    'libdir' => __DIR__ . '/fixtures',
    'tempdir' => $root . '/temporary files',
];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');

function rc21_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('RC21_PHASE6_REFS_FAILED ' . $message);
    }
}

function rc21_expect_block(callable $action, string $needle, string $message): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc21_check(str_contains($error->getMessage(), $needle),
            $message . ': ' . $error->getMessage());
    }
}

function rc21_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        rc21_remove($item->getPathname());
    }
    rmdir($path);
}

function rc21_run(array $command): void {
    $process = proc_open($command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    rc21_check(is_resource($process), 'no fue posible ejecutar el fixture');
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    rc21_check($exit === 0,
        'fixture exit=' . $exit . ' stdout=' . $stdout . ' stderr=' . $stderr);
}

function rc21_user(int $id, bool $deleted = false): array {
    return [
        'source_user_id' => $id,
        'source_username' => $deleted ? 'deleted-' . $id : 'user-' . $id,
        'source_email' => 'user-' . $id . '@example.org',
        'deleted' => $deleted,
    ];
}

function rc21_classification(array $users, string $source, array $required,
        array $map = [], array $planned = [], array $declared = []): array {
    return p6_classify_backup_users(
        $users, $source, $required, $map, $planned, $declared
    );
}

$fixture = json_decode((string)file_get_contents(
    __DIR__ . '/fixtures/rc21-forum-user-references.json'
), true, 512, JSON_THROW_ON_ERROR);
$coursekey = $fixture['course_key'];
$sourceid = $fixture['source_id'];
$source = $root . '/source course';
$archive = $root . '/archives/course with spaces.mbz';
$usersxml = '<users>' .
    '<user id="6"><username>participant</username><email>p@example.org</email><deleted>0</deleted></user>' .
    '<user id="171"><username>author</username><email>a@example.org</email><deleted>0</deleted></user>' .
    '</users>';
$forumxml = '<activity><forum><discussions><discussion id="700">' .
    '<userid>245</userid><firstpost>1792</firstpost><posts>' .
    '<post id="1792"><userid>171</userid></post>' .
    '</posts></discussion></discussions></forum></activity>';
$inforefxml = '<?xml version="1.0" encoding="UTF-8"?>' .
    '<inforef><userref><user><id>171</id></user></userref></inforef>';

rc21_check(mkdir($CFG->tempdir, 0770, true), 'no creó tempdir');
try {
    $multicommand = p6_tar_members_to_stdout_command(
        '/tmp/course with spaces.mbz',
        ['activities/forum_4599/inforef.xml', './activities/label_1/inforef.xml']
    );
    rc21_check($multicommand === [
        'tar', '--extract', '--to-stdout', '--file',
        '/tmp/course with spaces.mbz', '--',
        'activities/forum_4599/inforef.xml', './activities/label_1/inforef.xml',
    ] && !in_array('-xOf', $multicommand, true),
        'la lectura selectiva reintrodujo argumentos tar ambiguos');
    rc21_check(mkdir($source . '/activities/forum_4599', 0770, true),
        'no creó estructura del curso');
    rc21_check(mkdir($source . '/activities/label_1', 0770, true),
        'no creó segunda actividad');
    rc21_check(mkdir(dirname($archive), 0770, true), 'no creó archives');
    file_put_contents($source . '/users.xml', $usersxml);
    file_put_contents($source . '/activities/forum_4599/forum.xml', $forumxml);
    file_put_contents($source . '/activities/forum_4599/inforef.xml', $inforefxml);
    file_put_contents($source . '/activities/label_1/inforef.xml',
        '<?xml version="1.0" encoding="UTF-8"?><inforef><fileref/></inforef>');
    rc21_run([
        'tar', '--create', '--file', $archive, '--directory', $source, '--',
        'users.xml', 'activities/forum_4599/forum.xml',
        'activities/forum_4599/inforef.xml', 'activities/label_1/inforef.xml',
    ]);
    $archivehash = hash_file('sha256', $archive);

    $declared = p6_backup_declared_user_references($archive);
    rc21_check($declared['source_user_ids'] === [171] &&
        $declared['members_scanned'] === 2,
        'inforef.userref no aisló exactamente al usuario 171');
    rc21_check(!in_array(245, $declared['source_user_ids'], true),
        'discussion.userid se convirtió en userref transportado');
    rc21_check(hash_file('sha256', $archive) === $archivehash,
        'la auditoría selectiva modificó el MBZ');

    $bundle = [
        'user_rows_by_course' => [$coursekey => [['source_user_id' => 6]]],
        'role_rows_by_course' => [$coursekey => []],
    ];
    $inventory = [
        'enrolments' => [],
        'roles' => [],
        'relations' => $fixture['relations'],
    ];
    $sets = p6_course_user_reference_sets(
        $bundle, $coursekey, $inventory, $declared['source_user_ids']
    );
    rc21_check($sets['planned_participant_source_user_ids'] === [6],
        'cambió el participante planificado');
    rc21_check($sets['backup_declared_user_reference_ids'] === [171],
        'no conservó la dependencia declarada por el MBZ');
    rc21_check($sets['required_backup_user_ids'] === [6, 171],
        'required no es plan + userref');
    rc21_check($sets['referenced_source_user_ids'] === [6, 171, 245] &&
        $sets['reference_only_source_user_ids'] === [245],
        'se perdió la referencia/mapping de 245');
    rc21_check(in_array(245, $sets['strict_relation_source_user_ids'], true) &&
        !in_array(245, $sets['required_backup_user_ids'], true),
        'se confundió evidencia académica con transporte users.xml');

    $map = [
        $sourceid . ':6' => ['canonical_id' => 'CAN-6', 'target_user_id' => 3006],
        $sourceid . ':171' => ['canonical_id' => 'CAN-171', 'target_user_id' => 3171],
        $sourceid . ':245' => [
            'canonical_id' => $fixture['discussion']['canonical_id'],
            'target_user_id' => $fixture['discussion']['target_user_id'],
        ],
    ];
    $classification = rc21_classification(
        p6_backup_users_xml($archive), $sourceid,
        $sets['required_backup_user_ids'], $map,
        $sets['planned_participant_source_user_ids'],
        $sets['backup_declared_user_reference_ids']
    );
    rc21_check(!$classification['blocked'] &&
        $classification['missing_academic_user_ids'] === [],
        '245 ausente bloqueó la clasificación');
    $classified = array_column($classification['users'], null, 'source_user_id');
    rc21_check($classified[171]['backup_declared_user_reference'] === true &&
        $classified[171]['academic_participant'] === false &&
        $classified[6]['academic_participant'] === true,
        'se mezclaron participante y referencia transportada');
    rc21_check($map[$sourceid . ':245']['target_user_id'] === 3853,
        'se alteró el mapping global de 245');
    $missingdeclared = rc21_classification(
        [rc21_user(6)], $sourceid, $sets['required_backup_user_ids'], $map,
        $sets['planned_participant_source_user_ids'],
        $sets['backup_declared_user_reference_ids']
    );
    rc21_check($missingdeclared['blocked'] &&
        $missingdeclared['missing_academic_user_ids'] === [171],
        'un userref transportado ausente dejó de bloquear');

    // La rama ZIP conserva la misma semántica selectiva.
    $ziparchive = $root . '/archives/course.zip.mbz';
    $zip = new ZipArchive();
    rc21_check($zip->open($ziparchive,
        ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'no creó ZIP');
    $zip->addFromString('users.xml', $usersxml);
    $zip->addFromString('./activities/forum_4599/inforef.xml', $inforefxml);
    $zip->addFromString('activities/forum_4599/forum.xml', $forumxml);
    $zip->close();
    rc21_check(p6_backup_declared_user_references($ziparchive)['source_user_ids'] === [171],
        'la rama ZIP no conservó userref');

    // Faltantes del plan de usuarios o roles continúan bloqueando.
    foreach (['user_rows_by_course', 'role_rows_by_course'] as $rowset) {
        $planned = [
            'user_rows_by_course' => [$coursekey => []],
            'role_rows_by_course' => [$coursekey => []],
        ];
        $planned[$rowset][$coursekey][] = ['source_user_id' => 900];
        $plannedsets = p6_course_user_reference_sets($planned, $coursekey,
            ['relations' => []], []);
        $missing = rc21_classification([], $sourceid,
            $plannedsets['required_backup_user_ids']);
        rc21_check($missing['blocked'] &&
            $missing['missing_academic_user_ids'] === [900],
            $rowset . ' ausente dejó de bloquear');
    }

    $technical = p6_course_user_reference_sets($bundle, $coursekey, [
        'relations' => [
            'files' => [['source_user_id' => 301]],
            'course_completions' => [[
                'source_user_id' => 302, 'completed' => false,
            ], [
                'source_user_id' => 303, 'completed' => true,
            ]],
        ],
    ], []);
    rc21_check(!array_intersect([301, 302, 303],
        $technical['required_backup_user_ids']),
        'una relación externa inventó transporte users.xml');
    rc21_check(p6_relation_user_semantics('files',
        ['source_user_id' => 301]) === 'historical_technical_reference',
        'files perdió su semántica RC20');
    rc21_check(p6_relation_user_semantics('course_completions',
        ['source_user_id' => 302, 'completed' => false]) ===
        'non_effective_reference', 'completed=false perdió semántica RC18');
    rc21_check(p6_relation_user_semantics('course_completions',
        ['source_user_id' => 303, 'completed' => true]) ===
        'strict_academic_evidence', 'completed=true dejó de ser estricto');

    $unresolved = rc21_classification([rc21_user(999)], $sourceid, [], []);
    rc21_check($unresolved['blocked'] &&
        $unresolved['users'][0]['classification'] === 'active_unmapped',
        'usuario activo irresoluble no bloqueó');
    $historical = rc21_classification([rc21_user(998, true)], $sourceid, [], []);
    rc21_check(!$historical['blocked'] &&
        $historical['users'][0]['classification'] === 'historical_deleted',
        'historical_deleted cambió de política');

    $job = [
        'backup_user_contract_version' => 3,
        'planned_source_user_ids' => [6],
        'backup_declared_user_references' => $declared,
        'backup_declared_user_reference_ids' => [171],
        'strict_relation_source_user_ids' => [171, 245],
        'reference_only_source_user_ids' => [245],
        'required_backup_user_ids' => [6, 171],
        'referenced_source_user_ids' => [6, 171, 245],
        'backup_user_classification' => $classification,
    ];
    $checkpoint = [
        'checkpoint_status' => 'referenced',
        'backup_user_contract_version' => 3,
        'backup_user_classification_sha256' => p6_value_sha256($classification),
        'backup_declared_user_references_sha256' => p6_value_sha256($declared),
    ];
    p6_assert_referenced_checkpoint_classification($checkpoint, $job, 'RC21 válido');
    $blockedjob = $job;
    $blockedjob['backup_user_classification']['blocked'] = true;
    $blockedcheckpoint = $checkpoint;
    $blockedcheckpoint['backup_user_classification_sha256'] =
        p6_value_sha256($blockedjob['backup_user_classification']);
    rc21_expect_block(static fn() =>
        p6_assert_referenced_checkpoint_classification(
            $blockedcheckpoint, $blockedjob, 'RC21 bloqueado'),
        'bloqueada u obsoleta', 'aceptó referenced + blocked');
    $rc20checkpoint = $checkpoint;
    $rc20checkpoint['backup_user_contract_version'] = 2;
    rc21_expect_block(static fn() =>
        p6_assert_referenced_checkpoint_classification(
            $rc20checkpoint, $job, 'RC20 obsoleto'),
        'incompatible con RC21', 'reutilizó checkpoint RC20');

    $preparesource = (string)file_get_contents(
        __DIR__ . '/../scripts/phase6-prepare-package-course.php');
    rc21_check(str_contains($preparesource,
        'p6_backup_declared_user_references($packagebackuppath)') &&
        str_contains($preparesource,
            "'backup_user_contract_version' => 3"),
        'Fase 12 no exige el contrato v3 del MBZ');
    $blockedposition = strpos($preparesource,
        'if (($backupusers[\'blocked\'] ?? true) !== false)');
    $writeposition = strpos($preparesource,
        'p5_write_json($checkpointpath, $checkpoint)');
    rc21_check($blockedposition !== false && $writeposition !== false &&
        $blockedposition < $writeposition,
        'puede escribir referenced antes de validar blocked');

    echo "RC21_PHASE6_REFS_OK discussion_245=reference_only " .
        "firstpost_171=userref plan_missing=blocked active_unmapped=blocked " .
        "historical=preserved checkpoint_v2=regenerate tar_zip=selective\n";
} finally {
    rc21_remove($root);
}
