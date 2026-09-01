<?php
// Materializa usuarios necesarios, categoría oculta y jerarquía del lote.

declare(strict_types=1);
define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once('/opt/integrator-v1/phase5-lib.php');
require_once('/opt/integrator-v1/phase6-lib.php');
require_once('/opt/integrator-v1/incremental-common.php');
require_once('/opt/integrator-v1/incremental-apply-lib.php');

try {
    $options = inc_cli_options(['workdir' => '']);
    $workdir = inc_safe_workdir((string)$options['workdir']);
    $plan = inc_load_plan($workdir);
    $lockfactory = \core\lock\lock_config::get_lock_factory('inc_v1_base');
    $lock = $lockfactory->get_lock('batch-' . hash('sha256', $plan['batch_id']), 5);
    if (!$lock) {
        throw new RuntimeException('Otra ejecución prepara el mismo lote.');
    }
    try {
        $currentadmins = array_map('intval', array_keys(get_admins()));
        sort($currentadmins, SORT_NUMERIC);
        $expectedadmins = array_map('intval', $plan['target_site_admin_ids_before']);
        sort($expectedadmins, SORT_NUMERIC);
        if ($currentadmins !== $expectedadmins) {
            throw new RuntimeException(
                'La lista global de siteadmins cambió desde el preflight.'
            );
        }

        $initialsourceids = [];
        foreach ($plan['identities_by_email'] as $identity) {
            if (($identity['materialize_initially'] ?? false) === true) {
                $initialsourceids = array_merge(
                    $initialsourceids,
                    array_map('intval', $identity['source_user_ids'])
                );
            }
        }
        $initialsourceids = array_values(array_unique($initialsourceids));
        sort($initialsourceids, SORT_NUMERIC);
        $users = inc_materialize_source_users($plan, $initialsourceids);

        $personalizado = p6_ensure_personalizado_role();
        $parentplan = $plan['parent_category'];
        $matches = $DB->get_records(
            'course_categories',
            ['idnumber' => (string)$parentplan['marker']],
            'id ASC'
        );
        if (count($matches) > 1) {
            throw new RuntimeException('El destino repite la categoría padre del lote.');
        }
        $parentaction = 'reused';
        if (count($matches) === 1) {
            $parent = reset($matches);
            if ((int)$parent->parent !== 0 ||
                    inc_norm((string)$parent->name) !==
                        inc_norm((string)$parentplan['name'])) {
                throw new RuntimeException(
                    'La categoría padre marcada cambió de nombre o ubicación.'
                );
            }
            $parentid = (int)$parent->id;
        } else {
            $parent = core_course_category::create([
                'name' => (string)$parentplan['name'],
                'idnumber' => (string)$parentplan['marker'],
                'parent' => 0,
                'visible' => 0,
            ]);
            $parentid = (int)$parent->id;
            $parentaction = 'created_hidden';
        }

        $ids = [];
        $rows = [];
        foreach ($plan['categories'] as $categoryplan) {
            $sourcecategoryid = (int)$categoryplan['source_category_id'];
            $sourceparentid = (int)$categoryplan['source_parent_id'];
            $targetparentid = $sourceparentid > 0
                ? (int)($ids[$sourceparentid] ?? 0)
                : $parentid;
            if ($targetparentid < 1) {
                throw new RuntimeException(
                    'No se resolvió el padre de la categoría ' . $sourcecategoryid . '.'
                );
            }
            $matches = $DB->get_records(
                'course_categories',
                ['idnumber' => (string)$categoryplan['marker']],
                'id ASC'
            );
            if (count($matches) > 1) {
                throw new RuntimeException(
                    'El destino repite el marcador de la categoría ' . $sourcecategoryid . '.'
                );
            }
            $action = 'reused';
            if (count($matches) === 1) {
                $category = reset($matches);
                if ((int)$category->parent !== $targetparentid ||
                        inc_norm((string)$category->name) !==
                            inc_norm((string)$categoryplan['name'])) {
                    throw new RuntimeException(
                        'La categoría marcada ' . $sourcecategoryid .
                        ' cambió de nombre o padre.'
                    );
                }
                $targetcategoryid = (int)$category->id;
            } else {
                $category = core_course_category::create([
                    'name' => (string)$categoryplan['name'],
                    'idnumber' => (string)$categoryplan['marker'],
                    'parent' => $targetparentid,
                    'visible' => 0,
                ]);
                $targetcategoryid = (int)$category->id;
                $action = 'created_hidden';
            }
            $ids[$sourcecategoryid] = $targetcategoryid;
            $rows[(string)$sourcecategoryid] = [
                'source_category_id' => $sourcecategoryid,
                'target_category_id' => $targetcategoryid,
                'target_parent_id' => $targetparentid,
                'marker' => (string)$categoryplan['marker'],
                'name' => (string)$categoryplan['name'],
                'action' => $action,
            ];
        }

        $managerrole = $DB->get_record(
            'role',
            ['shortname' => 'manager'],
            'id,shortname',
            MUST_EXIST
        );
        $parentcontext = context_coursecat::instance($parentid);
        $adminassignments = [];
        foreach ($plan['source_site_admin_ids'] as $sourceadminid) {
            $identity = inc_identity_plan_for_source_user($plan, (int)$sourceadminid);
            $target = inc_ensure_target_user($identity, (string)$plan['source_id']);
            role_assign(
                (int)$managerrole->id,
                (int)$target['target_user_id'],
                (int)$parentcontext->id,
                '',
                0
            );
            $adminassignments[] = [
                'source_user_id' => (int)$sourceadminid,
                'target_user_id' => (int)$target['target_user_id'],
                'target_email' => (string)$target['target_email'],
                'target_role' => 'manager',
                'target_context' => 'batch_parent_category',
                'global_site_admin_granted' => false,
            ];
        }
        $afteradmins = array_map('intval', array_keys(get_admins()));
        sort($afteradmins, SORT_NUMERIC);
        if ($afteradmins !== $expectedadmins) {
            throw new RuntimeException(
                'La aplicación alteró indebidamente la lista global de siteadmins.'
            );
        }

        $categorydocument = [
            'schema_version' => INC_SCHEMA,
            'tool_version' => INC_VERSION,
            'phase' => 'apply-base',
            'generated_at_utc' => gmdate('c'),
            'plan_sha256' => (string)$plan['plan_sha256'],
            'batch_id' => (string)$plan['batch_id'],
            'parent_category_id' => $parentid,
            'parent_category_name' => (string)$parentplan['name'],
            'parent_category_marker' => (string)$parentplan['marker'],
            'parent_action' => $parentaction,
            'parent_visible' => (bool)$DB->get_field(
                'course_categories',
                'visible',
                ['id' => $parentid]
            ),
            'categories' => $rows,
            'personalizado_role' => [
                'id' => (int)$personalizado['role_id'],
                'action' => (string)$personalizado['action'],
                'safe' => (bool)$personalizado['safe'],
            ],
            'source_admin_category_managers' => $adminassignments,
            'site_admin_ids_before' => $expectedadmins,
            'site_admin_ids_after' => $afteradmins,
            'status' => 'applied',
        ];
        $categorydocument['category_map_sha256'] = inc_document_hash(
            $categorydocument,
            'category_map_sha256'
        );
        inc_write_json($workdir . '/category-map.json', $categorydocument);
        inc_write_json($workdir . '/user-materialization-initial.json', [
            'schema_version' => INC_SCHEMA,
            'tool_version' => INC_VERSION,
            'generated_at_utc' => gmdate('c'),
            'plan_sha256' => (string)$plan['plan_sha256'],
            'source_users_considered' => count($initialsourceids),
            'identities_materialized' => count($users),
            'users' => $users,
        ]);
        cli_writeln(
            'INCREMENTAL_BASE_OK users=' . count($users) .
            ' parent_category_id=' . $parentid .
            ' categories=' . count($rows) .
            ' source_admin_managers=' . count($adminassignments) .
            ' siteadmins_added=0'
        );
    } finally {
        $lock->release();
    }
} catch (Throwable $error) {
    cli_error('INCREMENTAL_BASE_ERROR ' . $error->getMessage());
}
