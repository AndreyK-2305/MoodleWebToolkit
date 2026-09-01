<?php
// Fase 5: inventario de solo lectura del destino antes de planear el piloto.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'output' => '/exports/phase5/target_preflight.json',
        'configsha' => null,
        'targetid' => null,
        'categoryid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase5-target-inventory.php --phase4=/exports/phase4 --output=RUTA " .
        "--configsha=SHA256 --targetid=target --categoryid=1 [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $output = (string)$options['output'];
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $categoryid = (int)$options['categoryid'];
    $expectlab = (bool)(int)$options['expectlab'];
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) || $categoryid < 1) {
        throw new RuntimeException('targetid o categoryid inválido.');
    }
    $category = $DB->get_record(
        'course_categories',
        ['id' => $categoryid],
        'id,name,idnumber',
        MUST_EXIST
    );
    $capabilities = p5_target_capabilities();
    $contract = p5_load_phase4_contract(
        $phase4dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $targetusers = [];
    foreach ($contract['target_by_canonical'] as $canonicalid => $mapped) {
        $user = $DB->get_record(
            'user',
            ['id' => (int)$mapped['target_user_id'], 'deleted' => 0],
            'id,username,email,auth,firstaccess',
            MUST_EXIST
        );
        if (p5_norm((string)$user->username) !==
                p5_norm((string)$mapped['target_username']) ||
                p5_norm((string)$user->email) !==
                p5_norm((string)$mapped['target_email'])) {
            throw new RuntimeException(
                'El usuario destino de ' . $canonicalid . ' cambió después de la fase 4.'
            );
        }
        $targetusers[] = [
            'canonical_id' => $canonicalid,
            'target_user_id' => (int)$user->id,
            'username' => (string)$user->username,
            'email' => (string)$user->email,
            'auth' => (string)$user->auth,
            'firstaccess' => (int)$user->firstaccess,
        ];
    }
    $data = [
        'schema_version' => '1.0',
        'phase' => '5-target-preflight',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'target_wwwroot' => (string)$CFG->wwwroot,
        'target_moodle_version' => (string)get_config('moodle', 'version'),
        'target_moodle_release' => (string)get_config('moodle', 'release'),
        'pilot_category' => [
            'id' => (int)$category->id,
            'name' => (string)$category->name,
            'idnumber' => (string)$category->idnumber,
        ],
        'courses' => p5_target_courses(),
        'verified_target_users' => $targetusers,
        'available_roles' => $capabilities['roles'],
        'available_modules' => $capabilities['modules'],
        'write_performed' => false,
    ];
    p5_write_json($output, $data);
    cli_writeln(
        'FASE5_TARGET_PREFLIGHT_OK courses=' . count($data['courses']) .
        ' users=' . count($data['verified_target_users']) .
        ' roles=' . count($data['available_roles']) .
        ' modules=' . count($data['available_modules'])
    );
} catch (Throwable $error) {
    cli_error('FASE5_TARGET_PREFLIGHT_ERROR ' . $error->getMessage());
}
