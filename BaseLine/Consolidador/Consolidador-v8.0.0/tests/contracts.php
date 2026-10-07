<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
define('IGNORE_MISSING', 0);
$CFG = (object)[
    'libdir' => __DIR__ . '/fixtures',
    'tempdir' => sys_get_temp_dir(),
];
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$xml = '<users>' .
    '<user id="101"><username>alumno</username><email>a@ufps.edu.co</email><deleted>0</deleted></user>' .
    '<user id="540"><username>auxiliar</username><email>b@ufps.edu.co</email><deleted>0</deleted></user>' .
    '<user id="202"><username>deleted-202</username><email>old@example.org</email><deleted>1</deleted></user>' .
    '<user id="203"><username>sin-mapa</username><email>c@ufps.edu.co</email><deleted>0</deleted></user>' .
    '<user id="3"><username>guest</username><email>guest@example.org</email><deleted>0</deleted></user>' .
    '</users>';
$archive = tempnam(sys_get_temp_dir(), 'p6-fixture-');
check($archive !== false, 'No se pudo crear fixture ZIP.');
$zip = new ZipArchive();
check($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'ZIP inválido.');
$zip->addFromString('users.xml', $xml);
$zip->close();
try {
    $users = p6_backup_users_xml($archive);
    check(count($users) === 5, 'Lectura selectiva de users.xml incompleta.');
    $global = [
        'pregrado:101' => ['canonical_id' => 'CAN-101', 'target_user_id' => 11],
        'pregrado:540' => ['canonical_id' => 'CAN-826914AA4E34', 'target_user_id' => 3899],
    ];
    $classified = p6_classify_backup_users($users, 'pregrado', [101], $global);
    $classes = [];
    foreach ($classified['users'] as $user) {
        $classes[$user['source_user_id']] = $user;
    }
    check($classes[540]['classification'] === 'auxiliary_mapped' &&
        $classes[540]['target_user_id'] === 3899 &&
        $classes[540]['academic_participant'] === false,
        'El usuario 540 pierde su mapa global o recibe matrícula artificial.');
    check($classes[202]['classification'] === 'historical_deleted',
        'Un usuario eliminado se activó o se descartó.');
    check($classes[203]['classification'] === 'active_unmapped' && $classified['blocked'],
        'Un usuario activo sin mapa debe bloquear el preflight.');
    check($classes[3]['classification'] === 'reserved_guest', 'Guest no es reservado.');
    $historical = array_values(array_filter(
        $users, static fn(array $row): bool => $row['source_user_id'] === 202
    ));
    $secondcourse = p6_classify_backup_users($historical, 'pregrado', [202], $global);
    check($secondcourse['users'][0]['classification'] === 'historical_deleted',
        'El mismo histórico debe conservar su clase entre cursos.');
    check($secondcourse['blocked'] === false,
        'Una referencia histórica eliminada no es una cuenta activa sin mapa.');
    $badglobal = $global + [
        'pregrado:202' => ['target_user_id' => 3899, 'canonical_id' => 'CAN-202'],
    ];
    $incorrecthistorical = p6_classify_backup_users(
        $historical, 'pregrado', [202], $badglobal
    );
    check($incorrecthistorical['blocked'] &&
        $incorrecthistorical['users'][0]['classification'] === 'historical_deleted' &&
        $incorrecthistorical['historical_map_conflicts'] === [202],
        'historical_user_reactivated: un mapa Phase4 capturó una cuenta eliminada.');
    $missing = p6_classify_backup_users($users, 'pregrado', [101, 999], $global);
    check($missing['missing_academic_user_ids'] === [999] && $missing['blocked'],
        'Un participante ausente del backup debe bloquear el preflight.');

    $sourcequiz = [
        ['source_user_id' => 540, 'activity_key' => 'quiz-1', 'state' => 'finished',
            'sumgrades' => 9.0, 'attempt' => 1],
        ['source_user_id' => 540, 'activity_key' => 'quiz-1', 'state' => 'finished',
            'sumgrades' => 9.0, 'attempt' => 2],
    ];
    $targetquiz = [[
        'source_user_id' => 3899, 'activity_key' => 'quiz-1',
        'state' => 'finished', 'sumgrades' => 9.0, 'attempt' => 1,
    ]];
    $difference = p6_quiz_attempt_differences($sourcequiz, $targetquiz, $global, 'pregrado');
    check(count($difference['missing']) === 1 &&
        $difference['missing'][0]['missing'] === 1 &&
        $difference['missing'][0]['attempt_number'] === 2 &&
        $difference['missing'][0]['exact_attempt_ambiguous'] === false,
        'La diferencia 2 contra 1 debe identificar el segundo intento.');
    $indistinguishable = p6_quiz_attempt_differences(
        [
            $sourcequiz[0] + ['attempt_id' => 71],
            $sourcequiz[0] + ['attempt_id' => 72],
        ],
        [$targetquiz[0] + ['attempt_id' => 400]],
        $global, 'pregrado'
    );
    check(count($indistinguishable['missing']) === 1 &&
        $indistinguishable['missing'][0]['exact_attempt_ambiguous'] === true &&
        $indistinguishable['missing'][0]['source_attempt_id'] === null &&
        $indistinguishable['missing'][0]['source_attempt_candidate_ids'] === [71, 72],
        'Los intentos realmente indistinguibles deben conservar todos sus candidatos.');
    $source487 = [];
    $target486 = [];
    for ($i = 1; $i <= 487; $i++) {
        $source487[] = [
            'source_user_id' => 540, 'activity_key' => 'quiz|evaluacion',
            'source_module_id' => 51, 'quiz_id' => 37,
            'attempt' => $i, 'state' => 'finished', 'timestart' => 1700000000 + $i,
            'timefinish' => 1700001800 + $i, 'sumgrades' => 9.0,
            'preview' => 0, 'attempt_id' => 1000 + $i,
        ];
        if ($i !== 172) {
            $target486[] = [
                'source_user_id' => 3899, 'activity_key' => 'quiz|evaluacion',
                'source_module_id' => 714, 'quiz_id' => 620,
                'attempt' => $i, 'state' => 'finished', 'timestart' => 1700000000 + $i,
                'timefinish' => 1700001800 + $i, 'sumgrades' => 9.0,
                'preview' => 0, 'attempt_id' => 8000 + $i,
            ];
        }
    }
    $difference = p6_quiz_attempt_differences(
        $source487, $target486, $global, 'pregrado', 70, 900
    );
    check($difference['has_differences'] && count($difference['missing']) === 1 &&
        !$difference['extra'] && !$difference['changed'] &&
        $difference['missing'][0]['source_attempt_id'] === 1172 &&
        $difference['missing'][0]['attempt_number'] === 172 &&
        $difference['missing'][0]['source_module_id'] === 51 &&
        $difference['missing'][0]['target_module_id'] === 714 &&
        $difference['missing'][0]['target_quiz_id'] === 620 &&
        $difference['missing'][0]['canonical_id'] === 'CAN-826914AA4E34',
        'La diferencia 487→486 no identifica el intento exacto o el contexto.');
    $reportpath = tempnam(sys_get_temp_dir(), 'p6-quiz-report-');
    check($reportpath !== false, 'No se pudo crear reporte de fixture.');
    unlink($reportpath);
    try {
        $sha = p5_write_quiz_attempt_report($reportpath, $difference, [
            'source_course_id' => 70, 'target_course_id' => 900,
            'course_key' => 'COURSE-TEST-FIXTURE', 'source_id' => 'pregrado',
        ]);
        $report = p5_read_json($reportpath);
        check($sha === hash_file('sha256', $reportpath) &&
            $report['source_count'] === 487 && $report['target_count'] === 486 &&
            count($report['missing']) === 1 &&
            $report['missing'][0]['source_attempt_id'] === 1172,
            'El artefacto de diagnóstico perdió el intento faltante.');
    } finally {
        if (is_file($reportpath)) {
            unlink($reportpath);
        }
    }
    $changed487 = $source487;
    $changed487[171]['sumgrades'] = 6.0;
    $changedtarget = $target486;
    $changedtarget[] = [
        'source_user_id' => 3899, 'activity_key' => 'quiz|evaluacion',
        'source_module_id' => 714, 'quiz_id' => 620, 'attempt' => 172,
        'state' => 'finished', 'timestart' => 1700000172,
        'timefinish' => 1700001972, 'sumgrades' => 9.0,
        'preview' => 0, 'attempt_id' => 8172,
    ];
    $changed = p6_quiz_attempt_differences($changed487, $changedtarget, $global, 'pregrado');
    check(count($changed['changed']) === 1 && !$changed['missing'] &&
        isset($changed['changed'][0]['changed_fields']['sumgrades']),
        'La nota alterada debe clasificarse como changed.');
    $extra = p6_quiz_attempt_differences($target486, $source487, [
        'pregrado:3899' => ['target_user_id' => 540, 'canonical_id' => 'CAN-540'],
    ], 'pregrado');
    check(count($extra['extra']) === 1 && !$extra['missing'],
        'Un intento sobrante debe clasificarse como extra.');
    // Tres archivos MBZ independientes con el mismo usuario histórico eliminado.
    $registry = null;
    $historicalarchives = [];
    try {
        for ($courseindex = 1; $courseindex <= 3; $courseindex++) {
            $historicalarchive = tempnam(sys_get_temp_dir(), 'p6-historical-');
            check($historicalarchive !== false, 'No se pudo reservar el MBZ histórico.');
            $historicalarchives[] = $historicalarchive;
            $historiczip = new ZipArchive();
            check($historiczip->open($historicalarchive,
                ZipArchive::CREATE | ZipArchive::OVERWRITE) === true,
                'El MBZ histórico no se pudo crear.');
            $historiczip->addFromString('users.xml', '<users><user id="202">' .
                '<username>deleted-202</username><email>old@example.org</email>' .
                '<deleted>1</deleted></user></users>');
            $historiczip->addFromString('course/academic.txt',
                'Referencia académica curso ' . $courseindex);
            $historiczip->close();
            $classification = p6_classify_backup_users(
                p6_backup_users_xml($historicalarchive), 'pregrado', [202], $global
            );
            $historyids = p6_historical_source_ids($classification, $global);
            check($historyids === [202] && !$classification['blocked'] &&
                $classification['users'][0]['classification'] === 'historical_deleted' &&
                $classification['users'][0]['target_user_id'] === null &&
                $classification['users'][0]['canonical_id'] === '',
                'historical_user_reactivated: el MBZ creó un mapa activo o pending_relink.');
            $registry = p6_historical_registry_update(
                $registry, 'pregrado', 202, 6001, 'COURSE-' . $courseindex
            );
            $sourcehistory = ['relations' => ['forum_posts' => [[
                'source_user_id' => 202, 'activity_key' => 'forum|historico',
                'subject' => 'Aporte curso ' . $courseindex,
            ]]]];
            $targethistory = ['relations' => ['forum_posts' => [[
                'source_user_id' => 6001, 'activity_key' => 'forum|historico',
                'subject' => 'Aporte curso ' . $courseindex,
            ]]]];
            check(p6_historical_relation_issues($sourcehistory, $targethistory,
                [202 => 6001]) === [], 'historical_history_lost: faltó un curso.');
            $targethistory['relations']['forum_posts'] = [];
            check((p6_historical_relation_issues($sourcehistory, $targethistory,
                [202 => 6001])[0]['error'] ?? '') === 'historical_history_lost',
                'La prueba no detectó historical_history_lost.');
        }
        check($registry['observed_course_keys'] ===
            ['COURSE-1', 'COURSE-2', 'COURSE-3'] &&
            $registry['target_user_id'] === 6001,
            'historical_user_duplicated: se crearon distintos destinos.');
        try {
            p6_historical_assert_disjoint([202 => 3899], $global);
            throw new RuntimeException('La prueba no detectó fusión histórica con Phase4.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'historical_user_duplicated'),
                'Una cuenta histórica se fusionó con una identidad activa.');
        }
        try {
            p6_historical_registry_update($registry, 'pregrado', 202, 6002, 'COURSE-4');
            throw new RuntimeException('La prueba no detectó historical_user_duplicated.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'historical_user_duplicated'),
                'La prueba de identidad histórica repetida no bloqueó la duplicación.');
        }
        $mockdb = new class {
            public int $deleted = 1;
            public function get_record(string $table, array $where,
                string $fields = '*', int $strictness = 0): ?object {
                if ($table === 'user') {
                    return (object)['id' => $where['id'], 'deleted' => $this->deleted,
                        'auth' => 'manual', 'username' => 'deleted-202',
                        'email' => 'old@example.org'];
                }
                return null;
            }
            public function get_manager(): object {
                return new class {
                    public function table_exists(string $name): bool { return false; }
                };
            }
            public function get_field(string $table, string $field, array $where): mixed {
                return false;
            }
        };
        check(p6_historical_assert_target($mockdb, 6001)['deleted'],
            'La identidad histórica no quedó marcada como eliminada.');
        $mockdb->deleted = 0;
        try {
            p6_historical_assert_target($mockdb, 6001);
            throw new RuntimeException('La prueba no detectó historical_user_reactivated.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'historical_user_reactivated'),
                'La reactivación del usuario histórico no fue detectada.');
        }
    } finally {
        foreach ($historicalarchives as $historicalarchive) {
            unlink($historicalarchive);
        }
    }
    $expected = [
        'counts' => ['course_completions' => 54],
        'modules_by_type' => [], 'modules' => [],
        'relations' => ['course_completions' => array_fill(0, 54, ['completed' => false])],
    ];
    $actual = [
        'counts' => ['course_completions' => 53],
        'modules_by_type' => [], 'modules' => [],
        'relations' => ['course_completions' => array_fill(0, 53, ['completed' => false])],
    ];
    check(p5_compare_course_inventories($expected, $actual)['complete'],
        'Las filas sin finalización no deben producir diferencias académicas.');
    $actual['relations']['course_completions'][0]['completed'] = true;
    check(!p5_compare_course_inventories($expected, $actual)['complete'],
        'Una finalización efectiva faltante debe bloquear.');
    echo "CONTRACTS_OK auxiliary=540 historical_deleted=3mbz quiz_missing=1 quiz_487_to_486=1\n";
} finally {
    unlink($archive);
}
