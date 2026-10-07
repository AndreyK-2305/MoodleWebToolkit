<?php
// Contrato de degradaciones detectables antes de iniciar el restore masivo.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

const P6_DEGRADATION_ANALYZER_VERSION = '2.2';

function p6_degradation_normalize_text(string $value): string {
    return (string)preg_replace('/\s+/u', ' ', trim($value));
}

function p6_degradation_normalize_member(string $member): string {
    $member = p6_validate_archive_member($member);
    return (string)preg_replace('#^(?:\./)+#', '', str_replace('\\', '/', $member));
}

/** @return array{type:string,members:array<string,array{name:string,size:?int}>,listing_sha256:string} */
function p6_degradation_archive_index(string $backup): array {
    if (!is_readable($backup)) {
        throw new RuntimeException('DEGRADATION_ARCHIVE_UNREADABLE: ' . $backup);
    }
    $members = [];
    $zip = new ZipArchive();
    if ($zip->open($backup) === true) {
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $zip->getNameIndex($index);
                if (!is_array($stat) || !is_string($name)) {
                    throw new RuntimeException('DEGRADATION_ARCHIVE_ENTRY_INVALID');
                }
                $candidate = (string)preg_replace('#^(?:\./)+#', '', $name);
                if ($candidate === '' || str_ends_with($candidate, '/')) {
                    continue;
                }
                $normalized = p6_degradation_normalize_member($name);
                if (isset($members[$normalized])) {
                    throw new RuntimeException(
                        'DEGRADATION_ARCHIVE_DUPLICATE_MEMBER: ' . $normalized
                    );
                }
                $members[$normalized] = [
                    'name' => $name,
                    'size' => isset($stat['size']) ? (int)$stat['size'] : null,
                ];
            }
        } finally {
            $zip->close();
        }
        $type = 'zip';
    } else {
        $listing = p6_archive_command_output(
            p6_tar_list_command($backup),
            'listar el MBZ para el preflight de degradaciones'
        );
        foreach (preg_split('/\r?\n/', $listing) ?: [] as $name) {
            if ($name === '') {
                continue;
            }
            $candidate = (string)preg_replace('#^(?:\./)+#', '', $name);
            if ($candidate === '' || str_ends_with($candidate, '/')) {
                continue;
            }
            $normalized = p6_degradation_normalize_member($name);
            if (isset($members[$normalized])) {
                throw new RuntimeException(
                    'DEGRADATION_ARCHIVE_DUPLICATE_MEMBER: ' . $normalized
                );
            }
            $members[$normalized] = ['name' => $name, 'size' => null];
        }
        $type = 'tar';
    }
    ksort($members, SORT_STRING);
    return [
        'type' => $type,
        'members' => $members,
        'listing_sha256' => hash('sha256', json_encode(
            array_keys($members),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )),
    ];
}

function p6_degradation_read_member(
    string $backup,
    array $index,
    string $normalized
): string {
    $entry = $index['members'][$normalized] ?? null;
    if (!is_array($entry)) {
        throw new RuntimeException('DEGRADATION_MEMBER_MISSING: ' . $normalized);
    }
    if (($index['type'] ?? '') === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($backup) !== true) {
            throw new RuntimeException('DEGRADATION_ZIP_REOPEN_FAILED');
        }
        try {
            $contents = $zip->getFromName((string)$entry['name']);
            if (!is_string($contents)) {
                throw new RuntimeException('DEGRADATION_MEMBER_UNREADABLE: ' . $normalized);
            }
            return $contents;
        } finally {
            $zip->close();
        }
    }
    return p6_archive_command_output(
        p6_tar_member_to_stdout_command($backup, (string)$entry['name']),
        'leer ' . $normalized . ' del MBZ'
    );
}

function p6_degradation_remove_tree(string $directory): void {
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $item) {
        $path = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            p6_degradation_remove_tree($path);
            continue;
        }
        if (!unlink($path)) {
            throw new RuntimeException('DEGRADATION_CACHE_CLEANUP_FAILED');
        }
    }
    if (!rmdir($directory)) {
        throw new RuntimeException('DEGRADATION_CACHE_CLEANUP_FAILED');
    }
}

/** Extrae únicamente XML; nunca copia payloads files/<contenthash>. */
function p6_degradation_extract_xml_metadata(
    string $backup,
    array $index,
    string $cache,
    string $backupsha
): array {
    $marker = rtrim($cache, '/\\') . '/.metadata-cache.json';
    if (is_readable($marker)) {
        $cached = json_decode((string)file_get_contents($marker), true);
        if (is_array($cached) &&
                ($cached['source_backup_sha256'] ?? '') === $backupsha &&
                ($cached['listing_sha256'] ?? '') === $index['listing_sha256'] &&
                ($cached['status'] ?? '') === 'complete') {
            return ['cache_hit' => true, 'xml_members' => (int)$cached['xml_members']];
        }
    }
    p6_degradation_remove_tree($cache);
    if (!mkdir($cache, 0770, true) && !is_dir($cache)) {
        throw new RuntimeException('DEGRADATION_CACHE_CREATE_FAILED');
    }
    $xmlmembers = [];
    foreach ($index['members'] as $normalized => $entry) {
        if (str_ends_with(strtolower($normalized), '.xml')) {
            $xmlmembers[$normalized] = (string)$entry['name'];
        }
    }
    if (!isset($xmlmembers['files.xml']) || !isset($xmlmembers['moodle_backup.xml'])) {
        throw new RuntimeException('DEGRADATION_ESSENTIAL_XML_MISSING');
    }
    if (($index['type'] ?? '') === 'zip') {
        foreach ($xmlmembers as $normalized => $member) {
            $destination = $cache . '/' . $normalized;
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
                throw new RuntimeException('DEGRADATION_CACHE_CREATE_FAILED');
            }
            $contents = p6_degradation_read_member($backup, $index, $normalized);
            if (file_put_contents($destination, $contents, LOCK_EX) === false) {
                throw new RuntimeException('DEGRADATION_CACHE_WRITE_FAILED');
            }
        }
    } else {
        foreach (array_chunk(array_values($xmlmembers), 750) as $members) {
            $command = array_merge([
                'tar', '--extract', '--file', $backup, '--directory', $cache,
                '--no-same-owner', '--no-same-permissions', '--',
            ], $members);
            p6_archive_command_output($command, 'extraer metadata XML selectiva del MBZ');
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            if ($item->isLink() || (!$item->isDir() && !$item->isFile())) {
                throw new RuntimeException('DEGRADATION_CACHE_UNSAFE_ENTRY');
            }
        }
    }
    $document = [
        'schema_version' => '1.0',
        'status' => 'complete',
        'source_backup_sha256' => $backupsha,
        'listing_sha256' => $index['listing_sha256'],
        'xml_members' => count($xmlmembers),
    ];
    p5_write_json($marker, $document);
    return ['cache_hit' => false, 'xml_members' => count($xmlmembers)];
}

function p6_degradation_resolution_map(array $rows): array {
    $result = [];
    foreach ($rows as $row) {
        $coursekey = strtoupper(trim((string)($row['course_key'] ?? '')));
        $type = strtolower(trim((string)($row['type'] ?? '')));
        $relation = strtolower(trim((string)($row['relation'] ?? '')));
        $identifier = strtolower(trim((string)($row['identifier'] ?? '')));
        $decision = strtolower(trim((string)($row['decision'] ?? '')));
        $operator = p6_degradation_normalize_text((string)($row['operator'] ?? ''));
        $evidence = p6_degradation_normalize_text((string)($row['evidence'] ?? ''));
        $reason = p6_degradation_normalize_text((string)($row['reason'] ?? ''));
        if ($coursekey === '' && $type === '' && $relation === '' &&
                $identifier === '' && $decision === '' && $operator === '' &&
                $evidence === '' && $reason === '') {
            continue;
        }
        $validstructural = $type === 'structural_mismatch' &&
            preg_match('/^[a-z][a-z0-9_]*$/', $relation) &&
            $identifier === '';
        $validmissing = $type === 'missing_payload' &&
            $relation === 'files' && preg_match('/^[1-9][0-9]*$/', $identifier) &&
            $operator !== '' && $evidence !== '';
        if (!preg_match('/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/', $coursekey) ||
                (!$validstructural && !$validmissing) ||
                $decision !== 'accept_warning' || $reason === '') {
            throw new RuntimeException('DEGRADATION_RESOLUTION_INVALID');
        }
        $canonical = [
            'course_key' => $coursekey,
            'type' => $type,
            'relation' => $relation,
            'identifier' => $identifier,
            'decision' => $decision,
            'operator' => $operator,
            'evidence' => $evidence,
            'reason' => $reason,
        ];
        $key = implode('|', [$coursekey, $type, $relation, $identifier]);
        if (isset($result[$key])) {
            throw new RuntimeException('DEGRADATION_RESOLUTION_DUPLICATE: ' . $key);
        }
        $result[$key] = $canonical;
    }
    ksort($result, SORT_STRING);
    return $result;
}

function p6_degradation_course_resolutions(array $resolutions, string $coursekey): array {
    $coursekey = strtoupper(trim($coursekey));
    $rows = array_values(array_filter(
        $resolutions,
        static fn(array $row): bool => ($row['course_key'] ?? '') === $coursekey
    ));
    usort($rows, static fn(array $a, array $b): int =>
        [$a['course_key'], $a['type'], $a['relation'], $a['identifier'],
            $a['decision'], $a['operator'], $a['evidence'], $a['reason']] <=>
        [$b['course_key'], $b['type'], $b['relation'], $b['identifier'],
            $b['decision'], $b['operator'], $b['evidence'], $b['reason']]
    );
    return $rows;
}

function p6_degradation_course_resolution_hash(
    array $resolutions,
    string $coursekey
): string {
    return p6_value_sha256(
        p6_degradation_course_resolutions($resolutions, $coursekey)
    );
}

function p6_degradation_find_resolution(
    array $resolutions,
    string $coursekey,
    string $type,
    string $relation,
    string $identifier = ''
): ?array {
    $key = implode('|', [
        strtoupper(trim($coursekey)),
        strtolower(trim($type)),
        strtolower(trim($relation)),
        strtolower(trim($identifier)),
    ]);
    return isset($resolutions[$key]) && is_array($resolutions[$key])
        ? $resolutions[$key] : null;
}

function p6_degradation_source_integrity_map(array $rows): array {
    $result = [];
    foreach ($rows as $row) {
        $sourceid = strtolower(trim((string)($row['source_id'] ?? '')));
        $policy = strtolower(trim((string)($row['integrity_policy'] ?? '')));
        $operator = p6_degradation_normalize_text((string)($row['operator'] ?? ''));
        $evidence = p6_degradation_normalize_text((string)($row['evidence'] ?? ''));
        $reason = p6_degradation_normalize_text((string)($row['reason'] ?? ''));
        if ($sourceid === '' && $policy === '' && $operator === '' &&
                $evidence === '' && $reason === '') {
            continue;
        }
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
                !in_array($policy, ['expected_complete', 'known_degraded', 'unknown'], true) ||
                $operator === '' || $evidence === '' || $reason === '' ||
                isset($result[$sourceid])) {
            throw new RuntimeException('SOURCE_INTEGRITY_POLICY_INVALID: ' . $sourceid);
        }
        $result[$sourceid] = [
            'source_id' => $sourceid,
            'integrity_policy' => $policy,
            'operator' => $operator,
            'evidence' => $evidence,
            'reason' => $reason,
        ];
    }
    ksort($result, SORT_STRING);
    return $result;
}

function p6_degradation_source_integrity_policy(
    array $policies,
    string $sourceid
): array {
    $sourceid = strtolower(trim($sourceid));
    return $policies[$sourceid] ?? [
        'source_id' => $sourceid,
        'integrity_policy' => 'unknown',
        'operator' => '',
        'evidence' => '',
        'reason' => 'No existe una política de integridad explícita para esta fuente.',
    ];
}

function p6_degradation_source_integrity_hash(array $policy): string {
    return p6_value_sha256($policy);
}

function p6_degradation_plan_reusable(array $plan, array $dependencies): bool {
    if (($plan['schema_version'] ?? '') !== '1.1' ||
            ($plan['analyzer_version'] ?? '') !== P6_DEGRADATION_ANALYZER_VERSION) {
        return false;
    }
    foreach ($dependencies as $field => $expected) {
        if (($plan[$field] ?? null) !== $expected) {
            return false;
        }
    }
    return true;
}

function p6_degradation_file_items(
    string $filesxml,
    array $members,
    string $coursekey,
    string $sourceid,
    array $sourceintegrity,
    array $resolutions = []
): array {
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->loadXML($filesxml, LIBXML_NONET)) {
        throw new RuntimeException('DEGRADATION_FILES_XML_INVALID');
    }
    $items = [];
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('/files/file') ?: [] as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $filename = (string)(p5_dom_direct_text($node, 'filename') ?? '');
        if ($filename === '.') {
            continue;
        }
        $repositorytype = p5_dom_direct_text($node, 'repositorytype');
        $reference = p5_dom_direct_text($node, 'reference');
        if (!p6_backup_nullish($repositorytype) || !p6_backup_nullish($reference)) {
            continue;
        }
        $component = (string)(p5_dom_direct_text($node, 'component') ?? '');
        $filearea = (string)(p5_dom_direct_text($node, 'filearea') ?? '');
        if (p5_is_regenerable_editpdf_file([
            'component' => $component,
            'filearea' => $filearea,
            'filename' => $filename,
        ])) {
            continue;
        }
        $contenthash = strtolower((string)(p5_dom_direct_text($node, 'contenthash') ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/', $contenthash)) {
            throw new RuntimeException(
                'DEGRADATION_FILE_METADATA_INVALID file_id=' .
                (int)$node->getAttribute('id')
            );
        }
        $payload = 'files/' . substr($contenthash, 0, 2) . '/' . $contenthash;
        if (isset($members[$payload])) {
            continue;
        }
        $fileid = (int)$node->getAttribute('id');
        $resolution = p6_degradation_find_resolution(
            $resolutions,
            $coursekey,
            'missing_payload',
            'files',
            (string)$fileid
        );
        $known = ($sourceintegrity['integrity_policy'] ?? 'unknown') ===
            'known_degraded';
        $approved = is_array($resolution);
        $usericonwarning = $component === 'user' && $filearea === 'icon';
        $items[] = [
            'course_key' => $coursekey,
            'source' => $sourceid,
            'type' => 'missing_payload',
            'source_integrity' => (string)($sourceintegrity['integrity_policy'] ?? 'unknown'),
            'classification' => $usericonwarning
                ? 'user_icon_missing_payload_warning'
                : ($known
                    ? 'known_source_degradation'
                    : ($approved
                        ? 'unexpected_missing_payload_approved'
                        : 'unexpected_missing_payload')),
            'file_id' => $fileid,
            'component' => $component,
            'filearea' => $filearea,
            'filename' => $filename,
            'contenthash' => $contenthash,
            'relation' => 'files',
            'reason' => $usericonwarning
                ? 'files.xml referencia un avatar user/icon cuyo payload físico no fue transportado por el MBZ'
                : ($approved
                    ? (string)$resolution['reason']
                    : 'files.xml referencia un payload físico ausente del MBZ sellado'),
            'action' => ($usericonwarning || $known || $approved)
                ? 'omit_known_missing' : 'manual_review',
            'blocking' => !$usericonwarning && !$known && !$approved,
            'resolution' => $resolution,
        ];
    }
    usort($items, static fn(array $a, array $b): int =>
        [$a['file_id'], $a['contenthash']] <=> [$b['file_id'], $b['contenthash']]
    );
    return $items;
}

function p6_degradation_structural_items(
    array $inventory,
    string $sourceid,
    int $sourcecourseid,
    string $coursekey,
    string $metadata,
    array $resolutions
): array {
    $identity = p5_prepare_module_identities($inventory, $sourceid, $sourcecourseid);
    $contexts = null;
    $activities = p5_rewrite_backup_module_idnumbers(
        $metadata,
        $identity['modules_by_source_id'],
        $contexts
    );
    $fileevidence = null;
    $candidates = p5_backup_relation_candidates($metadata, $activities, $fileevidence);
    $items = [];
    foreach ([
        'activity_completions', 'assignment_submissions', 'assignment_grades',
        'forum_discussions', 'forum_posts',
    ] as $relation) {
        $rows = $identity['inventory']['relations'][$relation] ?? [];
        if (!is_array($rows)) {
            throw new RuntimeException('DEGRADATION_RELATION_SCHEMA_INVALID: ' . $relation);
        }
        $structural = $candidates[$relation] ?? [];
        $fields = p5_relation_semantic_fields($rows, $relation);
        $expected = p5_relation_multiset($rows, $fields, $relation);
        $actual = p5_relation_multiset($structural, $fields, $relation);
        $differences = p5_relation_multiset_difference($expected, $actual);
        if (!$differences) {
            continue;
        }
        $rowdifference = array_sum(array_map(
            static fn(array $row): int => abs((int)$row['difference']),
            $differences
        ));
        $items[] = [
            'course_key' => $coursekey,
            'source' => $sourceid,
            'type' => 'structural_mismatch',
            'event' => 'STRUCTURAL_OBSERVATION',
            'classification' => 'structural_observation',
            'file_id' => null,
            'component' => '',
            'filearea' => '',
            'filename' => '',
            'contenthash' => '',
            'relation' => $relation,
            'inventory_rows' => count($rows),
            'structural_rows' => count($structural),
            'row_difference' => $rowdifference,
            'differences' => array_slice($differences, 0, 100),
            'reason' => 'La representación entre versiones se valida mediante restore nativo y verificación posterior.',
            'action' => 'delegate_to_moodle_native_restore',
            'blocking' => false,
        ];
    }
    return $items;
}

function p6_build_course_degradation_plan(
    string $backup,
    string $backupsha,
    array $inventory,
    string $sourceid,
    int $sourcecourseid,
    string $coursekey,
    string $cache,
    array $resolutions = [],
    array $sourceintegrity = []
): array {
    $started = microtime(true);
    $index = p6_degradation_archive_index($backup);
    $filesxml = p6_degradation_read_member($backup, $index, 'files.xml');
    $items = p6_degradation_file_items(
        $filesxml,
        $index['members'],
        $coursekey,
        $sourceid,
        $sourceintegrity,
        $resolutions
    );
    $cacheinfo = p6_degradation_extract_xml_metadata(
        $backup,
        $index,
        $cache,
        $backupsha
    );
    try {
        $items = array_merge($items, p6_degradation_structural_items(
            $inventory,
            $sourceid,
            $sourcecourseid,
            $coursekey,
            $cache,
            $resolutions
        ));
    } catch (Throwable $error) {
        $items[] = [
            'course_key' => $coursekey,
            'source' => $sourceid,
            'type' => 'structural_analysis_error',
            'classification' => 'blocking',
            'relation' => '',
            'reason' => $error->getMessage(),
            'action' => 'block_before_restore',
            'blocking' => true,
        ];
    }
    $blocking = count(array_filter(
        $items,
        static fn(array $item): bool => ($item['blocking'] ?? true) === true
    ));
    $missing = count(array_filter(
        $items,
        static fn(array $item): bool => ($item['type'] ?? '') === 'missing_payload'
    ));
    $knownmissing = count(array_filter(
        $items,
        static fn(array $item): bool =>
            ($item['classification'] ?? '') === 'known_source_degradation'
    ));
    $unexpectedmissing = count(array_filter(
        $items,
        static fn(array $item): bool => in_array(
            $item['classification'] ?? '',
            ['unexpected_missing_payload', 'unexpected_missing_payload_approved'],
            true
        )
    ));
    $unexpectedapproved = count(array_filter(
        $items,
        static fn(array $item): bool =>
            ($item['classification'] ?? '') === 'unexpected_missing_payload_approved'
    ));
    $itemstoomit = count(array_filter(
        $items,
        static fn(array $item): bool =>
            ($item['type'] ?? '') === 'missing_payload' &&
            ($item['action'] ?? '') === 'omit_known_missing' &&
            ($item['blocking'] ?? true) === false
    ));
    $structural = count(array_filter(
        $items,
        static fn(array $item): bool => ($item['type'] ?? '') === 'structural_mismatch'
    ));
    return [
        'schema_version' => '1.1',
        'phase' => '6-course-degradation-plan',
        'analyzer_version' => P6_DEGRADATION_ANALYZER_VERSION,
        'generated_at_utc' => gmdate('c'),
        'course_key' => $coursekey,
        'source' => $sourceid,
        'source_course_id' => $sourcecourseid,
        'source_backup_sha256' => $backupsha,
        'archive_type' => $index['type'],
        'archive_listing_sha256' => $index['listing_sha256'],
        'status' => $blocking > 0 ? 'blocking' : ($items ? 'warnings_detected' : 'clean'),
        'blocking_errors' => $blocking,
        'missing_payloads' => $missing,
        'known_degraded_missing_payloads' => $knownmissing,
        'unexpected_missing_payloads' => $unexpectedmissing,
        'unexpected_missing_payloads_approved' => $unexpectedapproved,
        'structural_warnings' => $structural - count(array_filter(
            $items,
            static fn(array $item): bool =>
                ($item['type'] ?? '') === 'structural_mismatch' &&
                ($item['blocking'] ?? true) === true
        )),
        'items_to_omit' => $itemstoomit,
        'items' => array_values($items),
        'metrics' => [
            'analysis_seconds' => round(microtime(true) - $started, 6),
            'archive_bytes' => (int)filesize($backup),
            'archive_members_inspected' => count($index['members']),
            'xml_members_extracted' => (int)$cacheinfo['xml_members'],
            'cache_hit' => (bool)$cacheinfo['cache_hit'],
        ],
        'destination_write_performed' => false,
    ];
}

function p6_degradation_approved_file_ids(array $plan): array {
    $ids = [];
    foreach ($plan['items'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'missing_payload' &&
                ($item['action'] ?? '') === 'omit_known_missing' &&
                ($item['blocking'] ?? true) === false) {
            $id = (int)($item['file_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
    }
    ksort($ids, SORT_NUMERIC);
    return $ids;
}

function p6_degradation_allows_structural(array $plan, string $relation): bool {
    foreach ($plan['items'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'structural_mismatch' &&
                ($item['relation'] ?? '') === $relation &&
                ($item['action'] ?? '') === 'delegate_to_moodle_native_restore' &&
                ($item['blocking'] ?? true) === false) {
            return true;
        }
    }
    return false;
}

function p6_load_course_degradation_plan(
    string $phase6,
    string $coursekey,
    string $expectedplansha,
    string $expectedmanifestsha
): array {
    $phase6 = rtrim($phase6, '/\\');
    $planpath = $phase6 . '/course-degradation-plans/plan-' .
        p6_backup_basename($coursekey) . '.json';
    if (!preg_match('/^[a-f0-9]{64}$/', $expectedplansha) ||
            !is_readable($planpath) ||
            hash_file('sha256', $planpath) !== $expectedplansha) {
        throw new RuntimeException('DEGRADATION_COURSE_PLAN_SEAL_INVALID: ' . $coursekey);
    }
    $plan = p5_read_json($planpath);
    if (($plan['course_key'] ?? '') !== $coursekey ||
            ($plan['batch_manifest_sha256'] ?? '') !== $expectedmanifestsha ||
            ($plan['worker_course_precheck'] ?? null) !== true ||
            ($plan['status'] ?? '') === 'blocking' ||
            (int)($plan['blocking_errors'] ?? -1) !== 0) {
        throw new RuntimeException('DEGRADATION_COURSE_PLAN_INVALID: ' . $coursekey);
    }
    return $plan;
}

function p6_load_approved_degradation_plan(
    string $phase6,
    string $coursekey,
    string $expectedapprovalsha,
    string $expectedmanifestsha
): array {
    $phase6 = rtrim($phase6, '/\\');
    $approvalpath = $phase6 . '/degradation_approval.json';
    $batchpath = $phase6 . '/degradation_batch.json';
    if (!is_readable($approvalpath) ||
            hash_file('sha256', $approvalpath) !== $expectedapprovalsha) {
        throw new RuntimeException('DEGRADATION_APPROVAL_SEAL_INVALID');
    }
    $approval = p5_read_json($approvalpath);
    $batch = p5_read_json($batchpath);
    if (($approval['status'] ?? '') !== 'approved' ||
            ($approval['degradation_batch_sha256'] ?? '') !==
                hash_file('sha256', $batchpath) ||
            ($approval['batch_manifest_sha256'] ?? '') !== $expectedmanifestsha ||
            ($batch['batch_manifest_sha256'] ?? '') !== $expectedmanifestsha ||
            ($approval['plans_sha256'] ?? '') !== ($batch['plans_sha256'] ?? '') ||
            (int)($batch['totals']['blocking_errors'] ?? -1) !== 0) {
        throw new RuntimeException('DEGRADATION_APPROVAL_CONTRACT_INVALID');
    }
    $entry = null;
    foreach ($batch['plans'] ?? [] as $candidate) {
        if (($candidate['course_key'] ?? '') === $coursekey) {
            $entry = $candidate;
            break;
        }
    }
    if (!is_array($entry)) {
        throw new RuntimeException('DEGRADATION_COURSE_PLAN_MISSING: ' . $coursekey);
    }
    $planpath = $phase6 . '/' . ltrim((string)$entry['plan_file'], '/\\');
    if (!is_readable($planpath) ||
            hash_file('sha256', $planpath) !== ($entry['plan_sha256'] ?? '')) {
        throw new RuntimeException('DEGRADATION_COURSE_PLAN_SEAL_INVALID: ' . $coursekey);
    }
    $plan = p5_read_json($planpath);
    if (($plan['course_key'] ?? '') !== $coursekey ||
            ($plan['status'] ?? '') === 'blocking' ||
            (int)($plan['blocking_errors'] ?? -1) !== 0) {
        throw new RuntimeException('DEGRADATION_COURSE_PLAN_INVALID: ' . $coursekey);
    }
    return $plan;
}

/** Retira solo filas files.xml aprobadas; nunca toca el MBZ original. */
function p6_apply_degradation_plan_to_extracted_backup(
    string $directory,
    array $plan
): array {
    if (($plan['schema_version'] ?? '') !== '1.1' ||
            ($plan['phase'] ?? '') !== '6-course-degradation-plan' ||
            ($plan['analyzer_version'] ?? '') !== P6_DEGRADATION_ANALYZER_VERSION ||
            ($plan['status'] ?? '') === 'blocking' ||
            (int)($plan['blocking_errors'] ?? 0) !== 0) {
        throw new RuntimeException('DEGRADATION_PLAN_NOT_APPROVED_FOR_CONSUMPTION');
    }
    $approved = p6_degradation_approved_file_ids($plan);
    if (!$approved) {
        return ['omitted_file_ids' => [], 'files_xml_changed' => false];
    }
    $path = rtrim($directory, '/\\') . '/files.xml';
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!$dom->load($path, LIBXML_NONET)) {
        throw new RuntimeException('DEGRADATION_FILES_XML_INVALID_AT_CONSUMPTION');
    }
    $removed = [];
    foreach (iterator_to_array($dom->getElementsByTagName('file')) as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $id = (int)$node->getAttribute('id');
        if (isset($approved[$id])) {
            $node->parentNode?->removeChild($node);
            $removed[$id] = true;
        }
    }
    ksort($removed, SORT_NUMERIC);
    if (array_keys($removed) !== array_keys($approved)) {
        throw new RuntimeException('DEGRADATION_APPROVED_FILE_ID_NOT_FOUND');
    }
    $temporary = $path . '.rc2-' . bin2hex(random_bytes(6));
    if ($dom->save($temporary) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('DEGRADATION_FILES_XML_WRITE_FAILED');
    }
    return [
        'omitted_file_ids' => array_map('intval', array_keys($removed)),
        'files_xml_changed' => true,
    ];
}
