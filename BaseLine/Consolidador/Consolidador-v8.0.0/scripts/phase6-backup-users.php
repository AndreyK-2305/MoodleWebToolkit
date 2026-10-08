<?php
// Contrato de usuarios contenidos en users.xml, independiente de matrículas.

declare(strict_types=1);

/** Argumentos inequívocos para leer un miembro del MBZ tar sin shell. */
function p6_tar_member_to_stdout_command(string $backup, string $member): array {
    return [
        'tar',
        '--extract',
        '--to-stdout',
        '--file',
        $backup,
        '--',
        $member,
    ];
}

/** Argumentos inequívocos para listar el MBZ tar sin shell. */
function p6_tar_list_command(string $backup): array {
    return ['tar', '--list', '--file', $backup];
}

/** Lee varios miembros exactos en una sola pasada secuencial del MBZ tar. */
function p6_tar_members_to_stdout_command(string $backup, array $members): array {
    if ($members === []) {
        throw new InvalidArgumentException('Se requiere al menos un miembro del MBZ.');
    }
    return array_merge(
        ['tar', '--extract', '--to-stdout', '--file', $backup, '--'],
        array_values($members)
    );
}

/** Ejecuta tar sin shell y conserva stderr para diagnóstico controlado. */
function p6_archive_command_output(array $command, string $context): string {
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo ' . $context . '.');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $stdout === false) {
        throw new RuntimeException(
            'No se pudo ' . $context . ': ' . substr((string)$stderr, 0, 500)
        );
    }
    return $stdout;
}

/** Solo admite rutas internas relativas y exactas del archive. */
function p6_validate_archive_member(string $member): string {
    $member = trim($member);
    $normalized = preg_replace('#^(?:\./)+#', '', $member);
    if ($member === '' || $normalized === null || $normalized === '' ||
            str_starts_with($member, '/') || str_contains($member, "\0") ||
            in_array('..', explode('/', str_replace('\\', '/', $normalized)), true)) {
        throw new RuntimeException('El MBZ contiene una ruta interna insegura.');
    }
    return $member;
}

/** Extrae exclusivamente IDs declarados dentro de bloques userref. */
function p6_parse_inforef_user_ids(string $xml, string $context): array {
    $xml = str_replace("\xEF\xBB\xBF", '', $xml);
    $xml = preg_replace('/<\?xml\s+[^?]*\?>/i', '', $xml) ?? $xml;
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->loadXML('<p6_inforefs>' . $xml . '</p6_inforefs>', LIBXML_NONET)) {
        throw new RuntimeException($context . ' contiene inforef.xml inválido.');
    }
    $ids = [];
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//userref//id') ?: [] as $node) {
        $value = trim($node->textContent);
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new RuntimeException(
                $context . ' contiene un userref con ID inválido.'
            );
        }
        $ids[(int)$value] = true;
    }
    $result = array_map('intval', array_keys($ids));
    sort($result, SORT_NUMERIC);
    return $result;
}

/**
 * Lee selectivamente los inforef.xml y devuelve las dependencias de usuario
 * que el propio formato Moodle declara como transportadas por el backup.
 */
function p6_backup_declared_user_references(string $backup): array {
    if (!is_readable($backup)) {
        throw new RuntimeException('No se puede leer el MBZ: ' . $backup);
    }
    $members = [];
    $ids = [];
    $zip = new ZipArchive();
    if ($zip->open($backup) === true) {
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $member = $zip->getNameIndex($index);
                if (!is_string($member)) {
                    throw new RuntimeException('El ZIP contiene una entrada ilegible.');
                }
                $normalized = preg_replace('#^(?:\./)+#', '', $member);
                if ($normalized === null ||
                        !preg_match('#(?:^|/)inforef\.xml$#', $normalized)) {
                    continue;
                }
                $members[] = p6_validate_archive_member($member);
                $xml = $zip->getFromIndex($index);
                if (!is_string($xml)) {
                    throw new RuntimeException('No se pudo leer ' . $member . ' del ZIP.');
                }
                foreach (p6_parse_inforef_user_ids($xml, $member) as $id) {
                    $ids[$id] = true;
                }
            }
        } finally {
            $zip->close();
        }
    } else {
        $listing = p6_archive_command_output(
            p6_tar_list_command($backup),
            'listar el MBZ tar'
        );
        foreach (preg_split('/\r?\n/', $listing) ?: [] as $member) {
            if ($member === '') {
                continue;
            }
            $normalized = preg_replace('#^(?:\./)+#', '', $member);
            if ($normalized === null ||
                    !preg_match('#(?:^|/)inforef\.xml$#', $normalized)) {
                continue;
            }
            $members[] = p6_validate_archive_member($member);
        }
        if ($members !== []) {
            $xml = p6_archive_command_output(
                p6_tar_members_to_stdout_command($backup, $members),
                'leer inforef.xml del MBZ tar'
            );
            foreach (p6_parse_inforef_user_ids($xml, 'El MBZ tar') as $id) {
                $ids[$id] = true;
            }
        }
    }
    sort($members, SORT_STRING);
    $userids = array_map('intval', array_keys($ids));
    sort($userids, SORT_NUMERIC);
    return [
        'source' => 'inforef.userref',
        'members_scanned' => count($members),
        'members_sha256' => hash('sha256', implode("\n", $members)),
        'source_user_ids' => $userids,
    ];
}

/** Lee solo users.xml del MBZ, sin extraer los archivos académicos ni alterarlos. */
function p6_backup_users_xml(string $backup): array {
    global $CFG;

    if (!is_readable($backup)) {
        throw new RuntimeException('No se puede leer el MBZ: ' . $backup);
    }
    $temporary = tempnam($CFG->tempdir, 'p6-users-');
    if ($temporary === false) {
        throw new RuntimeException('No se pudo reservar espacio para users.xml.');
    }
    try {
        $copied = false;
        $zip = new ZipArchive();
        if ($zip->open($backup) === true) {
            try {
                foreach (['users.xml', './users.xml'] as $member) {
                    $stream = $zip->getStream($member);
                    if ($stream === false) {
                        continue;
                    }
                    $destination = fopen($temporary, 'wb');
                    if ($destination === false) {
                        fclose($stream);
                        throw new RuntimeException('No se pudo escribir users.xml temporal.');
                    }
                    try {
                        $copied = stream_copy_to_stream($stream, $destination) !== false;
                    } finally {
                        fclose($stream);
                        fclose($destination);
                    }
                    break;
                }
            } finally {
                $zip->close();
            }
        } else {
            foreach (['users.xml', './users.xml'] as $member) {
                $process = proc_open(
                    p6_tar_member_to_stdout_command($backup, $member),
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('No se pudo inspeccionar el MBZ tar.');
                }
                $destination = fopen($temporary, 'wb');
                if ($destination === false) {
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    throw new RuntimeException('No se pudo escribir users.xml temporal.');
                }
                $bytes = stream_copy_to_stream($pipes[1], $destination);
                fclose($destination);
                fclose($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                if ($exit === 0 && $bytes !== false) {
                    $copied = true;
                    break;
                }
                if ($exit !== 0 && $member === './users.xml') {
                    throw new RuntimeException(
                        'El MBZ no permite leer users.xml: ' . substr((string)$stderr, 0, 500)
                    );
                }
            }
        }
        if (!$copied || filesize($temporary) === 0) {
            throw new RuntimeException('El MBZ no contiene un users.xml legible.');
        }
        return p6_parse_backup_users_xml($temporary);
    } finally {
        unlink($temporary);
    }
}

/** DOM por usuario, evitando cargar el XML completo en memoria. */
function p6_parse_backup_users_xml(string $path): array {
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_NONET)) {
        throw new RuntimeException('No se pudo abrir users.xml.');
    }
    $reader->setParserProperty(XMLReader::LOADDTD, false);
    $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
    $rows = [];
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'user') {
                continue;
            }
            $xml = $reader->readOuterXML();
            $dom = new DOMDocument();
            if ($xml === '' || !$dom->loadXML($xml, LIBXML_NONET) ||
                    !$dom->documentElement instanceof DOMElement) {
                throw new RuntimeException('users.xml contiene un usuario inválido.');
            }
            $user = $dom->documentElement;
            $id = (int)$user->getAttribute('id');
            if ($id < 1 || isset($rows[$id])) {
                throw new RuntimeException('users.xml tiene un ID inválido o repetido: ' . $id);
            }
            $deleted = trim(p5_dom_text($user, 'deleted'));
            if (!in_array($deleted, ['', '0', '1'], true)) {
                throw new RuntimeException('users.xml tiene deleted inválido para ID ' . $id);
            }
            $rows[$id] = [
                'source_user_id' => $id,
                'source_username' => p5_dom_text($user, 'username'),
                'source_email' => p5_dom_text($user, 'email'),
                'deleted' => $deleted === '1',
            ];
        }
    } finally {
        $reader->close();
    }
    ksort($rows, SORT_NUMERIC);
    return array_values($rows);
}

/** Identificador del contrato semántico introducido en RC21. */
function p6_backup_user_classification_contract(): string {
    return 'phase6-backup-users-v3';
}

/** La clasificación es determinista y forma parte del job sellado. */
function p6_classify_backup_users(
    array $users,
    string $sourceid,
    array $requiredbackupids,
    array $globalmap,
    array $plannedparticipantids = [],
    array $backupdeclaredids = []
): array {
    $academic = array_fill_keys(array_map('intval', $requiredbackupids), true);
    ksort($academic, SORT_NUMERIC);
    // Consumidores anteriores solo entregaban required; para ellos se conserva
    // la interpretación histórica de participante planificado.
    if ($plannedparticipantids === [] && $backupdeclaredids === []) {
        $plannedparticipantids = $requiredbackupids;
    }
    $planned = array_fill_keys(array_map('intval', $plannedparticipantids), true);
    $declared = array_fill_keys(array_map('intval', $backupdeclaredids), true);
    ksort($planned, SORT_NUMERIC);
    ksort($declared, SORT_NUMERIC);
    $contractrequired = $planned + $declared;
    ksort($contractrequired, SORT_NUMERIC);
    if (array_keys($contractrequired) !== array_keys($academic)) {
        throw new RuntimeException(
            'Los usuarios requeridos no coinciden con plan + userref del MBZ.'
        );
    }
    $observedacademic = [];
    $historicalmapconflicts = [];
    $audit = [];
    $counts = array_fill_keys([
        'academic_mapped', 'auxiliary_mapped', 'historical_deleted',
        'reserved_guest', 'academic_reference_unmapped', 'active_unmapped',
    ], 0);
    foreach ($users as $user) {
        $id = (int)$user['source_user_id'];
        $isacademic = isset($academic[$id]);
        if ($isacademic) {
            $observedacademic[$id] = true;
        }
        $mapping = $globalmap[$sourceid . ':' . $id] ?? null;
        if (strtolower(trim((string)$user['source_username'])) === 'guest') {
            $status = 'reserved_guest';
        } elseif ($user['deleted'] === true) {
            $status = 'historical_deleted';
            if (is_array($mapping)) {
                $historicalmapconflicts[] = $id;
            }
        } elseif (is_array($mapping) && (int)($mapping['target_user_id'] ?? 0) > 0) {
            $status = $isacademic ? 'academic_mapped' : 'auxiliary_mapped';
        } elseif ($isacademic) {
            $status = 'academic_reference_unmapped';
        } else {
            $status = 'active_unmapped';
        }
        $counts[$status]++;
        $audit[] = $user + [
            'required_backup_user' => $isacademic,
            'academic_participant' => isset($planned[$id]),
            'backup_declared_user_reference' => isset($declared[$id]),
            'classification' => $status,
            'canonical_id' => is_array($mapping) ? (string)$mapping['canonical_id'] : '',
            'target_user_id' => is_array($mapping) ? (int)$mapping['target_user_id'] : null,
        ];
    }
    $missing = array_values(array_diff(array_keys($academic), array_keys($observedacademic)));
    sort($missing, SORT_NUMERIC);
    return [
        'schema_version' => '3.0',
        'classification_contract' => p6_backup_user_classification_contract(),
        'source' => $sourceid,
        'planned_participant_source_user_ids' =>
            array_values(array_keys($planned)),
        'backup_declared_user_reference_ids' =>
            array_values(array_keys($declared)),
        'required_backup_user_ids' => array_values(array_keys($academic)),
        // Alias conservado para consumidores anteriores. En RC21 solo contiene
        // participantes y dependencias declaradas por el propio MBZ.
        'academic_source_user_ids' => array_values(array_keys($academic)),
        'counts' => $counts,
        'missing_academic_user_ids' => $missing,
        'historical_map_conflicts' => $historicalmapconflicts,
        'blocked' => $counts['academic_reference_unmapped'] > 0 ||
            $counts['active_unmapped'] > 0 || $missing !== [] ||
            $historicalmapconflicts !== [],
        'users' => $audit,
    ];
}
