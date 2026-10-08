<?php

use auth_oauth2\linked_login;
use core\oauth2\issuer;
use core\session\manager;

// This initializer can reach only the fresh isolated moodle_lab service.
define('CLI_SCRIPT', true);
define('CACHE_DISABLE_ALL', true);
$fixtureStage = 'bootstrap';
$fixtureErrorClass = null;
$fixtureErrorCode = null;
$fixtureErrorColumn = null;
register_shutdown_function(function () use (&$fixtureStage, &$fixtureErrorClass, &$fixtureErrorCode, &$fixtureErrorColumn): void {
    $last = error_get_last();
    file_put_contents('/tmp/toolkit-synthetic-fixture/diagnostic.json', json_encode([
        'stage' => $fixtureStage, 'error_class' => $fixtureErrorClass, 'error_code' => $fixtureErrorCode,
        'php_error_type' => $last['type'] ?? null,
        'php_error_file' => isset($last['file']) ? basename($last['file']) : null,
        'php_error_line' => $last['line'] ?? null,
        'invalid_column' => $fixtureErrorColumn,
    ], JSON_THROW_ON_ERROR));
});
if (count($argv) !== 2 || ! str_starts_with($argv[1], '/tmp/toolkit-synthetic-fixture/')) {
    exit(2);
}
require $argv[1];
require_once $CFG->libdir.'/clilib.php';
require_once $CFG->libdir.'/installlib.php';
require_once $CFG->libdir.'/adminlib.php';
require_once $CFG->libdir.'/componentlib.class.php';
set_exception_handler(function (Throwable $error) use (&$fixtureErrorClass, &$fixtureErrorCode, &$fixtureErrorColumn): void {
    $fixtureErrorClass = get_class($error);
    $candidate = $error instanceof moodle_exception ? $error->errorcode : null;
    $fixtureErrorCode = is_string($candidate) && preg_match('/^[a-z_]{1,100}$/D', $candidate) === 1 ? $candidate : null;
    if ($error instanceof dml_exception && preg_match('/column "([a-z_]{1,64})"/', $error->debuginfo ?? '', $match) === 1) {
        $fixtureErrorColumn = $match[1];
    }
    exit(1);
});
$marker = json_decode(file_get_contents('/opt/moodle-lab/.moodle-toolkit-synthetic-lab.json'), true, flags: JSON_THROW_ON_ERROR);
if ($CFG->dbhost !== 'moodle-lab-db' || $CFG->dbname !== 'moodle_lab' || $CFG->wwwroot !== 'http://moodle-lab.test'
    || ($marker['schema_version'] ?? null) !== 'synthetic-moodle.v1' || ! preg_match('/^[a-f0-9]{32}$/D', $marker['fixture_id'] ?? '')) {
    exit(2);
}
if ($DB->get_tables()) {
    if (get_config(null, 'local_toolkit_fixture_id') !== $marker['fixture_id']) {
        exit(3);
    }
    exit(0);
}
$CFG->early_install_lang = true;
$fixtureStage = 'installation';
$CFG->lang = 'en';
get_string_manager(true);
$CFG->early_install_lang = false;
get_string_manager(true);
install_cli_database(['lang' => 'en', 'adminuser' => 'fixture-admin', 'adminpass' => bin2hex(random_bytes(24)).'aA1!',
    'adminemail' => 'admin@moodle-lab.test', 'fullname' => 'Synthetic Moodle LAB', 'shortname' => 'SYNTHETIC-LAB',
    'summary' => '[LAB-MIGRATION] Synthetic fixture', 'supportemail' => 'support@moodle-lab.test'], false);
$CFG->debug = 0;
$fixtureStage = 'configuration';
$CFG->debugdisplay = false;
set_config('allowcoursethemes', 1);
set_config('allowuserthemes', 1);
set_config('theme', 'boost');
set_config('enablecompletion', 1);
require_once $CFG->dirroot.'/user/lib.php';
require_once $CFG->dirroot.'/course/lib.php';
require_once $CFG->dirroot.'/course/modlib.php';
manager::set_user(get_admin());
$category = core_course_category::create(['name' => '[LAB-MIGRATION] Synthetic courses', 'parent' => 0]);
$users = [];
$fixtureStage = 'users';
for ($index = 1; $index <= 3; $index++) {
    $users[] = user_create_user((object) ['username' => 'synthetic'.$index, 'auth' => 'nologin', 'confirmed' => 1,
        'firstname' => 'Synthetic', 'lastname' => 'User '.$index, 'email' => 'user'.$index.'@moodle-lab.test',
        'description' => '[LAB-MIGRATION] synthetic account', 'descriptionformat' => FORMAT_PLAIN,
        'theme' => $index === 1 ? 'classic' : '', 'mnethostid' => $CFG->mnet_localhost_id], false, false);
}
$fixtureStage = 'oauth';
$issuer = new issuer(0, (object) ['name' => 'Synthetic OAuth LAB', 'baseurl' => 'https://oauth-lab.test',
    'clientid' => 'synthetic-client', 'clientsecret' => '', 'enabled' => 1, 'showonloginpage' => 0,
    'image' => '', 'scopessupported' => '', 'servicetype' => '', 'loginpagename' => '', 'systememail' => '']);
$issuer->create();
$linkedLogin = new linked_login(0, (object) ['userid' => $users[0], 'issuerid' => $issuer->get('id'),
    'username' => 'synthetic-subject-1', 'email' => 'user1@moodle-lab.test', 'confirmtoken' => '', 'confirmtokenexpires' => 0]);
$linkedLogin->create();
$student = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$fixtureStage = 'courses';
for ($index = 1; $index <= 2; $index++) {
    $course = create_course((object) ['category' => $category->id, 'shortname' => 'SYNTHETIC-'.$index,
        'fullname' => '[LAB-MIGRATION] Synthetic course '.$index, 'format' => 'topics', 'numsections' => 1,
        'theme' => $index === 1 ? 'classic' : '', 'enablecompletion' => 1]);
    foreach ($users as $userId) {
        enrol_try_internal_enrol($course->id, $userId, $student);
    }
    $common = ['course' => $course->id, 'section' => 1, 'visible' => 1, 'cmidnumber' => '', 'groupmode' => 0,
        'groupingid' => 0, 'intro' => 'Synthetic content', 'introformat' => FORMAT_HTML];
    add_moduleinfo((object) [...$common, 'module' => $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST), 'modulename' => 'page', 'name' => 'Synthetic page',
        'content' => '<p>Small reproducible synthetic resource.</p>', 'contentformat' => FORMAT_HTML,
        'display' => 0, 'printintro' => 1, 'printheading' => 1, 'printlastmodified' => 0], $course);
    $module = add_moduleinfo((object) [...$common, 'module' => $DB->get_field('modules', 'id', ['name' => 'folder'], MUST_EXIST), 'modulename' => 'folder', 'name' => 'Small synthetic file',
        'display' => 0, 'showexpanded' => 1, 'showdownloadfolder' => 1, 'forcedownload' => 1, 'files' => 0], $course);
    get_file_storage()->create_file_from_string(['contextid' => context_module::instance($module->coursemodule)->id,
        'component' => 'mod_folder', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'synthetic.txt'],
        'Synthetic fixture file '.$index."\n");
}
purge_all_caches();
set_config('local_toolkit_fixture_id', $marker['fixture_id']);
$fixtureStage = 'ready';
exit(0);
