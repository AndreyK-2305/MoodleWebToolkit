<?php
// Rehidratación conservadora del precheck de Moodle para Fase 6.

declare(strict_types=1);

defined('MOODLE_INTERNAL') || die();

function p6_restore_precheck_message_count(mixed $messages): int {
    if ($messages === null || $messages === false || $messages === '') {
        return 0;
    }
    if (!is_array($messages)) {
        return 1;
    }
    $count = 0;
    foreach ($messages as $message) {
        if (is_array($message)) {
            $count += p6_restore_precheck_message_count($message);
        } else if ($message !== null && $message !== false && $message !== '') {
            $count++;
        }
    }
    return $count;
}

function p6_restore_precheck_issue_summary(mixed $result): array {
    if (!is_array($result)) {
        return [
            'errors' => p6_restore_precheck_message_count($result),
            'warnings' => 0,
        ];
    }
    return [
        'errors' => p6_restore_precheck_message_count($result['errors'] ?? []),
        'warnings' => p6_restore_precheck_message_count($result['warnings'] ?? []),
    ];
}

/** Clasifica el resultado sin confundir warnings con errores restaurables. */
function p6_restore_precheck_decision(bool $ok, mixed $result): array {
    $summary = p6_restore_precheck_issue_summary($result);
    if ($summary['errors'] > 0) {
        $mode = 'block';
    } else if ($ok) {
        $mode = 'ready';
    } else if ($summary['warnings'] > 0) {
        $mode = 'rehydrate';
    } else {
        $mode = 'inconsistent';
    }
    return $summary + ['mode' => $mode];
}

/**
 * Recrea exactamente las evidencias temporales consumidas por execute_plan().
 * El callback se ejecuta después del precheck de usuarios para que Fase 6
 * pueda fijar/adoptar mappings históricos antes de roles y preguntas.
 */
function p6_restore_rehydrate_tempids(
    object $controller,
    int $courseid,
    int $userid,
    callable $afterusers
): array {
    if ($courseid < 1 || $userid < 1) {
        throw new RuntimeException('MOODLE_PRECHECK_REHYDRATION_INVALID');
    }
    $restoreid = (string)$controller->get_restoreid();
    $plan = $controller->get_plan();
    $basepath = rtrim((string)$plan->get_basepath(), '/\\');
    if ($restoreid === '' || !is_dir($basepath)) {
        throw new RuntimeException('MOODLE_PRECHECK_REHYDRATION_INVALID');
    }
    restore_controller_dbops::create_restore_temp_tables($restoreid);
    restore_dbops::reset_backup_ids_cached();

    $progress = $controller->get_progress();
    $inforefs = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basepath, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile() && $item->getFilename() === 'inforef.xml') {
            $inforefs[] = $item->getPathname();
        }
    }
    sort($inforefs, SORT_STRING);
    if (!$inforefs) {
        throw new RuntimeException('MOODLE_PRECHECK_REHYDRATION_INFOREF_MISSING');
    }
    foreach ($inforefs as $inforef) {
        restore_dbops::load_inforef_to_tempids(
            $restoreid,
            $inforef,
            $progress
        );
    }

    $info = $controller->get_info();
    $samesite = backup_general_helper::backup_is_samesite($info);
    $userspath = $basepath . '/users.xml';
    if (!is_readable($userspath)) {
        throw new RuntimeException('MOODLE_PRECHECK_REHYDRATION_USERS_MISSING');
    }
    restore_dbops::load_users_to_tempids($restoreid, $userspath, $progress);
    $userissues = restore_dbops::precheck_included_users(
        $restoreid,
        $courseid,
        $userid,
        $samesite,
        $progress
    );
    $usersummary = p6_restore_precheck_issue_summary($userissues);
    if ($usersummary['errors'] > 0) {
        throw new RuntimeException(
            'MOODLE_PRECHECK_REHYDRATED_USER_ERRORS count=' .
            $usersummary['errors'] . '.'
        );
    }
    $afterusers($restoreid);

    $rolesummary = ['errors' => 0, 'warnings' => 0];
    $rolespath = $basepath . '/roles.xml';
    if (is_readable($rolespath)) {
        restore_dbops::load_roles_to_tempids($restoreid, $rolespath);
        if (!isset($info->role_mappings) ||
                !is_object($info->role_mappings) ||
                !property_exists($info->role_mappings, 'modified') ||
                !property_exists($info->role_mappings, 'mappings') ||
                !is_array($info->role_mappings->mappings)) {
            throw new RuntimeException(
                'MOODLE_PRECHECK_REHYDRATION_ROLE_MAPPINGS_INVALID'
            );
        }
        $roleissues = restore_dbops::precheck_included_roles(
            $restoreid,
            $courseid,
            $userid,
            $samesite,
            $info->role_mappings
        );
        $rolesummary = p6_restore_precheck_issue_summary($roleissues);
        if ($rolesummary['errors'] > 0) {
            throw new RuntimeException(
                'MOODLE_PRECHECK_REHYDRATED_ROLE_ERRORS count=' .
                $rolesummary['errors'] . '.'
            );
        }
    }

    $questionsummary = ['errors' => 0, 'warnings' => 0];
    $questionspath = $basepath . '/questions.xml';
    if (is_readable($questionspath)) {
        $activitiespath = $basepath . '/activities';
        restore_dbops::load_questionbanks_to_tempids(
            $restoreid,
            $activitiespath
        );
        restore_dbops::load_categories_and_questions_to_tempids(
            $restoreid,
            $questionspath
        );
        $questionissues = restore_dbops::precheck_categories_and_questions(
            $restoreid,
            $courseid,
            $userid,
            $samesite
        );
        $questionsummary = p6_restore_precheck_issue_summary($questionissues);
        if ($questionsummary['errors'] > 0) {
            throw new RuntimeException(
                'MOODLE_PRECHECK_REHYDRATED_QUESTION_ERRORS count=' .
                $questionsummary['errors'] . '.'
            );
        }
    }

    return [
        'restore_id' => $restoreid,
        'inforef_files' => count($inforefs),
        'user_warnings' => $usersummary['warnings'],
        'role_warnings' => $rolesummary['warnings'],
        'question_warnings' => $questionsummary['warnings'],
    ];
}

/** Cleanup idempotente para excepciones posteriores a la rehidratación. */
function p6_restore_drop_rehydrated_tempids(string $restoreid): void {
    global $DB;
    if ($restoreid === '') {
        return;
    }
    $failure = null;
    try {
        restore_controller_dbops::drop_restore_temp_tables($restoreid);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        restore_dbops::reset_backup_ids_cached();
    }
    if ($failure !== null) {
        $manager = $DB->get_manager();
        if ($manager->table_exists('backup_ids_temp') ||
                $manager->table_exists('backup_files_temp')) {
            throw new RuntimeException(
                'MOODLE_PRECHECK_TEMP_TABLE_CLEANUP_FAILED: ' .
                $failure->getMessage(),
                0,
                $failure
            );
        }
    }
}
