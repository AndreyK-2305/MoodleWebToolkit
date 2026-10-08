<?php
// Ejecutar dentro del contenedor Moodle después del restore.
declare(strict_types=1);

define('CLI_SCRIPT', true);
require '/var/www/html/config.php';

global $CFG, $DB;
require_once $CFG->libdir . '/clilib.php';

[$options] = cli_get_params([
    'plan'=>'/exports/theme-plan.json',
    'assignments'=>'/exports/theme-assignments',
    'maps'=>'',
    'output'=>'/exports/theme-assignment-verification.json',
    'scope'=>'all',
], ['h'=>'help']);

function v8t_fail(string $message): never {
    fwrite(STDERR, 'V8_THEME_TRANSPORT_ERROR ' . $message . PHP_EOL);
    exit(1);
}
function v8t_json(string $path): array {
    if (!is_readable($path)) { v8t_fail("No se puede leer $path."); }
    $value = json_decode((string)file_get_contents($path), true);
    if (!is_array($value)) { v8t_fail("JSON inválido: $path."); }
    return $value;
}
function v8t_csv(string $path): array {
    if (!is_readable($path)) { v8t_fail("No se puede leer $path."); }
    $stream = fopen($path, 'rb');
    $headers = fgetcsv($stream, 0, ',', '"', '');
    if (!is_array($headers)) { fclose($stream); v8t_fail("CSV inválido: $path."); }
    $rows = [];
    while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if (count($values) !== count($headers)) {
            fclose($stream); v8t_fail("CSV irregular: $path.");
        }
        $rows[] = array_combine($headers, $values);
    }
    fclose($stream);
    return $rows;
}
function v8t_atomic_json(string $path, array $value): void {
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($temporary, $json, LOCK_EX) === false ||
            !rename($temporary, $path)) {
        @unlink($temporary); v8t_fail("No se pudo escribir $path.");
    }
}

$plan = v8t_json((string)$options['plan']);
if (($plan['assignment_policy'] ?? '') !== 'preserve_course_assignments' ||
        ($plan['theme_assignment_policy_sealed'] ?? false) !== true) {
    v8t_fail('El plan de themes no está sellado.');
}
$globaltheme = (string)($plan['global_theme'] ?? 'boost');
if ((string)($CFG->theme ?? '') !== $globaltheme ||
        empty($CFG->allowcoursethemes)) {
    v8t_fail('La configuración administrada no aplica theme global y themes por curso.');
}
$available = array_fill_keys(array_keys(core_component::get_plugin_list('theme')), true);
if (!isset($available[$globaltheme])) {
    v8t_fail("El theme global $globaltheme no está instalado.");
}
$planned = [];
foreach ($plan['site_themes'] ?? [] as $source => $_site) {
    $path = rtrim((string)$options['assignments'], '/') . '/' . $source .
        '/course-themes.csv';
    foreach (v8t_csv($path) as $row) {
        $key = $source . ':' . (string)$row['source_course_id'];
        if (isset($planned[$key])) { v8t_fail("Asignación repetida: $key."); }
        $planned[$key] = $row;
    }
}
$mapped = [];
foreach (array_filter(explode(',', (string)$options['maps'])) as $mapPath) {
    foreach (v8t_csv($mapPath) as $row) {
        $key = (string)($row['source'] ?? '') . ':' .
            (string)($row['source_course_id'] ?? '');
        $courseid = (int)($row['target_course_id'] ?? 0);
        if (!isset($planned[$key]) || $courseid < 1 ||
                (isset($mapped[$key]) && $mapped[$key] !== $courseid)) {
            v8t_fail("Mapeo de curso inválido: $key.");
        }
        $mapped[$key] = $courseid;
    }
}
ksort($mapped, SORT_STRING);
$counts = [
    'courses_without_explicit_theme'=>0, 'expected_explicit_course_themes'=>0,
    'preserved_by_restore'=>0, 'reapplied'=>0, 'fallback_to_global'=>0,
    'metadata_not_available'=>0, 'not_transportable'=>0,
    'warnings'=>0, 'failed_functional'=>0,
];
$results = [];
foreach ($mapped as $key => $courseid) {
    $row = $planned[$key];
    $course = $DB->get_record('course', ['id'=>$courseid], 'id,shortname,theme', MUST_EXIST);
    $original = (string)$row['original_theme'];
    $resolved = (string)$row['resolved_target_theme'];
    $warning = (string)$row['warning_code'];
    if (($row['resolution_state'] ?? '') === 'THEME_METADATA_NOT_AVAILABLE') {
        $counts['metadata_not_available']++;
        $counts['warnings']++;
        $state = 'THEME_METADATA_NOT_AVAILABLE';
        $warning = 'WARNING_LEGACY_THEME_METADATA_UNAVAILABLE';
    } elseif ($original === '') {
        $counts['courses_without_explicit_theme']++;
        $state = 'NO_EXPLICIT_THEME';
    } elseif (($row['resolution_state'] ?? '') === 'FALLBACK_TO_GLOBAL' ||
            !isset($available[$resolved])) {
        $counts['expected_explicit_course_themes']++;
        if ((string)$course->theme !== '') {
            $course->theme = '';
            $DB->update_record('course', $course);
        }
        $state = 'FALLBACK_TO_GLOBAL';
        $warning = 'WARNING_COURSE_THEME_NOT_APPLIED';
        $counts['fallback_to_global']++;
        $counts['not_transportable']++;
        $counts['warnings']++;
    } elseif ((string)$course->theme === $resolved) {
        $counts['expected_explicit_course_themes']++;
        $counts['preserved_by_restore']++;
        $state = 'PRESERVED_BY_RESTORE';
    } else {
        $counts['expected_explicit_course_themes']++;
        $course->theme = $resolved;
        $DB->update_record('course', $course);
        $check = $DB->get_field('course', 'theme', ['id'=>$courseid], MUST_EXIST);
        if ((string)$check !== $resolved) {
            $counts['failed_functional']++;
            $state = 'FAILED_FUNCTIONAL';
        } else {
            $counts['reapplied']++;
            $state = 'REAPPLIED';
        }
    }
    rebuild_course_cache($courseid, true);
    $results[] = [
        'source'=>(string)$row['source'],
        'source_course_id'=>(string)$row['source_course_id'],
        'target_course_id'=>$courseid, 'course_shortname'=>(string)$course->shortname,
        'original_theme'=>$original, 'resolved_target_theme'=>$resolved,
        'verification_state'=>$state, 'warning_code'=>$warning,
    ];
}
$result = [
    'schema_version'=>'1.0', 'scope'=>(string)$options['scope'],
    'status'=>$counts['failed_functional'] > 0 ? 'failed' :
        ($counts['warnings'] > 0 ? 'passed_with_warnings' : 'passed'),
    'theme_plan_sha256'=>hash_file('sha256', (string)$options['plan']),
    'global_theme'=>$globaltheme, 'allow_course_themes'=>true,
    'counts'=>$counts, 'results'=>$results,
    'destination_write_performed'=>true,
];
v8t_atomic_json((string)$options['output'], $result);
if ($counts['failed_functional'] > 0) { v8t_fail('Asignaciones funcionales fallidas.'); }
echo 'V8_THEME_ASSIGNMENTS_OK scope=' . $options['scope'] .
    ' courses=' . count($results) . ' warnings=' . $counts['fallback_to_global'] . PHP_EOL;
