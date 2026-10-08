<?php

declare(strict_types=1);

define('COLLECTOR_SOURCE_EXPORT_LIBRARY_ONLY', true);
require_once(__DIR__ . '/../scripts/source-export.php');

function resume_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/collector-theme-resume-' . bin2hex(random_bytes(6));
foreach (['cursos', 'inventarios', 'checkpoints'] as $directory) {
    if (!mkdir($root . '/' . $directory, 0770, true) && !is_dir($root . '/' . $directory)) {
        throw new RuntimeException('No fue posible crear fixture temporal.');
    }
}
try {
    resume_assert(export_theme_inventory_needs_refresh([]),
        'TEST RETRY: inventario sin schema debe enriquecerse');
    resume_assert(export_theme_inventory_needs_refresh([
        'themes' => [
            'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
            'inventory_complete' => false,
        ],
        'theme_assignments' => [
            'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
            'inventory_complete' => true,
        ],
    ]), 'TEST RETRY: themes incompletos deben volver a enriquecerse');
    resume_assert(export_theme_inventory_needs_refresh([
        'themes' => [
            'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
            'inventory_complete' => true,
        ],
        'theme_assignments' => [
            'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
            'inventory_complete' => false,
        ],
    ]), 'TEST RETRY: assignments incompletos deben volver a enriquecerse');
    resume_assert(!export_theme_inventory_needs_refresh([
        'themes' => [
            'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
            'inventory_complete' => true,
        ],
        'theme_assignments' => [
            'schema_version' => COLLECTOR_THEME_SCHEMA_VERSION,
            'inventory_complete' => true,
        ],
    ]), 'TEST RETRY: inventario completo no debe enriquecerse otra vez');

    $sourceid = 'fixture';
    $courseid = 7216;
    $coursekey = export_course_key($sourceid, $courseid);
    $basename = strtolower($coursekey);
    $backup = $root . '/cursos/' . $basename . '.mbz';
    $inventorypath = $root . '/inventarios/inventory-' . $basename . '.json';
    $checkpointpath = $root . '/checkpoints/checkpoint-' . $basename . '.json';
    file_put_contents($backup, 'MBZ-INMUTABLE');
    $inventory = [
        'source_change_epoch' => 123,
        'course' => [
            'source_course_id' => $courseid,
            'shortname' => 'FIXTURE',
            'idnumber' => 'FIX-1',
        ],
        'counts' => ['activities' => 0],
        'modules_by_type' => [],
        'modules' => [],
        'enrolments' => [],
        'roles' => [],
        'relations' => [],
    ];
    $statehash = collector_theme_sha256($inventory);
    export_atomic_json($inventorypath, [
        'schema_version' => '1.0',
        'package_type' => 'moodle-consolidation-course-inventory',
        'source_id' => $sourceid,
        'course_key' => $coursekey,
        'source_state_sha256' => $statehash,
        'inventory' => $inventory,
        'write_performed' => false,
    ]);
    clearstatcache(true, $backup);
    clearstatcache(true, $inventorypath);
    $runfingerprint = str_repeat('a', 64);
    export_atomic_json($checkpointpath, [
        'schema_version' => '1.0',
        'package_type' => 'moodle-consolidation-source-course',
        'collector_version' => '7.4.1-linux',
        'source_id' => $sourceid,
        'course_key' => $coursekey,
        'source_course_id' => $courseid,
        'source_state_sha256' => $statehash,
        'backup_file' => 'cursos/' . $basename . '.mbz',
        'backup_sha256' => hash_file('sha256', $backup),
        'backup_bytes' => filesize($backup),
        'backup_mtime' => filemtime($backup),
        'inventory_file' => 'inventarios/inventory-' . $basename . '.json',
        'inventory_sha256' => hash_file('sha256', $inventorypath),
        'inventory_bytes' => filesize($inventorypath),
        'inventory_mtime' => filemtime($inventorypath),
        'run_fingerprint' => $runfingerprint,
        'status' => 'prepared',
    ]);
    $backupsha = hash_file('sha256', $backup);
    $backupmtime = filemtime($backup);

    resume_assert(export_enrich_course_theme_metadata(
        $root,
        $sourceid,
        ['source_course_id' => $courseid, 'theme' => 'almondb'],
        $runfingerprint
    ), 'TEST J: no se enriqueció el checkpoint 7.4.1');
    resume_assert(hash_file('sha256', $backup) === $backupsha, 'TEST J: se alteró el MBZ');
    resume_assert(filemtime($backup) === $backupmtime, 'TEST J: cambió mtime del MBZ');
    $document = export_read_json($inventorypath);
    $checkpoint = export_read_json($checkpointpath);
    resume_assert($document['inventory']['course']['theme'] === 'almondb', 'Theme individual ausente');
    resume_assert($document['source_state_sha256'] === $statehash, 'Cambió fingerprint académico');
    resume_assert($checkpoint['source_state_sha256'] === $statehash, 'Cambió checkpoint académico');
    resume_assert($checkpoint['collector_version'] === '7.4.2-linux', 'Checkpoint no migrado');
    resume_assert(
        export_fast_checkpoint($root, $sourceid, $courseid, $runfingerprint) !== null,
        'Resume rápido dejó de reconocer el checkpoint enriquecido'
    );
    resume_assert(!export_enrich_course_theme_metadata(
        $root,
        $sourceid,
        ['source_course_id' => $courseid, 'theme' => 'almondb'],
        $runfingerprint
    ), 'El enriquecimiento no es idempotente');
    fwrite(STDOUT, "RESUME_THEME_METADATA_OK\n");
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
