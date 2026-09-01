<?php
// Fase 6: inventario de solo lectura del destino y validación del piloto aprobado.

declare(strict_types=1);

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/consolidator/phase5-lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'phase4' => '/exports/phase4',
        'phase5' => '/exports/phase5',
        'output' => '/exports/phase6/target_inventory.json',
        'configsha' => null,
        'targetid' => null,
        'parentcategoryid' => null,
        'expectlab' => 0,
        'help' => false,
    ],
    ['h' => 'help']
);
if ($options['help']) {
    cli_writeln(
        "Uso: php phase6-target-inventory.php --phase4=/exports/phase4 " .
        "--phase5=/exports/phase5 --output=RUTA --configsha=SHA256 " .
        "--targetid=target --parentcategoryid=1 [--expectlab=1]\n"
    );
    exit(0);
}
if ($unrecognized) {
    cli_error('Opciones no reconocidas: ' . implode(', ', $unrecognized));
}

try {
    $phase4dir = rtrim((string)$options['phase4'], '/\\');
    $phase5dir = rtrim((string)$options['phase5'], '/\\');
    $output = trim((string)$options['output']);
    $configsha = p5_require_sha256((string)$options['configsha'], 'configsha');
    $targetid = p5_norm((string)$options['targetid']);
    $parentcategoryid = (int)$options['parentcategoryid'];
    $expectlab = (bool)(int)$options['expectlab'];
    if ($output === '' ||
            !preg_match('/^[a-z][a-z0-9_-]*$/', $targetid) ||
            $parentcategoryid < 1) {
        throw new RuntimeException('Los parámetros del inventario destino son inválidos.');
    }
    $outputdir = dirname($output);
    if (!is_dir($outputdir) &&
            !mkdir($outputdir, 0770, true) &&
            !is_dir($outputdir)) {
        throw new RuntimeException('No fue posible crear el directorio del inventario.');
    }

    $parentcategory = $DB->get_record(
        'course_categories',
        ['id' => $parentcategoryid],
        'id,parent,name,idnumber,depth,path',
        MUST_EXIST
    );
    $phase5bundle = p5_load_plan(
        $phase4dir,
        $phase5dir,
        $configsha,
        $targetid,
        $expectlab
    );
    $applypath = $phase5dir . '/apply_summary.json';
    $mappath = $phase5dir . '/pilot_course_map.csv';
    $verificationpath = $phase5dir . '/verification.json';
    $verificationcsvpath = $phase5dir . '/verification.csv';
    $targetcourseinventorypath = $phase5dir . '/target_course_inventory.json';
    $apply = p5_read_json($applypath);
    $verification = p5_read_json($verificationpath);
    $coursemap = p5_read_csv($mappath);
    if (count($coursemap) !== 1) {
        throw new RuntimeException('La evidencia de fase 5 no identifica un único curso piloto.');
    }
    if (($apply['config_sha256'] ?? '') !== $configsha ||
            ($apply['target_id'] ?? '') !== $targetid ||
            ($apply['plan_summary_sha256'] ?? '') !==
                hash_file('sha256', $phase5dir . '/plan_summary.json') ||
            ($apply['normalized_backup_sha256'] ?? '') !==
                $phase5bundle['hashes']['normalized_backup.mbz'] ||
            ($apply['pilot_course_map_sha256'] ?? '') !== hash_file('sha256', $mappath) ||
            ($apply['apply_performed'] ?? null) !== true) {
        throw new RuntimeException('La aplicación aprobada de fase 5 perdió integridad.');
    }
    if (($verification['config_sha256'] ?? '') !== $configsha ||
            ($verification['target_id'] ?? '') !== $targetid ||
            ($verification['plan_summary_sha256'] ?? '') !==
                hash_file('sha256', $phase5dir . '/plan_summary.json') ||
            ($verification['apply_summary_sha256'] ?? '') !== hash_file('sha256', $applypath) ||
            ($verification['pilot_course_map_sha256'] ?? '') !== hash_file('sha256', $mappath) ||
            ($verification['target_course_inventory_sha256'] ?? '') !==
                hash_file('sha256', $targetcourseinventorypath) ||
            ($verification['verification_csv_sha256'] ?? '') !==
                hash_file('sha256', $verificationcsvpath) ||
            ($verification['validation'] ?? '') !== 'passed' ||
            (int)($verification['failed_checks'] ?? -1) !== 0) {
        throw new RuntimeException('La fase 5 no conserva una verificación aprobada e íntegra.');
    }
    if ($expectlab && ($verification['lab_validation'] ?? '') !== 'passed') {
        throw new RuntimeException('La verificación LAB de fase 5 no está aprobada.');
    }

    $pilotcourseid = (int)$coursemap[0]['target_course_id'];
    if ($pilotcourseid < 1 ||
            $pilotcourseid !== (int)$apply['target_course_id'] ||
            $pilotcourseid !== (int)$verification['target_course_id']) {
        throw new RuntimeException('El ID del piloto no coincide entre las evidencias.');
    }
    $pilotcourse = $DB->get_record(
        'course',
        ['id' => $pilotcourseid],
        'id,category,fullname,shortname,idnumber',
        MUST_EXIST
    );
    if ((string)$pilotcourse->idnumber !==
            (string)$phase5bundle['summary']['target_course_marker']) {
        throw new RuntimeException('El curso piloto perdió su marcador aprobado.');
    }

    $contract = $phase5bundle['phase4'];
    $verifiedtargetusers = [];
    foreach ($contract['target_by_canonical'] as $canonicalid => $mapped) {
        $user = $DB->get_record(
            'user',
            ['id' => (int)$mapped['target_user_id'], 'deleted' => 0],
            'id,username,email,auth',
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
        $verifiedtargetusers[] = [
            'canonical_id' => (string)$canonicalid,
            'target_user_id' => (int)$user->id,
            'target_username' => (string)$user->username,
            'target_email' => (string)$user->email,
            'target_auth' => (string)$user->auth,
        ];
    }

    $categories = [];
    foreach ($DB->get_records(
        'course_categories',
        null,
        'depth ASC, id ASC',
        'id,parent,name,idnumber,depth,path,visible,sortorder'
    ) as $category) {
        $categories[] = [
            'target_category_id' => (int)$category->id,
            'target_parent_id' => (int)$category->parent,
            'name' => (string)$category->name,
            'idnumber' => (string)$category->idnumber,
            'depth' => (int)$category->depth,
            'path' => (string)$category->path,
            'visible' => (int)$category->visible,
            'sortorder' => (int)$category->sortorder,
        ];
    }
    $capabilities = p5_target_capabilities();
    $siteadmins = [];
    foreach (get_admins() as $admin) {
        $siteadmins[] = [
            'target_user_id' => (int)$admin->id,
            'target_username' => (string)$admin->username,
            'target_email' => (string)$admin->email,
        ];
    }
    $data = [
        'schema_version' => '1.0',
        'phase' => '6-target-inventory',
        'generated_at_utc' => gmdate('c'),
        'config_sha256' => $configsha,
        'target_id' => $targetid,
        'target_wwwroot' => (string)$CFG->wwwroot,
        'target_moodle_version' => (string)get_config('moodle', 'version'),
        'target_moodle_release' => (string)get_config('moodle', 'release'),
        'target_parent_category' => [
            'id' => (int)$parentcategory->id,
            'parent' => (int)$parentcategory->parent,
            'name' => (string)$parentcategory->name,
            'idnumber' => (string)$parentcategory->idnumber,
            'depth' => (int)$parentcategory->depth,
            'path' => (string)$parentcategory->path,
        ],
        'categories' => $categories,
        'courses' => p5_target_courses(),
        'verified_target_users' => $verifiedtargetusers,
        'available_roles' => $capabilities['roles'],
        'available_modules' => $capabilities['modules'],
        'site_administrators' => $siteadmins,
        'approved_phase5_pilot' => [
            'source_id' => (string)$phase5bundle['summary']['source_id'],
            'source_course_id' => (int)$phase5bundle['summary']['source_course_id'],
            'source_course_idnumber' =>
                (string)$phase5bundle['summary']['source_course_idnumber'],
            'source_shortname' => (string)$phase5bundle['summary']['source_shortname'],
            'target_course_id' => $pilotcourseid,
            'target_course_marker' => (string)$pilotcourse->idnumber,
            'verification_status' => 'passed',
        ],
        'phase4_input_sha256' => $contract['hashes'],
        'phase5_evidence_sha256' => [
            'plan_summary.json' => hash_file('sha256', $phase5dir . '/plan_summary.json'),
            'apply_summary.json' => hash_file('sha256', $applypath),
            'pilot_course_map.csv' => hash_file('sha256', $mappath),
            'target_course_inventory.json' =>
                hash_file('sha256', $targetcourseinventorypath),
            'verification.csv' => hash_file('sha256', $verificationcsvpath),
            'verification.json' => hash_file('sha256', $verificationpath),
        ],
        'write_performed' => false,
    ];
    p5_write_json($output, $data);
    cli_writeln(
        'FASE6_TARGET_INVENTORY_OK pilot_course_id=' . $pilotcourseid .
        ' categories=' . count($categories) .
        ' courses=' . count($data['courses']) .
        ' users=' . count($verifiedtargetusers) .
        ' roles=' . count($data['available_roles']) .
        ' modules=' . count($data['available_modules'])
    );
} catch (Throwable $error) {
    cli_error('FASE6_TARGET_INVENTORY_ERROR ' . $error->getMessage());
}
