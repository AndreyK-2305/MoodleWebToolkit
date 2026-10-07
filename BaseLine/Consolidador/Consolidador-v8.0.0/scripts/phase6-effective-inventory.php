<?php
// Vista efectiva y transportable de inventario para Fase 6.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

function p6_relation_candidate_matches(array $row, array $candidate): bool {
    foreach ($row as $field => $value) {
        if ($field === 'activity_key' || $field === '_source_module_id') {
            continue;
        }
        if (!array_key_exists($field, $candidate) ||
                p5_relation_canonical_value($field, $candidate[$field]) !==
                    p5_relation_canonical_value($field, $value)) {
            return false;
        }
    }
    return true;
}

function p6_effective_relation_activity_keys(
    array $rows,
    string $relation,
    array $modulesbysourceid,
    array $primaryidsbykey,
    array $nameidsbykey,
    array $structuralcandidates,
    array &$evidence
): array {
    $resolved = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new RuntimeException(
                'MODULE_RELATION_SCHEMA_INVALID ' . $relation .
                ' contiene una fila no interpretable.'
            );
        }
        $legacykey = (string)($row['activity_key'] ?? '');
        $moduleids = array_values(array_unique(array_map(
            'intval',
            $primaryidsbykey[$legacykey] ?? []
        )));
        $mode = 'primary_legacy_key';
        if (count($moduleids) !== 1) {
            $moduleids = array_values(array_unique(array_map(
                'intval',
                $nameidsbykey[$legacykey] ?? []
            )));
            $mode = 'historical_name_alias';
        }
        if (count($moduleids) !== 1) {
            $prefix = explode('|', $legacykey, 2)[0] ?? '';
            $structuralids = [];
            foreach ($structuralcandidates as $candidate) {
                $sourcemoduleid = (int)($candidate['_source_module_id'] ?? 0);
                $module = $modulesbysourceid[$sourcemoduleid] ?? null;
                if (!$module || p5_norm((string)$module['modname']) !== p5_norm($prefix) ||
                        !p6_relation_candidate_matches($row, $candidate)) {
                    continue;
                }
                $structuralids[$sourcemoduleid] = true;
            }
            $moduleids = array_map('intval', array_keys($structuralids));
            sort($moduleids, SORT_NUMERIC);
            $mode = 'structural_fallback';
        }
        if (count($moduleids) !== 1 || !isset($modulesbysourceid[$moduleids[0]])) {
            $evidence['aliases_blocked']++;
            $evidence['blocked'][] = [
                'relation' => $relation,
                'row_index' => $index,
                'activity_key' => $legacykey,
                'source_module_id_candidates' => $moduleids,
            ];
            throw new RuntimeException(
                'MODULE_ACTIVITY_ALIAS_UNRESOLVED relation=' . $relation .
                ' activity_key=' . $legacykey .
                ' candidates=' . implode('|', $moduleids) . '.'
            );
        }
        $sourcemoduleid = $moduleids[0];
        $row['activity_key'] =
            (string)$modulesbysourceid[$sourcemoduleid]['module_key'];
        $resolved[] = $row;
        $evidence[$mode]++;
        $evidence['resolutions'][] = [
            'relation' => $relation,
            'row_index' => $index,
            'legacy_activity_key' => $legacykey,
            'effective_activity_key' => $row['activity_key'],
            'source_module_id' => $sourcemoduleid,
            'resolution_mode' => $mode,
        ];
    }
    return $resolved;
}

/**
 * Reconstruye relaciones cuyo activity_key histórico puede agregar módulos
 * homónimos. El MBZ solo puede redistribuir la población cuando sus filas
 * estructurales conservan exactamente el mismo multiconjunto semántico que el
 * inventario fuente; cualquier diferencia real continúa bloqueando.
 */
function p6_structural_relation_projection(
    array $rows,
    string $relation,
    array $structuralcandidates,
    array $modulesbysourceid,
    array &$evidence,
    ?array $degradationplan = null
): array {
    $fields = p5_relation_semantic_fields($rows, $relation);
    $inventoryset = p5_relation_multiset($rows, $fields, $relation);
    $backupset = p5_relation_multiset($structuralcandidates, $fields, $relation);
    $differences = p5_relation_multiset_difference($inventoryset, $backupset);
    $rowdifference = 0;
    foreach ($differences as $difference) {
        $rowdifference += abs((int)$difference['difference']);
    }
    $relationaudit = [
        'relation' => $relation,
        'inventory_rows' => count($rows),
        'structural_rows' => count($structuralcandidates),
        'semantic_fields' => $fields,
        'multiset_signature_difference' => count($differences),
        'multiset_row_difference' => $rowdifference,
        'status' => $differences ? (
            $degradationplan !== null &&
            p6_degradation_allows_structural($degradationplan, $relation)
                ? 'warning_accepted' : 'blocked'
        ) : 'matched',
    ];
    $evidence['structural_projection_relations'][] = $relationaudit;
    if ($differences && !($degradationplan !== null &&
            p6_degradation_allows_structural($degradationplan, $relation))) {
        $evidence['aliases_blocked']++;
        $evidence['blocked'][] = [
            'relation' => $relation,
            'reason' => 'structural_multiset_mismatch',
            'differences' => array_slice($differences, 0, 20),
        ];
        throw new RuntimeException(
            'MODULE_RELATION_STRUCTURAL_MISMATCH relation=' . $relation .
            ' inventory_rows=' . count($rows) .
            ' structural_rows=' . count($structuralcandidates) .
            ' row_difference=' . $rowdifference . '.'
        );
    }
    if (!$rows) {
        return [];
    }
    $template = $rows[0];
    $rebuilt = [];
    foreach ($structuralcandidates as $candidate) {
        $rebuilt[] = p5_rebuilt_relation_row(
            $template,
            $candidate,
            $modulesbysourceid,
            $relation
        );
    }
    usort($rebuilt, static fn(array $left, array $right): int => strcmp(
        json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ));
    $evidence['structural_projection'] += count($rebuilt);
    return $rebuilt;
}

/**
 * Proyecta intentos legacy sobre la población que el MBZ realmente transporta.
 *
 * Los inventarios antiguos podían contener previews que Moodle nunca incluyó
 * como intentos académicos restaurables. Solo se admite que el inventario sea
 * un superconjunto del MBZ para la firma aprobada; cualquier fila adicional
 * del backup continúa siendo una inconsistencia bloqueante.
 */
function p6_quiz_attempt_legacy_projection(
    array $rows,
    array $structuralcandidates,
    array $modulesbysourceid,
    array $primaryidsbykey,
    array $nameidsbykey,
    array &$evidence
): array {
    $resolved = p6_effective_relation_activity_keys(
        $rows,
        'quiz_attempts',
        $modulesbysourceid,
        $primaryidsbykey,
        $nameidsbykey,
        $structuralcandidates,
        $evidence
    );
    if ($resolved) {
        p5_relation_semantic_fields($resolved, 'quiz_attempts');
    }

    $previewrows = [];
    foreach ($structuralcandidates as $index => $candidate) {
        if ((int)($candidate['preview'] ?? 0) !== 0) {
            $previewrows[] = [
                'candidate_index' => $index,
                'source_user_id' => (int)($candidate['source_user_id'] ?? 0),
                'source_module_id' => (int)($candidate['_source_module_id'] ?? 0),
                'preview' => (int)$candidate['preview'],
            ];
        }
    }
    if ($previewrows) {
        throw new RuntimeException(
            'QUIZ_ATTEMPT_TRANSPORT_PREVIEW_UNEXPECTED count=' .
            count($previewrows) . '.'
        );
    }
    if (!$resolved && $structuralcandidates) {
        throw new RuntimeException(
            'QUIZ_ATTEMPT_TRANSPORT_UNEXPLAINED inventory_rows=0 backup_rows=' .
            count($structuralcandidates) . '.'
        );
    }

    $rebuilt = [];
    $template = $resolved[0] ?? null;
    if ($template !== null) {
        foreach ($structuralcandidates as $candidate) {
            $rebuilt[] = p5_rebuilt_relation_row(
                $template,
                $candidate,
                $modulesbysourceid,
                'quiz_attempts'
            );
        }
    }
    $signature = static function(array $row): string {
        foreach (['source_user_id', 'activity_key', 'state', 'sumgrades'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new RuntimeException(
                    'QUIZ_ATTEMPT_EVIDENCE_MISSING field=' . $field . '.'
                );
            }
        }
        return json_encode([
            'source_user_id' => (int)$row['source_user_id'],
            'activity_key' => (string)$row['activity_key'],
            'state' => (string)$row['state'],
            'sumgrades' => p5_relation_canonical_value(
                'sumgrades',
                $row['sumgrades']
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    };
    $multiset = static function(array $candidates) use ($signature): array {
        $result = [];
        foreach ($candidates as $candidate) {
            $key = $signature($candidate);
            $result[$key] = (int)($result[$key] ?? 0) + 1;
        }
        ksort($result, SORT_STRING);
        return $result;
    };
    $inventoryset = $multiset($resolved);
    $backupset = $multiset($rebuilt);
    $differences = p5_relation_multiset_difference($inventoryset, $backupset);
    $excluded = 0;
    foreach ($differences as $difference) {
        if ((int)$difference['backup_count'] > (int)$difference['inventory_count']) {
            $evidence['aliases_blocked']++;
            $evidence['blocked'][] = [
                'relation' => 'quiz_attempts',
                'reason' => 'backup_not_explained_by_inventory',
                'difference' => $difference,
            ];
            throw new RuntimeException(
                'QUIZ_ATTEMPT_TRANSPORT_UNEXPLAINED inventory_rows=' .
                count($resolved) . ' backup_rows=' . count($rebuilt) . '.'
            );
        }
        $excluded += (int)$difference['inventory_count'] -
            (int)$difference['backup_count'];
    }
    usort($rebuilt, static fn(array $left, array $right): int => strcmp(
        json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ));
    $evidence['quiz_attempt_projection'] = [
        'relation' => 'quiz_attempts',
        'mode' => 'legacy_transport_projection',
        'inventory_rows' => count($resolved),
        'backup_rows' => count($rebuilt),
        'effective_rows' => count($rebuilt),
        'excluded_legacy_rows' => $excluded,
        'backup_preview_rows' => count($previewrows),
        'status' => $excluded > 0 ? 'projected_from_transport' : 'matched',
        'differences' => $differences,
    ];
    return $rebuilt;
}

function p6_backup_nullish(?string $value): bool {
    $value = trim((string)$value);
    return $value === '' || $value === '$@NULL@$' || strtolower($value) === 'null';
}

/**
 * Revisa la presencia física de todos los payloads declarados por files.xml.
 * Los únicos faltantes observados y aceptados como warning son user/icon: el
 * core los transporta, pero perderlos no altera contenido académico ni
 * actividad. Cualquier otro payload interno ausente sigue siendo bloqueante.
 */
function p6_audit_backup_file_payload_presence(string $backupdirectory): array {
    $filespath = rtrim($backupdirectory, '/\\') . '/files.xml';
    if (!is_readable($filespath)) {
        throw new RuntimeException('MODULE_FILE_TRANSPORT_INVALID files.xml ausente.');
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($filespath, LIBXML_NONET)) {
        throw new RuntimeException('MODULE_FILE_TRANSPORT_INVALID files.xml no es válido.');
    }
    $audit = [
        'all_file_records' => 0,
        'directory_records' => 0,
        'external_records' => 0,
        'regenerable_records' => 0,
        'internal_payload_records' => 0,
        'internal_payloads_present' => 0,
        'missing_user_icon_payloads_warning' => 0,
        'missing_user_icon_contenthashes' => [],
        'missing_user_icon_examples' => [],
    ];
    $seenpayloads = [];
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('/files/file') ?: [] as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $audit['all_file_records']++;
        $filename = (string)(p5_dom_direct_text($node, 'filename') ?? '');
        if ($filename === '.') {
            $audit['directory_records']++;
            continue;
        }
        $repositorytype = p5_dom_direct_text($node, 'repositorytype');
        $reference = p5_dom_direct_text($node, 'reference');
        if (!p6_backup_nullish($repositorytype) || !p6_backup_nullish($reference)) {
            $audit['external_records']++;
            continue;
        }
        $component = (string)(p5_dom_direct_text($node, 'component') ?? '');
        $filearea = (string)(p5_dom_direct_text($node, 'filearea') ?? '');
        if (p5_is_regenerable_editpdf_file([
            'component' => $component,
            'filearea' => $filearea,
            'filename' => $filename,
        ])) {
            $audit['regenerable_records']++;
            continue;
        }
        $contenthash = strtolower((string)(p5_dom_direct_text($node, 'contenthash') ?? ''));
        $filesizetext = p5_dom_direct_text($node, 'filesize');
        if (!preg_match('/^[a-f0-9]{40}$/', $contenthash) ||
                $filesizetext === null || !preg_match('/^[0-9]+$/', $filesizetext)) {
            throw new RuntimeException(
                'MODULE_FILE_PAYLOAD_METADATA_INVALID file_id=' .
                (int)$node->getAttribute('id') . '.'
            );
        }
        $audit['internal_payload_records']++;
        $payload = rtrim($backupdirectory, '/\\') . '/files/' .
            substr($contenthash, 0, 2) . '/' . $contenthash;
        if (isset($seenpayloads[$contenthash])) {
            if ($seenpayloads[$contenthash] === 'present') {
                $audit['internal_payloads_present']++;
            }
            continue;
        }
        $actualsize = is_file($payload) ? filesize($payload) : false;
        if ($actualsize !== false && (int)$actualsize === (int)$filesizetext) {
            $seenpayloads[$contenthash] = 'present';
            $audit['internal_payloads_present']++;
            continue;
        }
        if ($actualsize === false && $component === 'user' && $filearea === 'icon') {
            $seenpayloads[$contenthash] = 'warning-user-icon';
            $audit['missing_user_icon_payloads_warning']++;
            $audit['missing_user_icon_contenthashes'][$contenthash] = true;
            if (count($audit['missing_user_icon_examples']) < 20) {
                $audit['missing_user_icon_examples'][] = [
                    'file_id' => (int)$node->getAttribute('id'),
                    'contextid' => (int)(p5_dom_direct_text($node, 'contextid') ?? 0),
                    'filename' => $filename,
                    'contenthash' => $contenthash,
                ];
            }
            continue;
        }
        throw new RuntimeException(
            ($actualsize === false ? 'MODULE_FILE_PAYLOAD_MISSING' :
                'MODULE_FILE_PAYLOAD_SIZE_MISMATCH') .
            ' file_id=' . (int)$node->getAttribute('id') .
            ' component=' . $component . ' filearea=' . $filearea .
            ' filename=' . $filename . ' contenthash=' . $contenthash . '.'
        );
    }
    $audit['missing_user_icon_contenthashes'] = array_keys(
        $audit['missing_user_icon_contenthashes']
    );
    sort($audit['missing_user_icon_contenthashes'], SORT_STRING);
    return $audit;
}

function p6_validate_transportable_file_payloads(
    string $backupdirectory,
    array $candidatefiles
): int {
    if (!$candidatefiles) {
        return 0;
    }
    $filespath = rtrim($backupdirectory, '/\\') . '/files.xml';
    if (!is_readable($filespath)) {
        throw new RuntimeException('MODULE_FILE_TRANSPORT_INVALID files.xml ausente.');
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($filespath, LIBXML_NONET)) {
        throw new RuntimeException('MODULE_FILE_TRANSPORT_INVALID files.xml no es válido.');
    }
    $needed = [];
    foreach ($candidatefiles as $row) {
        $fileid = (int)($row['_file_id'] ?? 0);
        if ($fileid < 1) {
            throw new RuntimeException('MODULE_FILE_TRANSPORT_INVALID file_id ausente.');
        }
        $needed[$fileid] = true;
    }
    $checked = 0;
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*[local-name()="file"]') ?: [] as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $fileid = (int)$node->getAttribute('id');
        if (!isset($needed[$fileid])) {
            continue;
        }
        unset($needed[$fileid]);
        $filename = (string)(p5_dom_direct_text($node, 'filename') ?? '');
        if ($filename === '.') {
            continue;
        }
        $filesizetext = p5_dom_direct_text($node, 'filesize');
        if ($filesizetext === null || !preg_match('/^[0-9]+$/', $filesizetext)) {
            throw new RuntimeException(
                'MODULE_FILE_PAYLOAD_METADATA_MISSING file_id=' . $fileid . '.'
            );
        }
        $filesize = (int)$filesizetext;
        $contenthash = (string)(p5_dom_direct_text($node, 'contenthash') ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $contenthash)) {
            throw new RuntimeException(
                'MODULE_FILE_PAYLOAD_METADATA_MISSING file_id=' . $fileid . '.'
            );
        }
        $payload = rtrim($backupdirectory, '/\\') . '/files/' .
            substr($contenthash, 0, 2) . '/' . $contenthash;
        $actualsize = is_file($payload) ? filesize($payload) : false;
        if ($actualsize === false || (int)$actualsize !== $filesize ||
                hash_file('sha1', $payload) !== $contenthash) {
            throw new RuntimeException(
                'MODULE_FILE_PAYLOAD_MISSING file_id=' . $fileid .
                ' contenthash=' . $contenthash . '.'
            );
        }
        $checked++;
    }
    if ($needed) {
        throw new RuntimeException(
            'MODULE_FILE_TRANSPORT_INVALID missing_file_ids=' .
            implode('|', array_keys($needed)) . '.'
        );
    }
    return $checked;
}

/**
 * Construye la vista efectiva de Fase 6. files.xml define los archivos
 * transportables y quiz_attempts admite la proyección legacy aprobada contra
 * los intentos que existen físicamente en el MBZ.
 */
function p6_effective_restore_inventory(
    array $inventory,
    string $sourceid,
    int $sourcecourseid,
    string $backupdirectory,
    ?array &$audit = null,
    ?array $degradationplan = null
): array {
    $filespath = rtrim($backupdirectory, '/\\') . '/files.xml';
    if (!is_readable($filespath)) {
        throw new RuntimeException(
            'MODULE_FILE_TRANSPORT_INVALID files.xml ausente.'
        );
    }
    $identityplan = p5_prepare_module_identities(
        $inventory,
        $sourceid,
        $sourcecourseid
    );
    $contextevidence = null;
    $activitydirectories = p5_rewrite_backup_module_idnumbers(
        $backupdirectory,
        $identityplan['modules_by_source_id'],
        $contextevidence
    );
    $fileevidence = null;
    $candidates = p5_backup_relation_candidates(
        $backupdirectory,
        $activitydirectories,
        $fileevidence
    );
    $prepared = $identityplan['inventory'];
    $modulesbysourceid = $identityplan['modules_by_source_id'];
    $primaryidsbykey = $identityplan['legacy_module_ids_by_key'];
    $nameidsbykey = [];
    foreach ($modulesbysourceid as $sourcemoduleid => $module) {
        $effectivekey = (string)$module['module_key'];
        $primaryidsbykey[$effectivekey][] = (int)$sourcemoduleid;
        $namekey = p5_module_key(
            (string)$module['modname'],
            '',
            (string)$module['name']
        );
        $nameidsbykey[$namekey][] = (int)$sourcemoduleid;
    }
    $aliasevidence = [
        'primary_legacy_key' => 0,
        'historical_name_alias' => 0,
        'structural_fallback' => 0,
        'structural_projection' => 0,
        'structural_projection_relations' => [],
        'aliases_blocked' => 0,
        'resolutions' => [],
        'blocked' => [],
    ];
    $structuralrelations = array_fill_keys([
        'activity_completions',
        'assignment_submissions',
        'assignment_grades',
        'forum_discussions',
        'forum_posts',
    ], true);
    foreach ($prepared['relations'] ?? [] as $relation => $rows) {
        if (!is_array($rows) || $relation === 'course_completions' ||
                $relation === 'files') {
            continue;
        }
        if (isset($structuralrelations[$relation])) {
            $prepared['relations'][$relation] = p6_structural_relation_projection(
                $rows,
                (string)$relation,
                $candidates[$relation] ?? [],
                $modulesbysourceid,
                $aliasevidence,
                $degradationplan
            );
            continue;
        }
        if ($relation === 'quiz_attempts') {
            $prepared['relations'][$relation] = p6_quiz_attempt_legacy_projection(
                $rows,
                $candidates[$relation] ?? [],
                $modulesbysourceid,
                $primaryidsbykey,
                $nameidsbykey,
                $aliasevidence
            );
            $prepared['counts']['quiz_attempts'] =
                count($prepared['relations'][$relation]);
            continue;
        }
        $prepared['relations'][$relation] = p6_effective_relation_activity_keys(
            $rows,
            (string)$relation,
            $modulesbysourceid,
            $primaryidsbykey,
            $nameidsbykey,
            $candidates[$relation] ?? [],
            $aliasevidence
        );
    }

    $sourcefiles = is_array($inventory['relations']['files'] ?? null)
        ? $inventory['relations']['files']
        : [];
    $transportfiles = p5_filter_comparable_files($candidates['files'] ?? []);
    $allpayloadpresence = p6_audit_backup_file_payload_presence($backupdirectory);
    $payloadschecked = p6_validate_transportable_file_payloads(
        $backupdirectory,
        $transportfiles
    );
    $projectedfiles = [];
    foreach ($transportfiles as $candidate) {
        $sourcemoduleid = (int)($candidate['_source_module_id'] ?? 0);
        if (!isset($modulesbysourceid[$sourcemoduleid])) {
            throw new RuntimeException(
                'MODULE_FILE_CONTEXT_UNRESOLVED source_module_id=' .
                $sourcemoduleid . '.'
            );
        }
        $projectedfiles[] = [
            'source_user_id' => (int)($candidate['source_user_id'] ?? 0),
            'activity_key' =>
                (string)$modulesbysourceid[$sourcemoduleid]['module_key'],
            'component' => (string)$candidate['component'],
            'filearea' => (string)$candidate['filearea'],
            'filename' => (string)$candidate['filename'],
        ];
    }
    usort($projectedfiles, static fn(array $left, array $right): int => strcmp(
        json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ));
    $prepared['relations']['files'] = $projectedfiles;
    if (isset($prepared['counts']['module_files'])) {
        $prepared['counts']['module_files'] = count($projectedfiles);
    }
    $sourcecomparable = p5_filter_comparable_files($sourcefiles);
    $fileaudit = $fileevidence ?? [];
    $fileaudit['files_source_database_rows'] = count($sourcefiles);
    $fileaudit['files_source_database_comparable_rows'] = count($sourcecomparable);
    $fileaudit['files_xml_transport_rows'] = count($candidates['files'] ?? []);
    $fileaudit['files_comparable_rows'] = count($projectedfiles);
    $fileaudit['files_regenerable_excluded_source'] =
        count($sourcefiles) - count($sourcecomparable);
    $fileaudit['files_regenerable_excluded_transport'] =
        count($candidates['files'] ?? []) - count($transportfiles);
    $fileaudit['files_payloads_checked'] = $payloadschecked;
    $fileaudit['all_payload_presence'] = $allpayloadpresence;
    $fileaudit['transport_projection_applied'] = true;

    $audit = [
        'module_identity' => $identityplan['metrics'] + [
            'module_contexts' => $contextevidence,
        ],
        'activity_aliases' => $aliasevidence,
        'files_transport' => $fileaudit,
        'degradation_plan_consumed' => $degradationplan !== null,
    ];
    return $prepared;
}
