<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$CFG = (object)['libdir' => __DIR__ . '/fixtures'];
class core_text {
    public static function strtolower(string $value): string { return strtolower($value); }
    public static function strlen(string $value): int { return strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-lib.php');

function v8id_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('V8_RC2_INCREMENTAL_DEGRADATION_FAILED ' . $message);
    }
}
function v8id_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        v8id_remove($item->getPathname());
    }
    rmdir($path);
}

$targetIndex = 77;
$targetCourse = sprintf('COURSE-BENCH-%012X', $targetIndex);
$root = sys_get_temp_dir() . '/v8-rc2-incremental-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
try {
    $csv = $root . '/resolutions.csv';
    $rowOne = [
        'course_key' => $targetCourse,
        'type' => 'structural_mismatch',
        'relation' => 'forum_discussions',
        'identifier' => '',
        'decision' => 'accept_warning',
        'operator' => ' reviewer  one ',
        'evidence' => 'ticket 42',
        'reason' => 'validated mapping',
    ];
    $rowTwo = $rowOne;
    $rowTwo['relation'] = 'forum_posts';
    $rowTwo['evidence'] = 'ticket 43';

    $ordered = p6_degradation_resolution_map([$rowOne, $rowTwo]);
    $reversed = p6_degradation_resolution_map([$rowTwo, $rowOne]);
    v8id_check(
        p6_degradation_course_resolution_hash($ordered, $targetCourse) ===
            p6_degradation_course_resolution_hash($reversed, $targetCourse),
        'el hash dependió del orden físico de resoluciones'
    );
    file_put_contents($csv, "\xEF\xBB\xBF");
    p5_write_csv($csv, array_keys($rowOne), [$rowOne, $rowTwo]);
    $fromCsv = p6_degradation_resolution_map(p5_read_csv($csv));
    v8id_check(
        p6_degradation_course_resolution_hash($ordered, $targetCourse) ===
            p6_degradation_course_resolution_hash($fromCsv, $targetCourse),
        'BOM/espacios de CSV cambiaron la representación canónica'
    );

    $policyRows = p5_read_csv(__DIR__ . '/../config/source-integrity-policies.csv');
    $policies = p6_degradation_source_integrity_map($policyRows);
    $pregrado = 'pregrado-2026-03-04-directo';
    $posgrados = 'posgrados-2025-05-02-directo';
    v8id_check(
        p6_degradation_source_integrity_policy($policies, $pregrado)['integrity_policy'] ===
            'known_degraded' &&
        p6_degradation_source_integrity_policy($policies, $posgrados)['integrity_policy'] ===
            'expected_complete' &&
        p6_degradation_source_integrity_policy([], 'sin-politica')['integrity_policy'] ===
            'unknown',
        'políticas de fuente/configuración por defecto inesperadas'
    );

    $knownPolicy = p6_degradation_source_integrity_policy($policies, $pregrado);
    $completePolicy = p6_degradation_source_integrity_policy($policies, $posgrados);
    $newPregradoPolicy = $knownPolicy;
    $newPregradoPolicy['integrity_policy'] = 'expected_complete';
    $policyRowsReordered = array_reverse($policyRows);
    v8id_check(
        p6_degradation_source_integrity_hash(
            p6_degradation_source_integrity_policy(
                p6_degradation_source_integrity_map($policyRowsReordered), $pregrado
            )
        ) === p6_degradation_source_integrity_hash($knownPolicy),
        'el hash de política dependió del orden de filas'
    );

    $planDependencies = static function (
        string $course,
        string $source,
        array $resolutionMap,
        array $sourcePolicies
    ): array {
        $policy = p6_degradation_source_integrity_policy($sourcePolicies, $source);
        return [
            'course_backup_sha256' => hash('sha256', 'backup:' . $course),
            'course_inventory_sha256' => hash('sha256', 'inventory:' . $course),
            'course_job_sha256' => hash('sha256', 'job:' . $course),
            'source_integrity_policy_sha256' => p6_degradation_source_integrity_hash($policy),
            'course_resolution_sha256' =>
                p6_degradation_course_resolution_hash($resolutionMap, $course),
        ];
    };
    $plans = [];
    $sources = [];
    for ($index = 1; $index <= 365; $index++) {
        $course = sprintf('COURSE-BENCH-%012X', $index);
        $source = $index <= 182 ? $pregrado : $posgrados;
        $sources[$course] = $source;
        $plans[$course] = array_merge([
            'schema_version' => '1.1',
            'analyzer_version' => P6_DEGRADATION_ANALYZER_VERSION,
        ], $planDependencies($course, $source, [], $policies));
    }
    v8id_check(count($plans) === 365, 'fixture del lote no contiene 365 planes');

    $changedPlans = $plans;
    $changed = 0;
    $reused = 0;
    foreach ($plans as $course => $plan) {
        $dependencies = $planDependencies(
            $course,
            $sources[$course],
            $ordered,
            $policies
        );
        if (p6_degradation_plan_reusable($plan, $dependencies)) { $reused++; }
        else { $changed++; }
    }
    v8id_check($reused === 364 && $changed === 1,
        'una resolución de un curso no produjo 364 reuse y 1 rebuild');
    $changedPlans[$targetCourse] = array_merge([
        'schema_version' => '1.1',
        'analyzer_version' => P6_DEGRADATION_ANALYZER_VERSION,
    ], $planDependencies($targetCourse, $pregrado, $ordered, $policies));

    $rowTwo['reason'] = 'validated mapping revised';
    $revised = p6_degradation_resolution_map([$rowOne, $rowTwo]);
    $reused = 0;
    $changed = 0;
    foreach ($changedPlans as $course => $plan) {
        if (p6_degradation_plan_reusable(
            $plan,
            $planDependencies($course, $sources[$course], $revised, $policies)
        )) { $reused++; }
        else { $changed++; }
    }
    v8id_check($reused === 364 && $changed === 1,
        'cambiar nuevamente la resolución invalidó cursos ajenos');
    $changedPlans[$targetCourse] = array_merge([
        'schema_version' => '1.1',
        'analyzer_version' => P6_DEGRADATION_ANALYZER_VERSION,
    ], $planDependencies($targetCourse, $pregrado, $revised, $policies));

    $changedPolicyRows = $policyRows;
    foreach ($changedPolicyRows as &$policyRow) {
        if (strtolower((string)$policyRow['source_id']) === $pregrado) {
            $policyRow['integrity_policy'] = 'expected_complete';
        }
    }
    unset($policyRow);
    $changedPolicies = p6_degradation_source_integrity_map($changedPolicyRows);
    $pregradoRebuild = 0;
    $posgradosReuse = 0;
    foreach ($changedPlans as $course => $plan) {
        $dependencies = $planDependencies(
            $course,
            $sources[$course],
            $revised,
            $changedPolicies
        );
        if (p6_degradation_plan_reusable($plan, $dependencies)) {
            if ($sources[$course] === $posgrados) { $posgradosReuse++; }
        } elseif ($sources[$course] === $pregrado) {
            $pregradoRebuild++;
        }
    }
    v8id_check($pregradoRebuild === 182 && $posgradosReuse === 183,
        'cambio de política no quedó limitado a cursos de esa fuente');

    $missingHash = str_repeat('a', 40);
    $filesXml = '<files><file id="114378"><filename>image.png</filename>' .
        '<component>mod_label</component><filearea>intro</filearea><contenthash>' .
        $missingHash . '</contenthash><repositorytype>$@NULL@$</repositorytype>' .
        '<reference>$@NULL@$</reference></file></files>';
    $fileCourse = 'COURSE-BENCH-0000000004D2';
    $knownItems = p6_degradation_file_items(
        $filesXml, [], $fileCourse, $pregrado, $knownPolicy
    );
    $unexpectedItems = p6_degradation_file_items(
        $filesXml, [], $fileCourse, $posgrados, $completePolicy
    );
    $unknownPolicy = p6_degradation_source_integrity_policy([], 'source-unknown');
    $unknownItems = p6_degradation_file_items(
        $filesXml, [], $fileCourse, 'source-unknown', $unknownPolicy
    );
    v8id_check(count($knownItems) === 1 && !$knownItems[0]['blocking'] &&
        $knownItems[0]['classification'] === 'known_source_degradation' &&
        $unexpectedItems[0]['blocking'] &&
        $unexpectedItems[0]['classification'] === 'unexpected_missing_payload' &&
        $unknownItems[0]['blocking'] &&
        $unknownItems[0]['source_integrity'] === 'unknown',
        'known_degraded/expected_complete/unknown no aplicaron gates diferenciados');

    $resolutionRows = [[
        'course_key' => $fileCourse,
        'type' => 'missing_payload',
        'relation' => 'files',
        'identifier' => '114378',
        'decision' => 'accept_warning',
        'operator' => 'benchmark reviewer',
        'evidence' => 'case 114378',
        'reason' => 'pérdida evaluada y aceptada para este curso',
    ]];
    $resolved = p6_degradation_file_items(
        $filesXml,
        [],
        $fileCourse,
        $posgrados,
        $completePolicy,
        p6_degradation_resolution_map($resolutionRows)
    );
    v8id_check(!$resolved[0]['blocking'] &&
        $resolved[0]['classification'] === 'unexpected_missing_payload_approved' &&
        ($resolved[0]['resolution']['operator'] ?? '') === 'benchmark reviewer' &&
        ($resolved[0]['resolution']['evidence'] ?? '') === 'case 114378',
        'la excepción exacta no dejó auditoría de operador/evidencia');

    echo "V8_RC2_INCREMENTAL_DEGRADATION_OK courses=365 reuse=364 rebuild=1 " .
        "policy_scope=source known=warning expected_complete=block unknown=block\n";
} finally {
    v8id_remove($root);
}
