<?php
// Cierre de evidencia: verifica solo el lote nuevo y protege el destino previo.

declare(strict_types=1);
define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once('/opt/integrator-v1/phase5-lib.php');
require_once('/opt/integrator-v1/incremental-common.php');
require_once('/opt/integrator-v1/incremental-apply-lib.php');

function inc_canonical_row_set(array $rows): array {
    $canonical = static function (mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $canonical($item);
        }
        return $value;
    };
    $encoded = [];
    foreach ($rows as $row) {
        $encoded[] = json_encode(
            $canonical($row),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
    sort($encoded, SORT_STRING);
    return $encoded;
}

function inc_canonical_projected_row_set(array $rows, array $keys): array {
    sort($keys, SORT_STRING);
    $projected = [];
    foreach ($rows as $row) {
        $value = [];
        foreach ($keys as $key) {
            $value[$key] = $row[$key] ?? null;
        }
        $projected[] = $value;
    }
    return inc_canonical_row_set($projected);
}

function inc_compare_rows_by_sealed_schema(array $sealed, array $current): array {
    $keys = [];
    foreach ($sealed as $row) {
        foreach (array_keys($row) as $key) {
            $keys[(string)$key] = true;
        }
    }
    $fields = array_keys($keys);
    sort($fields, SORT_STRING);
    return [
        'matches' => count($sealed) === count($current) &&
            inc_canonical_projected_row_set($sealed, $fields) ===
                inc_canonical_projected_row_set($current, $fields),
        'fields' => $fields,
    ];
}

function inc_verify_physical_course_files(int $courseid): array {
    global $DB;

    $records = $DB->get_records_sql(
        'SELECT f.id, f.component, f.filearea, f.contenthash, f.filesize
           FROM {files} f
           JOIN {context} ctx
             ON ctx.id = f.contextid AND ctx.contextlevel = :modulelevel
           JOIN {course_modules} cm ON cm.id = ctx.instanceid
          WHERE cm.course = :courseid AND f.filename <> :dot
       ORDER BY f.id',
        [
            'modulelevel' => CONTEXT_MODULE,
            'courseid' => $courseid,
            'dot' => '.',
        ]
    );
    $filestorage = get_file_storage();
    $checked = 0;
    $checkedbytes = 0;
    $failures = [];
    foreach ($records as $record) {
        if (p5_is_regenerable_editpdf_file([
                'component' => (string)$record->component,
                'filearea' => (string)$record->filearea,
            ])) {
            continue;
        }
        $expectedhash = (string)$record->contenthash;
        $expectedbytes = (int)$record->filesize;
        if (preg_match('/^[a-f0-9]{40}$/', $expectedhash) !== 1) {
            $failures[] = 'fileid=' . (int)$record->id . ': contenthash inválido';
            continue;
        }
        try {
            $file = $filestorage->get_file_by_id((int)$record->id);
            if (!$file) {
                $failures[] = 'fileid=' . (int)$record->id . ': blob ausente';
                continue;
            }
            $handle = $file->get_content_file_handle();
            if (!is_resource($handle)) {
                $failures[] = 'fileid=' . (int)$record->id . ': no se pudo abrir';
                continue;
            }
            $hashcontext = hash_init('sha1');
            $actualbytes = hash_update_stream($hashcontext, $handle);
            fclose($handle);
            $actualhash = hash_final($hashcontext);
        } catch (Throwable $error) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            $failures[] = 'fileid=' . (int)$record->id .
                ': lectura fallida (' . get_class($error) . ')';
            continue;
        }
        if (!is_int($actualbytes) || $actualbytes !== $expectedbytes ||
                !hash_equals($expectedhash, $actualhash)) {
            $failures[] = 'fileid=' . (int)$record->id .
                ': tamaño o SHA-1 físico diferente';
            continue;
        }
        $checked++;
        $checkedbytes += $actualbytes;
    }
    return [
        'complete' => $failures === [],
        'files_checked' => $checked,
        'bytes_checked' => $checkedbytes,
        'failures' => $failures,
    ];
}

try {
    $options = inc_cli_options(['workdir' => '']);
    $workdir = inc_safe_workdir((string)$options['workdir']);
    $plan = inc_load_plan($workdir);
    $categorymap = inc_load_category_map($workdir, true);
    $categorymapintegrity = (string)($categorymap['_integrity_mode'] ?? 'unknown');
    $issues = [];
    $courses = [];
    foreach ($plan['courses'] as $courseplan) {
        $coursekey = (string)$courseplan['course_key'];
        $checkpointpath = $workdir . '/checkpoints/checkpoint-' .
            strtolower($coursekey) . '.json';
        if (!is_readable($checkpointpath)) {
            $issues[] = $coursekey . ': falta checkpoint.';
            continue;
        }
        $checkpoint = inc_read_json($checkpointpath);
        $targetcourseid = (int)($checkpoint['target_course_id'] ?? 0);
        $course = $DB->get_record(
            'course',
            ['id' => $targetcourseid],
            'id,category,fullname,shortname,idnumber,visible',
            IGNORE_MISSING
        );
        if (!$course || ($checkpoint['status'] ?? '') !== 'applied' ||
                ($checkpoint['plan_sha256'] ?? '') !== (string)$plan['plan_sha256'] ||
                ($checkpoint['package_sha256'] ?? '') !== (string)$plan['package_sha256'] ||
                (string)($course->idnumber ?? '') !== (string)$courseplan['target_marker'] ||
                inc_norm((string)($course->fullname ?? '')) !==
                    inc_norm((string)$courseplan['target_fullname']) ||
                inc_norm((string)($course->shortname ?? '')) !==
                    inc_norm((string)$courseplan['target_shortname']) ||
                (int)($course->category ?? 0) !==
                    (int)($checkpoint['target_category_id'] ?? 0) ||
                (int)($course->visible ?? 1) !== 0) {
            $issues[] = $coursekey . ': curso o checkpoint inconsistente.';
            continue;
        }
        $inventorypath = $workdir . '/inventories/target-' .
            strtolower($coursekey) . '.json';
        if (!is_readable($inventorypath) ||
                !inc_is_sha256($checkpoint['target_inventory_sha256'] ?? null) ||
                !hash_equals(
                    (string)$checkpoint['target_inventory_sha256'],
                    (string)hash_file('sha256', $inventorypath)
                )) {
            $issues[] = $coursekey . ': el inventario sellado perdió integridad.';
            continue;
        }
        $storedinventory = inc_read_json($inventorypath);
        $currentinventory = p5_collect_course_inventory($targetcourseid);
        $comparison = p5_compare_course_inventories(
            $storedinventory,
            $currentinventory
        );
        $contentissues = [];
        if (($comparison['complete'] ?? false) !== true) {
            $contentissues[] = 'conteos o módulos';
        }
        foreach (['enrolments', 'roles'] as $section) {
            if (inc_canonical_row_set($storedinventory[$section] ?? []) !==
                    inc_canonical_row_set($currentinventory[$section] ?? [])) {
                $contentissues[] = $section;
            }
        }
        foreach ([
            'assignment_submissions',
            'assignment_grades',
            'forum_discussions',
            'forum_posts',
            'quiz_attempts',
            'activity_completions',
            'course_completions',
        ] as $relation) {
            if (inc_canonical_row_set(
                    $storedinventory['relations'][$relation] ?? []
                ) !== inc_canonical_row_set(
                    $currentinventory['relations'][$relation] ?? []
                )) {
                $contentissues[] = 'relations.' . $relation;
            }
        }
        $storedfiles = p5_filter_comparable_files(
            $storedinventory['relations']['files'] ?? []
        );
        $currentfiles = p5_filter_comparable_files(
            $currentinventory['relations']['files'] ?? []
        );
        $filecomparison = inc_compare_rows_by_sealed_schema(
            $storedfiles,
            $currentfiles
        );
        $legacymissingfields = [];
        foreach (['bytes', 'content_sha1'] as $field) {
            foreach ($storedfiles as $filerow) {
                if (!array_key_exists($field, $filerow)) {
                    $legacymissingfields[] = $field;
                    break;
                }
            }
        }
        $physicalfileaudit = inc_verify_physical_course_files($targetcourseid);
        $filecontenthashesverified =
            ($filecomparison['matches'] ?? false) === true &&
            ($physicalfileaudit['complete'] ?? false) === true &&
            (int)($physicalfileaudit['files_checked'] ?? 0) ===
                count($currentfiles) &&
            count($currentfiles) > 0;
        if (($filecomparison['matches'] ?? false) !== true) {
            $contentissues[] = 'relations.files';
        }
        if (($physicalfileaudit['complete'] ?? false) !== true ||
                (int)($physicalfileaudit['files_checked'] ?? 0) !==
                    count($currentfiles)) {
            $contentissues[] = 'physical_file_hashes';
        }
        if ($contentissues) {
            $issues[] = $coursekey . ': cambió el contenido verificado (' .
                implode(', ', $contentissues) . ').';
            continue;
        }
        $courses[] = [
            'course_key' => $coursekey,
            'source_course_id' => (int)$courseplan['source_course_id'],
            'target_course_id' => $targetcourseid,
            'fullname' => (string)$course->fullname,
            'shortname' => (string)$course->shortname,
            'category_id' => (int)$course->category,
            'visible' => false,
            'checkpoint_sha256' => hash_file('sha256', $checkpointpath),
            'target_inventory_sha256' =>
                (string)($checkpoint['target_inventory_sha256'] ?? ''),
            'content_reverified' => true,
            'academic_relations_verified' =>
                $checkpoint['academic_relations_verified'] ?? [],
            'academic_course_fields_verified' =>
                $checkpoint['academic_course_fields_verified'] ?? [],
            'file_content_hashes_verified' =>
                $filecontenthashesverified,
            'file_inventory_comparison_mode' => $legacymissingfields
                ? 'legacy_projected_sealed_fields'
                : 'complete_sealed_fields',
            'file_inventory_fields_compared' =>
                $filecomparison['fields'] ?? [],
            'file_inventory_legacy_missing_fields' => $legacymissingfields,
            'physical_file_hashes_recomputed' =>
                (bool)($physicalfileaudit['complete'] ?? false),
            'physical_file_hash_algorithm' => 'sha1',
            'physical_files_checked' =>
                (int)($physicalfileaudit['files_checked'] ?? 0),
            'physical_file_bytes_checked' =>
                (int)($physicalfileaudit['bytes_checked'] ?? 0),
            'physical_file_hash_failures' =>
                $physicalfileaudit['failures'] ?? [],
            'verified_counts' => $currentinventory['counts'] ?? [],
        ];
    }

    $snapshotpath = $workdir . '/target-snapshot.json';
    if (!is_readable($snapshotpath) ||
            !inc_is_sha256($plan['target_snapshot_sha256'] ?? null) ||
            !hash_equals(
                (string)$plan['target_snapshot_sha256'],
                (string)hash_file('sha256', $snapshotpath)
            )) {
        throw new RuntimeException('El inventario base del destino perdió integridad.');
    }
    $snapshot = inc_read_json($snapshotpath);
    $baselinecoursechanges = [];
    foreach ($snapshot['courses'] ?? [] as $before) {
        $current = $DB->get_record(
            'course',
            ['id' => (int)$before['id']],
            'id,category,fullname,shortname,idnumber,visible',
            IGNORE_MISSING
        );
        $fields = ['category', 'fullname', 'shortname', 'idnumber', 'visible'];
        $changed = !$current;
        if ($current) {
            foreach ($fields as $field) {
                if ((string)$current->{$field} !== (string)$before[$field]) {
                    $changed = true;
                    break;
                }
            }
        }
        if ($changed) {
            $baselinecoursechanges[] = (int)$before['id'];
        }
    }
    if ($baselinecoursechanges) {
        $issues[] = 'Uno o más cursos que ya existían cambiaron: ' .
            implode(',', $baselinecoursechanges) . '.';
    }

    $snapshotusers = [];
    foreach ($snapshot['users'] ?? [] as $user) {
        $snapshotusers[(int)$user['id']] = $user;
    }
    $baselineuserchanges = [];
    foreach ($plan['identities_by_email'] as $identity) {
        $targetuserid = (int)($identity['target_user_id'] ?? 0);
        if (($identity['action'] ?? '') !== 'reuse_existing_by_email' ||
                isset($baselineuserchanges[$targetuserid])) {
            continue;
        }
        $before = $snapshotusers[$targetuserid] ?? null;
        $current = $DB->get_record(
            'user',
            ['id' => $targetuserid, 'deleted' => 0],
            'id,username,email,auth,confirmed,suspended,firstname,lastname',
            IGNORE_MISSING
        );
        $changed = !$before || !$current;
        if (!$changed) {
            foreach (['username', 'email', 'auth', 'confirmed', 'suspended', 'firstname', 'lastname'] as $field) {
                $currentvalue = $field === 'email'
                    ? inc_norm((string)$current->{$field})
                    : (string)$current->{$field};
                if ($currentvalue !== (string)$before[$field]) {
                    $changed = true;
                    break;
                }
            }
        }
        if ($changed) {
            $baselineuserchanges[$targetuserid] = true;
        }
    }
    if ($baselineuserchanges) {
        $issues[] = 'Uno o más perfiles destino reutilizados cambiaron: ' .
            implode(',', array_keys($baselineuserchanges)) . '.';
    }

    $currentadmins = array_map('intval', array_keys(get_admins()));
    sort($currentadmins, SORT_NUMERIC);
    $expectedadmins = array_map('intval', $plan['target_site_admin_ids_before']);
    sort($expectedadmins, SORT_NUMERIC);
    if ($currentadmins !== $expectedadmins) {
        $issues[] = 'La lista global de siteadmins cambió durante la integración.';
    }
    $parentid = (int)$categorymap['parent_category_id'];
    $parent = $DB->get_record(
        'course_categories',
        ['id' => $parentid],
        'id,parent,name,idnumber,visible',
        IGNORE_MISSING
    );
    if (!$parent ||
            (string)$parent->idnumber !==
                (string)$plan['parent_category']['marker'] ||
            inc_norm((string)$parent->name) !==
                inc_norm((string)$plan['parent_category']['name']) ||
            (int)$parent->parent !== 0) {
        $issues[] = 'La categoría padre del lote no conserva marcador, nombre o ubicación.';
    } else if ((int)$parent->visible !== 0) {
        $issues[] = 'La categoría padre del lote dejó de estar oculta.';
    }
    $plannedcategories = [];
    foreach ($plan['categories'] ?? [] as $plannedcategory) {
        $plannedcategories[(string)(int)$plannedcategory['source_category_id']] =
            $plannedcategory;
    }
    $mappedcategorykeys = array_map(
        static fn(mixed $value): string => (string)(int)$value,
        array_keys($categorymap['categories'] ?? [])
    );
    $plannedcategorykeys = array_map(
        static fn(mixed $value): string => (string)(int)$value,
        array_keys($plannedcategories)
    );
    sort($mappedcategorykeys, SORT_STRING);
    sort($plannedcategorykeys, SORT_STRING);
    if ($mappedcategorykeys !== $plannedcategorykeys) {
        $issues[] = 'El mapa de categorías no contiene exactamente las categorías del plan.';
    }
    foreach ($categorymap['categories'] ?? [] as $sourcecategoryid => $mapped) {
        $sourcekey = (string)(int)$sourcecategoryid;
        $planned = $plannedcategories[$sourcekey] ?? null;
        $expectedparentid = $parentid;
        if ($planned && (int)($planned['source_parent_id'] ?? 0) > 0) {
            $sourceparentkey = (string)(int)$planned['source_parent_id'];
            $expectedparentid = (int)(
                $categorymap['categories'][$sourceparentkey]['target_category_id'] ?? 0
            );
        }
        $category = $DB->get_record(
            'course_categories',
            ['id' => (int)($mapped['target_category_id'] ?? 0)],
            'id,parent,name,idnumber,visible',
            IGNORE_MISSING
        );
        if (!$planned ||
                (string)($mapped['marker'] ?? '') !==
                    (string)($planned['marker'] ?? '') ||
                inc_norm((string)($mapped['name'] ?? '')) !==
                    inc_norm((string)($planned['name'] ?? '')) ||
                (int)($mapped['target_parent_id'] ?? 0) !== $expectedparentid ||
                !$category ||
                (string)$category->idnumber !== (string)($planned['marker'] ?? '') ||
                inc_norm((string)$category->name) !==
                    inc_norm((string)($planned['name'] ?? '')) ||
                (int)$category->parent !== $expectedparentid ||
                (int)$category->visible !== 0) {
            $issues[] = 'La categoría importada ' . $sourcecategoryid .
                ' no conserva el plan, marcador, nombre, padre o visibilidad.';
        }
    }
    $managerroleid = (int)$DB->get_field(
        'role',
        'id',
        ['shortname' => 'manager'],
        MUST_EXIST
    );
    $parentcontext = $parent
        ? context_coursecat::instance($parentid, IGNORE_MISSING)
        : false;
    $adminmanagers = [];
    foreach ($plan['source_site_admin_ids'] as $sourceuserid) {
        if (!$parentcontext) {
            $issues[] = 'No se puede comprobar el manager del administrador de origen ' .
                $sourceuserid . ' porque falta el contexto de la categoría padre.';
            continue;
        }
        $identity = inc_identity_plan_for_source_user($plan, (int)$sourceuserid);
        $matches = inc_active_users_by_email((string)$identity['email']);
        if (count($matches) !== 1) {
            $issues[] = 'No se pudo resolver el administrador de origen ' .
                $sourceuserid . ' por correo.';
            continue;
        }
        $target = reset($matches);
        $ismanager = $DB->record_exists('role_assignments', [
            'roleid' => $managerroleid,
            'contextid' => (int)$parentcontext->id,
            'userid' => (int)$target->id,
        ]);
        if (!$ismanager) {
            $issues[] = 'El administrador de origen ' . $sourceuserid .
                ' no es manager de la categoría del lote.';
        }
        $adminmanagers[] = [
            'source_user_id' => (int)$sourceuserid,
            'target_user_id' => (int)$target->id,
            'target_email' => (string)$target->email,
            'category_manager' => $ismanager,
            'global_site_admin' => in_array((int)$target->id, $currentadmins, true),
        ];
    }

    $report = [
        'schema_version' => INC_SCHEMA,
        'tool_version' => INC_VERSION,
        'phase' => 'incremental-final-verification',
        'generated_at_utc' => gmdate('c'),
        'status' => $issues ? 'error' : 'completed_hidden',
        'plan_sha256' => (string)$plan['plan_sha256'],
        'package_sha256' => (string)$plan['package_sha256'],
        'source_id' => (string)$plan['source_id'],
        'source_name' => (string)$plan['source_name'],
        'batch_id' => (string)$plan['batch_id'],
        'target_wwwroot' => (string)$plan['target_wwwroot'],
        'parent_category_id' => $parentid,
        'parent_category_name' => (string)($parent->name ?? ''),
        'parent_category_visible' => (bool)($parent->visible ?? false),
        'category_map_integrity' => $categorymapintegrity,
        'legacy_category_map_revalidated' =>
            $categorymapintegrity === 'legacy_unsigned_live_revalidation',
        'courses_expected' => count($plan['courses']),
        'courses_verified' => count($courses),
        'courses' => $courses,
        'source_admin_category_managers' => $adminmanagers,
        'site_admin_ids_before' => $expectedadmins,
        'site_admin_ids_after' => $currentadmins,
        'site_admins_added' => count(array_diff($currentadmins, $expectedadmins)),
        'existing_target_courses_modified' => $baselinecoursechanges !== [],
        'existing_target_course_changes' => $baselinecoursechanges,
        'reused_target_profiles_modified' => $baselineuserchanges !== [],
        'reused_target_profile_changes' => array_map(
            'intval',
            array_keys($baselineuserchanges)
        ),
        'issues' => $issues,
        'publication_performed' => false,
    ];
    inc_write_json($workdir . '/final-report.json', $report);
    if ($issues) {
        throw new RuntimeException(
            'La verificación final detectó ' . count($issues) .
            ' incumplimiento(s). Revise final-report.json.'
        );
    }
    cli_writeln(
        'INCREMENTAL_INTEGRATION_OK source=' . $plan['source_id'] .
        ' courses=' . count($courses) .
        ' parent_category_id=' . $parentid .
        ' visible=0 siteadmins_added=0 report=' . $workdir . '/final-report.json'
    );
} catch (Throwable $error) {
    cli_error('INCREMENTAL_FINAL_VERIFY_ERROR ' . $error->getMessage());
}
