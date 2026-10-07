<?php
// Fase 5: restauración controlada con precheck explícito y rollback del contenedor.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once('/opt/consolidator/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'phase5' => '/exports/phase5',
        'configsha' => null,
        'targetid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase5-restore.php --phase4=/exports/phase4 --phase5=/exports/phase5 " .
        "--configsha=SHA256 --targetid=target [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

/**
 * Convierte resultados de Moodle a un valor JSON acotado y sin ciclos.
 */
function p5_restore_json_safe(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) {
        return '[profundidad omitida]';
    }
    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string)$key] = p5_restore_json_safe($item, $depth + 1);
        }
        return $result;
    }
    if (is_object($value)) {
        return p5_restore_json_safe(get_object_vars($value), $depth + 1);
    }
    if (is_resource($value)) {
        return '[recurso]';
    }
    return $value;
}

/**
 * Resume los resultados del precheck para que la consola conserve la causa real.
 */
function p5_restore_precheck_message(mixed $results): string {
    $encoded = json_encode(
        p5_restore_json_safe($results),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($encoded) || $encoded === '') {
        return 'El precheck devolvió un resultado no serializable.';
    }
    if (strlen($encoded) > 1800) {
        return substr($encoded, 0, 1800) . '...';
    }
    return $encoded;
}

/**
 * Cuenta los mensajes de una sección del precheck sin asumir su forma exacta.
 */
function p5_restore_precheck_message_count(mixed $messages): int {
    if ($messages === null || $messages === [] || $messages === '') {
        return 0;
    }
    if (is_array($messages)) {
        return count($messages);
    }
    if (is_object($messages)) {
        return count(get_object_vars($messages));
    }
    return 1;
}

/**
 * Devuelve una ruta útil para diagnóstico sin depender de rutas absolutas
 * específicas del contenedor.
 */
function p5_restore_diagnostic_path(string $path): string {
    global $CFG;

    foreach ([
        (string)$CFG->dirroot => '[moodle]',
        (string)$CFG->dataroot => '[moodledata]',
        (string)$CFG->tempdir => '[tempdir]',
    ] as $prefix => $replacement) {
        $prefix = rtrim($prefix, '/\\');
        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            return $replacement . str_replace('\\', '/', substr($path, strlen($prefix)));
        }
    }
    return str_replace('\\', '/', $path);
}

/**
 * Conserva la información técnica de una excepción de Moodle. En particular,
 * dml_write_exception guarda en debuginfo la consulta y el mensaje original
 * del motor de base de datos que no aparecen en getMessage().
 */
function p5_restore_exception_details(?Throwable $error): ?array {
    if ($error === null) {
        return null;
    }
    $chain = [];
    $current = $error;
    for ($level = 0; $current !== null && $level < 8; $level++) {
        $item = [
            'level' => $level,
            'class' => get_class($current),
            'message' => $current->getMessage(),
            'code' => $current->getCode(),
            'file' => p5_restore_diagnostic_path($current->getFile()),
            'line' => $current->getLine(),
        ];
        foreach (['errorcode', 'module', 'debuginfo', 'a'] as $property) {
            if (property_exists($current, $property)) {
                try {
                    $item[$property] = p5_restore_json_safe($current->{$property});
                } catch (Throwable $ignored) {
                    $item[$property] = '[propiedad no legible]';
                }
            }
        }
        $trace = [];
        foreach (array_slice($current->getTrace(), 0, 60) as $index => $frame) {
            $trace[] = [
                'index' => $index,
                'file' => isset($frame['file'])
                    ? p5_restore_diagnostic_path((string)$frame['file'])
                    : null,
                'line' => isset($frame['line']) ? (int)$frame['line'] : null,
                'class' => isset($frame['class']) ? (string)$frame['class'] : null,
                'type' => isset($frame['type']) ? (string)$frame['type'] : null,
                'function' => isset($frame['function']) ? (string)$frame['function'] : null,
            ];
        }
        $item['trace'] = $trace;
        $chain[] = $item;
        $current = $current->getPrevious();
    }
    return [
        'chain' => $chain,
        'chain_truncated' => $current !== null,
    ];
}

/**
 * Resume el detalle técnico para la consola; el contenido íntegro queda en
 * restore_diagnostic.json.
 */
function p5_restore_exception_console_summary(?Throwable $error): string {
    if ($error === null) {
        return 'errorcode=unknown';
    }
    $parts = [];
    if (property_exists($error, 'errorcode')) {
        try {
            $errorcode = trim((string)$error->errorcode);
            if ($errorcode !== '') {
                $parts[] = 'errorcode=' . preg_replace('/\s+/', '_', $errorcode);
            }
        } catch (Throwable $ignored) {
            // El nombre de clase seguirá permitiendo identificar la familia.
        }
    }
    $parts[] = 'class=' . str_replace('\\', '.', get_class($error));
    if (property_exists($error, 'debuginfo')) {
        try {
            $debuginfo = preg_replace('/\s+/', ' ', trim((string)$error->debuginfo));
            if ($debuginfo !== '') {
                if (strlen($debuginfo) > 1200) {
                    $debuginfo = substr($debuginfo, 0, 1200) . '...';
                }
                $parts[] = 'debuginfo=' . $debuginfo;
            }
        } catch (Throwable $ignored) {
            // El JSON conservará el resto de la excepción si esta propiedad falla.
        }
    }
    return implode(' ', $parts);
}

$phase4dir = rtrim((string)$options['phase4'], '/\\');
$phase5dir = rtrim((string)$options['phase5'], '/\\');
$configsha = '';
$targetid = '';
$expectlab = false;
$bundle = null;
$state = null;
$controller = null;
$courseid = 0;
$restorepath = '';
$precheckresults = [];
$precheckoutcome = 'not_run';
$precheckwarningcount = 0;
$precheckerrorcount = 0;
$primaryerror = null;
$destroyerror = null;
$cleanupcourse = 'not_needed';
$cleanuptemp = 'not_needed';
$restorestage = 'initializing';
$controllerstatusatfailure = null;
$olduser = $USER;

try {
    $restorestage = 'loading_signed_plan';
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $expectlab = (bool)(int)$options['expectlab'];
    $bundle = p5_load_plan(
        $phase4dir,
        $phase5dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $statepath = $phase5dir . '/apply_preflight.json';
    $state = p5_read_json($statepath);
    if (($state['plan_summary_sha256'] ?? '') !==
            hash_file('sha256', $phase5dir . '/plan_summary.json') ||
            ($state['normalized_backup_sha256'] ?? '') !==
            $bundle['hashes']['normalized_backup.mbz'] ||
            ($state['destination_write_performed'] ?? null) !== false) {
        throw new RuntimeException('apply_preflight.json no corresponde al plan confirmado.');
    }
    $mode = (string)($state['mode'] ?? '');
    if (!in_array($mode, ['restore_new', 'recover_failed_restore'], true)) {
        throw new RuntimeException('El modo ' . $mode . ' no requiere una nueva restauración.');
    }

    $admin = get_admin();
    if (!$admin) {
        throw new RuntimeException('No existe una cuenta administradora en el destino.');
    }
    \core\session\manager::set_user($admin);
    if (array_filter(
        $bundle['role_rows'],
        static fn(array $row): bool =>
            (string)$row['target_role_shortname'] === 'personalizado'
    )) {
        p5_ensure_personalizado_role();
    }
    $beforeids = array_values(array_map('intval', $state['before_course_ids'] ?? []));

    if ($mode === 'recover_failed_restore') {
        $restorestage = 'recovering_previous_container';
        $recoveryid = (int)($state['target_course_id'] ?? 0);
        $newids = array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            array_filter(
                p5_target_courses(),
                static fn(array $row): bool => !in_array((int)$row['id'], $beforeids, true)
            )
        ));
        if ($recoveryid < 1 || $newids !== [$recoveryid]) {
            throw new RuntimeException(
                'El curso residual ya no coincide de forma unívoca con el intento fallido.'
            );
        }
        $recoverycourse = $DB->get_record(
            'course',
            ['id' => $recoveryid],
            '*',
            MUST_EXIST
        );
        if ((int)$recoverycourse->category !==
                (int)$bundle['summary']['target_category_id'] ||
                (string)$recoverycourse->idnumber ===
                (string)$bundle['summary']['target_course_marker']) {
            throw new RuntimeException(
                'El curso residual no cumple las condiciones de recuperación segura.'
            );
        }
        if (!delete_course($recoverycourse, false)) {
            throw new RuntimeException(
                'Moodle no pudo eliminar el curso contenedor del intento fallido.'
            );
        }
        $cleanupcourse = 'recovered_previous_course_' . $recoveryid;
        cli_writeln('FASE5_RECOVERY_OK removed_course_id=' . $recoveryid);
        $state['mode'] = 'restore_new';
        $state['target_course_id'] = null;
        $state['before_course_ids'] = array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            p5_target_courses()
        ));
        $state['destination_write_performed'] = false;
        p5_write_json($statepath, $state);
    }

    $restorestage = 'extracting_normalized_backup';
    $backupfile = $bundle['paths']['normalized_backup.mbz'];
    $backupdir = 'phase5_' . bin2hex(random_bytes(12));
    $restorepath = $CFG->tempdir . DIRECTORY_SEPARATOR .
        'backup' . DIRECTORY_SEPARATOR . $backupdir;
    $packer = get_file_packer('application/vnd.moodle.backup');
    if (!$packer->extract_to_pathname($backupfile, $restorepath)) {
        throw new RuntimeException('Moodle no pudo extraer el backup normalizado.');
    }

    [$fullname, $shortname] = restore_dbops::calculate_course_names(
        0,
        get_string('restoringcourse', 'backup'),
        get_string('restoringcourseshortname', 'backup')
    );
    $courseid = (int)restore_dbops::create_new_course(
        $fullname,
        $shortname,
        (int)$bundle['summary']['target_category_id']
    );
    if ($courseid < 1) {
        throw new RuntimeException('Moodle no devolvió el ID del curso contenedor.');
    }

    $restorestage = 'course_container_created';
    $state['mode'] = 'restore_in_progress';
    $state['target_course_id'] = $courseid;
    $state['destination_write_performed'] = true;
    $state['restore_started_at_utc'] = gmdate('c');
    p5_write_json($statepath, $state);

    $controller = new restore_controller(
        $backupdir,
        $courseid,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        (int)$admin->id,
        backup::TARGET_NEW_COURSE
    );
    $restorestage = 'running_precheck';
    $precheckok = $controller->execute_precheck();
    $precheckresults = $controller->get_precheck_results();
    $precheckhaserrors = is_array($precheckresults) &&
        array_key_exists('errors', $precheckresults);
    $precheckwarningcount = is_array($precheckresults)
        ? p5_restore_precheck_message_count($precheckresults['warnings'] ?? [])
        : 0;
    $precheckerrorcount = is_array($precheckresults)
        ? p5_restore_precheck_message_count($precheckresults['errors'] ?? [])
        : 0;
    $precheckawaiting = $controller->get_status() === backup::STATUS_AWAITING;

    if (!$precheckok && ($precheckhaserrors || !$precheckawaiting)) {
        $precheckoutcome = 'rejected';
        p5_write_json($phase5dir . '/restore_precheck.json', [
            'schema_version' => '1.0',
            'phase' => '5-restore-precheck',
            'generated_at_utc' => gmdate('c'),
            'config_sha256' => $configsha,
            'target_id' => $targetid,
            'course_id_created' => $courseid,
            'outcome' => $precheckoutcome,
            'controller_status' => $controller->get_status(),
            'warning_count' => $precheckwarningcount,
            'error_count' => $precheckerrorcount,
            'results' => p5_restore_json_safe($precheckresults),
        ]);
        throw new RuntimeException(
            'El precheck de Moodle rechazó la restauración: ' .
            p5_restore_precheck_message($precheckresults)
        );
    }

    $precheckoutcome = $precheckok ? 'passed' : 'warnings_accepted';
    p5_write_json($phase5dir . '/restore_precheck.json', [
        'schema_version' => '1.0',
        'phase' => '5-restore-precheck',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'course_id_created' => $courseid,
        'outcome' => $precheckoutcome,
        'controller_status' => $controller->get_status(),
        'warning_count' => $precheckwarningcount,
        'error_count' => 0,
        'results' => p5_restore_json_safe($precheckresults),
    ]);
    if ($precheckwarningcount > 0) {
        cli_writeln(
            'FASE5_RESTORE_PRECHECK_WARNINGS count=' . $precheckwarningcount .
            ' action=continue'
        );
    }
    $restorestage = 'executing_restore_plan';
    $controller->execute_plan();
    $restorestage = 'restore_plan_completed';
} catch (Throwable $error) {
    $primaryerror = $error;
    if ($controller !== null) {
        try {
            $controllerstatusatfailure = $controller->get_status();
        } catch (Throwable $ignored) {
            $controllerstatusatfailure = null;
        }
    }
}

if ($controller !== null) {
    try {
        $controller->destroy();
    } catch (Throwable $error) {
        $destroyerror = $error;
    }
}

if ($primaryerror !== null || $destroyerror !== null) {
    if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
        try {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $cleanupcourse = delete_course($course, false) ? 'ok' : 'failed';
        } catch (Throwable $error) {
            $cleanupcourse = 'failed: ' . $error->getMessage();
        }
    }
    if ($restorepath !== '' && is_dir($restorepath)) {
        $cleanuptemp = fulldelete($restorepath) ? 'ok' : 'failed';
    }
    if (is_array($state) && $bundle !== null) {
        $state['mode'] = 'restore_new';
        $state['target_course_id'] = null;
        $state['before_course_ids'] = array_values(array_map(
            static fn(array $row): int => (int)$row['id'],
            p5_target_courses()
        ));
        $state['destination_write_performed'] = false;
        $state['last_restore_failed_at_utc'] = gmdate('c');
        $state['last_restore_error'] = $primaryerror?->getMessage() ??
            $destroyerror?->getMessage() ?? 'Error desconocido.';
        p5_write_json($phase5dir . '/apply_preflight.json', $state);
    }
    $diagnostic = [
        'schema_version' => '1.0',
        'phase' => '5-restore-diagnostic',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'course_id_created' => $courseid ?: null,
        'primary_error_class' => $primaryerror ? get_class($primaryerror) : null,
        'primary_error' => $primaryerror?->getMessage(),
        'primary_exception' => p5_restore_exception_details($primaryerror),
        'controller_destroy_error' => $destroyerror?->getMessage(),
        'controller_destroy_exception' => p5_restore_exception_details($destroyerror),
        'restore_stage' => $restorestage,
        'controller_status_at_failure' => $controllerstatusatfailure,
        'precheck_outcome' => $precheckoutcome,
        'precheck_warning_count' => $precheckwarningcount,
        'precheck_error_count' => $precheckerrorcount,
        'precheck_results' => p5_restore_json_safe($precheckresults),
        'course_cleanup' => $cleanupcourse,
        'temp_cleanup' => $cleanuptemp,
        'safe_to_retry' =>
            !($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])),
    ];
    p5_write_json($phase5dir . '/restore_diagnostic.json', $diagnostic);
    \core\session\manager::set_user($olduser);
    $message = $primaryerror?->getMessage() ?? $destroyerror?->getMessage() ??
        'Error desconocido.';
    $technicalsummary = p5_restore_exception_console_summary(
        $primaryerror ?? $destroyerror
    );
    cli_error(
        'FASE5_RESTORE_ERROR ' . $message .
        ' ' . $technicalsummary .
        ' course_cleanup=' . $cleanupcourse .
        ' temp_cleanup=' . $cleanuptemp
    );
}

if ($restorepath !== '' && is_dir($restorepath)) {
    $cleanuptemp = fulldelete($restorepath) ? 'ok' : 'failed';
    if ($cleanuptemp !== 'ok') {
        throw new RuntimeException('No fue posible limpiar el directorio temporal del restore.');
    }
}
$state['mode'] = 'restore_completed';
$state['target_course_id'] = $courseid;
$state['destination_write_performed'] = true;
$state['restore_completed_at_utc'] = gmdate('c');
unset($state['last_restore_error'], $state['last_restore_failed_at_utc']);
p5_write_json($phase5dir . '/apply_preflight.json', $state);
\core\session\manager::set_user($olduser);
cli_writeln('FASE5_RESTORE_OK course_id=' . $courseid);
