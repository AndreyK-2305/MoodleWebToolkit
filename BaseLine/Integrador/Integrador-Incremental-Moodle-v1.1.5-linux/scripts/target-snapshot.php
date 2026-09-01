<?php
// Inventario masivo y de solo lectura del Moodle que ya está en producción.

declare(strict_types=1);
define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/admin/roles/lib.php');
require_once('/opt/integrator-v1/phase5-lib.php');
require_once('/opt/integrator-v1/phase6-lib.php');
require_once('/opt/integrator-v1/incremental-common.php');

try {
    $options = inc_cli_options(['output' => '', 'targetid' => 'target']);
    $output = (string)$options['output'];
    $targetid = inc_safe_component((string)$options['targetid'], 'targetid');
    if ($output === '' || !str_starts_with($output, '/exports/integrator/')) {
        throw new RuntimeException('La ruta de salida es inválida.');
    }

    $admins = [];
    foreach (get_admins() as $admin) {
        $admins[(int)$admin->id] = true;
    }
    $users = [];
    foreach ($DB->get_records_select(
        'user',
        'deleted = 0 AND username <> :guest',
        ['guest' => 'guest'],
        'id ASC',
        'id,username,email,auth,confirmed,suspended,firstname,lastname,firstaccess'
    ) as $user) {
        $users[] = [
            'id' => (int)$user->id,
            'username' => (string)$user->username,
            'email' => inc_norm((string)$user->email),
            'auth' => (string)$user->auth,
            'confirmed' => (int)$user->confirmed,
            'suspended' => (int)$user->suspended,
            'firstname' => (string)$user->firstname,
            'lastname' => (string)$user->lastname,
            'firstaccess' => (int)$user->firstaccess,
            'is_site_admin' => isset($admins[(int)$user->id]),
        ];
    }
    $courses = [];
    foreach ($DB->get_records_select(
        'course',
        'id <> :siteid',
        ['siteid' => SITEID],
        'id ASC',
        'id,category,fullname,shortname,idnumber,visible'
    ) as $course) {
        $courses[] = [
            'id' => (int)$course->id,
            'category' => (int)$course->category,
            'fullname' => (string)$course->fullname,
            'shortname' => (string)$course->shortname,
            'idnumber' => (string)$course->idnumber,
            'visible' => (int)$course->visible,
        ];
    }
    $categories = [];
    foreach ($DB->get_records(
        'course_categories',
        null,
        'depth ASC,id ASC',
        'id,parent,name,idnumber,visible,depth,path'
    ) as $category) {
        $categories[] = [
            'id' => (int)$category->id,
            'parent' => (int)$category->parent,
            'name' => (string)$category->name,
            'idnumber' => (string)$category->idnumber,
            'visible' => (int)$category->visible,
            'depth' => (int)$category->depth,
            'path' => (string)$category->path,
        ];
    }
    $plugins = [];
    foreach (core_plugin_manager::instance()->get_plugins() as $type => $instances) {
        foreach ($instances as $name => $plugin) {
            $component = (string)($plugin->component ?? ($type . '_' . $name));
            $plugins[] = [
                'component' => $component,
                'type' => (string)$type,
                'name' => (string)$name,
                'version_disk' => isset($plugin->versiondisk) ? (int)$plugin->versiondisk : null,
                'version_db' => isset($plugin->versiondb) ? (int)$plugin->versiondb : null,
                'source' => method_exists($plugin, 'is_standard') && $plugin->is_standard()
                    ? 'standard'
                    : 'additional',
            ];
        }
    }
    usort($plugins, static fn(array $a, array $b): int =>
        strcmp((string)$a['component'], (string)$b['component']));
    $modules = [];
    foreach ($DB->get_records('modules', null, 'name ASC', 'id,name,visible') as $module) {
        $modules[(string)$module->name] = (int)$module->visible;
    }
    $personalizadorole = p6_personalizado_role_status();
    $snapshot = [
        'schema_version' => INC_SCHEMA,
        'tool_version' => INC_VERSION,
        'phase' => 'target-snapshot',
        'generated_at_utc' => gmdate('c'),
        'target_id' => $targetid,
        'target_wwwroot' => (string)$CFG->wwwroot,
        'moodle_version' => (string)get_config('moodle', 'version'),
        'moodle_release' => (string)get_config('moodle', 'release'),
        'maintenance_enabled' => (int)get_config('core', 'maintenance_enabled'),
        'site_admin_ids' => array_map('intval', array_keys($admins)),
        'users' => $users,
        'courses' => $courses,
        'categories' => $categories,
        'plugins' => $plugins,
        'activity_modules' => $modules,
        'personalizado_role' => $personalizadorole,
        'counts' => [
            'users' => count($users),
            'courses' => count($courses),
            'categories' => count($categories),
            'plugins' => count($plugins),
        ],
        'write_performed' => false,
    ];
    inc_write_json($output, $snapshot);
    cli_writeln(
        'INCREMENTAL_TARGET_SNAPSHOT_OK users=' . count($users) .
        ' courses=' . count($courses) .
        ' categories=' . count($categories) .
        ' plugins=' . count($plugins) .
        ' write=0'
    );
} catch (Throwable $error) {
    cli_error('INCREMENTAL_TARGET_SNAPSHOT_ERROR ' . $error->getMessage());
}
