<?php
// Funciones compartidas de la fase 5: contrato, inventario, backup y normalización.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

function p5_norm(string $value): string {
    return core_text::strtolower(trim($value));
}

function p5_read_json(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('No se puede leer ' . $path . '.');
    }
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException($path . ' no contiene un objeto JSON.');
    }
    return $data;
}

function p5_write_json(string $path, array $data): void {
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $directory = dirname($path);
    if (!is_dir($directory) &&
            !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear ' . $directory . '.');
    }
    $temporary = $path . '.partial-' . bin2hex(random_bytes(6));
    if ($json === false ||
            file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        throw new RuntimeException('No fue posible crear ' . $path . '.');
    }
}

function p5_read_csv(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('No se puede leer ' . $path . '.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('No fue posible abrir ' . $path . '.');
    }
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        fclose($handle);
        throw new RuntimeException($path . ' no contiene encabezados.');
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
    if (count($headers) !== count(array_unique($headers))) {
        fclose($handle);
        throw new RuntimeException($path . ' contiene columnas repetidas.');
    }
    $rows = [];
    $line = 1;
    while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($values === [null] || $values === []) {
            continue;
        }
        if (count($values) !== count($headers)) {
            fclose($handle);
            throw new RuntimeException($path . ', fila ' . $line . ': columnas inválidas.');
        }
        $row = array_combine($headers, $values);
        if ($row === false) {
            fclose($handle);
            throw new RuntimeException($path . ', fila ' . $line . ': no se pudo interpretar.');
        }
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function p5_write_csv(string $path, array $columns, array $rows): void {
    $directory = dirname($path);
    if (!is_dir($directory) &&
            !mkdir($directory, 0770, true) &&
            !is_dir($directory)) {
        throw new RuntimeException('No fue posible crear ' . $directory . '.');
    }
    $temporary = $path . '.partial-' . bin2hex(random_bytes(6));
    $handle = fopen($temporary, 'wb');
    if ($handle === false) {
        throw new RuntimeException('No fue posible crear ' . $path . '.');
    }
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $columns, ',', '"', '\\', "\r\n");
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } else if (is_array($value)) {
                $value = implode('|', array_map('strval', $value));
            }
            $values[] = $value;
        }
        fputcsv($handle, $values, ',', '"', '\\', "\r\n");
    }
    if (!fclose($handle) || !rename($temporary, $path)) {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
        throw new RuntimeException('No fue posible sellar ' . $path . '.');
    }
}

function p5_hash_files(array $paths): array {
    $hashes = [];
    foreach ($paths as $name => $path) {
        if (!is_readable($path)) {
            throw new RuntimeException('Falta el archivo requerido ' . $path . '.');
        }
        $hashes[$name] = hash_file('sha256', $path);
    }
    ksort($hashes, SORT_STRING);
    return $hashes;
}

function p5_require_sha256(string $value, string $label): string {
    $value = p5_norm($value);
    if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
        throw new RuntimeException($label . ' no contiene un SHA-256 válido.');
    }
    return $value;
}

/**
 * Valida que la fase 4 aplicada y verificada siga siendo exactamente la aprobada.
 */
function p5_load_phase4_contract(
    string $phase4dir,
    string $configsha,
    string $targetid,
    bool $expectlab
): array {
    $phase4dir = rtrim($phase4dir, '/\\');
    $paths = [
        'target_user_plan.csv' => $phase4dir . '/target_user_plan.csv',
        'plan_summary.json' => $phase4dir . '/plan_summary.json',
        'target_user_map.csv' => $phase4dir . '/target_user_map.csv',
        'source_to_target_user_map.csv' => $phase4dir . '/source_to_target_user_map.csv',
        'apply_summary.json' => $phase4dir . '/apply_summary.json',
        'verification.csv' => $phase4dir . '/verification.csv',
        'verification.json' => $phase4dir . '/verification.json',
    ];
    $hashes = p5_hash_files($paths);
    $plansummary = p5_read_json($paths['plan_summary.json']);
    $applysummary = p5_read_json($paths['apply_summary.json']);
    $verification = p5_read_json($paths['verification.json']);
    foreach ([$plansummary, $applysummary, $verification] as $summary) {
        if (($summary['config_sha256'] ?? '') !== $configsha ||
                ($summary['target_id'] ?? '') !== $targetid) {
            throw new RuntimeException('La fase 4 corresponde a otra configuración o destino.');
        }
    }
    if (($verification['validation'] ?? '') !== 'passed' ||
            (int)($verification['failed_checks'] ?? -1) !== 0 ||
            (int)($verification['oauth2_links_failed'] ?? -1) !== 0 ||
            (int)($verification['oauth2_links_verified'] ?? -1) !==
                (int)($verification['oauth2_links_expected'] ?? -2) ||
            (int)($verification['oauth2_issuer_id'] ?? 0) < 1 ||
            (int)($applysummary['oauth2_issuer_id'] ?? 0) !==
                (int)($verification['oauth2_issuer_id'] ?? -1)) {
        throw new RuntimeException(
            'La fase 4 no tiene identidades y accesos OAuth2 aprobados. ' .
            'Ejecute nuevamente su verificación.'
        );
    }
    if ($expectlab && ($verification['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('La validación LAB de la fase 4 no está aprobada.');
    }
    if (($applysummary['target_user_map_sha256'] ?? '') !==
            $hashes['target_user_map.csv'] ||
            ($applysummary['source_to_target_user_map_sha256'] ?? '') !==
            $hashes['source_to_target_user_map.csv']) {
        throw new RuntimeException('Los mapas de fase 4 cambiaron después de su aplicación.');
    }
    if (($verification['target_user_map_sha256'] ?? '') !==
            $hashes['target_user_map.csv'] ||
            ($verification['source_to_target_user_map_sha256'] ?? '') !==
            $hashes['source_to_target_user_map.csv'] ||
            ($verification['verification_csv_sha256'] ?? '') !==
            $hashes['verification.csv']) {
        throw new RuntimeException('Los resultados de fase 4 cambiaron después de verificarse.');
    }
    if (($applysummary['roles_applied'] ?? null) !== false ||
            ($applysummary['enrolments_applied'] ?? null) !== false ||
            ($verification['roles_applied'] ?? null) !== false ||
            ($verification['enrolments_applied'] ?? null) !== false) {
        throw new RuntimeException('La fase 4 declara permisos fuera de su alcance aprobado.');
    }

    $targetrows = p5_read_csv($paths['target_user_map.csv']);
    $sourcerows = p5_read_csv($paths['source_to_target_user_map.csv']);
    $planrows = p5_read_csv($paths['target_user_plan.csv']);
    $targetbycanonical = [];
    foreach ($targetrows as $row) {
        $canonicalid = trim((string)($row['canonical_id'] ?? ''));
        $targetuserid = (int)($row['target_user_id'] ?? 0);
        if ($canonicalid === '' || $targetuserid < 1 || isset($targetbycanonical[$canonicalid])) {
            throw new RuntimeException('target_user_map.csv contiene una fila inválida o repetida.');
        }
        $targetbycanonical[$canonicalid] = $row;
    }
    $planbycanonical = [];
    foreach ($planrows as $row) {
        $canonicalid = trim((string)($row['canonical_id'] ?? ''));
        if ($canonicalid === '' || isset($planbycanonical[$canonicalid])) {
            throw new RuntimeException('target_user_plan.csv contiene una identidad repetida.');
        }
        $planbycanonical[$canonicalid] = $row;
    }
    $sourcebykey = [];
    foreach ($sourcerows as $row) {
        $source = trim((string)($row['source'] ?? ''));
        $sourceuserid = (int)($row['source_user_id'] ?? 0);
        $canonicalid = trim((string)($row['canonical_id'] ?? ''));
        $key = $source . ':' . $sourceuserid;
        if ($source === '' || $sourceuserid < 1 || isset($sourcebykey[$key])) {
            throw new RuntimeException('source_to_target_user_map.csv contiene una cuenta inválida.');
        }
        $target = $targetbycanonical[$canonicalid] ?? null;
        $plan = $planbycanonical[$canonicalid] ?? null;
        $mappedid = (int)($row['target_user_id'] ?? 0);
        if (($row['mapping_status'] ?? '') !== 'mapped' ||
                !$target || !$plan || $mappedid < 1 ||
                $mappedid !== (int)$target['target_user_id']) {
            throw new RuntimeException(
                'La cuenta ' . $key . ' no posee un usuario destino verificable.'
            );
        }
        $row['target_username'] = (string)$target['target_username'];
        $row['target_email'] = (string)$target['target_email'];
        $row['target_auth'] = (string)($plan['desired_auth'] ?? '');
        $sourcebykey[$key] = $row;
    }
    return [
        'paths' => $paths,
        'hashes' => $hashes,
        'plan_summary' => $plansummary,
        'apply_summary' => $applysummary,
        'verification' => $verification,
        'source_rows' => $sourcerows,
        'source_by_key' => $sourcebykey,
        'target_by_canonical' => $targetbycanonical,
        'plan_by_canonical' => $planbycanonical,
    ];
}

function p5_course_marker(string $sourceid, string $courseidnumber): string {
    $sourceid = preg_replace('/[^a-z0-9_-]+/i', '-', $sourceid);
    $courseidnumber = preg_replace('/[^a-z0-9_.:-]+/i', '-', $courseidnumber);
    $readable = 'MIG-P5-' . strtoupper((string)$sourceid) . '-' . strtoupper((string)$courseidnumber);
    if (core_text::strlen($readable) <= 100) {
        return $readable;
    }
    return 'MIG-P5-' . strtoupper((string)$sourceid) . '-' .
        strtoupper(substr(hash('sha256', $sourceid . '|' . $courseidnumber), 0, 20));
}

function p5_module_key(string $modname, string $idnumber, string $name): string {
    $identity = trim($idnumber) !== '' ? trim($idnumber) : trim($name);
    return p5_norm($modname) . '|' . p5_norm($identity);
}

function p5_sorted_rows(array $rows, callable $keybuilder): array {
    usort($rows, static function(array $left, array $right) use ($keybuilder): int {
        return strcmp((string)$keybuilder($left), (string)$keybuilder($right));
    });
    return $rows;
}

/**
 * Inventario semántico que puede compararse aunque cambien los IDs internos.
 */
function p5_collect_course_inventory(int $courseid): array {
    global $DB;

    $course = $DB->get_record(
        'course',
        ['id' => $courseid],
        'id,category,fullname,shortname,idnumber,startdate,enddate,format,enablecompletion',
        MUST_EXIST
    );
    $context = context_course::instance($courseid);
    $modules = [];
    $modulebyid = [];
    $modulerecords = $DB->get_records_sql(
        'SELECT cm.id, cm.instance, cm.idnumber, cm.section, cm.completion, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = :courseid AND cm.deletioninprogress = 0
       ORDER BY m.name, cm.id',
        ['courseid' => $courseid]
    );
    $instancesbytype = [];
    foreach ($modulerecords as $record) {
        $instancesbytype[(string)$record->modname][] = (int)$record->instance;
    }
    $namesbytype = [];
    foreach ($instancesbytype as $modname => $instanceids) {
        if (!$DB->get_manager()->table_exists($modname)) {
            continue;
        }
        $instanceids = array_values(array_unique(array_map('intval', $instanceids)));
        foreach ($DB->get_records_list(
            $modname,
            'id',
            $instanceids,
            '',
            'id,name'
        ) as $instance) {
            $namesbytype[$modname][(int)$instance->id] = (string)$instance->name;
        }
    }
    foreach ($modulerecords as $record) {
        $name = (string)($namesbytype[(string)$record->modname][
            (int)$record->instance
        ] ?? '');
        $key = p5_module_key((string)$record->modname, (string)$record->idnumber, $name);
        $row = [
            'source_module_id' => (int)$record->id,
            'modname' => (string)$record->modname,
            'instance' => (int)$record->instance,
            'idnumber' => (string)$record->idnumber,
            'name' => $name,
            'module_key' => $key,
            'completion_mode' => (int)$record->completion,
        ];
        $modules[] = $row;
        $modulebyid[(int)$record->id] = $row;
    }
    $modules = p5_sorted_rows($modules, static fn(array $row): string => $row['module_key']);

    $enrolments = [];
    $enrolrecords = $DB->get_records_sql(
        'SELECT ue.id, ue.userid, ue.status, e.enrol, e.id AS enrolid,
                u.username, u.email
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {user} u ON u.id = ue.userid
          WHERE e.courseid = :courseid AND u.deleted = 0
       ORDER BY ue.userid, e.id',
        ['courseid' => $courseid]
    );
    foreach ($enrolrecords as $record) {
        $enrolments[] = [
            'source_user_id' => (int)$record->userid,
            'source_username' => (string)$record->username,
            'source_email' => (string)$record->email,
            'enrol_method' => (string)$record->enrol,
            'enrol_status' => (int)$record->status,
        ];
    }
    $enrolments = p5_sorted_rows(
        $enrolments,
        static fn(array $row): string => sprintf('%012d|%s', $row['source_user_id'], $row['enrol_method'])
    );

    $roles = [];
    $rolerecords = $DB->get_records_sql(
        'SELECT ra.id, ra.userid, r.shortname, r.archetype, ra.component, ra.itemid
           FROM {role_assignments} ra
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.contextid = :contextid
       ORDER BY ra.userid, r.shortname',
        ['contextid' => (int)$context->id]
    );
    foreach ($rolerecords as $record) {
        $roles[] = [
            'source_user_id' => (int)$record->userid,
            'role_shortname' => (string)$record->shortname,
            'role_archetype' => (string)$record->archetype,
            'component' => (string)$record->component,
            'itemid' => (int)$record->itemid,
        ];
    }
    $roles = p5_sorted_rows(
        $roles,
        static fn(array $row): string => sprintf(
            '%012d|%s|%s|%012d',
            $row['source_user_id'],
            $row['role_shortname'],
            $row['component'],
            $row['itemid']
        )
    );

    $submissions = [];
    if ($DB->get_manager()->table_exists('assign_submission')) {
        $records = $DB->get_records_sql(
            "SELECT s.id, s.userid, s.status, s.latest, a.name
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment
              WHERE a.course = :courseid AND s.latest = 1 AND s.status <> :newstatus
           ORDER BY a.name, s.userid",
            ['courseid' => $courseid, 'newstatus' => 'new']
        );
        foreach ($records as $record) {
            $submissions[] = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => p5_module_key('assign', '', (string)$record->name),
                'status' => (string)$record->status,
            ];
        }
    }

    $assignmentgrades = [];
    if ($DB->get_manager()->table_exists('assign_grades')) {
        $records = $DB->get_records_sql(
            'SELECT g.id, g.userid, g.grade, a.name
               FROM {assign_grades} g
               JOIN {assign} a ON a.id = g.assignment
              WHERE a.course = :courseid AND g.grade >= 0
           ORDER BY a.name, g.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $assignmentgrades[] = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => p5_module_key('assign', '', (string)$record->name),
                'grade' => round((float)$record->grade, 5),
            ];
        }
    }

    $forumdiscussions = [];
    $forumposts = [];
    if ($DB->get_manager()->table_exists('forum_discussions')) {
        $records = $DB->get_records_sql(
            'SELECT d.id, d.userid, d.name, f.name AS forumname
               FROM {forum_discussions} d
               JOIN {forum} f ON f.id = d.forum
              WHERE f.course = :courseid
           ORDER BY f.name, d.name, d.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $forumdiscussions[] = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => p5_module_key('forum', '', (string)$record->forumname),
                'subject' => (string)$record->name,
            ];
        }
        $records = $DB->get_records_sql(
            'SELECT p.id, p.userid, p.subject, f.name AS forumname
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
               JOIN {forum} f ON f.id = d.forum
              WHERE f.course = :courseid
           ORDER BY f.name, p.id',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $forumposts[] = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => p5_module_key('forum', '', (string)$record->forumname),
                'subject' => (string)$record->subject,
            ];
        }
    }

    $quizattempts = [];
    if ($DB->get_manager()->table_exists('quiz_attempts')) {
        $records = $DB->get_records_sql(
            'SELECT qa.id, qa.userid, qa.state, qa.sumgrades, q.name
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid
           ORDER BY q.name, qa.userid, qa.attempt',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $quizattempts[] = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => p5_module_key('quiz', '', (string)$record->name),
                'state' => (string)$record->state,
                'sumgrades' => $record->sumgrades === null ? null : round((float)$record->sumgrades, 5),
            ];
        }
    }

    $activitycompletions = [];
    if ($DB->get_manager()->table_exists('course_modules_completion')) {
        $records = $DB->get_records_sql(
            'SELECT cmc.id, cmc.userid, cmc.completionstate, cmc.overrideby, cm.id AS cmid
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.completionstate > 0
           ORDER BY cm.id, cmc.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            if (!isset($modulebyid[(int)$record->cmid])) {
                continue;
            }
            $activitycompletions[] = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => $modulebyid[(int)$record->cmid]['module_key'],
                'completion_state' => (int)$record->completionstate,
            ];
        }
    }

    $coursecompletions = [];
    if ($DB->get_manager()->table_exists('course_completions')) {
        $records = $DB->get_records(
            'course_completions',
            ['course' => $courseid],
            'userid ASC',
            'id,userid,timecompleted'
        );
        foreach ($records as $record) {
            $coursecompletions[] = [
                'source_user_id' => (int)$record->userid,
                'completed' => $record->timecompleted !== null && (int)$record->timecompleted > 0,
            ];
        }
    }

    $files = [];
    $filerecords = $DB->get_records_sql(
        'SELECT f.id, f.userid, f.component, f.filearea, f.filename,
                f.contenthash, f.filesize, cm.id AS cmid
           FROM {files} f
           JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :modulelevel
           JOIN {course_modules} cm ON cm.id = ctx.instanceid
          WHERE cm.course = :courseid AND f.filename <> :dot
       ORDER BY cm.id, f.component, f.filearea, f.filename, f.id',
        ['modulelevel' => CONTEXT_MODULE, 'courseid' => $courseid, 'dot' => '.']
    );
    foreach ($filerecords as $record) {
        if (!isset($modulebyid[(int)$record->cmid])) {
            continue;
        }
        $files[] = [
            'source_user_id' => (int)$record->userid,
            'activity_key' => $modulebyid[(int)$record->cmid]['module_key'],
            'component' => (string)$record->component,
            'filearea' => (string)$record->filearea,
            'filename' => (string)$record->filename,
            'content_sha1' => (string)$record->contenthash,
            'bytes' => (int)$record->filesize,
        ];
    }

    $modulesbytype = [];
    foreach ($modules as $module) {
        $modname = (string)$module['modname'];
        $modulesbytype[$modname] = ($modulesbytype[$modname] ?? 0) + 1;
    }
    ksort($modulesbytype, SORT_STRING);
    $counts = [
        'sections' => $DB->count_records('course_sections', ['course' => $courseid]),
        'activities' => count($modules),
        'enrolments' => count($enrolments),
        'course_role_assignments' => count($roles),
        'assignment_submissions' => count($submissions),
        'assignment_grades' => count($assignmentgrades),
        'forum_discussions' => count($forumdiscussions),
        'forum_posts' => count($forumposts),
        'quiz_attempts' => count($quizattempts),
        'activity_completions' => count($activitycompletions),
        'course_completions' => count($coursecompletions),
        'module_files' => count($files),
    ];
    return [
        'course' => [
            'source_course_id' => (int)$course->id,
            'category_id' => (int)$course->category,
            'fullname' => (string)$course->fullname,
            'shortname' => (string)$course->shortname,
            'idnumber' => (string)$course->idnumber,
            'startdate' => (int)$course->startdate,
            'enddate' => (int)$course->enddate,
            'format' => (string)$course->format,
            'enablecompletion' => (int)$course->enablecompletion,
        ],
        'counts' => $counts,
        'modules_by_type' => $modulesbytype,
        'modules' => $modules,
        'enrolments' => $enrolments,
        'roles' => $roles,
        'relations' => [
            'assignment_submissions' => $submissions,
            'assignment_grades' => $assignmentgrades,
            'forum_discussions' => $forumdiscussions,
            'forum_posts' => $forumposts,
            'quiz_attempts' => $quizattempts,
            'activity_completions' => $activitycompletions,
            'course_completions' => $coursecompletions,
            'files' => $files,
        ],
    ];
}

/**
 * Indica si una fila de files representa un derivado regenerable de
 * assignfeedback_editpdf y no un archivo académico aportado por el usuario.
 *
 * Moodle puede reconstruir estos PDF intermedios al abrir la anotación. No se
 * excluyen stamps, submission_files ni ninguna otra área del componente.
 */
function p5_is_regenerable_editpdf_file(array $row): bool {
    return (string)($row['component'] ?? '') === 'assignfeedback_editpdf' &&
        in_array(
            (string)($row['filearea'] ?? ''),
            ['combined', 'pages', 'partial'],
            true
        );
}

/**
 * Conserva únicamente archivos comparables entre Moodle 4.5 y 5.x.
 */
function p5_filter_comparable_files(array $rows): array {
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => !p5_is_regenerable_editpdf_file($row)
    ));
}

/**
 * Compara inventarios de curso entre versiones distintas de Moodle.
 *
 * Desde Moodle 5.0, las categorías de preguntas que antes vivían en el
 * contexto de curso pueden restaurarse dentro de actividades mod_qbank.
 * Esos módulos técnicos no existían en Moodle 4.5 y, por tanto, no deben
 * interpretarse como pérdida o duplicación de una actividad académica.
 *
 * Moodle también puede omitir durante la restauración los derivados
 * regenerables de assignfeedback_editpdf en combined, pages y partial. La
 * comparación descuenta esas filas en ambos extremos, pero continúa validando
 * estrictamente stamps, submission_files y cualquier otra área de archivos.
 */
function p5_compare_course_inventories(array $expected, array $actual): array {
    $expectedmodulesbytype = $expected['modules_by_type'] ?? [];
    $actualmodulesbytype = $actual['modules_by_type'] ?? [];
    $expectedqbank = (int)($expectedmodulesbytype['qbank'] ?? 0);
    $actualqbank = (int)($actualmodulesbytype['qbank'] ?? 0);
    $ignoredqbank = $expectedqbank === 0 ? $actualqbank : 0;

    $comparableexpectedcounts = $expected['counts'] ?? [];
    $comparableactualcounts = $actual['counts'] ?? [];
    if ($ignoredqbank > 0 && array_key_exists('activities', $comparableactualcounts)) {
        $comparableactualcounts['activities'] =
            (int)$comparableactualcounts['activities'] - $ignoredqbank;
    }

    $rawexpectedfiles = $expected['relations']['files'] ?? null;
    $rawactualfiles = $actual['relations']['files'] ?? null;
    $comparableexpectedfiles = is_array($rawexpectedfiles)
        ? p5_filter_comparable_files($rawexpectedfiles)
        : null;
    $comparableactualfiles = is_array($rawactualfiles)
        ? p5_filter_comparable_files($rawactualfiles)
        : null;
    $ignoredexpectededitpdf = is_array($rawexpectedfiles)
        ? count($rawexpectedfiles) - count($comparableexpectedfiles)
        : 0;
    $ignoredactualeditpdf = is_array($rawactualfiles)
        ? count($rawactualfiles) - count($comparableactualfiles)
        : 0;
    if ($comparableexpectedfiles !== null &&
            array_key_exists('module_files', $comparableexpectedcounts)) {
        $comparableexpectedcounts['module_files'] = count($comparableexpectedfiles);
    }
    if ($comparableactualfiles !== null &&
            array_key_exists('module_files', $comparableactualcounts)) {
        $comparableactualcounts['module_files'] = count($comparableactualfiles);
    }

    $comparableactualmodulesbytype = $actualmodulesbytype;
    if ($ignoredqbank > 0) {
        unset($comparableactualmodulesbytype['qbank']);
    }
    ksort($expectedmodulesbytype, SORT_STRING);
    ksort($comparableactualmodulesbytype, SORT_STRING);

    $expectedmodulekeys = array_values(array_column(
        $expected['modules'] ?? [],
        'module_key'
    ));
    $comparableactualmodules = array_values(array_filter(
        $actual['modules'] ?? [],
        static fn(array $row): bool =>
            !($ignoredqbank > 0 && (string)($row['modname'] ?? '') === 'qbank')
    ));
    $actualmodulekeys = array_values(array_column(
        $comparableactualmodules,
        'module_key'
    ));
    sort($expectedmodulekeys, SORT_STRING);
    sort($actualmodulekeys, SORT_STRING);

    $issues = [];
    foreach ($comparableexpectedcounts as $name => $expectedcount) {
        $rawexpected = (int)(($expected['counts'] ?? [])[$name] ?? -1);
        $rawactual = (int)(($actual['counts'] ?? [])[$name] ?? -1);
        $comparableactual = (int)($comparableactualcounts[$name] ?? -1);
        if ($comparableactual !== (int)$expectedcount) {
            $issues[] = [
                'field' => 'counts.' . $name,
                'expected' => (int)$expectedcount,
                'raw_expected' => $rawexpected,
                'actual' => $rawactual,
                'comparable_actual' => $comparableactual,
            ];
        }
    }
    if ($expectedmodulesbytype !== $comparableactualmodulesbytype) {
        $issues[] = [
            'field' => 'modules_by_type',
            'expected' => $expectedmodulesbytype,
            'actual' => $actualmodulesbytype,
            'comparable_actual' => $comparableactualmodulesbytype,
        ];
    }
    if ($expectedmodulekeys !== $actualmodulekeys) {
        $issues[] = [
            'field' => 'module_keys',
            'expected' => $expectedmodulekeys,
            'actual' => array_values(array_column(
                $actual['modules'] ?? [],
                'module_key'
            )),
            'comparable_actual' => $actualmodulekeys,
        ];
    }

    $compatibilityreasons = [];
    if ($ignoredqbank > 0) {
        $compatibilityreasons[] =
            'Moodle 5.x materializa bancos de preguntas heredados como mod_qbank.';
    }
    if ($ignoredexpectededitpdf > 0 || $ignoredactualeditpdf > 0) {
        $compatibilityreasons[] =
            'Se excluyeron derivados regenerables de assignfeedback_editpdf en ' .
            'combined, pages y partial; stamps y submission_files permanecen estrictos.';
    }

    return [
        'complete' => $issues === [],
        'issues' => $issues,
        'raw_expected_counts' => $expected['counts'] ?? [],
        'expected_counts' => $comparableexpectedcounts,
        'actual_counts' => $actual['counts'] ?? [],
        'comparable_actual_counts' => $comparableactualcounts,
        'expected_modules_by_type' => $expectedmodulesbytype,
        'actual_modules_by_type' => $actualmodulesbytype,
        'comparable_actual_modules_by_type' => $comparableactualmodulesbytype,
        'expected_module_keys' => $expectedmodulekeys,
        'actual_module_keys' => array_values(array_column(
            $actual['modules'] ?? [],
            'module_key'
        )),
        'comparable_actual_module_keys' => $actualmodulekeys,
        'compatibility_adjustments' => [
            'ignored_target_qbank_modules' => $ignoredqbank,
            'ignored_source_assignfeedback_editpdf_files' =>
                $ignoredexpectededitpdf,
            'ignored_target_assignfeedback_editpdf_files' =>
                $ignoredactualeditpdf,
            'qbank_reason' => $ignoredqbank > 0
                ? 'Moodle 5.x materializa bancos de preguntas heredados como mod_qbank.'
                : '',
            'assignfeedback_editpdf_reason' =>
                ($ignoredexpectededitpdf > 0 || $ignoredactualeditpdf > 0)
                    ? 'Se excluyeron derivados regenerables de assignfeedback_editpdf ' .
                        'en combined, pages y partial; stamps y submission_files ' .
                        'permanecen estrictos.'
                    : '',
            'reason' => implode(' ', $compatibilityreasons),
        ],
    ];
}

/**
 * Genera un backup oficial con usuarios y datos académicos.
 */
function p5_create_course_backup(int $courseid, string $rawpath): void {
    global $CFG, $USER;

    require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
    $admin = get_admin();
    if (!$admin) {
        throw new RuntimeException('No existe una cuenta administradora para crear el backup.');
    }
    $olduser = $USER;
    \core\session\manager::set_user($admin);
    $controller = null;
    try {
        $controller = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int)$admin->id
        );
        foreach ([
            'users' => 1,
            'anonymize' => 0,
            'activities' => 1,
            'blocks' => 1,
            'filters' => 1,
            'comments' => 1,
            'badges' => 1,
            'calendarevents' => 1,
            'competencies' => 1,
            'contentbankcontent' => 1,
        ] as $settingname => $value) {
            try {
                $controller->get_plan()->get_setting($settingname)->set_value($value);
            } catch (Throwable $ignored) {
                // Algunas ramas no exponen todos los ajustes; los obligatorios
                // se verifican después mediante users.xml y el inventario.
            }
        }
        $controller->execute_plan();
        $results = $controller->get_results();
        $destination = $results['backup_destination'] ?? null;
        if ($destination instanceof stored_file) {
            if (!$destination->copy_content_to($rawpath)) {
                throw new RuntimeException('Moodle no pudo copiar el backup a exports.');
            }
        } else if (is_string($destination) && is_readable($destination)) {
            if (!copy($destination, $rawpath)) {
                throw new RuntimeException('Moodle no pudo copiar el backup a exports.');
            }
        } else {
            throw new RuntimeException('Moodle no devolvió un backup_destination utilizable.');
        }
    } finally {
        if ($controller !== null) {
            $controller->destroy();
        }
        \core\session\manager::set_user($olduser);
    }
    if (!is_readable($rawpath) || filesize($rawpath) < 1) {
        throw new RuntimeException('El backup oficial quedó vacío o no puede leerse.');
    }
}

function p5_dom_text(DOMElement $user, string $tag): string {
    $nodes = $user->getElementsByTagName($tag);
    return $nodes->length > 0 ? trim((string)$nodes->item(0)->textContent) : '';
}

function p5_dom_set(DOMElement $user, string $tag, string $value): void {
    $nodes = $user->getElementsByTagName($tag);
    if ($nodes->length > 0) {
        $nodes->item(0)->nodeValue = $value;
    }
}

function p5_archive_files(string $directory): array {
    $directory = rtrim($directory, '/\\');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $fullpath = $item->getPathname();
        $relative = str_replace('\\', '/', substr($fullpath, strlen($directory) + 1));
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../')) {
            throw new RuntimeException('Ruta insegura al reconstruir el backup: ' . $relative . '.');
        }
        $files[$relative] = $fullpath;
    }
    ksort($files, SORT_STRING);
    return $files;
}

/**
 * Moodle ha devuelto las entradas de file_packer::list_files() como objetos
 * stdClass y, en otras implementaciones, puede entregarlas como arreglos.
 */
function p5_archive_entry_pathname($item): string {
    if (is_array($item)) {
        return (string)($item['pathname'] ?? '');
    }
    if (is_object($item)) {
        return (string)($item->pathname ?? '');
    }
    throw new RuntimeException(
        'El listado del backup contiene una entrada con formato no compatible.'
    );
}

/**
 * Impide restaurar categorías ordinarias de preguntas sin categoría superior.
 *
 * Desde Moodle 3.5, parent=0 está reservado para la categoría especial "top".
 * Si una categoría con preguntas queda en la raíz, Moodle 5.2 puede omitir su
 * creación durante la conversión al módulo de banco y dejar la entrada del
 * banco sin questioncategoryid.
 */
function p5_validate_backup_question_hierarchy(string $questionspath): array {
    if (!is_readable($questionspath)) {
        return [
            'categories_checked' => 0,
            'categories_with_questions' => 0,
        ];
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($questionspath, LIBXML_NONET)) {
        throw new RuntimeException('questions.xml no es XML válido.');
    }
    $xpath = new DOMXPath($dom);
    $categories = $xpath->query('/question_categories/question_category');
    if ($categories === false) {
        throw new RuntimeException('No fue posible inspeccionar las categorías de questions.xml.');
    }

    $checked = 0;
    $withquestions = 0;
    $invalid = [];
    foreach ($categories as $category) {
        if (!$category instanceof DOMElement) {
            continue;
        }
        $checked++;
        $entries = $xpath->query(
            './question_bank_entries/question_bank_entry | ./questions/question',
            $category
        );
        $entrycount = $entries === false ? 0 : $entries->length;
        if ($entrycount < 1) {
            continue;
        }
        $withquestions++;
        if ((int)p5_dom_text($category, 'parent') !== 0) {
            continue;
        }
        $invalid[] = sprintf(
            'id=%s name="%s" idnumber="%s"',
            $category->getAttribute('id'),
            p5_dom_text($category, 'name'),
            p5_dom_text($category, 'idnumber')
        );
    }
    if ($invalid) {
        throw new RuntimeException(
            'El backup contiene una categoría ordinaria de preguntas con parent=0: ' .
            implode('; ', $invalid) . '. Repare la jerarquía en el Moodle origen ' .
            'y genere nuevamente el backup.'
        );
    }
    return [
        'categories_checked' => $checked,
        'categories_with_questions' => $withquestions,
    ];
}

/**
 * Reescribe solamente atributos de identidad en users.xml; conserva los IDs
 * internos del backup para no romper relaciones académicas.
 */
function p5_normalize_backup(
    string $rawpath,
    string $normalizedpath,
    string $sourceid,
    string $targeturl,
    array $contract,
    array $targetusersbyid,
    string $auditpath
): array {
    $packer = get_file_packer('application/vnd.moodle.backup');
    $tempdir = make_temp_directory(
        'phase5-normalize/' . sha1($sourceid . '|' . $rawpath . '|' . microtime(true))
    );
    try {
        $result = $packer->extract_to_pathname($rawpath, $tempdir);
        if ($result === false || !is_readable($tempdir . '/moodle_backup.xml') ||
                !is_readable($tempdir . '/users.xml')) {
            throw new RuntimeException(
                'El .mbz no contiene moodle_backup.xml y users.xml; compruebe que incluya usuarios.'
            );
        }
        $questionvalidation = p5_validate_backup_question_hierarchy(
            $tempdir . '/questions.xml'
        );
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if (!$dom->load($tempdir . '/users.xml', LIBXML_NONET)) {
            throw new RuntimeException('users.xml no es XML válido.');
        }
        $audit = [];
        $targetids = [];
        foreach ($dom->getElementsByTagName('user') as $user) {
            if (!$user instanceof DOMElement) {
                continue;
            }
            $sourceuserid = (int)$user->getAttribute('id');
            $sourceusername = p5_dom_text($user, 'username');
            $sourceemail = p5_dom_text($user, 'email');
            $sourcefirstaccess = p5_dom_text($user, 'firstaccess');
            if ($sourceuserid < 1) {
                throw new RuntimeException('users.xml contiene un ID de usuario inválido.');
            }
            if (p5_norm($sourceusername) === 'guest') {
                $audit[] = [
                    'source' => $sourceid,
                    'source_user_id' => $sourceuserid,
                    'source_username' => $sourceusername,
                    'source_email' => $sourceemail,
                    'source_firstaccess' => $sourcefirstaccess,
                    'canonical_id' => '',
                    'target_user_id' => '',
                    'target_username' => 'guest',
                    'target_email' => '',
                    'target_firstaccess' => '',
                    'rewrite_status' => 'reserved_guest',
                    'message' => 'Cuenta reservada de Moodle; no representa una identidad migrada.',
                ];
                continue;
            }
            $key = $sourceid . ':' . $sourceuserid;
            $mapping = $contract['source_by_key'][$key] ?? null;
            if (!$mapping) {
                throw new RuntimeException(
                    'users.xml incluye ' . $key . ' sin target_user_id verificado.'
                );
            }
            $targetuserid = (int)$mapping['target_user_id'];
            if (isset($targetids[$targetuserid])) {
                throw new RuntimeException(
                    'El curso contiene varias cuentas de origen que convergen en target_user_id=' .
                    $targetuserid . '. Este caso requiere una estrategia de fusión de actividad.'
                );
            }
            $targetids[$targetuserid] = $key;
            $targetuser = $targetusersbyid[$targetuserid] ?? null;
            if (!$targetuser ||
                    p5_norm((string)$targetuser['username']) !==
                        p5_norm((string)$mapping['target_username']) ||
                    p5_norm((string)$targetuser['email']) !==
                        p5_norm((string)$mapping['target_email'])) {
                throw new RuntimeException(
                    'El inventario destino no confirma target_user_id=' . $targetuserid . '.'
                );
            }
            p5_dom_set($user, 'username', (string)$targetuser['username']);
            p5_dom_set($user, 'email', (string)$targetuser['email']);
            p5_dom_set($user, 'auth', (string)$targetuser['auth']);
            p5_dom_set($user, 'firstaccess', (string)(int)$targetuser['firstaccess']);
            p5_dom_set($user, 'mnethosturl', rtrim($targeturl, '/'));
            $audit[] = [
                'source' => $sourceid,
                'source_user_id' => $sourceuserid,
                'source_username' => $sourceusername,
                'source_email' => $sourceemail,
                'source_firstaccess' => $sourcefirstaccess,
                'canonical_id' => (string)$mapping['canonical_id'],
                'target_user_id' => $targetuserid,
                'target_username' => (string)$targetuser['username'],
                'target_email' => (string)$targetuser['email'],
                'target_firstaccess' => (int)$targetuser['firstaccess'],
                'rewrite_status' => 'mapped',
                'message' => 'Identidad alineada con el usuario canónico verificado.',
            ];
        }
        if (!$audit) {
            throw new RuntimeException('users.xml no contiene usuarios para validar.');
        }
        if ($dom->save($tempdir . '/users.xml') === false) {
            throw new RuntimeException('No fue posible guardar users.xml normalizado.');
        }

        $roleconversions = [];
        $rolespath = $tempdir . '/roles.xml';
        if (is_readable($rolespath)) {
            $rolesdom = new DOMDocument();
            $rolesdom->preserveWhiteSpace = false;
            $rolesdom->formatOutput = true;
            if (!$rolesdom->load($rolespath, LIBXML_NONET)) {
                throw new RuntimeException('roles.xml no es XML válido.');
            }
            foreach ($rolesdom->getElementsByTagName('role') as $role) {
                if (!$role instanceof DOMElement) {
                    continue;
                }
                $original = p5_norm(p5_dom_text($role, 'shortname'));
                if ($original === '') {
                    continue;
                }
                [$normalized, $target, $approved] = p5_role_policy($original);
                if (!$approved || $target === '') {
                    throw new RuntimeException(
                        'El rol ' . $original . ' no tiene una política aprobada.'
                    );
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
        p5_write_csv($auditpath, [
            'source', 'source_user_id', 'source_username', 'source_email',
            'source_firstaccess', 'canonical_id', 'target_user_id',
            'target_username', 'target_email', 'target_firstaccess',
            'rewrite_status', 'message',
        ], $audit);
        $files = p5_archive_files($tempdir);
        if (is_file($normalizedpath) && !unlink($normalizedpath)) {
            throw new RuntimeException('No fue posible reemplazar el backup normalizado anterior.');
        }
        if (!$packer->archive_to_pathname($files, $normalizedpath, false)) {
            throw new RuntimeException('Moodle no pudo reconstruir el .mbz normalizado.');
        }
        $listing = $packer->list_files($normalizedpath);
        $names = array_map(
            'p5_archive_entry_pathname',
            is_array($listing) ? $listing : []
        );
        if (!in_array('moodle_backup.xml', $names, true) ||
                !in_array('users.xml', $names, true)) {
            throw new RuntimeException('El backup reconstruido no conserva los XML obligatorios.');
        }
        return [
            'backup_users' => count($audit),
            'mapped_users' => count(array_filter(
                $audit,
                static fn(array $row): bool => $row['rewrite_status'] === 'mapped'
            )),
            'reserved_users' => count(array_filter(
                $audit,
                static fn(array $row): bool => $row['rewrite_status'] === 'reserved_guest'
            )),
            'question_categories_checked' =>
                (int)$questionvalidation['categories_checked'],
            'question_categories_with_questions' =>
                (int)$questionvalidation['categories_with_questions'],
            'role_conversions' => array_values($roleconversions),
            'audit_rows' => $audit,
        ];
    } finally {
        fulldelete($tempdir);
    }
}

function p5_role_policy(string $shortname): array {
    return match (p5_norm($shortname)) {
        'student' => ['estudiante', 'student', true, 'Rol académico estándar permitido.'],
        'editingteacher' => ['docente', 'editingteacher', true, 'Rol docente estándar permitido.'],
        'teacher' => ['docente', 'editingteacher', true, 'Rol docente normalizado.'],
        'manager', 'coursecreator', 'siteadmin' => [
            'administrador',
            'manager',
            true,
            'Rol administrativo conservado con alcance de curso.',
        ],
        default => [
            'personalizado',
            'personalizado',
            true,
            'Rol no estándar normalizado con acceso de solo lectura.',
        ],
    };
}

function p5_personalizado_allowed_capabilities(): array {
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

function p5_personalizado_role_status(): array {
    global $DB;

    $role = $DB->get_record(
        'role',
        ['shortname' => 'personalizado'],
        'id,name,shortname,description,archetype',
        IGNORE_MISSING
    );
    if (!$role) {
        return ['exists' => false, 'safe' => true, 'role_id' => null, 'issues' => []];
    }
    $issues = [];
    if (!str_contains((string)$role->description, 'MIG-P6')) {
        $issues[] =
            'El shortname personalizado ya existe, pero no pertenece a esta migración.';
    }
    $levels = array_values(array_map('intval', get_role_contextlevels((int)$role->id)));
    sort($levels, SORT_NUMERIC);
    if ($levels !== [CONTEXT_COURSE]) {
        $issues[] = 'El rol personalizado no está limitado al contexto de curso.';
    }
    $allowed = array_fill_keys(p5_personalizado_allowed_capabilities(), true);
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

function p5_ensure_personalizado_role(): array {
    global $CFG;

    require_once($CFG->dirroot . '/admin/roles/lib.php');
    $status = p5_personalizado_role_status();
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
    foreach (p5_personalizado_allowed_capabilities() as $capability) {
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
    }
    $status = p5_personalizado_role_status();
    if (!$status['exists'] || !$status['safe']) {
        throw new RuntimeException(
            'El rol personalizado creado no superó su validación de mínimo privilegio.'
        );
    }
    $status['action'] = 'created';
    return $status;
}

function p5_target_courses(): array {
    global $DB;
    $records = $DB->get_records_select(
        'course',
        'id <> :siteid',
        ['siteid' => SITEID],
        'id ASC',
        'id,category,fullname,shortname,idnumber'
    );
    $rows = [];
    foreach ($records as $record) {
        $rows[] = [
            'id' => (int)$record->id,
            'category' => (int)$record->category,
            'fullname' => (string)$record->fullname,
            'shortname' => (string)$record->shortname,
            'idnumber' => (string)$record->idnumber,
        ];
    }
    return $rows;
}

function p5_target_capabilities(): array {
    global $DB;
    $roles = array_values(array_map(
        static fn(stdClass $role): string => (string)$role->shortname,
        $DB->get_records('role', null, 'shortname ASC', 'id,shortname')
    ));
    $modules = array_values(array_map(
        static fn(stdClass $module): string => (string)$module->name,
        $DB->get_records('modules', ['visible' => 1], 'name ASC', 'id,name')
    ));
    return ['roles' => $roles, 'modules' => $modules];
}

/**
 * Carga y valida todos los archivos firmados producidos por el comando 16.
 */
function p5_load_plan(
    string $phase4dir,
    string $phase5dir,
    string $configsha,
    string $targetid,
    bool $expectlab
): array {
    $phase5dir = rtrim($phase5dir, '/\\');
    $summarypath = $phase5dir . '/plan_summary.json';
    $summary = p5_read_json($summarypath);
    if (($summary['config_sha256'] ?? '') !== $configsha ||
            ($summary['target_id'] ?? '') !== $targetid) {
        throw new RuntimeException('El plan de fase 5 corresponde a otra configuración o destino.');
    }
    if ($expectlab && ($summary['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('La simulación LAB de fase 5 no está aprobada.');
    }
    $paths = [
        'pilot_config.json' => $phase5dir . '/pilot_config.json',
        'pilot_course_plan.csv' => $phase5dir . '/pilot_course_plan.csv',
        'pilot_user_plan.csv' => $phase5dir . '/pilot_user_plan.csv',
        'pilot_role_plan.csv' => $phase5dir . '/pilot_role_plan.csv',
        'backup_user_rewrite.csv' => $phase5dir . '/backup_user_rewrite.csv',
        'source_course_inventory.json' => $phase5dir . '/source_course_inventory.json',
        'target_preflight.json' => $phase5dir . '/target_preflight.json',
        'raw_backup.mbz' => $phase5dir . '/backups/' .
            (string)($summary['raw_backup_file'] ?? ''),
        'normalized_backup.mbz' => $phase5dir . '/backups/' .
            (string)($summary['normalized_backup_file'] ?? ''),
    ];
    $hashes = p5_hash_files($paths);
    if (($summary['artifacts_sha256'] ?? []) !== $hashes) {
        throw new RuntimeException('Un artefacto de fase 5 cambió después de la simulación.');
    }
    $contract = p5_load_phase4_contract(
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    if (($summary['phase4_input_sha256'] ?? []) !== $contract['hashes']) {
        throw new RuntimeException('La fase 4 cambió después de generar el plan de curso.');
    }
    $courserows = p5_read_csv($paths['pilot_course_plan.csv']);
    if (count($courserows) !== 1) {
        throw new RuntimeException('pilot_course_plan.csv debe contener exactamente un curso.');
    }
    $userrows = p5_read_csv($paths['pilot_user_plan.csv']);
    $rolerows = p5_read_csv($paths['pilot_role_plan.csv']);
    if (count($userrows) !== (int)($summary['enrolments_planned'] ?? -1) ||
            count($rolerows) !== (int)($summary['roles_planned'] ?? -1)) {
        throw new RuntimeException('Los planes de usuarios o roles no coinciden con el resumen.');
    }
    if ((int)($summary['blocking_conflicts'] ?? -1) !== 0) {
        throw new RuntimeException('El plan conserva conflictos bloqueantes.');
    }
    return [
        'summary' => $summary,
        'paths' => $paths,
        'hashes' => $hashes,
        'course_row' => $courserows[0],
        'user_rows' => $userrows,
        'role_rows' => $rolerows,
        'source_inventory' => p5_read_json($paths['source_course_inventory.json']),
        'target_preflight' => p5_read_json($paths['target_preflight.json']),
        'phase4' => $contract,
    ];
}
