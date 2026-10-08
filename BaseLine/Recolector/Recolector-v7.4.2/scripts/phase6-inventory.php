<?php
// Fase 6: inventario masivo y de solo lectura de una instancia origen.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/bootstrap.php');
require(collector_moodle_config_path());
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/phase5-lib.php');
require_once(__DIR__ . '/theme-contract.php');

[$options, $unrecognized] = cli_get_params(
    [
        'config' => null,
        'output' => null,
        'configsha' => null,
        'sourceid' => null,
        'sourcename' => null,
        'enrich' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase6-inventory.php --output=RUTA --configsha=SHA256 " .
        "--sourceid=virtual --sourcename=\"Pregrado virtual\" " .
        "--config=/var/www/html/config.php [--enrich=0|1]\n"
    );
    exit(0);
}

function collector_theme_policy_value(string $name): array {
    $value = get_config('core', $name);
    if ($value === false) {
        return ['state' => 'not_supported'];
    }
    return ['state' => 'complete', 'value' => ((int)$value) === 1];
}

function collector_theme_assignments_for_table(
    string $table,
    string $idfield,
    string $outputid
): array {
    global $DB;

    try {
        $columns = $DB->get_columns($table);
        if (!isset($columns['theme'])) {
            return ['state' => 'not_supported', 'assignments' => []];
        }
        $rows = [];
        foreach ($DB->get_records_select(
            $table,
            "theme IS NOT NULL AND theme <> ''",
            [],
            $idfield . ' ASC',
            $idfield . ',theme'
        ) as $record) {
            $theme = collector_theme_name((string)$record->theme);
            if ($theme === '') {
                continue;
            }
            $rows[] = [
                $outputid => (int)$record->{$idfield},
                'theme' => $theme,
            ];
        }
        return [
            'state' => $rows === [] ? 'empty' : 'complete',
            'assignments' => $rows,
        ];
    } catch (Throwable $error) {
        return [
            'state' => 'error',
            'assignments' => [],
            'error' => $error->getMessage(),
        ];
    }
}

function collector_theme_evidence(array $courses, bool $coursefieldsupported): array {
    global $CFG;

    $globaltheme = '';
    $globalstate = 'unknown';
    $globalsource = 'not_available';
    $configured = get_config('core', 'theme');
    if (is_string($configured) && collector_theme_name($configured) !== '') {
        $globaltheme = collector_theme_name($configured);
        $globalstate = 'complete';
        $globalsource = 'core_config';
    } else if (isset($CFG->theme) && is_string($CFG->theme) &&
            collector_theme_name($CFG->theme) !== '') {
        $globaltheme = collector_theme_name($CFG->theme);
        $globalstate = 'complete';
        $globalsource = 'cfg_runtime';
    }

    $profiles = [];
    $profilesstate = 'complete';
    try {
        if (!class_exists('core_plugin_manager')) {
            throw new RuntimeException('core_plugin_manager no está disponible.');
        }
        $themes = core_plugin_manager::instance()->get_plugins()['theme'] ?? [];
        foreach ($themes as $name => $plugin) {
            $component = (string)($plugin->component ?? ('theme_' . $name));
            $themename = collector_theme_name($component);
            if ($themename === '') {
                continue;
            }
            try {
                $profiles[$themename] = collector_theme_profile(
                    $component,
                    get_config($component)
                );
                if (($profiles[$themename]['state'] ?? '') === 'error') {
                    $profilesstate = 'error';
                }
            } catch (Throwable $error) {
                $profiles[$themename] = [
                    'component' => 'theme_' . $themename,
                    'state' => 'error',
                    'settings' => [],
                    'settings_count' => 0,
                    'error' => $error->getMessage(),
                ];
                $profilesstate = 'error';
            }
        }
        ksort($profiles, SORT_STRING);
        if ($profiles === [] && $profilesstate !== 'error') {
            $profilesstate = 'empty';
        }
    } catch (Throwable $error) {
        $profilesstate = 'error';
        $profiles = [];
    }

    $courseassignments = [];
    if ($coursefieldsupported) {
        foreach ($courses as $course) {
            $theme = collector_theme_name((string)($course['theme'] ?? ''));
            if ($theme !== '') {
                $courseassignments[] = [
                    'source_course_id' => (int)($course['source_course_id'] ?? 0),
                    'theme' => $theme,
                ];
            }
        }
    }
    usort(
        $courseassignments,
        static fn(array $left, array $right): int =>
            $left['source_course_id'] <=> $right['source_course_id']
    );
    $coursestate = !$coursefieldsupported
        ? 'error'
        : ($courseassignments === [] ? 'empty' : 'complete');
    $users = collector_theme_assignments_for_table('user', 'id', 'source_user_id');
    $categories = collector_theme_assignments_for_table(
        'course_categories',
        'id',
        'source_category_id'
    );

    $criticalcomplete = $globalstate === 'complete' &&
        $coursefieldsupported && $profilesstate !== 'error';
    $policies = [
        'allow_course_themes' => collector_theme_policy_value('allowcoursethemes'),
        'allow_user_themes' => collector_theme_policy_value('allowuserthemes'),
        'allow_category_themes' => collector_theme_policy_value('allowcategorythemes'),
    ];
    $assignments = [
        'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
        'inventory_complete' => $criticalcomplete,
        'courses' => $courseassignments,
        'users' => $users['assignments'],
        'categories' => $categories['assignments'],
        'cohorts' => [],
        'states' => [
            'courses' => $coursestate,
            'users' => $users['state'],
            'categories' => $categories['state'],
            'cohorts' => 'not_supported',
        ],
    ];
    if (isset($users['error'])) {
        $assignments['errors']['users'] = $users['error'];
    }
    if (isset($categories['error'])) {
        $assignments['errors']['categories'] = $categories['error'];
    }
    if (!$coursefieldsupported) {
        $assignments['errors']['courses'] = 'El esquema no expone course.theme.';
    }

    $themes = [
        'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
        'inventory_complete' => $criticalcomplete,
        'global_theme' => $globaltheme,
        'global_theme_state' => $globalstate,
        'site' => [
            'global_theme' => $globaltheme,
            'global_theme_state' => $globalstate,
            'global_theme_source' => $globalsource,
        ],
        'policies' => $policies,
        'profiles' => $profiles,
        'states' => [
            'global_theme' => $globalstate,
            'course_assignments' => $coursestate,
            'profiles' => $profilesstate,
            'installed_themes' => $profilesstate,
        ],
    ];
    $themes['fingerprint_sha256'] = collector_theme_sha256([
        'site' => $themes['site'],
        'policies' => $themes['policies'],
        'profiles' => $themes['profiles'],
        'assignments' => $assignments,
    ]);
    return ['themes' => $themes, 'theme_assignments' => $assignments];
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $output = trim((string)$options['output']);
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $sourceid = p5_norm((string)$options['sourceid']);
    $sourcename = trim((string)$options['sourcename']);
    $enrich = (int)$options['enrich'];
    if ($output === '' ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $sourceid) ||
            $sourcename === '' || !in_array($enrich, [0, 1], true)) {
        throw new RuntimeException('output o sourceid inválido.');
    }
    $outputdir = dirname($output);
    if (!is_dir($outputdir) &&
            !mkdir($outputdir, 0770, true) &&
            !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear el directorio del inventario.');
    }

    $coursecolumns = $DB->get_columns('course');
    $coursefieldsupported = isset($coursecolumns['theme']);
    $categorycolumns = $DB->get_columns('course_categories');
    $categoryfieldsupported = isset($categorycolumns['theme']);

    if ($enrich === 1) {
        if (!is_readable($output)) {
            throw new RuntimeException('El inventario que se desea enriquecer no existe.');
        }
        $data = p5_read_json($output);
        if (($data['source_id'] ?? '') !== $sourceid ||
                !is_array($data['courses'] ?? null)) {
            throw new RuntimeException('El inventario anterior no corresponde al origen.');
        }
        $courseids = array_values(array_filter(array_map(
            static fn(array $course): int => (int)($course['source_course_id'] ?? 0),
            $data['courses']
        )));
        $themesbycourse = [];
        if ($coursefieldsupported && $courseids !== []) {
            foreach (array_chunk($courseids, 500) as $chunk) {
                foreach ($DB->get_records_list('course', 'id', $chunk, '', 'id,theme') as $row) {
                    $themesbycourse[(int)$row->id] = collector_theme_name((string)$row->theme);
                }
            }
        }
        foreach ($data['courses'] as &$course) {
            $courseid = (int)($course['source_course_id'] ?? 0);
            $course['theme'] = $coursefieldsupported
                ? (string)($themesbycourse[$courseid] ?? '')
                : null;
        }
        unset($course);
        $evidence = collector_theme_evidence($data['courses'], $coursefieldsupported);
        $data['themes'] = $evidence['themes'];
        $data['theme_assignments'] = $evidence['theme_assignments'];
        $data['theme_metadata_updated_at_utc'] = gmdate('c');
        p5_write_json($output, $data);
        cli_writeln(
            'FASE6_THEME_ENRICH_OK source=' . $sourceid .
            ' courses=' . count($data['courses']) .
            ' complete=' . ($data['themes']['inventory_complete'] ? '1' : '0')
        );
        exit(0);
    }

    $categories = [];
    $categoryids = [];
    foreach ($DB->get_records(
        'course_categories',
        null,
        'depth ASC, id ASC',
        'id,parent,name,idnumber,depth,path,visible,sortorder' .
            ($categoryfieldsupported ? ',theme' : '')
    ) as $category) {
        $categoryid = (int)$category->id;
        $categoryids[$categoryid] = true;
        $categories[] = [
            'source_category_id' => $categoryid,
            'source_parent_id' => (int)$category->parent,
            'name' => (string)$category->name,
            'idnumber' => (string)$category->idnumber,
            'depth' => (int)$category->depth,
            'path' => (string)$category->path,
            'visible' => (int)$category->visible,
            'sortorder' => (int)$category->sortorder,
            'theme' => $categoryfieldsupported
                ? collector_theme_name((string)$category->theme)
                : null,
        ];
    }

    $courses = [];
    $courseindex = [];
    $courseids = [];
    foreach ($DB->get_records_select(
        'course',
        'id <> :siteid',
        ['siteid' => SITEID],
        'id ASC',
        'id,category,fullname,shortname,idnumber,visible,startdate,enddate,format,' .
            'enablecompletion,timemodified' . ($coursefieldsupported ? ',theme' : '')
    ) as $course) {
        $courseid = (int)$course->id;
        $row = [
            'source_course_id' => $courseid,
            'source_category_id' => (int)$course->category,
            'fullname' => (string)$course->fullname,
            'shortname' => (string)$course->shortname,
            'idnumber' => (string)$course->idnumber,
            'visible' => (int)$course->visible,
            'startdate' => (int)$course->startdate,
            'enddate' => (int)$course->enddate,
            'format' => (string)$course->format,
            'enablecompletion' => (int)$course->enablecompletion,
            'theme' => $coursefieldsupported
                ? collector_theme_name((string)$course->theme)
                : null,
            'sections_count' => 0,
            'source_change_epoch' => (int)$course->timemodified,
            'modules_by_type' => [],
            'modules' => [],
            'enrolments' => [],
            'roles' => [],
        ];
        $courseindex[$courseid] = count($courses);
        $courseids[$courseid] = true;
        $courses[] = $row;
    }

    $sections = $DB->get_records_sql(
        'SELECT cs.course, COUNT(1) AS sectioncount,
                MAX(cs.timemodified) AS changetime
           FROM {course_sections} cs
       GROUP BY cs.course'
    );
    foreach ($sections as $section) {
        $courseid = (int)$section->course;
        if (!isset($courseindex[$courseid])) {
            continue;
        }
        $index = $courseindex[$courseid];
        $courses[$index]['sections_count'] = (int)$section->sectioncount;
        $courses[$index]['source_change_epoch'] = max(
            (int)$courses[$index]['source_change_epoch'],
            (int)$section->changetime
        );
    }

    $modulerecords = $DB->get_records_sql(
        'SELECT cm.id, cm.course, cm.instance, cm.idnumber, cm.section,
                cm.completion, cm.added, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.deletioninprogress = 0
       ORDER BY cm.course, m.name, cm.id'
    );
    $instancesbymodule = [];
    foreach ($modulerecords as $module) {
        $instancesbymodule[(string)$module->modname][] = (int)$module->instance;
        $courseid = (int)$module->course;
        if (!isset($courseindex[$courseid])) {
            continue;
        }
        $index = $courseindex[$courseid];
        $courses[$index]['source_change_epoch'] = max(
            (int)$courses[$index]['source_change_epoch'],
            (int)$module->added
        );
    }

    $moduledetails = [];
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
            foreach ($DB->get_records_list($modname, 'id', $chunk, '', $fields) as $record) {
                $moduledetails[$modname][(int)$record->id] = [
                    'name' => isset($record->name) ? (string)$record->name : '',
                    'timemodified' => isset($record->timemodified)
                        ? (int)$record->timemodified
                        : 0,
                ];
            }
        }
    }
    foreach ($modulerecords as $module) {
        $courseid = (int)$module->course;
        if (!isset($courseindex[$courseid])) {
            continue;
        }
        $index = $courseindex[$courseid];
        $modname = p5_norm((string)$module->modname);
        $details = $moduledetails[$modname][(int)$module->instance] ?? [
            'name' => '',
            'timemodified' => 0,
        ];
        $name = (string)$details['name'];
        $courses[$index]['modules'][] = [
            'source_module_id' => (int)$module->id,
            'modname' => $modname,
            'instance' => (int)$module->instance,
            'idnumber' => (string)$module->idnumber,
            'name' => $name,
            'module_key' => p5_module_key($modname, (string)$module->idnumber, $name),
            'completion_mode' => (int)$module->completion,
        ];
        $courses[$index]['modules_by_type'][$modname] =
            (int)($courses[$index]['modules_by_type'][$modname] ?? 0) + 1;
        $courses[$index]['source_change_epoch'] = max(
            (int)$courses[$index]['source_change_epoch'],
            (int)$details['timemodified']
        );
    }
    foreach ($courses as &$course) {
        ksort($course['modules_by_type'], SORT_STRING);
        $course['modules'] = p5_sorted_rows(
            $course['modules'],
            static fn(array $row): string => $row['module_key']
        );
    }
    unset($course);

    $enrolments = $DB->get_records_sql(
        'SELECT ue.id, e.courseid, ue.userid, ue.status, ue.timemodified, e.enrol,
                u.username, u.email
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {user} u ON u.id = ue.userid
          WHERE u.deleted = 0
       ORDER BY e.courseid, ue.userid, e.enrol, ue.id'
    );
    foreach ($enrolments as $enrolment) {
        $courseid = (int)$enrolment->courseid;
        if (!isset($courseindex[$courseid])) {
            continue;
        }
        $index = $courseindex[$courseid];
        $courses[$index]['enrolments'][] = [
            'source_user_id' => (int)$enrolment->userid,
            'source_username' => (string)$enrolment->username,
            'source_email' => (string)$enrolment->email,
            'enrol_method' => (string)$enrolment->enrol,
            'enrol_status' => (int)$enrolment->status,
        ];
        $courses[$index]['source_change_epoch'] = max(
            (int)$courses[$index]['source_change_epoch'],
            (int)$enrolment->timemodified
        );
    }

    $roles = $DB->get_records_sql(
        'SELECT ra.id, ctx.instanceid AS courseid, ra.userid, ra.timemodified,
                r.shortname, r.archetype, ra.component, ra.itemid
           FROM {role_assignments} ra
           JOIN {context} ctx
             ON ctx.id = ra.contextid AND ctx.contextlevel = :courselevel
           JOIN {role} r ON r.id = ra.roleid
       ORDER BY ctx.instanceid, ra.userid, r.shortname, ra.id',
        ['courselevel' => CONTEXT_COURSE]
    );
    foreach ($roles as $role) {
        $courseid = (int)$role->courseid;
        if (!isset($courseindex[$courseid])) {
            continue;
        }
        $index = $courseindex[$courseid];
        $courses[$index]['roles'][] = [
            'source_user_id' => (int)$role->userid,
            'role_shortname' => (string)$role->shortname,
            'role_archetype' => (string)$role->archetype,
            'component' => (string)$role->component,
            'itemid' => (int)$role->itemid,
        ];
        $courses[$index]['source_change_epoch'] = max(
            (int)$courses[$index]['source_change_epoch'],
            (int)$role->timemodified
        );
    }
    foreach ($courses as &$course) {
        $course['enrolments'] = p5_sorted_rows(
            $course['enrolments'],
            static fn(array $row): string => sprintf(
                '%012d|%s',
                $row['source_user_id'],
                $row['enrol_method']
            )
        );
        $course['roles'] = p5_sorted_rows(
            $course['roles'],
            static fn(array $row): string => sprintf(
                '%012d|%s|%s|%012d',
                $row['source_user_id'],
                $row['role_shortname'],
                $row['component'],
                $row['itemid']
            )
        );
    }
    unset($course);

    $orphanedcourses = 0;
    foreach ($courses as $course) {
        if (!isset($categoryids[(int)$course['source_category_id']])) {
            $orphanedcourses++;
        }
    }
    $evidence = collector_theme_evidence($courses, $coursefieldsupported);
    $data = [
        'schema_version' => '1.0',
        'phase' => '6-source-inventory',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'source_id' => $sourceid,
        'source_name' => $sourcename,
        'source_wwwroot' => (string)$CFG->wwwroot,
        'source_moodle_version' => (string)get_config('moodle', 'version'),
        'source_moodle_release' => (string)get_config('moodle', 'release'),
        'categories' => $categories,
        'courses' => $courses,
        'themes' => $evidence['themes'],
        'theme_assignments' => $evidence['theme_assignments'],
        'counts' => [
            'categories' => count($categories),
            'courses' => count($courses),
            'orphaned_courses' => $orphanedcourses,
            'enrolments' => array_sum(array_map(
                static fn(array $course): int => count($course['enrolments']),
                $courses
            )),
            'course_role_assignments' => array_sum(array_map(
                static fn(array $course): int => count($course['roles']),
                $courses
            )),
        ],
        'write_performed' => false,
    ];
    p5_write_json($output, $data);
    cli_writeln(
        'FASE6_SOURCE_INVENTORY_OK source=' . $sourceid .
        ' categories=' . count($categories) .
        ' courses=' . count($courses) .
        ' enrolments=' . $data['counts']['enrolments'] .
        ' roles=' . $data['counts']['course_role_assignments']
    );
} catch (Throwable $error) {
    cli_error('FASE6_SOURCE_INVENTORY_ERROR ' . $error->getMessage());
}
