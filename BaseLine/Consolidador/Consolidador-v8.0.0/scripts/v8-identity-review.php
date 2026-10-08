<?php
// Importa decisiones fuzzy editadas a un contrato consumido por Fase 3.
declare(strict_types=1);
require_once __DIR__ . '/v8-preparation.php';

function v8ir_fail(string $message): never {
    throw new V8PreparationException('V8_IDENTITY_REVIEW_INVALID: ' . $message);
}

function v8ir_import(array $original, array $review): array {
    $originalByGroup = [];
    foreach ($original as $row) {
        $group = (string)($row['candidate_group'] ?? '');
        if ($group === '' || isset($originalByGroup[$group])) {
            v8ir_fail('candidate_group original inválido o repetido.');
        }
        $originalByGroup[$group] = $row;
    }
    if (count($originalByGroup) !== count($review)) {
        v8ir_fail('la revisión debe decidir exactamente todos los candidatos actuales.');
    }
    $output = [];
    foreach ($review as $row) {
        $group = (string)($row['candidate_group'] ?? '');
        if (!isset($originalByGroup[$group])) {
            v8ir_fail("candidato desconocido: $group");
        }
        $sealed = $originalByGroup[$group];
        foreach (['source_a','source_user_id_a','canonical_id_a','canonical_members_a',
                'source_b','source_user_id_b','canonical_id_b','canonical_members_b',
                'other_evidence'] as $field) {
            if ((string)($row[$field] ?? '') !== (string)($sealed[$field] ?? '')) {
                v8ir_fail("$group alteró el campo sellado $field.");
            }
        }
        $decision = strtoupper(trim((string)($row['resolution'] ?? '')));
        if (!in_array($decision, ['MERGE','KEEP_SEPARATE','IGNORE'], true)) {
            v8ir_fail("$group requiere MERGE, KEEP_SEPARATE o IGNORE.");
        }
        foreach (['operator','decision_timestamp_utc','evidence_reference','justification']
                as $field) {
            if (trim((string)($row[$field] ?? '')) === '') {
                v8ir_fail("$group requiere $field.");
            }
        }
        $timestamp = trim((string)$row['decision_timestamp_utc']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/',
                $timestamp) || strtotime($timestamp) === false) {
            v8ir_fail("$group requiere fecha ISO 8601 con zona horaria.");
        }
        $accounts = array_values(array_unique(array_filter(array_map('trim',
            explode('|', (string)$row['canonical_members_a'] . '|' .
                (string)$row['canonical_members_b'])))));
        sort($accounts, SORT_STRING);
        $canonicalTarget = trim((string)($row['canonical_target'] ?? ''));
        $canonicalEmail = mb_strtolower(trim((string)($row['canonical_email'] ?? '')),
            'UTF-8');
        $canonicalUsername = mb_strtolower(trim((string)($row['canonical_username'] ?? '')),
            'UTF-8');
        $oauthPolicy = trim((string)($row['oauth_policy'] ?? ''));
        if ($decision === 'MERGE') {
            if (!in_array($canonicalTarget, $accounts, true) ||
                    filter_var($canonicalEmail, FILTER_VALIDATE_EMAIL) === false ||
                    $canonicalUsername === '' ||
                    !in_array($oauthPolicy, ['canonical_account','none'], true)) {
                v8ir_fail("$group: MERGE requiere canonical_target, correo, username y " .
                    'oauth_policy canonical_account|none.');
            }
        } elseif ($canonicalTarget !== '' || $canonicalEmail !== '' ||
                $canonicalUsername !== '' || $oauthPolicy !== '') {
            v8ir_fail("$group: KEEP_SEPARATE/IGNORE no define identidad canónica.");
        }
        $resolutionId = 'FUZ-' . substr($group, 5);
        $previous = [(string)$row['canonical_id_a'], (string)$row['canonical_id_b']];
        sort($previous, SORT_STRING);
        foreach ($accounts as $account) {
            $output[] = [
                'resolution_id'=>$resolutionId, 'candidate_group'=>$group,
                'decision'=>$decision, 'source_account'=>$account,
                'canonical_account'=>$canonicalTarget,
                'canonical_email'=>$canonicalEmail,
                'canonical_username'=>$canonicalUsername,
                'oauth_policy'=>$oauthPolicy,
                'approved_by'=>(string)$row['operator'],
                'approved_at_utc'=>$timestamp,
                'evidence_reference'=>(string)$row['evidence_reference'],
                'justification'=>(string)$row['justification'],
                'algorithm_version'=>'v8-blocked-2',
                'previous_canonical_ids'=>implode('|', $previous),
                'active'=>'true',
            ];
        }
        unset($originalByGroup[$group]);
    }
    if ($originalByGroup) {
        v8ir_fail('quedaron candidatos sin decisión.');
    }
    usort($output, static fn(array $a, array $b): int =>
        [$a['resolution_id'],$a['source_account']] <=>
        [$b['resolution_id'],$b['source_account']]);
    return $output;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = v8p_options($argv);
        $original = v8p_read_csv($options['candidates'] ?? '')['rows'];
        $review = v8p_read_csv($options['review'] ?? '')['rows'];
        $rows = v8ir_import($original, $review);
        $headers = [
            'resolution_id','candidate_group','decision','source_account',
            'canonical_account','canonical_email','canonical_username','oauth_policy',
            'approved_by','approved_at_utc','evidence_reference','justification',
            'algorithm_version','previous_canonical_ids','active',
        ];
        v8p_write_csv($options['output'] ?? '', $headers, $rows);
        echo 'V8_IDENTITY_REVIEW_IMPORTED decisions=' .
            count(array_unique(array_column($rows, 'resolution_id'))) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
