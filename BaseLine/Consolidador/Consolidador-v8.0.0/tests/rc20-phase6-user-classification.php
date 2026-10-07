<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures', 'tempdir' => sys_get_temp_dir()];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');

function rc20_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('RC20_PHASE6_USERS_FAILED ' . $message);
    }
}

function rc20_expect_block(callable $action, string $needle, string $message): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        rc20_check(str_contains($error->getMessage(), $needle),
            $message . ': ' . $error->getMessage());
    }
}

function rc20_user(int $id, bool $deleted = false): array {
    return [
        'source_user_id' => $id,
        'source_username' => $deleted ? 'deleted-' . $id : 'user-' . $id,
        'source_email' => 'user-' . $id . '@example.org',
        'deleted' => $deleted,
    ];
}

$fixture = json_decode((string)file_get_contents(
    __DIR__ . '/fixtures/rc20-backup-user-classification.json'
), true, 512, JSON_THROW_ON_ERROR);
$coursekey = $fixture['course_key'];
$sourceid = $fixture['source_id'];
$bundle = [
    'user_rows_by_course' => [$coursekey => []],
    'role_rows_by_course' => [$coursekey => []],
];
foreach ($fixture['planned_source_user_ids'] as $index => $userid) {
    $row = ['source_user_id' => $userid];
    if ($index < 3) {
        $bundle['user_rows_by_course'][$coursekey][] = $row;
    } else {
        $bundle['role_rows_by_course'][$coursekey][] = $row;
    }
}
$inventory = [
    'enrolments' => [],
    'roles' => [],
    'relations' => $fixture['relations'],
];
$sets = p6_course_user_reference_sets($bundle, $coursekey, $inventory);
rc20_check(
    $sets['planned_participant_source_user_ids'] === [6, 78, 210, 211, 1317],
    'los cinco participantes planificados cambiaron'
);
rc20_check(
    $sets['required_backup_user_ids'] === [6, 78, 210, 211, 1317],
    '24/25/77 se convirtieron en participantes obligatorios'
);
rc20_check(
    $sets['reference_only_source_user_ids'] === [24, 25, 77] &&
    $sets['referenced_source_user_ids'] === [6, 24, 25, 77, 78, 210, 211, 1317],
    'las referencias históricas no quedaron separadas'
);

$globalmap = [];
$users = [];
foreach ($fixture['planned_source_user_ids'] as $userid) {
    $users[] = rc20_user($userid);
    $globalmap[$sourceid . ':' . $userid] = [
        'canonical_id' => 'CAN-' . $userid,
        'target_user_id' => 10000 + $userid,
    ];
}
$classification = p6_classify_backup_users(
    $users,
    $sourceid,
    $sets['required_backup_user_ids'],
    $globalmap
);
rc20_check(!$classification['blocked'] &&
    $classification['missing_academic_user_ids'] === [] &&
    $classification['required_backup_user_ids'] === [6, 78, 210, 211, 1317],
    'el curso real sigue bloqueado por 24/25/77'
);

// completed=true conserva exactamente la vista efectiva aprobada en RC18.
$strictinventory = $inventory;
$strictinventory['relations']['course_completions'][] = [
    'source_user_id' => 900,
    'completed' => true,
];
$strictsets = p6_course_user_reference_sets($bundle, $coursekey, $strictinventory);
rc20_check(in_array(900, $strictsets['strict_relation_source_user_ids'], true) &&
    !in_array(900, $strictsets['required_backup_user_ids'], true),
    'completed=true dejó de ser evidencia académica estricta');
$strictclassification = p6_classify_backup_users(
    $users,
    $sourceid,
    $strictsets['required_backup_user_ids'],
    $globalmap
);
rc20_check(!$strictclassification['blocked'] &&
    $strictclassification['missing_academic_user_ids'] === [],
    'el inventario externo inventó una dependencia users.xml');

// Todas las relaciones académicas soportadas siguen siendo estrictas.
$strictrelations = [
    'assignment_submissions', 'assignment_grades', 'forum_discussions',
    'forum_posts', 'quiz_attempts', 'activity_completions',
];
$strictall = $inventory;
foreach ($strictrelations as $index => $relation) {
    $strictall['relations'][$relation] = [[
        'source_user_id' => 1000 + $index,
    ]];
}
$strictallsets = p6_course_user_reference_sets($bundle, $coursekey, $strictall);
foreach (range(1000, 1005) as $userid) {
    rc20_check(in_array($userid,
        $strictallsets['strict_relation_source_user_ids'], true),
        'se relajó una evidencia académica para ' . $userid);
}

// Un participante real faltante continúa bloqueando.
$missingparticipant = p6_classify_backup_users(
    [],
    $sourceid,
    [100],
    []
);
rc20_check($missingparticipant['blocked'] &&
    $missingparticipant['missing_academic_user_ids'] === [100],
    'un participante planificado ausente no bloqueó');

// Una identidad activa requerida y presente, pero sin mapping, sigue bloqueando.
$unresolved = p6_classify_backup_users(
    [rc20_user(100)],
    $sourceid,
    [100],
    []
);
rc20_check($unresolved['blocked'] &&
    $unresolved['users'][0]['classification'] === 'academic_reference_unmapped',
    'una identidad académica activa sin mapping no bloqueó');

// historical_deleted no se reactiva; un mapa activo continúa siendo conflicto.
$historical = p6_classify_backup_users(
    [rc20_user(202, true)],
    $sourceid,
    [202],
    []
);
rc20_check(!$historical['blocked'] &&
    $historical['users'][0]['classification'] === 'historical_deleted',
    'historical_deleted cambió de política');
$historicalconflict = p6_classify_backup_users(
    [rc20_user(202, true)],
    $sourceid,
    [202],
    [$sourceid . ':202' => ['canonical_id' => 'CAN-202', 'target_user_id' => 1202]]
);
rc20_check($historicalconflict['blocked'] &&
    $historicalconflict['historical_map_conflicts'] === [202],
    'historical_map_conflicts dejó de bloquear');

// referenced nunca puede coexistir con backup_user_classification.blocked=true.
$job = [
    'backup_user_contract_version' => 3,
    'planned_source_user_ids' => [6, 78, 210, 211, 1317],
    'backup_declared_user_references' => [
        'source' => 'inforef.userref',
        'members_scanned' => 0,
        'members_sha256' => hash('sha256', ''),
        'source_user_ids' => [],
    ],
    'backup_declared_user_reference_ids' => [],
    'strict_relation_source_user_ids' => [],
    'reference_only_source_user_ids' => [24, 25, 77],
    'required_backup_user_ids' => [6, 78, 210, 211, 1317],
    'referenced_source_user_ids' => [6, 24, 25, 77, 78, 210, 211, 1317],
    'backup_user_classification' => $classification,
];
$checkpoint = [
    'checkpoint_status' => 'referenced',
    'backup_user_contract_version' => 3,
    'backup_user_classification_sha256' => p6_value_sha256($classification),
    'backup_declared_user_references_sha256' => p6_value_sha256(
        $job['backup_declared_user_references']
    ),
];
p6_assert_referenced_checkpoint_classification($checkpoint, $job, 'fixture válido');
$blockedjob = $job;
$blockedjob['backup_user_classification']['blocked'] = true;
$blockedcheckpoint = $checkpoint;
$blockedcheckpoint['backup_user_classification_sha256'] =
    p6_value_sha256($blockedjob['backup_user_classification']);
rc20_expect_block(
    static fn() => p6_assert_referenced_checkpoint_classification(
        $blockedcheckpoint, $blockedjob, 'fixture bloqueado'
    ),
    'bloqueada u obsoleta',
    'se aceptó referenced con clasificación bloqueada'
);
$rc19checkpoint = $checkpoint;
$rc19checkpoint['backup_user_contract_version'] = 2;
rc20_expect_block(
    static fn() => p6_assert_referenced_checkpoint_classification(
        $rc19checkpoint, $job, 'fixture RC19'
    ),
    'incompatible con RC21',
    'se reutilizó un checkpoint RC20 sin regenerar'
);

$preparesource = (string)file_get_contents(
    __DIR__ . '/../scripts/phase6-prepare-package-course.php'
);
$blockedcheck = strpos(
    $preparesource,
    'if (($backupusers[\'blocked\'] ?? true) !== false)'
);
$checkpointwrite = strpos($preparesource, 'p5_write_json($checkpointpath, $checkpoint)');
rc20_check($blockedcheck !== false && $checkpointwrite !== false &&
    $blockedcheck < $checkpointwrite,
    'Fase 12 puede sellar antes de comprobar blocked');
rc20_check(!str_contains($preparesource, '$mappingsourceids') &&
    str_contains($preparesource, 'p6_course_user_reference_sets'),
    'se reintrodujo la unión indiscriminada de relaciones');
$wizardsource = (string)file_get_contents(
    __DIR__ . '/../scripts/consolidation-wizard.ps1'
);
$workersource = (string)file_get_contents(
    __DIR__ . '/../scripts/phase6-course-worker.ps1'
);
$workerprepare = strpos($workersource, 'phase6-prepare-package-course.php');
$workeranalyze = strpos($workersource, 'phase6-analyze-course.php');
$workerapply = strpos($workersource, 'phase6-apply-course.php');
rc20_check($workerprepare !== false && $workeranalyze !== false &&
    $workerapply !== false && $workerprepare < $workeranalyze &&
    $workeranalyze < $workerapply,
    'el worker no clasifica usuarios antes de analizar/restaurar el curso');
rc20_check(str_contains($wizardsource, '[bool]$summary.worker_course_precheck') &&
    str_contains($wizardsource, "manifest_status -eq 'BATCH_READY'"),
    'el asistente no exige el precheck RC20 diferido a cada worker');

echo "RC20_PHASE6_USERS_OK real=5 reference_only=24,25,77 " .
    "completion_true=strict_reference academic_relations=audited participant_missing=blocked " .
    "unresolved=blocked historical=preserved checkpoint=fail_closed resume=regenerate\n";
