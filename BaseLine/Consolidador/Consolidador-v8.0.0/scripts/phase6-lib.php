<?php
// Funciones compartidas de la fase 6: selección, marcadores y política de roles.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/phase6-backup-users.php');
require_once(__DIR__ . '/quiz-attempt-diagnostics.php');
require_once(__DIR__ . '/phase6-historical-users.php');
require_once(__DIR__ . '/phase6-restore-precheck.php');
require_once(__DIR__ . '/phase6-random-normalization.php');
require_once(__DIR__ . '/phase6-effective-inventory.php');
require_once(__DIR__ . '/phase6-degradation.php');

/**
 * Convierte los booleanos persistidos en JSON o CSV por las fases anteriores.
 *
 * La fase 6 no debe depender de utilidades privadas de otra etapa para
 * interpretar decisiones sensibles como la conservación de siteadmin.
 */
function p6_bool(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    $normalized = core_text::strtolower(trim((string)$value));
    if (in_array($normalized, ['1', 'true', 'yes', 'si', 'sí'], true)) {
        return true;
    }
    if (in_array($normalized, ['', '0', 'false', 'no'], true)) {
        return false;
    }
    throw new RuntimeException(
        'La fase 6 recibió un valor booleano ambiguo: ' . (string)$value . '.'
    );
}

function p6_read_batch_config(string $path): array {
    $config = p5_read_json($path);
    foreach ([
        'schema_version',
        'batch_id',
        'target_parent_category_id',
        'exclude_verified_phase5_pilot',
        'sources',
        'source_aliases',
        'selection',
        'role_policy',
    ] as $required) {
        if (!array_key_exists($required, $config)) {
            throw new RuntimeException('La configuración de fase 6 no contiene ' . $required . '.');
        }
    }
    if ((string)$config['schema_version'] !== '1.0' ||
            !preg_match('/^[a-z][a-z0-9_-]{2,63}$/', (string)$config['batch_id']) ||
            (int)$config['target_parent_category_id'] < 1 ||
            !is_bool($config['exclude_verified_phase5_pilot']) ||
            !is_array($config['sources']) ||
            !$config['sources'] ||
            !is_array($config['selection']) ||
            ($config['selection']['mode'] ?? '') !== 'all_non_site_courses' ||
            !is_bool($config['selection']['include_hidden'] ?? null) ||
            !is_array($config['role_policy'])) {
        throw new RuntimeException('La configuración de fase 6 es inválida.');
    }
    $sources = [];
    foreach ($config['sources'] as $source) {
        $source = p5_norm((string)$source);
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $source) ||
                isset($sources[$source])) {
            throw new RuntimeException('La selección de fuentes de fase 6 es inválida.');
        }
        $sources[$source] = true;
    }
    if (!is_array($config['source_aliases']) ||
            count($config['source_aliases']) !== count($sources)) {
        throw new RuntimeException(
            'La configuración de fase 6 no contiene aliases inequívocos por fuente.'
        );
    }
    $sourcealiases = [];
    $seenaliases = [];
    foreach (array_keys($sources) as $source) {
        $alias = strtoupper(trim((string)($config['source_aliases'][$source] ?? '')));
        if (!preg_match('/^[A-Z0-9]{3}$/', $alias) || isset($seenaliases[$alias])) {
            throw new RuntimeException(
                'La configuración de fase 6 contiene un alias inválido o repetido para ' .
                $source . '.'
            );
        }
        $sourcealiases[$source] = $alias;
        $seenaliases[$alias] = $source;
    }
    if (array_diff_key($config['source_aliases'], $sources)) {
        throw new RuntimeException(
            'La configuración de fase 6 contiene aliases de fuentes no seleccionadas.'
        );
    }

    $expectedpolicy = [
        'student' => 'student',
        'teacher' => 'editingteacher',
        'editingteacher' => 'editingteacher',
        'manager' => 'manager',
        'fallback' => 'personalizado',
    ];
    foreach ($expectedpolicy as $source => $target) {
        if (p5_norm((string)($config['role_policy'][$source] ?? '')) !== $target) {
            throw new RuntimeException(
                'La política de roles de fase 6 no coincide para ' . $source . '.'
            );
        }
    }
    if (($config['role_policy']['preserve_site_admins_separately'] ?? null) !== true) {
        throw new RuntimeException(
            'La política debe conservar por separado a los administradores del sitio.'
        );
    }
    $safety = $config['role_policy']['personalizado_safety'] ?? null;
    if (!is_array($safety) ||
            ($safety['assignable_context'] ?? '') !== 'course_only' ||
            ($safety['profile'] ?? '') !== 'student_readonly') {
        throw new RuntimeException(
            'El rol personalizado debe ser de solo lectura y exclusivo del contexto de curso.'
        );
    }
    foreach ([
        'allow_content_view',
        'deny_content_mutation',
        'deny_grading',
        'deny_enrolment_and_roles',
        'deny_backup_restore',
        'deny_configuration',
    ] as $requiredsafety) {
        if (($safety[$requiredsafety] ?? null) !== true) {
            throw new RuntimeException(
                'La protección ' . $requiredsafety . ' del rol personalizado debe estar activa.'
            );
        }
    }
    $config['sources'] = array_keys($sources);
    $config['source_aliases'] = $sourcealiases;
    return $config;
}

function p6_load_role_overrides(string $path): array {
    $rows = p5_read_csv($path);
    $overrides = [];
    foreach ($rows as $index => $row) {
        $source = p5_norm((string)($row['source'] ?? ''));
        $sourcerole = p5_norm((string)($row['source_role_shortname'] ?? ''));
        $targetrole = p5_norm((string)($row['target_role_shortname'] ?? ''));
        $decision = p5_norm((string)($row['decision'] ?? ''));
        $reason = trim((string)($row['reason'] ?? ''));
        if ($source === '' && $sourcerole === '' && $targetrole === '' &&
                $decision === '' && $reason === '') {
            continue;
        }
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $source) ||
                !preg_match('/^[a-z][a-z0-9_-]*$/', $sourcerole) ||
                !in_array($targetrole, [
                    'student',
                    'editingteacher',
                    'manager',
                    'personalizado',
                ], true) ||
                $decision !== 'approved_contextual' ||
                $reason === '') {
            throw new RuntimeException(
                'Resolución de rol inválida en la fila ' . ($index + 2) . '.'
            );
        }
        $key = $source . '|' . $sourcerole;
        if (isset($overrides[$key])) {
            throw new RuntimeException('Resolución de rol repetida para ' . $key . '.');
        }
        $overrides[$key] = [
            'source' => $source,
            'source_role_shortname' => $sourcerole,
            'target_role_shortname' => $targetrole,
            'decision' => $decision,
            'reason' => $reason,
        ];
    }
    return $overrides;
}

function p6_normalized_role_name(string $targetrole): string {
    return match (p5_norm($targetrole)) {
        'student' => 'estudiante',
        'editingteacher' => 'docente',
        'manager' => 'administrador',
        'personalizado' => 'personalizado',
        default => throw new RuntimeException('Rol objetivo no soportado: ' . $targetrole . '.'),
    };
}

/**
 * Política por defecto aprobada para evitar que un lote sin revisión replique
 * roles arbitrarios o privilegios administrativos.
 */
function p6_role_policy(
    string $sourceid,
    string $shortname,
    array $batchconfig,
    array $overrides
): array {
    $sourceid = p5_norm($sourceid);
    $shortname = p5_norm($shortname);
    $override = $overrides[$sourceid . '|' . $shortname] ?? null;
    if ($override !== null) {
        $target = (string)$override['target_role_shortname'];
        return [
            p6_normalized_role_name($target),
            $target,
            'approved_contextual',
            (string)$override['reason'],
            $target === 'personalizado',
        ];
    }
    $policy = $batchconfig['role_policy'];
    $target = match ($shortname) {
        'student' => (string)$policy['student'],
        'teacher' => (string)$policy['teacher'],
        'editingteacher' => (string)$policy['editingteacher'],
        'manager', 'coursecreator', 'siteadmin' => (string)$policy['manager'],
        default => (string)$policy['fallback'],
    };
    $defaultfallback = !in_array(
        $shortname,
        [
            'student',
            'teacher',
            'editingteacher',
            'manager',
            'coursecreator',
            'siteadmin',
        ],
        true
    );
    return [
        p6_normalized_role_name($target),
        $target,
        $defaultfallback ? 'approved_default_fallback' : 'approved_standard',
        $defaultfallback
            ? 'Rol no estándar normalizado automáticamente como personalizado.'
            : 'Rol estándar normalizado por la política aprobada.',
        $target === 'personalizado',
    ];
}

/**
 * Devuelve los usuarios canónicos que deben conservar privilegio siteadmin.
 * El privilegio se transporta aparte de los roles de contexto de curso.
 */
function p6_planned_site_administrators(array $contract): array {
    $rows = [];
    foreach ($contract['plan_by_canonical'] ?? [] as $canonicalid => $plan) {
        if (!p6_bool($plan['siteadmin_required'] ?? false)) {
            continue;
        }
        $mapping = $contract['target_by_canonical'][$canonicalid] ?? null;
        $targetuserid = (int)($mapping['target_user_id'] ?? 0);
        if (!$mapping || $targetuserid < 1) {
            throw new RuntimeException(
                'El administrador canónico ' . $canonicalid .
                ' no tiene un usuario destino aplicado.'
            );
        }
        $rows[$targetuserid] = [
            'canonical_id' => (string)$canonicalid,
            'target_user_id' => $targetuserid,
            'target_username' => (string)$mapping['target_username'],
            'target_email' => (string)$mapping['target_email'],
        ];
    }
    ksort($rows, SORT_NUMERIC);
    return array_values($rows);
}

function p6_current_site_administrator_ids(): array {
    $ids = [];
    foreach (get_admins() as $admin) {
        $userid = (int)$admin->id;
        if ($userid > 0) {
            $ids[$userid] = true;
        }
    }
    $ids = array_keys($ids);
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * Añade los administradores aprobados sin retirar ninguno preexistente.
 */
function p6_apply_planned_site_administrators(array $contract): array {
    global $DB;

    $before = p6_current_site_administrator_ids();
    $plannedrows = p6_planned_site_administrators($contract);
    $plannedids = array_values(array_map(
        static fn(array $row): int => (int)$row['target_user_id'],
        $plannedrows
    ));
    foreach ($plannedids as $userid) {
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new RuntimeException(
                'El administrador aprobado ' . $userid . ' no existe en el destino.'
            );
        }
    }
    $expected = array_values(array_unique(array_merge($before, $plannedids)));
    sort($expected, SORT_NUMERIC);
    set_config('siteadmins', implode(',', $expected));
    $after = p6_current_site_administrator_ids();
    if ($after !== $expected) {
        throw new RuntimeException(
            'Moodle no conservó la lista aprobada de administradores del sitio.'
        );
    }
    return [
        'before_ids' => $before,
        'planned_ids' => $plannedids,
        'after_ids' => $after,
        'added' => count(array_diff($after, $before)),
    ];
}

function p6_token(string $value, int $length = 16): string {
    return substr(hash('sha256', $value), 0, $length);
}

function p6_root_category_key(string $sourceid): string {
    return 'ROOT-' . strtoupper(preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid));
}

function p6_category_key(string $sourceid, int $categoryid): string {
    return 'CAT-' . strtoupper(preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid)) .
        '-' . strtoupper(p6_token($sourceid . '|category|' . $categoryid, 12));
}

function p6_course_key(string $sourceid, int $courseid): string {
    return 'COURSE-' . strtoupper(preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid)) .
        '-' . strtoupper(p6_token($sourceid . '|course|' . $courseid, 12));
}

/**
 * Reserva un shortname único para el destino sin perder el original.
 *
 * Los nombres no conflictivos se conservan. Cuando el nombre ya está ocupado
 * o aparece en más de un origen, se antepone el identificador del origen. Si
 * ese candidato también existe, se añade el id del curso y un contador
 * determinista. Moodle limita course.shortname a 255 caracteres.
 *
 * @return array{shortname: string, resolution: string}
 */
function p6_allocate_target_shortname(
    string $sourceid,
    string $sourceshortname,
    int $sourcecourseid,
    bool $forceprefix,
    array &$reserved
): array {
    $sourceshortname = trim($sourceshortname);
    if ($sourceshortname === '' || $sourcecourseid < 1) {
        throw new RuntimeException('El curso no contiene un shortname de origen válido.');
    }
    if (!$forceprefix && !isset($reserved[p5_norm($sourceshortname)])) {
        $reserved[p5_norm($sourceshortname)] = true;
        return [
            'shortname' => $sourceshortname,
            'resolution' => 'preserved',
        ];
    }

    $prefix = core_text::strtoupper(trim($sourceid)) . '-';
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        $suffix = $attempt === 0
            ? ''
            : '-' . $sourcecourseid . ($attempt === 1 ? '' : '-' . $attempt);
        $available = 255 -
            core_text::strlen($prefix) -
            core_text::strlen($suffix);
        if ($available < 1) {
            throw new RuntimeException(
                'El identificador del origen no permite construir un shortname válido.'
            );
        }
        $candidate = $prefix .
            core_text::substr($sourceshortname, 0, $available) .
            $suffix;
        $normalized = p5_norm($candidate);
        if (!isset($reserved[$normalized])) {
            $reserved[$normalized] = true;
            return [
                'shortname' => $candidate,
                'resolution' => $attempt === 0
                    ? 'source_prefixed'
                    : 'source_prefixed_with_id',
            ];
        }
    }
    throw new RuntimeException('No fue posible reservar un shortname único.');
}

/**
 * Reserva un fullname legible. Las colisiones se renombran como
 * "[instancia] Nombre original" y, solo si fuese necesario, se añade el id.
 * Moodle limita course.fullname a 254 caracteres.
 *
 * @return array{fullname: string, resolution: string}
 */
function p6_allocate_target_fullname(
    string $sourcelabel,
    string $sourcefullname,
    int $sourcecourseid,
    bool $forceprefix,
    array &$reserved
): array {
    $sourcelabel = trim($sourcelabel);
    $sourcefullname = trim($sourcefullname);
    if ($sourcelabel === '' || $sourcefullname === '' || $sourcecourseid < 1) {
        throw new RuntimeException('El curso no contiene un fullname de origen válido.');
    }
    if (!$forceprefix && !isset($reserved[p5_norm($sourcefullname)])) {
        $reserved[p5_norm($sourcefullname)] = true;
        return [
            'fullname' => $sourcefullname,
            'resolution' => 'preserved',
        ];
    }

    $prefix = '[' . $sourcelabel . '] ';
    for ($attempt = 0; $attempt < 1000; $attempt++) {
        $suffix = $attempt === 0
            ? ''
            : ' [ID ' . $sourcecourseid .
                ($attempt === 1 ? '' : '-' . $attempt) . ']';
        $available = 254 -
            core_text::strlen($prefix) -
            core_text::strlen($suffix);
        if ($available < 1) {
            throw new RuntimeException(
                'El nombre de la instancia no permite construir un fullname válido.'
            );
        }
        $candidate = $prefix .
            core_text::substr($sourcefullname, 0, $available) .
            $suffix;
        $normalized = p5_norm($candidate);
        if (!isset($reserved[$normalized])) {
            $reserved[$normalized] = true;
            return [
                'fullname' => $candidate,
                'resolution' => $attempt === 0
                    ? 'source_prefixed'
                    : 'source_prefixed_with_id',
            ];
        }
    }
    throw new RuntimeException('No fue posible reservar un fullname único.');
}

function p6_category_marker(
    string $sourcealias,
    string $sourceidnumber,
    int $sourcecategoryid,
    bool $root = false
): string {
    $alias = p5_source_alias($sourcealias);
    if ($root) {
        return $alias . '-ROOT';
    }
    if ($sourcecategoryid < 1) {
        throw new RuntimeException('La categoría no contiene un id de origen válido.');
    }
    $original = p5_operational_component($sourceidnumber);
    $suffix = $original !== '' ? $original : 'CAT-' . $sourcecategoryid;
    $code = $alias . '-' . $suffix;
    if (core_text::strlen($code) > 100) {
        throw new RuntimeException(
            'El código operativo de categoría supera los 100 caracteres; requiere resolución manual.'
        );
    }
    return $code;
}

function p6_course_marker(string $sourcealias, string $sourceshortname): string {
    return p5_course_marker($sourcealias, $sourceshortname);
}

function p6_issue_text(array $issues): string {
    $issues = array_values(array_unique(array_filter(array_map(
        static fn(mixed $issue): string => trim((string)$issue),
        $issues
    ))));
    return implode(' ', $issues);
}

/**
 * Normaliza un conjunto de firmas para poder comparar acceso académico sin
 * depender del orden en que Moodle devolvió matrículas o roles.
 */
function p6_access_signature(array $values): string {
    $values = array_values(array_unique(array_map(
        static fn(mixed $value): string => trim((string)$value),
        $values
    )));
    sort($values, SORT_STRING);
    return implode('|', $values);
}

/**
 * Evalúa cuentas de un mismo curso que la fase 4 ya hizo converger en un solo
 * usuario canónico. Solo aprueba la fusión si todas provienen de una decisión
 * de convergencia aprobada en la fase 3/4 y tienen matrículas equivalentes. Los roles se
 * conservan como una unión por curso: una misma identidad puede ser estudiante
 * en un curso, docente en otro o tener ambos roles en el mismo contexto.
 *
 * @param array<int, array<string, mixed>> $participantsbytarget
 * @return array{
 *     rows: array<int, array<string, mixed>>,
 *     issues: array<int, string>,
 *     approved: int,
 *     blocked: int,
 *     source_accounts: int,
 *     enrolment_rows_collapsed: int,
 *     role_rows_collapsed: int
 * }
 */
function p6_evaluate_identity_convergences(
    string $coursekey,
    string $sourceid,
    int $sourcecourseid,
    array $participantsbytarget
): array {
    $rows = [];
    $issues = [];
    $approved = 0;
    $blocked = 0;
    $sourceaccounts = 0;
    $enrolmentrowscollapsed = 0;
    $rolerowscollapsed = 0;

    ksort($participantsbytarget, SORT_NUMERIC);
    foreach ($participantsbytarget as $targetuserid => $participant) {
        $accounts = $participant['source_accounts'] ?? [];
        if (!is_array($accounts) || count($accounts) < 2) {
            continue;
        }
        ksort($accounts, SORT_NUMERIC);
        $sourceaccounts += count($accounts);
        $canonicalids = [];
        $decisions = [];
        $userids = [];
        $usernames = [];
        $enrolmentsignatures = [];
        $rolesignatures = [];
        $mergedroles = [];
        $sourcerolerows = 0;
        foreach ($accounts as $sourceuserid => $account) {
            $sourceuserid = (int)$sourceuserid;
            $canonicalids[] = trim((string)($account['canonical_id'] ?? ''));
            $decisions[] = p5_norm((string)($account['identity_decision'] ?? ''));
            $userids[] = $sourceuserid;
            $usernames[] = (string)($account['source_username'] ?? '');
            $enrolmentsignatures[$sourceuserid] = p6_access_signature(
                is_array($account['enrolments'] ?? null)
                    ? $account['enrolments']
                    : []
            );
            $accountroles = is_array($account['roles'] ?? null)
                ? array_values(array_unique(array_map(
                    static fn(mixed $role): string => p5_norm((string)$role),
                    $account['roles']
                )))
                : [];
            sort($accountroles, SORT_STRING);
            $rolesignatures[$sourceuserid] = implode('|', $accountroles);
            $mergedroles = array_merge($mergedroles, $accountroles);
            $sourcerolerows += count($accountroles);
        }

        $canonicalids = array_values(array_unique($canonicalids));
        $uniquedecisions = array_values(array_unique($decisions));
        $uniqueenrolments = array_values(array_unique($enrolmentsignatures));
        $mergedroles = array_values(array_unique($mergedroles));
        sort($mergedroles, SORT_STRING);
        $mergedrolesignature = implode('|', $mergedroles);
        $reasons = [];
        if (count($canonicalids) !== 1 || $canonicalids[0] === '') {
            $reasons[] = 'Las cuentas no comparten una identidad canónica verificable.';
        }
        $approvedmergedecisions = [
            'merge',
            'merge_with_warning',
            'merge_oauth_email',
            'merge_manual_username',
            'resolved_merge',
        ];
        if (count($uniquedecisions) !== 1 ||
                !in_array($uniquedecisions[0], $approvedmergedecisions, true)) {
            $reasons[] =
                'La convergencia no proviene de una decisión de fusión aprobada.';
        }
        if (count($uniqueenrolments) !== 1) {
            $reasons[] = 'Las matrículas de las cuentas no son equivalentes.';
        }

        $isapproved = !$reasons;
        if ($isapproved) {
            $approved++;
            $accountduplicates = count($accounts) - 1;
            $enrolmentcount = $uniqueenrolments[0] === ''
                ? 0
                : count(explode('|', $uniqueenrolments[0]));
            $enrolmentrowscollapsed += $accountduplicates * $enrolmentcount;
            $rolerowscollapsed += $sourcerolerows - count($mergedroles);
            $reason =
                'Fusión canónica aprobada en fase 4; matrícula equivalente y ' .
                'unión de roles normalizados limitada a este curso.';
        } else {
            $blocked++;
            $reason = p6_issue_text($reasons);
            $issues[] = 'La convergencia hacia target_user_id=' .
                (int)$targetuserid . ' no es fusionable automáticamente: ' .
                $reason;
        }

        $formatpersource = static function (array $signatures): string {
            $parts = [];
            foreach ($signatures as $sourceuserid => $signature) {
                $parts[] = (int)$sourceuserid . '=' .
                    ($signature === '' ? '(none)' : $signature);
            }
            return implode(';', $parts);
        };
        $rows[] = [
            'convergence_id' => 'MERGE-' . strtoupper(p6_token(
                $coursekey . '|target|' . (int)$targetuserid,
                16
            )),
            'course_key' => $coursekey,
            'source' => $sourceid,
            'source_course_id' => $sourcecourseid,
            'canonical_id' => count($canonicalids) === 1 ? $canonicalids[0] : '',
            'target_user_id' => (int)$targetuserid,
            'source_user_ids' => implode('|', $userids),
            'source_usernames' => implode('|', $usernames),
            'identity_decisions' => implode('|', $decisions),
            'enrolment_signatures' => $formatpersource($enrolmentsignatures),
            'normalized_role_signatures' => $formatpersource($rolesignatures),
            'merged_normalized_roles' =>
                $mergedrolesignature === '' ? '(none)' : $mergedrolesignature,
            'resolution_status' =>
                $isapproved ? 'approved_equivalent_merge' : 'blocked_non_equivalent',
            'planned_action' =>
                $isapproved ? 'merge_into_canonical_user' : 'manual_review',
            'safety_profile' =>
                'phase4_resolved_merge_with_course_scoped_role_union',
            'reason' => $reason,
        ];
    }

    return [
        'rows' => $rows,
        'issues' => $issues,
        'approved' => $approved,
        'blocked' => $blocked,
        'source_accounts' => $sourceaccounts,
        'enrolment_rows_collapsed' => $enrolmentrowscollapsed,
        'role_rows_collapsed' => $rolerowscollapsed,
    ];
}

/**
 * Produce una representación estable para firmar estados académicos y filas
 * del plan sin depender del orden de las claves asociativas.
 */
function p6_canonical_value(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('p6_canonical_value', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = p6_canonical_value($item);
    }
    return $value;
}

function p6_value_sha256(mixed $value): string {
    return hash('sha256', json_encode(
        p6_canonical_value($value),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    ));
}

function p6_backup_basename(string $coursekey): string {
    $coursekey = strtolower(trim($coursekey));
    if (!preg_match('/^course-[a-z0-9_-]+-[a-f0-9]{12}$/', $coursekey)) {
        throw new RuntimeException('course_key inválido para el backup.');
    }
    return $coursekey;
}

/**
 * Devuelve la unión de participantes que el plan firmado espera encontrar
 * dentro del backup de un curso. Incluye matrículas y asignaciones de rol,
 * porque Moodle permite roles de curso sin una matrícula equivalente.
 */
function p6_expected_course_source_user_ids(
    array $bundle,
    string $coursekey
): array {
    $expectedids = [];
    foreach (['user_rows_by_course', 'role_rows_by_course'] as $rowset) {
        foreach ($bundle[$rowset][$coursekey] ?? [] as $row) {
            $sourceuserid = (int)($row['source_user_id'] ?? 0);
            if ($sourceuserid < 1) {
                throw new RuntimeException(
                    $rowset . ' contiene un participante inválido para ' .
                    $coursekey . '.'
                );
            }
            $expectedids[$sourceuserid] = true;
        }
    }
    $ids = array_map('intval', array_keys($expectedids));
    sort($ids, SORT_NUMERIC);
    return $ids;
}

/**
 * Semántica de una referencia de usuario dentro del inventario académico.
 *
 * El valor por defecto es estricto para no debilitar relaciones nuevas. Las
 * únicas excepciones aprobadas son la autoría de files y el seguimiento de
 * course_completions sin una finalización efectiva.
 */
function p6_relation_user_semantics(string $relation, array $row): string {
    if ($relation === 'files') {
        return 'historical_technical_reference';
    }
    if ($relation === 'course_completions') {
        $view = p5_course_completion_comparison_view([$row], []);
        return $view['course_completions_effective_expected'] === 1
            ? 'strict_academic_evidence'
            : 'non_effective_reference';
    }
    return 'strict_academic_evidence';
}

/**
 * Separa participantes obligatorios, referencias declaradas por el MBZ,
 * evidencia académica y meras referencias internas. user_mappings conserva
 * la unión completa, pero users.xml solo exige participantes planificados y
 * los userref que Moodle declaró explícitamente como transportados.
 */
function p6_course_user_reference_sets(
    array $bundle,
    string $coursekey,
    array $inventory,
    array $backupdeclaredids = []
): array {
    $participants = p6_expected_course_source_user_ids($bundle, $coursekey);
    $required = array_fill_keys($participants, true);
    $referenced = $required;
    $backupdeclared = [];
    $strictrelations = [];
    $referenceonly = [];

    foreach ($backupdeclaredids as $sourceuserid) {
        $sourceuserid = (int)$sourceuserid;
        if ($sourceuserid < 1) {
            throw new RuntimeException(
                'inforef.xml contiene una referencia de usuario inválida.'
            );
        }
        $backupdeclared[$sourceuserid] = true;
        $required[$sourceuserid] = true;
        $referenced[$sourceuserid] = true;
    }

    $collectreference = static function(array $rows) use (&$referenced): void {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceuserid = (int)($row['source_user_id'] ?? 0);
            if ($sourceuserid > 0) {
                $referenced[$sourceuserid] = true;
            }
        }
    };
    $collectreference($inventory['enrolments'] ?? []);
    $collectreference($inventory['roles'] ?? []);

    foreach ($inventory['relations'] ?? [] as $relation => $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sourceuserid = (int)($row['source_user_id'] ?? 0);
            if ($sourceuserid < 1) {
                continue;
            }
            $referenced[$sourceuserid] = true;
            $semantics = p6_relation_user_semantics((string)$relation, $row);
            if ($semantics === 'strict_academic_evidence') {
                $strictrelations[$sourceuserid] = true;
            } else {
                $referenceonly[$sourceuserid] = true;
            }
        }
    }
    foreach (array_keys($referenced) as $sourceuserid) {
        if (!isset($required[$sourceuserid])) {
            $referenceonly[$sourceuserid] = true;
        }
    }
    foreach (array_keys($required) as $sourceuserid) {
        unset($referenceonly[$sourceuserid]);
    }

    $sortids = static function(array $set): array {
        $ids = array_map('intval', array_keys($set));
        sort($ids, SORT_NUMERIC);
        return $ids;
    };
    return [
        'planned_participant_source_user_ids' => $participants,
        'backup_declared_user_reference_ids' => $sortids($backupdeclared),
        'strict_relation_source_user_ids' => $sortids($strictrelations),
        'reference_only_source_user_ids' => $sortids($referenceonly),
        'required_backup_user_ids' => $sortids($required),
        'referenced_source_user_ids' => $sortids($referenced),
    ];
}

/** Garantiza que la clasificación usa el contrato RC21 y quedó aprobada. */
function p6_assert_current_backup_user_classification(
    array $classification,
    string $context
): void {
    $required = $classification['required_backup_user_ids'] ?? null;
    $academic = $classification['academic_source_user_ids'] ?? null;
    $planned = $classification['planned_participant_source_user_ids'] ?? null;
    $declared = $classification['backup_declared_user_reference_ids'] ?? null;
    $counts = $classification['counts'] ?? null;
    $missing = $classification['missing_academic_user_ids'] ?? null;
    $historicalconflicts = $classification['historical_map_conflicts'] ?? null;
    if (($classification['schema_version'] ?? '') !== '3.0' ||
            ($classification['classification_contract'] ?? '') !==
                p6_backup_user_classification_contract() ||
            !is_array($required) || !is_array($academic) ||
            !is_array($planned) || !is_array($declared) ||
            $required !== $academic ||
            !is_array($counts) || !is_array($missing) ||
            !is_array($historicalconflicts) ||
            !is_array($classification['users'] ?? null) ||
            ($classification['blocked'] ?? null) !== false ||
            (int)($counts['academic_reference_unmapped'] ?? -1) !== 0 ||
            (int)($counts['active_unmapped'] ?? -1) !== 0 ||
            $missing !== [] || $historicalconflicts !== []) {
        throw new RuntimeException(
            $context . ': clasificación de users.xml bloqueada u obsoleta.'
        );
    }
    $normalized = array_values(array_unique(array_map('intval', $required)));
    sort($normalized, SORT_NUMERIC);
    $contractrequired = array_values(array_unique(array_merge(
        array_map('intval', $planned),
        array_map('intval', $declared)
    )));
    sort($contractrequired, SORT_NUMERIC);
    if ($normalized !== $required ||
            $contractrequired !== $required ||
            array_filter($normalized, static fn(int $id): bool => $id < 1)) {
        throw new RuntimeException(
            $context . ': required_backup_user_ids no es determinista.'
        );
    }
}

/**
 * Invariancia RC21: referenced significa clasificación actual y no bloqueada.
 */
function p6_assert_referenced_checkpoint_classification(
    array $checkpoint,
    array $job,
    string $context
): void {
    $classification = $job['backup_user_classification'] ?? null;
    if (($checkpoint['checkpoint_status'] ?? '') !== 'referenced' ||
            (int)($checkpoint['backup_user_contract_version'] ?? 0) !== 3 ||
            (int)($job['backup_user_contract_version'] ?? 0) !== 3 ||
            !is_array($classification)) {
        throw new RuntimeException(
            $context . ': checkpoint referenced incompatible con RC21.'
        );
    }
    p6_assert_current_backup_user_classification($classification, $context);
    if (($checkpoint['backup_user_classification_sha256'] ?? '') !==
            p6_value_sha256($classification) ||
            ($job['required_backup_user_ids'] ?? null) !==
                $classification['required_backup_user_ids'] ||
            ($job['planned_source_user_ids'] ?? null) !==
                $classification['planned_participant_source_user_ids'] ||
            ($job['backup_declared_user_reference_ids'] ?? null) !==
                $classification['backup_declared_user_reference_ids']) {
        throw new RuntimeException(
            $context . ': checkpoint y clasificación de users.xml difieren.'
        );
    }
    foreach ([
        'planned_source_user_ids',
        'backup_declared_user_reference_ids',
        'strict_relation_source_user_ids',
        'reference_only_source_user_ids',
        'referenced_source_user_ids',
    ] as $field) {
        if (!is_array($job[$field] ?? null)) {
            throw new RuntimeException($context . ': falta ' . $field . '.');
        }
    }
    $expectedrequired = array_values(array_unique(array_merge(
        array_map('intval', $job['planned_source_user_ids']),
        array_map('intval', $job['backup_declared_user_reference_ids'])
    )));
    sort($expectedrequired, SORT_NUMERIC);
    $declaredevidence = $job['backup_declared_user_references'] ?? null;
    if ($expectedrequired !== $job['required_backup_user_ids'] ||
            array_diff($expectedrequired, $job['referenced_source_user_ids']) ||
            !is_array($declaredevidence) ||
            ($declaredevidence['source'] ?? '') !== 'inforef.userref' ||
            !is_int($declaredevidence['members_scanned'] ?? null) ||
            (int)$declaredevidence['members_scanned'] < 0 ||
            !preg_match('/^[a-f0-9]{64}$/',
                (string)($declaredevidence['members_sha256'] ?? '')) ||
            ($declaredevidence['source_user_ids'] ?? null) !==
                $job['backup_declared_user_reference_ids'] ||
            ($checkpoint['backup_declared_user_references_sha256'] ?? '') !==
                p6_value_sha256($declaredevidence)) {
        throw new RuntimeException(
            $context . ': los conjuntos de usuarios del job son contradictorios.'
        );
    }
}

/**
 * Carga el plan aplicable del comando 19 y vuelve a verificar todos sus hashes.
 * Esta función es el contrato común para preparar, aplicar y verificar el lote.
 */
function p6_load_inventory_plan(
    string $phase4dir,
    string $phase6dir,
    string $configsha,
    string $targetid,
    bool $expectlab
): array {
    $phase6dir = rtrim($phase6dir, '/\\');
    $summarypath = $phase6dir . '/plan_summary.json';
    $summary = p5_read_json($summarypath);
    if (($summary['schema_version'] ?? '') !== '1.0' ||
            ($summary['phase'] ?? '') !== '6-multi-course-inventory-plan' ||
            ($summary['config_sha256'] ?? '') !== $configsha ||
            ($summary['target_id'] ?? '') !== $targetid ||
            ($summary['plan_status'] ?? '') !== 'applicable' ||
            (int)($summary['blocking_conflicts'] ?? -1) !== 0 ||
            (int)($summary['blocked_categories'] ?? -1) !== 0 ||
            (int)($summary['blocked_courses'] ?? -1) !== 0 ||
            (int)($summary['blocked_identity_convergences'] ?? -1) !== 0 ||
            ($summary['course_shortname_policy'] ?? '') !==
                'source_alias_academic_code' ||
            ($summary['course_fullname_policy'] ?? '') !==
                'preserve_or_prefix_source_label' ||
            ($summary['destination_write_performed'] ?? null) !== false ||
            ($summary['backups_created'] ?? null) !== false ||
            ($summary['courses_restored'] ?? null) !== false) {
        throw new RuntimeException(
            'El plan de fase 6 no está aplicable, íntegro o libre de escrituras.'
        );
    }
    if ($expectlab && ($summary['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('La validación LAB del plan de fase 6 no está aprobada.');
    }

    $batchconfigpath = $phase6dir . '/batch_config.json';
    $batchconfig = p6_read_batch_config($batchconfigpath);
    if ((string)$batchconfig['batch_id'] !== (string)$summary['batch_id'] ||
            $batchconfig['sources'] !== ($summary['selected_sources'] ?? []) ||
            $batchconfig['source_aliases'] !== ($summary['source_aliases'] ?? []) ||
            (int)$batchconfig['target_parent_category_id'] !==
                (int)$summary['target_parent_category_id']) {
        throw new RuntimeException('batch_config.json no coincide con el resumen aplicable.');
    }

    $paths = [
        'batch_config.json' => $batchconfigpath,
        'role_resolutions.csv' => $phase6dir . '/role_resolutions.csv',
        'target_inventory.json' => $phase6dir . '/target_inventory.json',
        'category_plan.csv' => $phase6dir . '/category_plan.csv',
        'course_plan.csv' => $phase6dir . '/course_plan.csv',
        'course_user_plan.csv' => $phase6dir . '/course_user_plan.csv',
        'course_role_plan.csv' => $phase6dir . '/course_role_plan.csv',
        'role_normalization.csv' => $phase6dir . '/role_normalization.csv',
        'identity_convergence.csv' => $phase6dir . '/identity_convergence.csv',
    ];
    foreach ($batchconfig['sources'] as $sourceid) {
        $paths['source_inventory_' . $sourceid . '.json'] =
            $phase6dir . '/source-inventory-' . $sourceid . '.json';
    }
    $hashes = p5_hash_files($paths);
    $expectedhashes = $summary['artifacts_sha256'] ?? [];
    if (!is_array($expectedhashes)) {
        throw new RuntimeException('plan_summary.json no contiene hashes de artefactos.');
    }
    ksort($expectedhashes, SORT_STRING);
    if ($expectedhashes !== $hashes) {
        throw new RuntimeException(
            'Un artefacto del comando 19 cambió después de aprobarse.'
        );
    }

    $contract = p5_load_phase4_contract(
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $phase4hashes = $summary['phase4_input_sha256'] ?? [];
    if (!is_array($phase4hashes)) {
        throw new RuntimeException('El plan no conserva el contrato de fase 4.');
    }
    ksort($phase4hashes, SORT_STRING);
    $contracthashes = $contract['hashes'];
    ksort($contracthashes, SORT_STRING);
    if ($phase4hashes !== $contracthashes) {
        throw new RuntimeException('La fase 4 cambió después de generar el plan masivo.');
    }
    if ((int)($summary['site_administrators_planned'] ?? -1) !==
            count(p6_planned_site_administrators($contract))) {
        throw new RuntimeException(
            'El plan no conserva los administradores aprobados en la fase 4.'
        );
    }

    $categoryrows = p5_read_csv($paths['category_plan.csv']);
    $courserows = p5_read_csv($paths['course_plan.csv']);
    $userrows = p5_read_csv($paths['course_user_plan.csv']);
    $rolerows = p5_read_csv($paths['course_role_plan.csv']);
    $convergencerows = p5_read_csv($paths['identity_convergence.csv']);
    $categorykeys = [];
    foreach ($categoryrows as $row) {
        $categorykey = trim((string)($row['category_key'] ?? ''));
        if ($categorykey === '' || isset($categorykeys[$categorykey]) ||
                !in_array((string)($row['action'] ?? ''), ['create', 'reuse'], true) ||
                trim((string)($row['blocking_reason'] ?? '')) !== '') {
            throw new RuntimeException('category_plan.csv contiene una fila no aplicable.');
        }
        $categorykeys[$categorykey] = true;
    }
    if (count($categoryrows) !== (int)$summary['target_categories_planned']) {
        throw new RuntimeException('category_plan.csv no coincide con el resumen.');
    }

    $coursesbykey = [];
    $restorecourses = [];
    $actions = [];
    $targetshortnames = [];
    $targetfullnames = [];
    $adjustedshortnames = 0;
    $adjustedfullnames = 0;
    foreach ($courserows as $row) {
        $coursekey = trim((string)($row['course_key'] ?? ''));
        $action = trim((string)($row['action'] ?? ''));
        $sourceshortname = trim((string)($row['source_shortname'] ?? ''));
        $targetshortname = trim((string)($row['target_shortname'] ?? ''));
        $targetmarker = trim((string)($row['target_course_marker'] ?? ''));
        $shortnameresolution = trim(
            (string)($row['shortname_resolution'] ?? '')
        );
        $sourcefullname = trim((string)($row['source_fullname'] ?? ''));
        $targetfullname = trim((string)($row['target_fullname'] ?? ''));
        $fullnameresolution = trim(
            (string)($row['fullname_resolution'] ?? '')
        );
        if ($coursekey === '' || isset($coursesbykey[$coursekey]) ||
                $sourceshortname === '' ||
                $targetshortname === '' ||
                core_text::strlen($targetshortname) > 255 ||
                $targetmarker === '' ||
                core_text::strlen($targetmarker) > 100 ||
                $targetmarker !== $targetshortname ||
                $sourcefullname === '' ||
                $targetfullname === '' ||
                core_text::strlen($targetfullname) > 254 ||
                !in_array($shortnameresolution, [
                    'source_alias_code',
                    'existing_migration',
                    'excluded',
                ], true) ||
                !in_array($fullnameresolution, [
                    'preserved',
                    'source_prefixed',
                    'source_prefixed_with_id',
                    'existing_migration',
                    'excluded',
                ], true) ||
                !in_array($action, [
                    'restore_new',
                    'already_migrated',
                    'excluded_phase5_pilot',
                    'excluded_hidden',
                ], true) ||
                trim((string)($row['blocking_reason'] ?? '')) !== '') {
            throw new RuntimeException('course_plan.csv contiene una fila no aplicable.');
        }
        $coursesbykey[$coursekey] = $row;
        $actions[$action] = (int)($actions[$action] ?? 0) + 1;
        $shortnameadjusted =
            p5_norm($sourceshortname) !== p5_norm($targetshortname);
        $fullnameadjusted =
            p5_norm($sourcefullname) !== p5_norm($targetfullname);
        if (
            (
                $action === 'restore_new' &&
                (
                    (
                        $shortnameresolution === 'source_alias_code' &&
                        !$shortnameadjusted
                    ) ||
                    in_array($shortnameresolution, [
                        'existing_migration',
                        'excluded',
                    ], true)
                )
            ) ||
            (
                $action === 'already_migrated' &&
                $shortnameresolution !== 'existing_migration'
            ) ||
            (
                str_starts_with($action, 'excluded_') &&
                $shortnameresolution !== 'excluded'
            )
        ) {
            throw new RuntimeException(
                'course_plan.csv contiene una resolución de shortname incoherente.'
            );
        }
        if (
            (
                $action === 'restore_new' &&
                (
                    ($fullnameresolution === 'preserved' && $fullnameadjusted) ||
                    (
                        in_array($fullnameresolution, [
                            'source_prefixed',
                            'source_prefixed_with_id',
                        ], true) &&
                        !$fullnameadjusted
                    ) ||
                    in_array($fullnameresolution, [
                        'existing_migration',
                        'excluded',
                    ], true)
                )
            ) ||
            (
                $action === 'already_migrated' &&
                $fullnameresolution !== 'existing_migration'
            ) ||
            (
                str_starts_with($action, 'excluded_') &&
                $fullnameresolution !== 'excluded'
            )
        ) {
            throw new RuntimeException(
                'course_plan.csv contiene una resolución de fullname incoherente.'
            );
        }
        if ($action === 'restore_new') {
            $normalizedtarget = p5_norm($targetshortname);
            if (isset($targetshortnames[$normalizedtarget])) {
                throw new RuntimeException(
                    'course_plan.csv repite un target_shortname.'
                );
            }
            $targetshortnames[$normalizedtarget] = true;
            $normalizedfullname = p5_norm($targetfullname);
            if (isset($targetfullnames[$normalizedfullname])) {
                throw new RuntimeException(
                    'course_plan.csv repite un target_fullname.'
                );
            }
            $targetfullnames[$normalizedfullname] = true;
            if ($shortnameadjusted) {
                $adjustedshortnames++;
            }
            if ($fullnameadjusted) {
                $adjustedfullnames++;
            }
            $restorecourses[] = $row;
        }
    }
    if (count($courserows) !== (int)$summary['courses_discovered'] ||
            count($restorecourses) !== (int)$summary['courses_to_restore'] ||
            (int)($actions['already_migrated'] ?? 0) !==
                (int)$summary['courses_already_migrated'] ||
            (int)($actions['excluded_phase5_pilot'] ?? 0) !==
                (int)$summary['courses_excluded_phase5_pilot'] ||
            (int)($actions['excluded_hidden'] ?? 0) !==
                (int)$summary['courses_excluded_hidden'] ||
            $adjustedshortnames !==
                (int)($summary['course_shortnames_adjusted'] ?? -1) ||
            $adjustedfullnames !==
                (int)($summary['course_fullnames_adjusted'] ?? -1)) {
        throw new RuntimeException('Los conteos de course_plan.csv no coinciden con el resumen.');
    }

    $userrowsbycourse = [];
    foreach ($userrows as $row) {
        $coursekey = trim((string)($row['course_key'] ?? ''));
        $sourcekey = (string)($row['source'] ?? '') . ':' .
            (int)($row['source_user_id'] ?? 0);
        $mapping = $contract['source_by_key'][$sourcekey] ?? null;
        if (!isset($coursesbykey[$coursekey]) ||
                !$mapping ||
                ($row['mapping_status'] ?? '') !== 'mapped' ||
                (int)($row['target_user_id'] ?? 0) !==
                    (int)$mapping['target_user_id'] ||
                (string)($row['canonical_id'] ?? '') !==
                    (string)$mapping['canonical_id']) {
            throw new RuntimeException(
                'course_user_plan.csv contiene una matrícula sin mapa aprobado.'
            );
        }
        $userrowsbycourse[$coursekey][] = $row;
    }
    if (count($userrows) !== (int)$summary['enrolments_mapped']) {
        throw new RuntimeException('course_user_plan.csv no coincide con el resumen.');
    }

    $allowedroles = ['student', 'editingteacher', 'manager', 'personalizado'];
    $rolerowsbycourse = [];
    foreach ($rolerows as $row) {
        $coursekey = trim((string)($row['course_key'] ?? ''));
        $targetrole = p5_norm((string)($row['target_role_shortname'] ?? ''));
        $approval = (string)($row['approval_status'] ?? '');
        $safety = (string)($row['safety_profile'] ?? '');
        if (!isset($coursesbykey[$coursekey]) ||
                !in_array($targetrole, $allowedroles, true) ||
                !in_array($approval, [
                    'approved_standard',
                    'approved_default_fallback',
                    'approved_contextual',
                ], true) ||
                (
                    $targetrole === 'personalizado' &&
                    $safety !== 'student_readonly'
                ) ||
                (
                    $targetrole !== 'personalizado' &&
                    $safety !== 'standard'
                )) {
            throw new RuntimeException(
                'course_role_plan.csv viola la política de roles aprobada.'
            );
        }
        $rolerowsbycourse[$coursekey][] = $row;
    }
    if (count($rolerows) !== (int)$summary['course_roles_normalized']) {
        throw new RuntimeException('course_role_plan.csv no coincide con el resumen.');
    }

    $convergencebycourseandtarget = [];
    foreach ($convergencerows as $row) {
        $coursekey = trim((string)($row['course_key'] ?? ''));
        $targetuserid = (int)($row['target_user_id'] ?? 0);
        if (!isset($coursesbykey[$coursekey]) ||
                $targetuserid < 1 ||
                ($row['resolution_status'] ?? '') !== 'approved_equivalent_merge' ||
                ($row['planned_action'] ?? '') !== 'merge_into_canonical_user' ||
                ($row['safety_profile'] ?? '') !==
                    'phase4_resolved_merge_with_course_scoped_role_union' ||
                isset($convergencebycourseandtarget[$coursekey][$targetuserid])) {
            throw new RuntimeException(
                'identity_convergence.csv contiene una fusión no aprobada o repetida.'
            );
        }
        $sourceuserids = array_values(array_filter(array_map(
            'intval',
            explode('|', (string)($row['source_user_ids'] ?? ''))
        ), static fn(int $id): bool => $id > 0));
        sort($sourceuserids, SORT_NUMERIC);
        if (count($sourceuserids) < 2 ||
                count($sourceuserids) !== count(array_unique($sourceuserids))) {
            throw new RuntimeException('Una convergencia no identifica cuentas válidas.');
        }
        $row['source_user_ids_parsed'] = $sourceuserids;
        $convergencebycourseandtarget[$coursekey][$targetuserid] = $row;
    }
    if (count($convergencerows) !==
            (int)$summary['approved_identity_convergences']) {
        throw new RuntimeException(
            'Las convergencias aprobadas no coinciden con plan_summary.json.'
        );
    }
    if (count($userrows) -
                (int)$summary['enrolment_rows_collapsed_by_identity_merge'] !==
            (int)$summary['effective_target_enrolments_planned'] ||
            count($rolerows) -
                (int)$summary['course_role_rows_collapsed_by_identity_merge'] !==
            (int)$summary['effective_target_course_roles_planned']) {
        throw new RuntimeException(
            'La reducción efectiva por convergencia no coincide con el plan.'
        );
    }

    $sourceinventories = [];
    foreach ($batchconfig['sources'] as $sourceid) {
        $inventory = p5_read_json(
            $paths['source_inventory_' . $sourceid . '.json']
        );
        if (($inventory['config_sha256'] ?? '') !== $configsha ||
                ($inventory['source_id'] ?? '') !== $sourceid ||
                ($inventory['write_performed'] ?? null) !== false) {
            throw new RuntimeException(
                'El inventario firmado de ' . $sourceid . ' no es válido.'
            );
        }
        $sourceinventories[$sourceid] = $inventory;
    }

    return [
        'summary' => $summary,
        'summary_path' => $summarypath,
        'summary_sha256' => hash_file('sha256', $summarypath),
        'paths' => $paths,
        'hashes' => $hashes,
        'batch_config' => $batchconfig,
        'phase4' => $contract,
        'category_rows' => $categoryrows,
        'course_rows' => $courserows,
        'courses_by_key' => $coursesbykey,
        'restore_courses' => $restorecourses,
        'user_rows' => $userrows,
        'user_rows_by_course' => $userrowsbycourse,
        'role_rows' => $rolerows,
        'role_rows_by_course' => $rolerowsbycourse,
        'convergence_rows' => $convergencerows,
        'convergence_by_course_target' => $convergencebycourseandtarget,
        'source_inventories' => $sourceinventories,
    ];
}

/**
 * Carga el manifiesto sellado por el comando 20 y vuelve a comprobar cada
 * artefacto. Es el contrato de entrada para aplicar y verificar el lote.
 */
function p6_load_prepared_manifest(
    string $phase4dir,
    string $phase6dir,
    string $configsha,
    string $targetid,
    bool $expectlab,
    ?string $artifactcoursekey = null
): array {
    $phase6dir = rtrim($phase6dir, '/\\');
    if ($artifactcoursekey !== null &&
            !preg_match(
                '/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/',
                $artifactcoursekey
            )) {
        throw new RuntimeException(
            'El alcance de verificación por curso es inválido.'
        );
    }
    $bundle = p6_load_inventory_plan(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $manifestpath = $phase6dir . '/batch_manifest.json';
    $progresspath = $phase6dir . '/backup_progress.csv';
    $manifest = p5_read_json($manifestpath);
    if (($manifest['schema_version'] ?? '') !== '1.0' ||
            ($manifest['phase'] ?? '') !== '6-multi-course-backup-manifest' ||
            ($manifest['config_sha256'] ?? '') !== $configsha ||
            ($manifest['target_id'] ?? '') !== $targetid ||
            ($manifest['batch_id'] ?? '') !==
                (string)$bundle['summary']['batch_id'] ||
            ($manifest['plan_summary_sha256'] ?? '') !==
                $bundle['summary_sha256'] ||
            ($manifest['manifest_status'] ?? '') !== 'prepared' ||
            (int)($manifest['courses_expected'] ?? -1) !==
                count($bundle['restore_courses']) ||
            (int)($manifest['courses_prepared'] ?? -1) !==
                count($bundle['restore_courses']) ||
            (int)($manifest['courses_pending'] ?? -1) !== 0 ||
            ($manifest['normalization_performed'] ?? null) !== false ||
            ($manifest['destination_write_performed'] ?? null) !== false ||
            ($manifest['categories_created'] ?? null) !== false ||
            ($manifest['courses_restored'] ?? null) !== false ||
            ($manifest['backup_progress_sha256'] ?? '') !==
                hash_file('sha256', $progresspath)) {
        throw new RuntimeException(
            'batch_manifest.json no conserva un lote preparado e íntegro.'
        );
    }
    $planhashes = $manifest['plan_artifacts_sha256'] ?? [];
    if (!is_array($planhashes)) {
        throw new RuntimeException('El manifiesto no conserva los hashes del plan.');
    }
    ksort($planhashes, SORT_STRING);
    $expectedplanhashes = $bundle['hashes'];
    ksort($expectedplanhashes, SORT_STRING);
    if ($planhashes !== $expectedplanhashes) {
        throw new RuntimeException('El plan cambió después de sellar los backups.');
    }
    $entries = $manifest['entries'] ?? null;
    if (!is_array($entries) ||
            count($entries) !== count($bundle['restore_courses']) ||
            ($manifest['entries_sha256'] ?? '') !== p6_value_sha256($entries)) {
        throw new RuntimeException('Las entradas del manifiesto perdieron integridad.');
    }
    $entriesbycourse = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('El manifiesto contiene una entrada inválida.');
        }
        $coursekey = trim((string)($entry['course_key'] ?? ''));
        $courseplan = $bundle['courses_by_key'][$coursekey] ?? null;
        if (!$courseplan ||
                isset($entriesbycourse[$coursekey]) ||
                ($courseplan['action'] ?? '') !== 'restore_new' ||
                ($entry['source_shortname'] ?? '') !==
                    (string)$courseplan['source_shortname'] ||
                ($entry['target_shortname'] ?? '') !==
                    (string)$courseplan['target_shortname'] ||
                ($entry['target_fullname'] ?? '') !==
                    (string)$courseplan['target_fullname'] ||
                ($entry['preparation_status'] ?? '') !== 'prepared') {
            throw new RuntimeException(
                'El manifiesto contiene un curso ajeno, repetido o no preparado.'
            );
        }
        $paths = [
            'raw_backup' => $phase6dir . '/' .
                ltrim((string)($entry['raw_backup_file'] ?? ''), '/\\'),
            'source_inventory' => $phase6dir . '/' .
                ltrim((string)($entry['source_inventory_file'] ?? ''), '/\\'),
            'backup_audit' => $phase6dir . '/' .
                ltrim((string)($entry['backup_audit_file'] ?? ''), '/\\'),
            'checkpoint' => $phase6dir . '/' .
                ltrim((string)($entry['checkpoint_file'] ?? ''), '/\\'),
        ];
        foreach ($paths as $label => $path) {
            if (!is_readable($path)) {
                throw new RuntimeException(
                    'Falta el artefacto ' . $label . ' de ' . $coursekey . '.'
                );
            }
        }
        $verifyartifacts =
            $artifactcoursekey === null || $artifactcoursekey === $coursekey;
        if ($verifyartifacts) {
            $actualhashes = [
                'raw_backup_sha256' =>
                    hash_file('sha256', $paths['raw_backup']),
                'source_inventory_sha256' =>
                    hash_file('sha256', $paths['source_inventory']),
                'backup_audit_sha256' =>
                    hash_file('sha256', $paths['backup_audit']),
                'checkpoint_sha256' =>
                    hash_file('sha256', $paths['checkpoint']),
            ];
            foreach ($actualhashes as $field => $hash) {
                if (($entry[$field] ?? '') !== $hash) {
                    throw new RuntimeException(
                        'El artefacto ' . $field . ' de ' .
                        $coursekey . ' cambió.'
                    );
                }
            }
        }
        $entry['_paths'] = $paths;
        $entry['_artifact_hashes_verified'] = $verifyartifacts;
        $entriesbycourse[$coursekey] = $entry;
    }
    if ($artifactcoursekey !== null &&
            !isset($entriesbycourse[$artifactcoursekey])) {
        throw new RuntimeException(
            'El curso solicitado no pertenece al manifiesto preparado.'
        );
    }
    if ($expectlab &&
            (($manifest['lab_validation'] ?? '') !== 'passed' ||
             count($entries) !== count($bundle['restore_courses']) ||
             (int)($manifest['approved_identity_convergences'] ?? -1) !==
                (int)$bundle['summary']['approved_identity_convergences'])) {
        throw new RuntimeException('El manifiesto LAB no conserva los totales aprobados.');
    }
    $bundle['manifest'] = $manifest;
    $bundle['manifest_path'] = $manifestpath;
    $bundle['manifest_sha256'] = hash_file('sha256', $manifestpath);
    $bundle['manifest_entries_by_course'] = $entriesbycourse;
    return $bundle;
}

/** Rechaza incluso modificaciones que conservan el tamaño del archivo sellado. */
function p6_assert_sealed_sha256(string $path, string $expected): void {
    if (!preg_match('/^[a-f0-9]{64}$/', $expected) ||
            !is_readable($path) || hash_file('sha256', $path) !== $expected) {
        throw new RuntimeException('El artefacto sellado cambió: ' . $path);
    }
}

/** Reanudar un curso restaurado sin ejecutar una segunda restauración. */
function p6_resume_completed_restore(string $state): bool {
    return in_array($state, [
        'restore_completed', 'restored_pending_verification',
        'verification_failed',
    ], true);
}

/** Revalida los hashes sellados antes de ejecutar cada curso. */
function p6_load_reference_manifest(
    string $phase4dir,
    string $phase6dir,
    string $configsha,
    string $targetid,
    bool $expectlab,
    ?string $artifactcoursekey = null
): array {
    $phase6dir = rtrim($phase6dir, '/\\');
    if ($artifactcoursekey !== null &&
            !preg_match(
                '/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/',
                $artifactcoursekey
            )) {
        throw new RuntimeException('El alcance de verificación por curso es inválido.');
    }
    $bundle = p6_load_inventory_plan(
        $phase4dir,
        $phase6dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $manifestpath = $phase6dir . '/batch_manifest.json';
    $progresspath = $phase6dir . '/backup_progress.csv';
    $manifest = p5_read_json($manifestpath);
    if (($manifest['schema_version'] ?? '') !== '1.1' ||
            ($manifest['phase'] ?? '') !== '6-lightweight-batch-manifest' ||
            ($manifest['config_sha256'] ?? '') !== $configsha ||
            ($manifest['target_id'] ?? '') !== $targetid ||
            ($manifest['batch_id'] ?? '') !== (string)$bundle['summary']['batch_id'] ||
            ($manifest['plan_summary_sha256'] ?? '') !== $bundle['summary_sha256'] ||
            ($manifest['manifest_status'] ?? '') !== 'BATCH_READY' ||
            ($manifest['single_extraction_pipeline'] ?? null) !== true ||
            ($manifest['worker_course_precheck'] ?? null) !== true ||
            (int)($manifest['mbz_deep_open_count'] ?? -1) !== 0 ||
            ($manifest['source_archives_hashed_again'] ?? null) !== false ||
            (int)($manifest['raw_backups_created'] ?? -1) !== 0 ||
            (int)($manifest['normalized_backups_created'] ?? -1) !== 0 ||
            (int)($manifest['courses_expected'] ?? -1) !==
                count($bundle['restore_courses']) ||
            (int)($manifest['courses_prepared'] ?? -1) !== 0 ||
            (int)($manifest['courses_pending'] ?? -1) !==
                count($bundle['restore_courses']) ||
            ($manifest['normalization_performed'] ?? null) !== false ||
            ($manifest['destination_write_performed'] ?? null) !== false ||
            ($manifest['categories_created'] ?? null) !== false ||
            ($manifest['courses_restored'] ?? null) !== false ||
            ($manifest['backup_progress_sha256'] ?? '') !==
                hash_file('sha256', $progresspath)) {
        throw new RuntimeException('batch_manifest.json no conserva el contrato optimizado.');
    }
    $planhashes = $manifest['plan_artifacts_sha256'] ?? [];
    $expectedplanhashes = $bundle['hashes'];
    if (!is_array($planhashes)) {
        throw new RuntimeException('El manifiesto no conserva los hashes del plan.');
    }
    ksort($planhashes, SORT_STRING);
    ksort($expectedplanhashes, SORT_STRING);
    if ($planhashes !== $expectedplanhashes) {
        throw new RuntimeException('El plan cambió después de sellar las referencias.');
    }
    $entries = $manifest['entries'] ?? null;
    if (!is_array($entries) ||
            count($entries) !== count($bundle['restore_courses']) ||
            ($manifest['entries_sha256'] ?? '') !== p6_value_sha256($entries)) {
        throw new RuntimeException('Las entradas del manifiesto perdieron integridad.');
    }
    $entriesbycourse = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('El manifiesto contiene una entrada inválida.');
        }
        $coursekey = trim((string)($entry['course_key'] ?? ''));
        $courseplan = $bundle['courses_by_key'][$coursekey] ?? null;
        if (!$courseplan || isset($entriesbycourse[$coursekey]) ||
                ($courseplan['action'] ?? '') !== 'restore_new' ||
                ($entry['source_shortname'] ?? '') !==
                    (string)$courseplan['source_shortname'] ||
                ($entry['target_shortname'] ?? '') !==
                    (string)$courseplan['target_shortname'] ||
                ($entry['target_fullname'] ?? '') !==
                    (string)$courseplan['target_fullname'] ||
                ($entry['preparation_status'] ?? '') !==
                    'referenced_pending_worker_precheck' ||
                ($entry['worker_precheck_deferred'] ?? null) !== true ||
                ($entry['source_archive_hashed_again'] ?? null) !== false ||
                ($entry['source_archive_copied'] ?? null) !== false) {
            throw new RuntimeException('El manifiesto contiene un curso ajeno o repetido.');
        }
        $sourcebackuprelative = ltrim(
            (string)($entry['source_backup_file'] ?? ''),
            '/\\'
        );
        if ($sourcebackuprelative === '' || str_contains($sourcebackuprelative, '..')) {
            throw new RuntimeException('La referencia de ' . $coursekey . ' es insegura.');
        }
        $basename = p6_backup_basename($coursekey);
        $paths = [
            'source_backup' => dirname($phase6dir) . '/' . $sourcebackuprelative,
            'package_inventory' => dirname($phase6dir) . '/' .
                ltrim((string)($entry['package_inventory_file'] ?? ''), '/\\'),
            'source_inventory' => $phase6dir . '/course-inventories/inventory-' .
                $basename . '.json',
            'course_job' => $phase6dir . '/course-jobs/job-' . $basename . '.json',
            'checkpoint' => $phase6dir . '/backup-checkpoints/checkpoint-' .
                $basename . '.json',
        ];
        foreach (['source_backup', 'package_inventory'] as $label) {
            $path = $paths[$label];
            if (!is_readable($path)) {
                throw new RuntimeException('Falta ' . $label . ' de ' . $coursekey . '.');
            }
        }
        $actualbytes = filesize($paths['source_backup']);
        if ($actualbytes === false ||
                (int)$actualbytes !== (int)($entry['source_backup_bytes'] ?? 0) ||
                !preg_match(
                    '/^[a-f0-9]{64}$/',
                    (string)($entry['source_backup_sha256'] ?? '')
                ) ||
                !preg_match(
                    '/^[a-f0-9]{64}$/',
                    (string)($entry['package_inventory_sha256'] ?? '')
                ) ||
                hash_file('sha256', $paths['package_inventory']) !==
                    (string)$entry['package_inventory_sha256']) {
            throw new RuntimeException('El MBZ referenciado de ' . $coursekey . ' cambió de tamaño.');
        }
        $entry['_paths'] = $paths;
        $entry['_artifact_hashes_verified'] = false;
        $entry['_source_archive_sha_trusted_from_import'] = true;
        $entriesbycourse[$coursekey] = $entry;
    }
    if ($artifactcoursekey !== null && !isset($entriesbycourse[$artifactcoursekey])) {
        throw new RuntimeException('El curso solicitado no pertenece al manifiesto.');
    }
    if ($expectlab && ($manifest['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('El manifiesto LAB no superó su validación.');
    }
    $bundle['manifest'] = $manifest;
    $bundle['manifest_path'] = $manifestpath;
    $bundle['manifest_sha256'] = hash_file('sha256', $manifestpath);
    $bundle['manifest_entries_by_course'] = $entriesbycourse;
    return $bundle;
}

/**
 * Carga solo el trabajo del curso. Evita que cada worker vuelva a leer el plan
 * global, los inventarios de todas las fuentes y los planes de los demás
 * cursos. El coordinador ya validó el lote completo en el preflight.
 */
function p6_load_course_job(
    string $phase4dir,
    string $phase6dir,
    string $configsha,
    string $targetid,
    string $coursekey,
    bool $expectlab
): array {
    $phase6dir = rtrim($phase6dir, '/\\');
    if (!preg_match('/^COURSE-[A-Z0-9_-]+-[A-F0-9]{12}$/', $coursekey)) {
        throw new RuntimeException('coursekey inválido para el trabajo liviano.');
    }
    $manifestpath = $phase6dir . '/batch_manifest.json';
    $manifest = p5_read_json($manifestpath);
    if (($manifest['schema_version'] ?? '') !== '1.1' ||
            ($manifest['phase'] ?? '') !== '6-lightweight-batch-manifest' ||
            ($manifest['config_sha256'] ?? '') !== $configsha ||
            ($manifest['target_id'] ?? '') !== $targetid ||
            ($manifest['manifest_status'] ?? '') !== 'BATCH_READY' ||
            ($manifest['single_extraction_pipeline'] ?? null) !== true ||
            ($manifest['worker_course_precheck'] ?? null) !== true ||
            ($manifest['destination_write_performed'] ?? null) !== false) {
        throw new RuntimeException('El manifiesto no admite trabajos livianos.');
    }
    $entries = $manifest['entries'] ?? null;
    if (!is_array($entries) ||
            ($manifest['entries_sha256'] ?? '') !== p6_value_sha256($entries)) {
        throw new RuntimeException('Las entradas del manifiesto perdieron integridad.');
    }
    $entry = null;
    foreach ($entries as $candidate) {
        if (($candidate['course_key'] ?? '') === $coursekey) {
            if ($entry !== null) {
                throw new RuntimeException('El manifiesto repite el curso solicitado.');
            }
            $entry = $candidate;
        }
    }
    if (!is_array($entry) ||
            ($entry['preparation_status'] ?? '') !==
                'referenced_pending_worker_precheck') {
        throw new RuntimeException('El curso no pertenece al lote referenciado.');
    }
    $sourcebackuprelative = ltrim(
        (string)($entry['source_backup_file'] ?? ''),
        '/\\'
    );
    if ($sourcebackuprelative === '' || str_contains($sourcebackuprelative, '..')) {
        throw new RuntimeException('La referencia del MBZ es insegura.');
    }
    $basename = p6_backup_basename($coursekey);
    $paths = [
        'source_backup' => dirname($phase6dir) . '/' . $sourcebackuprelative,
        'source_inventory' => $phase6dir . '/course-inventories/inventory-' .
            $basename . '.json',
        'course_job' => $phase6dir . '/course-jobs/job-' . $basename . '.json',
        'checkpoint' => $phase6dir . '/backup-checkpoints/checkpoint-' .
            $basename . '.json',
    ];
    foreach ($paths as $label => $path) {
        if (!is_readable($path)) {
            throw new RuntimeException('Falta ' . $label . ' de ' . $coursekey . '.');
        }
    }
    $actualbytes = filesize($paths['source_backup']);
    if ($actualbytes === false ||
            (int)$actualbytes !== (int)($entry['source_backup_bytes'] ?? 0)) {
        throw new RuntimeException('Los artefactos livianos del curso cambiaron.');
    }
    $job = p5_read_json($paths['course_job']);
    $checkpoint = p5_read_json($paths['checkpoint']);
    $checkpointfiles = $checkpoint['files_sha256'] ?? null;
    $actualfiles = p5_hash_files([
        'source_inventory' => $paths['source_inventory'],
        'course_job' => $paths['course_job'],
    ]);
    if (!is_array($checkpointfiles)) {
        throw new RuntimeException('El checkpoint no contiene hashes derivados.');
    }
    ksort($checkpointfiles, SORT_STRING);
    if ($checkpointfiles !== $actualfiles) {
        throw new RuntimeException('Los artefactos derivados del worker cambiaron.');
    }
    $courseplan = $job['course_plan'] ?? null;
    if (($job['schema_version'] ?? '') !== '1.0' ||
            ($job['phase'] ?? '') !== '6-course-restore-job' ||
            ($job['config_sha256'] ?? '') !== $configsha ||
            ($job['plan_summary_sha256'] ?? '') !==
                (string)$manifest['plan_summary_sha256'] ||
            ($job['batch_id'] ?? '') !== (string)$manifest['batch_id'] ||
            ($job['course_key'] ?? '') !== $coursekey ||
            !is_array($courseplan) ||
            ($courseplan['action'] ?? '') !== 'restore_new' ||
            ($job['source_backup_sha256'] ?? '') !==
                (string)$entry['source_backup_sha256'] ||
            ($job['source_backup_file'] ?? '') !==
                (string)$entry['source_backup_file'] ||
            (int)($job['source_backup_bytes'] ?? 0) !== (int)$actualbytes ||
            ($job['source_archive_hashed_again'] ?? null) !== false ||
            ($job['source_archive_copied'] ?? null) !== false) {
        throw new RuntimeException('El trabajo liviano no conserva su contrato.');
    }
    p6_assert_referenced_checkpoint_classification(
        $checkpoint,
        $job,
        'El trabajo liviano de ' . $coursekey
    );
    $sourceid = (string)$courseplan['source'];
    $globalcontract = p5_load_phase4_contract(
        $phase4dir, $configsha, $targetid, $expectlab
    );
    $approvedhashes = $job['phase4_input_sha256'] ?? null;
    $currenthashes = $globalcontract['hashes'];
    if (!is_array($approvedhashes)) {
        throw new RuntimeException('El job no tiene un contrato global de Fase 4.');
    }
    ksort($approvedhashes, SORT_STRING);
    ksort($currenthashes, SORT_STRING);
    if ($approvedhashes !== $currenthashes) {
        throw new RuntimeException('El mapa global de Fase 4 cambió tras sellar el job.');
    }
    $classification = $job['backup_user_classification'] ?? null;
    if (!is_array($classification) ||
            ($classification['source'] ?? '') !== $sourceid) {
        throw new RuntimeException('El job no conserva una clasificación aprobada de users.xml.');
    }
    $sourceinventorydocument = p5_read_json($paths['source_inventory']);
    p6_source_course_inventory_payload(
        $sourceinventorydocument,
        (string)($job['source_state_sha256'] ?? '')
    );
    $sourcebykey = [];
    foreach ($job['user_mappings'] ?? [] as $item) {
        $sourceuserid = (int)($item['source_user_id'] ?? 0);
        $mapping = $item['mapping'] ?? null;
        if ($sourceuserid < 1 || !is_array($mapping)) {
            throw new RuntimeException('El trabajo contiene un mapa de usuario inválido.');
        }
        $key = $sourceid . ':' . $sourceuserid;
        if (isset($sourcebykey[$key]) ||
                ($globalcontract['source_by_key'][$key] ?? null) !== $mapping) {
            throw new RuntimeException('El subconjunto del job contradice Fase 4: ' . $key);
        }
        $sourcebykey[$key] = $mapping;
    }
    $entry['_paths'] = $paths;
    $entry['_artifact_hashes_verified'] = true;
    $entry['_source_archive_sha_trusted_from_import'] = true;
    return [
        'summary' => ['batch_id' => (string)$manifest['batch_id']],
        'batch_config' => $job['batch_config'] ?? [],
        'courses_by_key' => [$coursekey => $courseplan],
        'restore_courses' => [$courseplan],
        'phase4' => ['source_by_key' => $sourcebykey],
        'phase4_global_source_by_key' => $globalcontract['source_by_key'],
        'user_rows_by_course' => [
            $coursekey => array_values($job['course_user_plan_rows'] ?? []),
        ],
        'role_rows_by_course' => [
            $coursekey => array_values($job['course_role_plan_rows'] ?? []),
        ],
        'convergence_by_course_target' => [
            $coursekey => $job['identity_convergences_by_target'] ?? [],
        ],
        'manifest' => $manifest,
        'manifest_path' => $manifestpath,
        'manifest_sha256' => hash_file('sha256', $manifestpath),
        'manifest_entries_by_course' => [$coursekey => $entry],
        'course_job' => $job,
        'source_inventory_document' => $sourceinventorydocument,
    ];
}

/**
 * Reduce las matrículas del plan al conjunto efectivo del destino. Una
 * convergencia aprobada puede transformar varias cuentas de origen en una sola
 * matrícula canónica, pero nunca mezcla estados incompatibles.
 */
function p6_effective_course_enrolments(array $bundle, string $coursekey): array {
    $rows = [];
    foreach ($bundle['user_rows_by_course'][$coursekey] ?? [] as $row) {
        $key = (int)$row['target_user_id'] . '|' .
            p5_norm((string)$row['enrol_method']);
        $normalized = [
            'target_user_id' => (int)$row['target_user_id'],
            'target_username' => (string)$row['target_username'],
            'enrol_method' => p5_norm((string)$row['enrol_method']),
            'enrol_status' => (int)$row['enrol_status'],
        ];
        if (isset($rows[$key]) &&
                $rows[$key]['enrol_status'] !== $normalized['enrol_status']) {
            throw new RuntimeException(
                'El plan contiene estados de matrícula incompatibles en ' .
                $coursekey . '.'
            );
        }
        $rows[$key] = $normalized;
    }
    ksort($rows, SORT_STRING);
    return array_values($rows);
}

/**
 * Conserva la unión de roles por curso para cada identidad canónica.
 */
function p6_effective_course_roles(array $bundle, string $coursekey): array {
    $rows = [];
    foreach ($bundle['role_rows_by_course'][$coursekey] ?? [] as $row) {
        $role = p5_norm((string)$row['target_role_shortname']);
        $key = (int)$row['target_user_id'] . '|' . $role;
        $rows[$key] = [
            'target_user_id' => (int)$row['target_user_id'],
            'target_role_shortname' => $role,
            'safety_profile' => (string)$row['safety_profile'],
        ];
    }
    ksort($rows, SORT_STRING);
    return array_values($rows);
}

function p6_personalizado_allowed_capabilities(): array {
    return [
        'moodle/course:view',
        'mod/assign:view',
        'mod/book:read',
        'mod/data:viewentry',
        'mod/feedback:view',
        'mod/folder:view',
        'mod/forum:viewdiscussion',
        'mod/glossary:view',
        'mod/h5pactivity:view',
        'mod/imscp:view',
        'mod/lesson:view',
        'mod/page:view',
        'mod/qbank:view',
        'mod/quiz:view',
        'mod/resource:view',
        'mod/scorm:view',
        'mod/url:view',
        'mod/wiki:viewpage',
        'mod/workshop:view',
    ];
}

/**
 * Comprueba que personalizado sea estrictamente de lectura y solo asignable
 * en cursos. Un rol preexistente con el mismo shortname debe coincidir con la
 * política aprobada; nunca se corrige silenciosamente.
 */
function p6_personalizado_role_status(): array {
    global $DB;

    $role = $DB->get_record(
        'role',
        ['shortname' => 'personalizado'],
        'id,name,shortname,description,archetype',
        IGNORE_MISSING
    );
    if (!$role) {
        return [
            'exists' => false,
            'safe' => true,
            'role_id' => null,
            'issues' => [],
        ];
    }
    $issues = [];
    if (!str_contains((string)$role->description, 'MIG-P6')) {
        $issues[] =
            'El shortname personalizado ya existe, pero no pertenece a esta migración.';
    }
    $levels = array_values(array_map(
        'intval',
        get_role_contextlevels((int)$role->id)
    ));
    sort($levels, SORT_NUMERIC);
    if ($levels !== [CONTEXT_COURSE]) {
        $issues[] = 'El rol personalizado no está limitado al contexto de curso.';
    }
    $allowed = array_fill_keys(p6_personalizado_allowed_capabilities(), true);
    $granted = [];
    foreach ($DB->get_records(
        'role_capabilities',
        ['roleid' => (int)$role->id],
        'capability ASC',
        'id,capability,permission'
    ) as $capability) {
        if ((int)$capability->permission <= 0) {
            continue;
        }
        $granted[] = (string)$capability->capability;
        if (!isset($allowed[(string)$capability->capability])) {
            $issues[] = 'Capacidad no permitida: ' .
                (string)$capability->capability . '.';
        }
    }
    if (!in_array('moodle/course:view', $granted, true)) {
        $issues[] = 'El rol personalizado no permite consultar el curso.';
    }
    return [
        'exists' => true,
        'safe' => $issues === [],
        'role_id' => (int)$role->id,
        'granted_capabilities' => $granted,
        'issues' => $issues,
    ];
}

function p6_ensure_personalizado_role(): array {
    global $CFG;

    require_once($CFG->dirroot . '/admin/roles/lib.php');
    $status = p6_personalizado_role_status();
    if ($status['exists']) {
        if (!$status['safe']) {
            throw new RuntimeException(implode(' ', $status['issues']));
        }
        $status['action'] = 'reused';
        return $status;
    }
    $roleid = (int)create_role(
        'Personalizado (solo lectura)',
        'personalizado',
        'MIG-P6: acceso de consulta, sin edición ni administración.',
        ''
    );
    if ($roleid < 1) {
        throw new RuntimeException('Moodle no pudo crear el rol personalizado.');
    }
    set_role_contextlevels($roleid, [CONTEXT_COURSE]);
    $systemcontext = context_system::instance();
    $granted = [];
    foreach (p6_personalizado_allowed_capabilities() as $capability) {
        if (get_capability_info($capability) === null) {
            continue;
        }
        assign_capability(
            $capability,
            CAP_ALLOW,
            $roleid,
            (int)$systemcontext->id,
            true
        );
        $granted[] = $capability;
    }
    $status = p6_personalizado_role_status();
    if (!$status['exists'] || !$status['safe']) {
        throw new RuntimeException(
            'El rol personalizado creado no superó su validación de mínimo privilegio.'
        );
    }
    $status['action'] = 'created';
    $status['granted_capabilities'] = $granted;
    return $status;
}

/**
 * Materializa como archivos regulares únicamente aliases del repositorio local
 * cuyo destino lógico no está incluido en el backup, pero cuyo payload físico
 * sí está presente e íntegro en el pool del MBZ.
 *
 * No modifica aliases resolubles de forma nativa por Moodle.
 */
function p6_materialize_unresolvable_local_aliases(string $directory): array {
    $directory = rtrim($directory, '/\\');
    $filespath = $directory . '/files.xml';

    if (!is_readable($filespath)) {
        throw new RuntimeException(
            'LOCAL_ALIAS_MATERIALIZATION_FILES_XML_MISSING'
        );
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;

    if (!$dom->load($filespath, LIBXML_NONET)) {
        throw new RuntimeException(
            'LOCAL_ALIAS_MATERIALIZATION_FILES_XML_INVALID'
        );
    }

    $nodes = [];
    $logicalfiles = [];

    $logicalkey = static function(
        mixed $contextid,
        mixed $component,
        mixed $filearea,
        mixed $itemid,
        mixed $filepath,
        mixed $filename
    ): string {
        return implode("\x1f", [
            (string)$contextid,
            (string)$component,
            (string)$filearea,
            (string)$itemid,
            (string)$filepath,
            (string)$filename,
        ]);
    };

    foreach ($dom->getElementsByTagName('file') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $nodes[] = $node;

        $logicalfiles[$logicalkey(
            p5_dom_direct_text($node, 'contextid'),
            p5_dom_direct_text($node, 'component'),
            p5_dom_direct_text($node, 'filearea'),
            p5_dom_direct_text($node, 'itemid'),
            p5_dom_direct_text($node, 'filepath'),
            p5_dom_direct_text($node, 'filename')
        )] = true;
    }

    $materialized = [];

    foreach ($nodes as $node) {
        $repositorytype = trim(
            (string)(p5_dom_direct_text($node, 'repositorytype') ?? '')
        );

        if ($repositorytype !== 'local') {
            continue;
        }

        $repositoryid = p5_dom_direct_text($node, 'repositoryid');
        $packedreference = p5_dom_direct_text($node, 'reference');

        // Si repositoryid ya es nulo, Moodle lo tratará como archivo regular.
        if (p6_backup_nullish($repositoryid)) {
            continue;
        }

        $fileid = (int)$node->getAttribute('id');

        if (p6_backup_nullish($packedreference)) {
            throw new RuntimeException(
                'LOCAL_ALIAS_REFERENCE_MISSING file_id=' . $fileid
            );
        }

        try {
            $reference = file_storage::unpack_reference(
                (string)$packedreference
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'LOCAL_ALIAS_REFERENCE_INVALID file_id=' . $fileid,
                0,
                $e
            );
        }

        if (!is_array($reference)) {
            throw new RuntimeException(
                'LOCAL_ALIAS_REFERENCE_INVALID file_id=' . $fileid
            );
        }

        foreach ([
            'contextid',
            'component',
            'filearea',
            'itemid',
            'filepath',
            'filename',
        ] as $required) {
            if (!array_key_exists($required, $reference)) {
                throw new RuntimeException(
                    'LOCAL_ALIAS_REFERENCE_INCOMPLETE file_id=' .
                    $fileid . ' field=' . $required
                );
            }
        }

        $referencekey = $logicalkey(
            $reference['contextid'],
            $reference['component'],
            $reference['filearea'],
            $reference['itemid'],
            $reference['filepath'],
            $reference['filename']
        );

        // Moodle puede restaurar este alias de forma nativa.
        if (isset($logicalfiles[$referencekey])) {
            continue;
        }

        $contenthash = strtolower(trim(
            (string)(p5_dom_direct_text($node, 'contenthash') ?? '')
        ));

        if (!preg_match('/^[a-f0-9]{40}$/', $contenthash)) {
            throw new RuntimeException(
                'LOCAL_ALIAS_CONTENTHASH_INVALID file_id=' . $fileid
            );
        }

        $filesizetext = trim(
            (string)(p5_dom_direct_text($node, 'filesize') ?? '')
        );

        if (!preg_match('/^\d+$/', $filesizetext)) {
            throw new RuntimeException(
                'LOCAL_ALIAS_FILESIZE_INVALID file_id=' . $fileid
            );
        }

        $expectedbytes = (int)$filesizetext;
        $payload = $directory . '/files/' .
            substr($contenthash, 0, 2) . '/' . $contenthash;

        if (!is_file($payload) || !is_readable($payload)) {
            throw new RuntimeException(
                'LOCAL_ALIAS_PAYLOAD_MISSING file_id=' .
                $fileid . ' contenthash=' . $contenthash
            );
        }

        $actualbytes = filesize($payload);
        if ($actualbytes === false || $actualbytes !== $expectedbytes) {
            throw new RuntimeException(
                'LOCAL_ALIAS_PAYLOAD_SIZE_MISMATCH file_id=' .
                $fileid .
                ' expected=' . $expectedbytes .
                ' actual=' . ($actualbytes === false ? 'unreadable' : $actualbytes)
            );
        }

        $actualhash = hash_file('sha1', $payload);
        if ($actualhash === false ||
                !hash_equals($contenthash, strtolower($actualhash))) {
            throw new RuntimeException(
                'LOCAL_ALIAS_PAYLOAD_HASH_MISMATCH file_id=' . $fileid
            );
        }

        // El contenido está íntegro dentro del propio backup. Se conserva la
        // ubicación lógica del alias, pero Moodle recibirá una copia física
        // ordinaria en vez de intentar reconstruir una referencia cross-site.
        p5_dom_set($node, 'repositorytype', '$@NULL@$');
        p5_dom_set($node, 'repositoryid', '$@NULL@$');
        p5_dom_set($node, 'reference', '$@NULL@$');

        $materialized[] = [
            'file_id' => $fileid,
            'contenthash' => $contenthash,
            'filesize' => $expectedbytes,
            'component' =>
                (string)(p5_dom_direct_text($node, 'component') ?? ''),
            'filearea' =>
                (string)(p5_dom_direct_text($node, 'filearea') ?? ''),
            'filename' =>
                (string)(p5_dom_direct_text($node, 'filename') ?? ''),
            'missing_reference_contextid' =>
                (int)$reference['contextid'],
            'missing_reference_component' =>
                (string)$reference['component'],
            'missing_reference_filearea' =>
                (string)$reference['filearea'],
        ];
    }

    if ($materialized) {
        $mode = fileperms($filespath);
        $temporary = $filespath . '.alias-' . bin2hex(random_bytes(6));

        if ($dom->save($temporary) === false ||
                ($mode !== false &&
                    !chmod($temporary, $mode & 0777)) ||
                !rename($temporary, $filespath)) {
            @unlink($temporary);
            throw new RuntimeException(
                'LOCAL_ALIAS_MATERIALIZATION_WRITE_FAILED'
            );
        }
    }

    return [
        'materialized_count' => count($materialized),
        'materialized' => $materialized,
        'files_xml_changed' => count($materialized) > 0,
    ];
}

/**
 * Normaliza usuarios y roles directamente dentro de la única extracción que
 * consumirá restore_controller. No reconstruye ni duplica el MBZ.
 */
function p6_normalize_extracted_backup(
    string $directory,
    string $sourceid,
    string $coursekey,
    string $targeturl,
    array $bundle,
    array $targetusersbyid,
    string $auditpath,
    string $effectiveinventorypath,
    string $sourcebackupsha256,
    int $sourcebackupbytes,
    ?array $degradationplan = null
): array {
    $directory = rtrim($directory, '/\\');
    $userspath = $directory . '/users.xml';
    $rolespath = $directory . '/roles.xml';
    if (!is_dir($directory) ||
            !is_readable($directory . '/moodle_backup.xml') ||
            !is_readable($userspath) ||
            !preg_match('/^[a-f0-9]{64}$/', $sourcebackupsha256) ||
            $sourcebackupbytes < 1) {
        throw new RuntimeException(
            'La extracción única no contiene los artefactos obligatorios.'
        );
    }
    $questionnormalization = p6_normalize_legacy_random_questions($directory);
    if (($questionnormalization['legacy_random_detected'] ?? 0) > 0) {
        cli_writeln(
            'LEGACY_RANDOM_QUESTION_NORMALIZED detected=' .
            (int)$questionnormalization['legacy_random_detected'] .
            ' removed=' .
            (int)$questionnormalization['legacy_random_removed'] .
            ' orphan_physical_rows=' .
            (int)$questionnormalization['legacy_random_orphan_physical_rows'] .
            ' set_references_verified=' .
            (int)$questionnormalization['legacy_random_set_references_verified']
        );
    }
    $aliasmaterialization =
        p6_materialize_unresolvable_local_aliases($directory);

    if (($aliasmaterialization['materialized_count'] ?? 0) > 0) {
        cli_writeln(
            'LOCAL_ALIAS_MATERIALIZED count=' .
            (int)$aliasmaterialization['materialized_count'] .
            ' file_ids=' .
            implode(
                '|',
                array_map(
                    static fn(array $row): string =>
                        (string)$row['file_id'],
                    $aliasmaterialization['materialized']
                )
            )
        );
    }

    $sourceinventorydocument = $bundle['source_inventory_document'] ?? null;
    $courseplan = $bundle['courses_by_key'][$coursekey] ?? null;
    if (!is_array($sourceinventorydocument) || !is_array($courseplan) ||
            (string)($courseplan['source'] ?? '') !== $sourceid) {
        throw new RuntimeException(
            'El trabajo no contiene el inventario fuente necesario para normalizar.'
        );
    }
    $sourceinventory = p6_source_course_inventory_payload(
        $sourceinventorydocument,
        (string)($bundle['course_job']['source_state_sha256'] ?? '')
    );
    $degradationapplication = $degradationplan === null
        ? ['omitted_file_ids' => [], 'files_xml_changed' => false]
        : p6_apply_degradation_plan_to_extracted_backup(
            $directory,
            $degradationplan
        );
    $effectiveaudit = null;
    $effectiveinventory = p6_effective_restore_inventory(
        $sourceinventory,
        $sourceid,
        (int)($courseplan['source_course_id'] ?? 0),
        $directory,
        $effectiveaudit,
        $degradationplan
    );
    $effectiveaudit['degradation_application'] = $degradationapplication;
    $effectiveaudit['local_alias_materialization'] = $aliasmaterialization;
    p5_write_json($effectiveinventorypath, [
        'schema_version' => '1.0',
        'phase' => '6-effective-source-course-inventory',
        'generated_at_utc' => gmdate('c'),
        'course_key' => $coursekey,
        'source_id' => $sourceid,
        'source_state_sha256' =>
            (string)$bundle['course_job']['source_state_sha256'],
        'source_inventory_sha256' => hash_file(
            'sha256',
            (string)$bundle['manifest_entries_by_course'][$coursekey]
                ['_paths']['source_inventory']
        ),
        'inventory' => $effectiveinventory,
        'normalization_evidence' => $effectiveaudit,
        'write_performed' => false,
        'single_extraction' => true,
        'normalized_archive_created' => false,
    ]);
    $effectiveinventorysha = hash_file('sha256', $effectiveinventorypath);
    if ($effectiveinventorysha === false) {
        throw new RuntimeException('No fue posible sellar el inventario efectivo.');
    }
    $usersbefore = hash_file('sha256', $userspath);
    $rolesbefore = is_readable($rolespath)
        ? hash_file('sha256', $rolespath)
        : null;

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!$dom->load($userspath, LIBXML_NONET)) {
        throw new RuntimeException('users.xml no es XML válido.');
    }
    $globalmap = $bundle['phase4_global_source_by_key'] ?? [];
    $expectedclassification = $bundle['course_job']['backup_user_classification'] ?? null;
    $expected = $expectedclassification['academic_source_user_ids'] ?? null;
    $expectedplanned =
        $expectedclassification['planned_participant_source_user_ids'] ?? null;
    $expecteddeclared =
        $expectedclassification['backup_declared_user_reference_ids'] ?? null;
    if (!is_array($expected) || !is_array($expectedplanned) ||
            !is_array($expecteddeclared)) {
        throw new RuntimeException('El job carece de referencias académicas clasificadas.');
    }
    $plannedparticipants = p6_expected_course_source_user_ids($bundle, $coursekey);
    $actualclassification = p6_classify_backup_users(
        p6_parse_backup_users_xml($userspath),
        $sourceid,
        $expected,
        $globalmap,
        $expectedplanned,
        $expecteddeclared
    );
    if (!is_array($expectedclassification) ||
            $actualclassification !== $expectedclassification ||
            $actualclassification['blocked']) {
        throw new RuntimeException(
            'users.xml de ' . $coursekey .
            ' no coincide con la clasificación sellada antes de aplicar.'
        );
    }
    $classifiedbyid = [];
    foreach ($actualclassification['users'] as $row) {
        $classifiedbyid[(int)$row['source_user_id']] = $row;
    }
    $audit = [];
    $observed = [];
    $targetsources = [];
    foreach ($dom->getElementsByTagName('user') as $user) {
        if (!$user instanceof DOMElement) {
            continue;
        }
        $sourceuserid = (int)$user->getAttribute('id');
        $sourceusername = p5_dom_text($user, 'username');
        $sourceemail = p5_dom_text($user, 'email');
        if ($sourceuserid < 1) {
            throw new RuntimeException('users.xml contiene un ID inválido.');
        }
        if (p5_norm($sourceusername) === 'guest') {
            continue;
        }
        $classification = $classifiedbyid[$sourceuserid]['classification'] ?? '';
        if ($classification === 'historical_deleted') {
            // Moodle recibe el registro histórico original. No se inventa un
            // usuario activo ni una vinculación OAuth para sustituirlo.
            $audit[] = [
                'source_user_id' => $sourceuserid,
                'source_username' => $sourceusername,
                'source_email' => $sourceemail,
                'target_user_id' => null,
                'canonical_id' => '',
                'rewrite_status' => 'historical_deleted',
            ];
            $observed[$sourceuserid] = true;
            continue;
        }
        $mapping = $bundle['phase4']['source_by_key'][
            $sourceid . ':' . $sourceuserid
        ] ?? $globalmap[$sourceid . ':' . $sourceuserid] ?? null;
        if (!$mapping) {
            throw new RuntimeException(
                'No existe mapa canónico para ' . $sourceid . ':' .
                $sourceuserid . '.'
            );
        }
        $targetuserid = (int)$mapping['target_user_id'];
        $targetuser = $targetusersbyid[$targetuserid] ?? null;
        if (!$targetuser ||
                p5_norm((string)$targetuser['username']) !==
                    p5_norm((string)$mapping['target_username']) ||
                p5_norm((string)$targetuser['email']) !==
                    p5_norm((string)$mapping['target_email'])) {
            throw new RuntimeException(
                'El usuario destino ' . $targetuserid . ' cambió.'
            );
        }
        p5_dom_set($user, 'username', (string)$targetuser['username']);
        p5_dom_set($user, 'email', (string)$targetuser['email']);
        p5_dom_set($user, 'auth', (string)$targetuser['auth']);
        p5_dom_set(
            $user,
            'firstaccess',
            (string)(int)$targetuser['firstaccess']
        );
        p5_dom_set($user, 'mnethosturl', rtrim($targeturl, '/'));
        $observed[$sourceuserid] = true;
        if (in_array($sourceuserid, $plannedparticipants, true)) {
            $targetsources[$targetuserid][] = $sourceuserid;
        }
        $audit[] = [
            'source_user_id' => $sourceuserid,
            'source_username' => $sourceusername,
            'source_email' => $sourceemail,
            'target_user_id' => $targetuserid,
            'target_username' => (string)$targetuser['username'],
            'canonical_id' => (string)$mapping['canonical_id'],
            'rewrite_status' => $classification,
        ];
    }
    $actual = array_map('intval', array_keys($observed));
    sort($actual, SORT_NUMERIC);
    $missing = array_values(array_diff($expected, $actual));
    if ($missing) {
        throw new RuntimeException(
            'users.xml omite participantes del plan firmado: ' .
            implode('|', $missing) . '.'
        );
    }
    $merges = 0;
    foreach ($targetsources as $targetuserid => $sourceids) {
        $sourceids = array_values(array_unique(array_map('intval', $sourceids)));
        sort($sourceids, SORT_NUMERIC);
        if (count($sourceids) < 2) {
            continue;
        }
        $approved =
            $bundle['convergence_by_course_target'][$coursekey][$targetuserid]
                ['source_user_ids_parsed'] ?? [];
        sort($approved, SORT_NUMERIC);
        if ($sourceids !== $approved) {
            throw new RuntimeException(
                'La normalización detectó una convergencia no aprobada.'
            );
        }
        $merges++;
    }
    if ($dom->save($userspath) === false) {
        throw new RuntimeException('No fue posible guardar users.xml normalizado.');
    }

    $roleconversions = [];
    if (is_readable($rolespath)) {
        $rolesdom = new DOMDocument();
        $rolesdom->preserveWhiteSpace = false;
        $rolesdom->formatOutput = true;
        if (!$rolesdom->load($rolespath, LIBXML_NONET)) {
            throw new RuntimeException('roles.xml no es XML válido.');
        }
        $plannedrolemap = [];
        foreach (
            $bundle['role_rows_by_course'][$coursekey] ?? []
            as $plannedrole
        ) {
            $plannedrolemap[
                p5_norm((string)$plannedrole['source_role_shortname'])
            ] = p5_norm((string)$plannedrole['target_role_shortname']);
        }
        foreach ($rolesdom->getElementsByTagName('role') as $role) {
            if (!$role instanceof DOMElement) {
                continue;
            }
            $original = p5_norm(p5_dom_text($role, 'shortname'));
            if ($original === '') {
                continue;
            }
            $target = $plannedrolemap[$original] ?? '';
            if ($target === '') {
                [$normalized, $target] = p6_role_policy(
                    $sourceid,
                    $original,
                    $bundle['batch_config'],
                    []
                );
            } else {
                $normalized = p6_normalized_role_name($target);
            }
            p5_dom_set($role, 'shortname', $target);
            p5_dom_set($role, 'name', ucfirst($normalized));
            p5_dom_set(
                $role,
                'archetype',
                $target === 'personalizado' ? '' : $target
            );
            foreach ($role->getElementsByTagName('role_capabilities') as $caps) {
                while ($caps->firstChild !== null) {
                    $caps->removeChild($caps->firstChild);
                }
            }
            $roleconversions[$original . '|' . $target] = [
                'source_role_shortname' => $original,
                'target_role_shortname' => $target,
            ];
        }
        if ($rolesdom->save($rolespath) === false) {
            throw new RuntimeException('No fue posible guardar roles.xml normalizado.');
        }
    }

    $usersafter = hash_file('sha256', $userspath);
    $rolesafter = is_readable($rolespath)
        ? hash_file('sha256', $rolespath)
        : null;
    p5_write_json($auditpath, [
        'schema_version' => '1.0',
        'phase' => '6-course-single-extraction-normalization-audit',
        'generated_at_utc' => gmdate('c'),
        'course_key' => $coursekey,
        'source' => $sourceid,
        'source_backup_sha256' => $sourcebackupsha256,
        'source_backup_bytes' => $sourcebackupbytes,
        'single_extraction' => true,
        'normalized_archive_created' => false,
        'users_xml_sha256_before' => $usersbefore,
        'users_xml_sha256_after' => $usersafter,
        'roles_xml_sha256_before' => $rolesbefore,
        'roles_xml_sha256_after' => $rolesafter,
        'questions_xml_sha256_before' =>
            $questionnormalization['questions_xml_sha256_before'],
        'questions_xml_sha256_after' =>
            $questionnormalization['questions_xml_sha256_after'],
        'legacy_random_detected' =>
            $questionnormalization['legacy_random_detected'],
        'legacy_random_removed' =>
            $questionnormalization['legacy_random_removed'],
        'legacy_random_entries_removed' =>
            $questionnormalization['legacy_random_entries_removed'],
        'legacy_random_question_ids' =>
            $questionnormalization['legacy_random_question_ids'],
        'legacy_random_entry_ids' =>
            $questionnormalization['legacy_random_entry_ids'],
        'legacy_random_category_ids' =>
            $questionnormalization['legacy_random_category_ids'],
        'legacy_random_physical_rows' =>
            $questionnormalization['legacy_random_physical_rows'],
        'legacy_random_logical_stamps' =>
            $questionnormalization['legacy_random_logical_stamps'],
        'legacy_random_stamps' =>
            $questionnormalization['legacy_random_stamps'],
        'legacy_random_set_references_verified' =>
            $questionnormalization['legacy_random_set_references_verified'],
        'legacy_random_set_reference_cardinality_by_quiz' =>
            $questionnormalization['legacy_random_set_reference_cardinality_by_quiz'],
        'legacy_random_orphan_physical_rows' =>
            $questionnormalization['legacy_random_orphan_physical_rows'],
        'legacy_random_orphan_entries' =>
            $questionnormalization['legacy_random_orphan_entries'],
        'legacy_random_orphan_semantics' =>
            $questionnormalization['legacy_random_orphan_semantics'],
        'legacy_random_filter_formats' =>
            $questionnormalization['legacy_random_filter_formats'],
        'legacy_random_normalization_status' =>
            $questionnormalization['legacy_random_normalization_status'],
        'effective_source_inventory_sha256' => $effectiveinventorysha,
        'module_identity_normalization' => $effectiveaudit['module_identity'],
        'activity_key_resolution' => $effectiveaudit['activity_aliases'],
        'files_transport_projection' => $effectiveaudit['files_transport'],
        'degradation_application' => $degradationapplication,
        'source_users' => count($actual),
        'target_users' => count($targetsources),
        'approved_identity_convergences' => $merges,
        'role_conversions' => array_values($roleconversions),
        'users' => $audit,
    ]);
    $auditsha = hash_file('sha256', $auditpath);
    if ($auditsha === false) {
        throw new RuntimeException('No fue posible sellar la auditoría de normalización.');
    }
    return [
        'source_users' => count($actual),
        'target_users' => count($targetsources),
        'approved_identity_convergences' => $merges,
        'role_types_normalized' => count($roleconversions),
        'users_xml_sha256_before' => $usersbefore,
        'users_xml_sha256_after' => $usersafter,
        'roles_xml_sha256_before' => $rolesbefore,
        'roles_xml_sha256_after' => $rolesafter,
        'questions_xml_sha256_before' =>
            $questionnormalization['questions_xml_sha256_before'],
        'questions_xml_sha256_after' =>
            $questionnormalization['questions_xml_sha256_after'],
        'legacy_random_detected' =>
            $questionnormalization['legacy_random_detected'],
        'legacy_random_removed' =>
            $questionnormalization['legacy_random_removed'],
        'legacy_random_entries_removed' =>
            $questionnormalization['legacy_random_entries_removed'],
        'legacy_random_question_ids' =>
            $questionnormalization['legacy_random_question_ids'],
        'legacy_random_entry_ids' =>
            $questionnormalization['legacy_random_entry_ids'],
        'legacy_random_category_ids' =>
            $questionnormalization['legacy_random_category_ids'],
        'legacy_random_physical_rows' =>
            $questionnormalization['legacy_random_physical_rows'],
        'legacy_random_logical_stamps' =>
            $questionnormalization['legacy_random_logical_stamps'],
        'legacy_random_stamps' =>
            $questionnormalization['legacy_random_stamps'],
        'legacy_random_set_references_verified' =>
            $questionnormalization['legacy_random_set_references_verified'],
        'legacy_random_set_reference_cardinality_by_quiz' =>
            $questionnormalization['legacy_random_set_reference_cardinality_by_quiz'],
        'legacy_random_orphan_physical_rows' =>
            $questionnormalization['legacy_random_orphan_physical_rows'],
        'legacy_random_orphan_entries' =>
            $questionnormalization['legacy_random_orphan_entries'],
        'legacy_random_orphan_semantics' =>
            $questionnormalization['legacy_random_orphan_semantics'],
        'legacy_random_filter_formats' =>
            $questionnormalization['legacy_random_filter_formats'],
        'legacy_random_normalization_status' =>
            $questionnormalization['legacy_random_normalization_status'],
        'effective_source_inventory_sha256' => $effectiveinventorysha,
        'module_identity_normalization' => $effectiveaudit['module_identity'],
        'activity_key_resolution' => $effectiveaudit['activity_aliases'],
        'files_transport_projection' => $effectiveaudit['files_transport'],
        'degradation_application' => $degradationapplication,
        'normalization_audit_sha256' => $auditsha,
        'single_extraction' => true,
        'normalized_archive_created' => false,
    ];
}

/**
 * Extrae el inventario académico del documento liviano sellado por la fase 6.
 */
function p6_source_course_inventory_payload(
    array $document,
    string $expectedsourcestatehash
): array {
    $allowedphases = [
        '6-source-course-reference-inventory',
        '6-source-course-backup-inventory',
        '6-effective-source-course-inventory',
    ];
    if (($document['schema_version'] ?? '') !== '1.0' ||
            !in_array((string)($document['phase'] ?? ''), $allowedphases, true) ||
            ($document['write_performed'] ?? null) !== false) {
        throw new RuntimeException(
            'El documento de inventario académico no conserva el contrato de fase 6.'
        );
    }
    $inventory = $document['inventory'] ?? null;
    if (!is_array($inventory)) {
        throw new RuntimeException(
            'El documento de inventario académico no contiene inventory.'
        );
    }
    foreach ([
        'counts',
        'modules_by_type',
        'modules',
        'enrolments',
        'roles',
        'relations',
    ] as $field) {
        if (!isset($inventory[$field]) || !is_array($inventory[$field])) {
            throw new RuntimeException(
                'El inventario académico no contiene una sección válida: ' .
                $field . '.'
            );
        }
    }
    foreach (['enrolments', 'course_role_assignments'] as $countfield) {
        if (!array_key_exists($countfield, $inventory['counts'])) {
            throw new RuntimeException(
                'El inventario académico no contiene el conteo ' .
                $countfield . '.'
            );
        }
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $expectedsourcestatehash) ||
            !hash_equals(
                $expectedsourcestatehash,
                (string)($document['source_state_sha256'] ?? '')
            )) {
        throw new RuntimeException(
            'El inventario académico no corresponde al estado de origen firmado.'
        );
    }
    return $inventory;
}

/**
 * Compara el inventario académico después de sustituir los conteos que deben
 * reducirse por convergencias de identidad.
 */
function p6_compare_applied_course(
    array $sourceinventorydocument,
    array $targetinventory,
    int $effectiveenrolments,
    int $effectiveroles,
    string $expectedsourcestatehash,
    array $globalmap,
    string $sourceid,
    array $historicalmap = [],
    int $sourcecourseid = 0,
    int $targetcourseid = 0
): array {
    $expected = p6_source_course_inventory_payload(
        $sourceinventorydocument,
        $expectedsourcestatehash
    );
    $expected['counts']['enrolments'] = $effectiveenrolments;
    $expected['counts']['course_role_assignments'] = $effectiveroles;
    $result = p5_compare_course_inventories($expected, $targetinventory);
    $quiz = p6_quiz_attempt_differences(
        $expected['relations']['quiz_attempts'] ?? [],
        $targetinventory['relations']['quiz_attempts'] ?? [],
        $globalmap + $historicalmap,
        $sourceid,
        $sourcecourseid,
        $targetcourseid
    );
    $result['quiz_attempt_diagnostic'] = $quiz;
    if ($quiz['has_differences']) {
        $result['issues'][] = ['field' => 'relations.quiz_attempts', 'diagnostic' => $quiz];
        $result['complete'] = false;
    }
    return $result;
}

/** Conserva la entrada histórica de RC2 para consumidores existentes. */
function p6_quiz_attempt_differences(
    array $source,
    array $target,
    array $globalmap,
    string $sourceid,
    int $sourcecourseid = 0,
    int $targetcourseid = 0
): array {
    return p5_quiz_attempt_differences(
        $source, $target, $globalmap, $sourceid, $sourcecourseid, $targetcourseid
    );
}
