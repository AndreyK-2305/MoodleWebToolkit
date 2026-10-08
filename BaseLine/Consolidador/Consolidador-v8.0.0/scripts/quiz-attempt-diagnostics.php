<?php
// Diagnóstico de intentos por identidad, actividad, número y evidencia estable.
declare(strict_types=1);

/**
 * Identifica intentos usando exclusivamente los campos presentes de forma
 * consistente en el inventario fuente. Ausente, cero y null son distintos.
 */
function p5_quiz_attempt_differences(
    array $source,
    array $target,
    array $identitymap,
    string $sourceid,
    int $sourcecourseid = 0,
    int $targetcourseid = 0
): array {
    $requiredfields = ['source_user_id', 'activity_key', 'state', 'sumgrades'];
    $optionalfields = ['attempt', 'preview', 'timestart', 'timefinish'];
    $schemaissues = [];
    $signaturefields = $requiredfields;
    foreach ($requiredfields as $field) {
        foreach ($source as $index => $row) {
            if (!is_array($row) || !array_key_exists($field, $row)) {
                $schemaissues[] = [
                    'field' => $field,
                    'reason' => 'required_field_absent',
                    'row_index' => $index,
                ];
            }
        }
    }
    foreach ($optionalfields as $field) {
        $present = count(array_filter(
            $source,
            static fn($row): bool => is_array($row) && array_key_exists($field, $row)
        ));
        if ($present === 0) {
            continue;
        }
        if ($present !== count($source)) {
            $schemaissues[] = [
                'field' => $field,
                'reason' => 'inconsistent_field_presence',
                'present_rows' => $present,
                'source_rows' => count($source),
            ];
            continue;
        }
        $signaturefields[] = $field;
    }
    $strongfields = array_values(array_intersect($optionalfields, $signaturefields));
    $contract = $strongfields ? 'strong' : 'historical_weak';
    if ($schemaissues) {
        return [
            'comparison_contract' => 'schema_inconsistent',
            'signature_fields' => $signaturefields,
            'schema_issues' => $schemaissues,
            'source_count' => count($source),
            'target_count' => count($target),
            'missing' => [],
            'extra' => [],
            'changed' => [],
            'unexpected' => [],
            'has_differences' => true,
        ];
    }

    $token = static function(array $row, string $field): array {
        if (!array_key_exists($field, $row)) {
            return ['presence' => 'absent', 'value' => null];
        }
        $value = $row[$field];
        if ($value !== null && in_array(
            $field,
            ['source_user_id', 'attempt', 'preview', 'timestart', 'timefinish'],
            true
        )) {
            $value = (int)$value;
        } else if ($value !== null && $field === 'sumgrades') {
            $value = round((float)$value, 5);
        } else if ($value !== null) {
            $value = (string)$value;
        }
        return ['presence' => 'present', 'value' => $value];
    };
    $keyfields = array_values(array_intersect(
        ['attempt', 'preview'],
        $signaturefields
    ));
    $exactfields = array_values(array_intersect(
        ['state', 'sumgrades', 'timestart', 'timefinish'],
        $signaturefields
    ));
    $prepare = static function(array $rows, bool $origin) use (
        $identitymap, $sourceid, $token, $keyfields, $exactfields
    ): array {
        $prepared = [];
        foreach ($rows as $row) {
            $userid = (int)($row['source_user_id'] ?? 0);
            $mapping = $origin ? ($identitymap[$sourceid . ':' . $userid] ?? null) : null;
            $targetuserid = $origin ? (int)($mapping['target_user_id'] ?? 0) : $userid;
            $identity = $targetuserid > 0 ? 'target:' . $targetuserid :
                'unmapped:' . $sourceid . ':' . $userid;
            $activity = (string)($row['activity_key'] ?? '');
            $key = ['activity_key' => $activity, 'identity' => $identity];
            foreach ($keyfields as $field) {
                $key[$field] = $token($row, $field);
            }
            $exact = [];
            foreach ($exactfields as $field) {
                $exact[$field] = $token($row, $field);
            }
            $prepared[] = [
                'row' => $row,
                'source_user_id' => $origin ? $userid : null,
                'target_user_id' => $targetuserid ?: null,
                'canonical_id' => $origin ? (string)($mapping['canonical_id'] ?? '') : '',
                'activity_key' => $activity,
                'identity' => $identity,
                'key' => json_encode($key, JSON_THROW_ON_ERROR),
                'exact' => json_encode($exact, JSON_THROW_ON_ERROR),
            ];
        }
        usort($prepared, static fn(array $a, array $b): int =>
            [$a['key'], $a['exact'], (int)($a['row']['attempt_id'] ?? 0)] <=>
            [$b['key'], $b['exact'], (int)($b['row']['attempt_id'] ?? 0)]
        );
        return $prepared;
    };
    $left = $prepare($source, true);
    $right = $prepare($target, false);
    $lgroups = [];
    $rgroups = [];
    $byactivity = [];
    $byuser = [];
    foreach ($left as $item) {
        $lgroups[$item['key']][] = $item;
    }
    foreach ($right as $item) {
        $rgroups[$item['key']][] = $item;
        $byactivity[$item['activity_key']][] = $item;
        $byuser[$item['activity_key'] . '|' . $item['identity']][] = $item;
    }
    $details = static function(?array $leftrow, ?array $rightrow, string $type) use (
        $sourcecourseid, $targetcourseid, $byactivity, $byuser,
        $keyfields, $signaturefields, $optionalfields, $token
    ): array {
        $origin = $leftrow['row'] ?? [];
        $restored = $rightrow['row'] ?? [];
        $activity = (string)($leftrow['activity_key'] ?? $rightrow['activity_key'] ?? '');
        $identity = (string)($leftrow['identity'] ?? $rightrow['identity'] ?? '');
        $pool = $byuser[$activity . '|' . $identity] ?? $byactivity[$activity] ?? [];
        $reference = $leftrow['row'] ?? $rightrow['row'] ?? [];
        $candidates = array_values(array_filter(
            $pool,
            static function(array $candidate) use ($reference, $keyfields, $token): bool {
                foreach ($keyfields as $field) {
                    if ($token($candidate['row'], $field) !== $token($reference, $field)) {
                        return false;
                    }
                }
                return true;
            }
        ));
        if (!$candidates) {
            usort($pool, static fn(array $a, array $b): int =>
                (int)($a['row']['attempt_id'] ?? 0) <=>
                (int)($b['row']['attempt_id'] ?? 0)
            );
            $candidates = array_slice($pool, 0, 4);
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $item): int => (int)($item['row']['attempt_id'] ?? 0),
            $candidates
        ))));
        sort($ids, SORT_NUMERIC);
        $targetactivity = $rightrow ?? ($candidates[0] ?? null);
        $targetvalues = $targetactivity['row'] ?? $restored;
        return [
            'classification' => $type,
            'source_course_id' => $sourcecourseid ?: null,
            'target_course_id' => $targetcourseid ?: null,
            'source_activity_key' => $leftrow['activity_key'] ?? null,
            'target_activity_key' => $targetactivity['activity_key'] ?? null,
            'source_module_id' => isset($origin['source_module_id']) ?
                (int)$origin['source_module_id'] : null,
            'target_module_id' => isset($targetactivity['row']['source_module_id']) ?
                (int)$targetactivity['row']['source_module_id'] : null,
            'source_user_id' => $leftrow['source_user_id'] ?? null,
            'canonical_id' => ($leftrow['canonical_id'] ?? '') !== '' ?
                $leftrow['canonical_id'] : null,
            'target_user_id' => $leftrow['target_user_id'] ?? $rightrow['target_user_id'] ?? null,
            'source_quiz_id' => isset($origin['quiz_id']) ? (int)$origin['quiz_id'] : null,
            'target_quiz_id' => isset($targetactivity['row']['quiz_id']) ?
                (int)$targetactivity['row']['quiz_id'] : null,
            'attempt_number' => array_key_exists('attempt', $origin)
                ? $origin['attempt'] : null,
            'target_attempt_number' => array_key_exists('attempt', $targetvalues)
                ? $targetvalues['attempt'] : null,
            'state' => $origin['state'] ?? $restored['state'] ?? null,
            'target_state' => $targetvalues['state'] ?? null,
            'timestart' => array_key_exists('timestart', $origin)
                ? $origin['timestart'] : null,
            'target_timestart' => array_key_exists('timestart', $targetvalues)
                ? $targetvalues['timestart'] : null,
            'timefinish' => array_key_exists('timefinish', $origin)
                ? $origin['timefinish'] : null,
            'target_timefinish' => array_key_exists('timefinish', $targetvalues)
                ? $targetvalues['timefinish'] : null,
            'sumgrades' => $origin['sumgrades'] ?? $restored['sumgrades'] ?? null,
            'target_sumgrades' => $targetvalues['sumgrades'] ?? null,
            'preview' => array_key_exists('preview', $origin)
                ? $origin['preview'] : null,
            'target_preview' => array_key_exists('preview', $targetvalues)
                ? $targetvalues['preview'] : null,
            'signature_fields' => $signaturefields,
            'source_field_presence' => array_combine(
                $signaturefields,
                array_map(
                    static fn(string $field): string =>
                        array_key_exists($field, $origin) ? 'present' : 'absent',
                    $signaturefields
                )
            ),
            'target_field_presence' => array_combine(
                $signaturefields,
                array_map(
                    static fn(string $field): string =>
                        array_key_exists($field, $restored) ? 'present' : 'absent',
                    $signaturefields
                )
            ),
            'source_optional_field_presence' => array_combine(
                $optionalfields,
                array_map(
                    static fn(string $field): string =>
                        array_key_exists($field, $origin) ? 'present' : 'absent',
                    $optionalfields
                )
            ),
            'target_optional_field_presence' => array_combine(
                $optionalfields,
                array_map(
                    static fn(string $field): string =>
                        array_key_exists($field, $targetvalues) ? 'present' : 'absent',
                    $optionalfields
                )
            ),
            'source_attempt_id' => isset($origin['attempt_id']) ?
                (int)$origin['attempt_id'] : null,
            'target_attempt_id' => isset($restored['attempt_id']) ?
                (int)$restored['attempt_id'] : null,
            'target_candidate_ids' => $ids,
        ];
    };
    $missing = [];
    $extra = [];
    $changed = [];
    $keys = array_unique(array_merge(array_keys($lgroups), array_keys($rgroups)));
    sort($keys, SORT_STRING);
    foreach ($keys as $key) {
        $sourcegroup = $lgroups[$key] ?? [];
        $targetgroup = $rgroups[$key] ?? [];
        $targetbyexact = [];
        foreach ($targetgroup as $index => $row) {
            $targetbyexact[$row['exact']][] = $index;
        }
        $pairedsource = [];
        $pairedtarget = [];
        foreach ($sourcegroup as $index => $row) {
            if (empty($targetbyexact[$row['exact']])) {
                continue;
            }
            $targetindex = array_shift($targetbyexact[$row['exact']]);
            $pairedsource[$index] = true;
            $pairedtarget[$targetindex] = true;
        }
        $sourceleft = array_values(array_filter($sourcegroup,
            static fn(int $index): bool => !isset($pairedsource[$index]), ARRAY_FILTER_USE_KEY));
        $targetleft = array_values(array_filter($targetgroup,
            static fn(int $index): bool => !isset($pairedtarget[$index]), ARRAY_FILTER_USE_KEY));
        // Una misma identidad, actividad, número y preview con distinto estado,
        // fecha o nota es un intento cambiado, no uno ausente más uno extra.
        $paircount = min(count($sourceleft), count($targetleft));
        for ($i = 0; $i < $paircount; $i++) {
            $item = $details($sourceleft[$i], $targetleft[$i], 'changed');
            $s = $sourceleft[$i]['row'];
            $t = $targetleft[$i]['row'];
            $item['changed_fields'] = [];
            foreach ($exactfields as $field) {
                $expected = $token($s, $field);
                $actual = $token($t, $field);
                if ($expected !== $actual) {
                    $item['changed_fields'][$field] = ['source' => $expected, 'target' => $actual];
                }
            }
            $changed[] = $item;
        }
        for ($i = $paircount; $i < count($sourceleft); $i++) {
            $row = $sourceleft[$i];
            $same = array_values(array_filter($sourcegroup,
                static fn(array $candidate): bool => $candidate['exact'] === $row['exact']));
            $item = $details($row, null, 'missing');
            $item['missing'] = 1;
            $item['exact_attempt_ambiguous'] = count($same) > 1;
            $item['source_attempt_candidate_ids'] = array_values(array_map(
                static fn(array $candidate): int => (int)($candidate['row']['attempt_id'] ?? 0),
                $same
            ));
            // Campo anterior de RC2: conserva el grupo ante coincidencias ambiguas.
            $item['source_candidates'] = array_values(array_map(
                static fn(array $candidate): array => $candidate['row'], $same
            ));
            if ($item['exact_attempt_ambiguous']) {
                $item['source_attempt_id'] = null;
            }
            $missing[] = $item;
        }
        for ($i = $paircount; $i < count($targetleft); $i++) {
            $item = $details(null, $targetleft[$i], 'extra');
            $item['unexpected'] = 1;
            $extra[] = $item;
        }
    }
    return [
        'comparison_contract' => $contract,
        'signature_fields' => $signaturefields,
        'schema_issues' => [],
        'source_count' => count($source),
        'target_count' => count($target),
        'missing' => $missing,
        'extra' => $extra,
        'changed' => $changed,
        'unexpected' => $extra,
        'has_differences' => $missing !== [] || $extra !== [] || $changed !== [] ||
            count($source) !== count($target),
    ];
}

/** Conserva el reporte previo si un nuevo intento produce otra diferencia. */
function p5_write_quiz_attempt_report(string $path, array $diagnostic, array $context): ?string {
    if (!$diagnostic['has_differences']) {
        return null;
    }
    $report = [
        'schema_version' => '1.0',
        'phase' => 'quiz-attempt-verification-failed',
        'generated_at_utc' => gmdate('c'),
        'verification_state' => 'verification_failed',
        'source_course_id' => $context['source_course_id'] ?? null,
        'target_course_id' => $context['target_course_id'] ?? null,
        'course_key' => $context['course_key'] ?? null,
        'source_id' => $context['source_id'] ?? null,
        'comparison_contract' => $diagnostic['comparison_contract'] ?? null,
        'signature_fields' => $diagnostic['signature_fields'] ?? [],
        'schema_issues' => $diagnostic['schema_issues'] ?? [],
        'source_count' => $diagnostic['source_count'],
        'target_count' => $diagnostic['target_count'],
        'missing' => $diagnostic['missing'],
        'extra' => $diagnostic['extra'],
        'changed' => $diagnostic['changed'],
    ];
    if (is_file($path)) {
        $previous = p5_read_json($path);
        $changes = false;
        foreach (['source_course_id', 'target_course_id', 'comparison_contract',
            'signature_fields', 'schema_issues', 'source_count', 'target_count',
            'missing', 'extra', 'changed'] as $field) {
            if (($previous[$field] ?? null) !== ($report[$field] ?? null)) {
                $changes = true;
                break;
            }
        }
        if (!$changes) {
            return hash_file('sha256', $path);
        }
        $archived = $path . '.previous-' . substr(hash_file('sha256', $path), 0, 16) . '.json';
        if (!is_file($archived) && !copy($path, $archived)) {
            throw new RuntimeException('No se pudo preservar el diagnóstico de quiz anterior.');
        }
    }
    p5_write_json($path, $report);
    return hash_file('sha256', $path);
}
