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
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRESERVE_ZERO_FRACTION |
        JSON_THROW_ON_ERROR
    );
    $temporary = $path . '.tmp.' . getmypid();
    if ($json === false ||
            file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary);
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
    $handle = fopen($path, 'wb');
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
    fclose($handle);
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
            (int)($verification['failed_checks'] ?? -1) !== 0) {
        throw new RuntimeException(
            'La fase 4 no tiene una verificación aprobada. Ejecute nuevamente el comando 15.'
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
function p5_collect_course_inventory(int $courseid, ?array $seed = null): array {
    global $DB;

    $modulebyid = [];
    if ($seed !== null) {
        if ((int)($seed['source_course_id'] ?? 0) !== $courseid ||
                !isset($seed['modules'], $seed['enrolments'], $seed['roles']) ||
                !is_array($seed['modules']) ||
                !is_array($seed['enrolments']) ||
                !is_array($seed['roles'])) {
            throw new RuntimeException('El inventario global no contiene una semilla válida para el curso.');
        }
        $course = (object)[
            'id' => $courseid,
            'category' => (int)($seed['source_category_id'] ?? 0),
            'fullname' => (string)($seed['fullname'] ?? ''),
            'shortname' => (string)($seed['shortname'] ?? ''),
            'idnumber' => (string)($seed['idnumber'] ?? ''),
            'startdate' => (int)($seed['startdate'] ?? 0),
            'enddate' => (int)($seed['enddate'] ?? 0),
            'format' => (string)($seed['format'] ?? ''),
            'enablecompletion' => (int)($seed['enablecompletion'] ?? 0),
        ];
        $modules = array_values($seed['modules']);
        $enrolments = array_values($seed['enrolments']);
        $roles = array_values($seed['roles']);
        $sectioncount = max(0, (int)($seed['sections_count'] ?? 0));
        $sourcechangeepoch = max(0, (int)($seed['source_change_epoch'] ?? 0));
        foreach ($modules as $row) {
            $moduleid = (int)($row['source_module_id'] ?? 0);
            if ($moduleid < 1) {
                throw new RuntimeException('La semilla contiene un módulo sin ID válido.');
            }
            $modulebyid[$moduleid] = $row;
        }
    } else {
        $course = $DB->get_record(
            'course',
            ['id' => $courseid],
            'id,category,fullname,shortname,idnumber,startdate,enddate,format,enablecompletion,timemodified',
            MUST_EXIST
        );
        $context = context_course::instance($courseid);
        $sourcechangeepoch = max(0, (int)$course->timemodified);
        $modules = [];
        $modulerecords = $DB->get_records_sql(
            'SELECT cm.id, cm.instance, cm.idnumber, cm.section, cm.completion,
                    cm.added, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0
           ORDER BY m.name, cm.id',
            ['courseid' => $courseid]
        );
        $instancesbymodule = [];
        foreach ($modulerecords as $record) {
            $instancesbymodule[(string)$record->modname][] = (int)$record->instance;
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->added);
        }
        $modulenames = [];
        foreach ($instancesbymodule as $modname => $instanceids) {
            if (!$DB->get_manager()->table_exists($modname)) {
                continue;
            }
            $columns = $DB->get_columns($modname);
            $fieldnames = ['id'];
            if (isset($columns['name'])) {
                $fieldnames[] = 'name';
            }
            if (isset($columns['timemodified'])) {
                $fieldnames[] = 'timemodified';
            }
            $fields = implode(',', $fieldnames);
            foreach (array_chunk(array_values(array_unique($instanceids)), 500) as $chunk) {
                $records = $DB->get_records_list($modname, 'id', $chunk, '', $fields);
                foreach ($records as $record) {
                    $modulenames[$modname][(int)$record->id] = isset($record->name)
                        ? (string)$record->name
                        : '';
                    if (isset($record->timemodified)) {
                        $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
                    }
                }
            }
        }
        foreach ($modulerecords as $record) {
            $name = (string)($modulenames[(string)$record->modname][(int)$record->instance] ?? '');
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
            'SELECT ue.id, ue.userid, ue.status, ue.timemodified, e.enrol,
                    e.id AS enrolid, u.username, u.email
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
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
        }
        $enrolments = p5_sorted_rows(
            $enrolments,
            static fn(array $row): string => sprintf('%012d|%s', $row['source_user_id'], $row['enrol_method'])
        );

        $roles = [];
        $rolerecords = $DB->get_records_sql(
            'SELECT ra.id, ra.userid, ra.timemodified, r.shortname,
                    r.archetype, ra.component, ra.itemid
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
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
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
        $sectioncount = $DB->count_records('course_sections', ['course' => $courseid]);
    }

    $submissions = [];
    if ($DB->get_manager()->table_exists('assign_submission')) {
        $records = $DB->get_records_sql(
            "SELECT s.id, s.userid, s.status, s.latest, s.timemodified, a.name
               FROM {assign_submission} s
               JOIN {assign} a ON a.id = s.assignment
              WHERE a.course = :courseid AND s.latest = 1 AND s.status <> :newstatus
           ORDER BY a.name, s.userid",
            ['courseid' => $courseid, 'newstatus' => 'new']
        );
        foreach ($records as $record) {
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
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
            'SELECT g.id, g.userid, g.grade, g.timemodified, a.name
               FROM {assign_grades} g
               JOIN {assign} a ON a.id = g.assignment
              WHERE a.course = :courseid AND g.grade >= 0
           ORDER BY a.name, g.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
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
            'SELECT d.id, d.userid, d.name, d.timemodified, f.name AS forumname
               FROM {forum_discussions} d
               JOIN {forum} f ON f.id = d.forum
              WHERE f.course = :courseid
           ORDER BY f.name, d.name, d.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
            $forumdiscussions[] = [
                'source_user_id' => (int)$record->userid,
                'activity_key' => p5_module_key('forum', '', (string)$record->forumname),
                'subject' => (string)$record->name,
            ];
        }
        $records = $DB->get_records_sql(
            'SELECT p.id, p.userid, p.subject, p.modified, f.name AS forumname
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
               JOIN {forum} f ON f.id = d.forum
              WHERE f.course = :courseid
           ORDER BY f.name, p.id',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->modified);
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
            'SELECT qa.id, qa.userid, qa.state, qa.sumgrades, qa.timemodified, q.name
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid
           ORDER BY q.name, qa.userid, qa.attempt',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
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
            'SELECT cmc.id, cmc.userid, cmc.completionstate, cmc.overrideby,
                    cmc.timemodified, cm.id AS cmid
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
              WHERE cm.course = :courseid AND cmc.completionstate > 0
           ORDER BY cm.id, cmc.userid',
            ['courseid' => $courseid]
        );
        foreach ($records as $record) {
            $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
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
            'id,userid,timecompleted,timestarted,timeenrolled'
        );
        foreach ($records as $record) {
            $sourcechangeepoch = max(
                $sourcechangeepoch,
                (int)$record->timecompleted,
                (int)$record->timestarted,
                (int)$record->timeenrolled
            );
            $coursecompletions[] = [
                'source_user_id' => (int)$record->userid,
                'completed' => $record->timecompleted !== null && (int)$record->timecompleted > 0,
            ];
        }
    }

    $files = [];
    $filerecords = $DB->get_records_sql(
        'SELECT f.id, f.userid, f.component, f.filearea, f.filename,
                f.timemodified, cm.id AS cmid
           FROM {files} f
           JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :modulelevel
           JOIN {course_modules} cm ON cm.id = ctx.instanceid
          WHERE cm.course = :courseid AND f.filename <> :dot
       ORDER BY cm.id, f.component, f.filearea, f.filename, f.id',
        ['modulelevel' => CONTEXT_MODULE, 'courseid' => $courseid, 'dot' => '.']
    );
    foreach ($filerecords as $record) {
        $sourcechangeepoch = max($sourcechangeepoch, (int)$record->timemodified);
        if (!isset($modulebyid[(int)$record->cmid])) {
            continue;
        }
        $files[] = [
            'source_user_id' => (int)$record->userid,
            'activity_key' => $modulebyid[(int)$record->cmid]['module_key'],
            'component' => (string)$record->component,
            'filearea' => (string)$record->filearea,
            'filename' => (string)$record->filename,
        ];
    }

    $modulesbytype = [];
    foreach ($modules as $module) {
        $modname = (string)$module['modname'];
        $modulesbytype[$modname] = ($modulesbytype[$modname] ?? 0) + 1;
    }
    ksort($modulesbytype, SORT_STRING);
    $counts = [
        'sections' => $sectioncount,
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
        'source_change_epoch' => $sourcechangeepoch,
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
 * Compara inventarios de curso entre versiones distintas de Moodle.
 *
 * Desde Moodle 5.0, las categorías de preguntas que antes vivían en el
 * contexto de curso pueden restaurarse dentro de actividades mod_qbank.
 * Esos módulos técnicos no existían en Moodle 4.5 y, por tanto, no deben
 * interpretarse como pérdida o duplicación de una actividad académica.
 *
 * La excepción es deliberadamente estrecha: solo se ignoran módulos cuyo
 * modname sea exactamente "qbank", y únicamente cuando el origen no tenía
 * módulos de ese tipo. Todas las demás actividades, conteos y tipos deben
 * seguir coincidiendo exactamente.
 */
function p5_compare_course_inventories(array $expected, array $actual): array {
    $expectedmodulesbytype = $expected['modules_by_type'] ?? [];
    $actualmodulesbytype = $actual['modules_by_type'] ?? [];
    $expectedqbank = (int)($expectedmodulesbytype['qbank'] ?? 0);
    $actualqbank = (int)($actualmodulesbytype['qbank'] ?? 0);
    $ignoredqbank = $expectedqbank === 0 ? $actualqbank : 0;

    $comparableactualcounts = $actual['counts'] ?? [];
    if ($ignoredqbank > 0 && array_key_exists('activities', $comparableactualcounts)) {
        $comparableactualcounts['activities'] =
            (int)$comparableactualcounts['activities'] - $ignoredqbank;
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
    foreach (($expected['counts'] ?? []) as $name => $expectedcount) {
        $rawactual = (int)(($actual['counts'] ?? [])[$name] ?? -1);
        $comparableactual = (int)($comparableactualcounts[$name] ?? -1);
        if ($comparableactual !== (int)$expectedcount) {
            $issues[] = [
                'field' => 'counts.' . $name,
                'expected' => (int)$expectedcount,
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

    return [
        'complete' => $issues === [],
        'issues' => $issues,
        'expected_counts' => $expected['counts'] ?? [],
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
            'reason' => $ignoredqbank > 0
                ? 'Moodle 5.x materializa bancos de preguntas heredados como mod_qbank.'
                : '',
        ],
    ];
}

/**
 * Copia un flujo a disco y calcula SHA-256 con los mismos bloques leídos.
 *
 * @param resource $input
 * @return array{sha256:string,bytes:int,mtime:int}
 */
function p5_copy_stream_with_sha256($input, string $rawpath): array {
    if (!is_resource($input)) {
        throw new RuntimeException('Moodle no devolvió un flujo de backup válido.');
    }

    $output = @fopen($rawpath, 'xb');
    if ($output === false) {
        throw new RuntimeException('No fue posible crear el backup de salida.');
    }

    $hashcontext = hash_init('sha256');
    $bytes = 0;
    try {
        while (!feof($input)) {
            $chunk = fread($input, 8 * 1024 * 1024);
            if ($chunk === false) {
                throw new RuntimeException('Falló la lectura del backup de Moodle.');
            }
            if ($chunk === '') {
                if (feof($input)) {
                    break;
                }
                throw new RuntimeException('La lectura del backup no avanzó.');
            }

            $length = strlen($chunk);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($output, substr($chunk, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Falló la escritura del backup.');
                }
                $offset += $written;
            }
            hash_update($hashcontext, $chunk);
            $bytes += $length;
        }
        if (!fflush($output)) {
            throw new RuntimeException('No fue posible vaciar el backup a disco.');
        }
        if (!fclose($output)) {
            $output = null;
            throw new RuntimeException('No fue posible cerrar el backup generado.');
        }
        $output = null;
    } catch (Throwable $error) {
        if (is_resource($output)) {
            @fclose($output);
        }
        if (is_file($rawpath)) {
            @unlink($rawpath);
        }
        throw $error;
    }

    clearstatcache(true, $rawpath);
    $actualbytes = filesize($rawpath);
    $mtime = filemtime($rawpath);
    if ($bytes < 1 || $actualbytes === false || $actualbytes !== $bytes ||
            $mtime === false) {
        @unlink($rawpath);
        throw new RuntimeException(
            'El backup oficial quedó vacío o su tamaño no coincide.'
        );
    }

    return [
        'sha256' => hash_final($hashcontext),
        'bytes' => $bytes,
        'mtime' => (int)$mtime,
    ];
}

/**
 * Perfil efectivo del respaldo. Conserva los valores generales definidos por
 * el administrador y hace explícitos los componentes imprescindibles del
 * consolidador.
 *
 * @return array<string,int>
 */
function p5_expected_backup_settings(): array {
    $settings = [];
    foreach ([
        'users',
        'role_assignments',
        'activities',
        'blocks',
        'files',
        'filters',
        'comments',
        'badges',
        'calendarevents',
        'userscompletion',
        'logs',
        'histories',
        'questionbank',
        'groups',
        'competencies',
        'contentbankcontent',
        'xapistate',
        'legacyfiles',
    ] as $name) {
        $configured = get_config('backup', 'backup_general_' . $name);
        if ($configured !== false) {
            $settings[$name] = (int)(bool)$configured;
        }
    }
    foreach ([
        'users' => 1,
        'anonymize' => 0,
        'role_assignments' => 1,
        'activities' => 1,
        'blocks' => 1,
        'files' => 1,
        'filters' => 1,
        'comments' => 1,
        'badges' => 1,
        'calendarevents' => 1,
        'userscompletion' => 1,
        'questionbank' => 1,
        'groups' => 1,
        'competencies' => 1,
        'contentbankcontent' => 1,
    ] as $name => $value) {
        $settings[$name] = $value;
    }
    ksort($settings, SORT_STRING);
    return $settings;
}

/**
 * Genera un backup oficial con usuarios y datos académicos.
 *
 * @return array{sha256:string,bytes:int,mtime:int}
 */
function p5_create_course_backup(
    int $courseid,
    string $rawpath,
    ?callable $phasecallback = null
): array {
    global $CFG, $USER;

    require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
    $admin = get_admin();
    if (!$admin) {
        throw new RuntimeException('No existe una cuenta administradora para crear el backup.');
    }
    $olduser = $USER;
    \core\session\manager::set_user($admin);
    $controller = null;
    $input = null;
    $metadata = null;
    $destination = null;
    $phase = static function(string $stage, array $details = []) use ($phasecallback): void {
        if ($phasecallback !== null) {
            $phasecallback($stage, $details);
        }
    };
    try {
        $phase('backup-prepare');
        $controller = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int)$admin->id
        );
        $backupfilename = sprintf(
            'collector-course-%d-%d-%s.mbz',
            $courseid,
            getmypid(),
            bin2hex(random_bytes(6))
        );
        try {
            $controller->get_plan()->get_setting('filename')->set_value($backupfilename);
        } catch (Throwable $error) {
            throw new RuntimeException(
                'Moodle no permitió asignar un destino único al backup: ' .
                $error->getMessage()
            );
        }
        foreach (p5_expected_backup_settings() as $settingname => $value) {
            try {
                $controller->get_plan()->get_setting($settingname)->set_value($value);
            } catch (Throwable $ignored) {
                // Algunas ramas no exponen todos los ajustes; los obligatorios
                // se verifican después mediante users.xml y el inventario.
            }
        }
        $phase('moodle-backup');
        $controller->execute_plan();
        $results = $controller->get_results();
        $destination = $results['backup_destination'] ?? null;
        if ($destination instanceof stored_file) {
            $input = $destination->get_content_file_handle();
        } else if (is_string($destination) && is_readable($destination)) {
            $input = @fopen($destination, 'rb');
            if ($input === false) {
                throw new RuntimeException(
                    'Moodle no pudo abrir el backup temporal.'
                );
            }
        } else {
            throw new RuntimeException('Moodle no devolvió un backup_destination utilizable.');
        }
        $phase('copy-sha');
        $metadata = p5_copy_stream_with_sha256($input, $rawpath);
    } finally {
        if (is_resource($input)) {
            @fclose($input);
        }
        if ($destination instanceof stored_file) {
            try {
                $phase('moodle-storage-cleanup');
                if (!$destination->delete()) {
                    $phase('moodle-storage-cleanup-warning', [
                        'reason' => 'stored_file_delete_returned_false',
                    ]);
                }
            } catch (Throwable $cleanupError) {
                $phase('moodle-storage-cleanup-warning', [
                    'reason' => $cleanupError->getMessage(),
                ]);
            }
        }
        if ($controller !== null) {
            $controller->destroy();
        }
        \core\session\manager::set_user($olduser);
    }
    if (!is_array($metadata) || !is_readable($rawpath) || filesize($rawpath) < 1) {
        throw new RuntimeException('El backup oficial quedó vacío o no puede leerse.');
    }
    return $metadata;
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
        'teacher' => ['docente', 'teacher', true, 'Rol docente sin edición permitido.'],
        default => [
            'personalizado',
            '',
            false,
            'El rol no estándar o privilegiado requiere aprobación contextual antes de restaurar.',
        ],
    };
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
