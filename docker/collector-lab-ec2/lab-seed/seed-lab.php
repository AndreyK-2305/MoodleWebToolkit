<?php

use core\session\manager;
use core_question\local\bank\question_version_status;
use mod_quiz\quiz_settings;

// Representative synthetic content for the two EC2 laboratory sources. The
// expected identity and category outcomes are in docs/LAB-ORIGENES-SINTETICOS.md.
// Idempotent: a second run keeps what exists. --reset deletes and recreates only
// what this seeder created; --remove only deletes it; --status only reads.
// Refuses any site that is not the synthetic laboratory (marker + fixture id).
define('CLI_SCRIPT', true);

const LAB_SEED_VERSION = 'lab-seed.v1';
const LAB_SEED_PLUGIN = 'mwt_labseed';
const LAB_SEED_TAG = '[LAB-SEED]';
const LAB_SEED_PREFIX = 'LABSEED-';

$options = ['variant' => '', 'config' => '/opt/moodle-lab/code/config.php',
    'marker' => '/opt/moodle-lab/.moodle-toolkit-synthetic-lab.json', 'mode' => 'seed'];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(variant|config|marker)=(.+)$/D', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } elseif (in_array($argument, ['--reset', '--remove', '--status'], true) && $options['mode'] === 'seed') {
        $options['mode'] = substr($argument, 2);
    } else {
        fwrite(STDERR, "usage: seed-lab.php --variant=pregrado|posgrado [--reset|--remove|--status]\n");
        exit(2);
    }
}
if (! in_array($options['variant'], ['pregrado', 'posgrado'], true)) {
    fwrite(STDERR, "LAB_SEED_ERROR variant must be pregrado or posgrado\n");
    exit(2);
}

require $options['config'];
require_once $CFG->libdir.'/clilib.php';
require_once $CFG->dirroot.'/user/lib.php';
require_once $CFG->dirroot.'/course/lib.php';
require_once $CFG->dirroot.'/mod/assign/locallib.php';
require_once $CFG->dirroot.'/mod/quiz/locallib.php';
require_once $CFG->libdir.'/testing/generator/lib.php';
// Only in memory: nothing seeded here may send mail.
$CFG->noemailever = true;

function lab_seed_fail(string $code, string $detail = ''): never
{
    fwrite(STDERR, 'LAB_SEED_ERROR '.$code.($detail === '' ? '' : ' '.$detail)."\n");
    exit(1);
}

set_exception_handler(function (Throwable $error): void {
    $code = $error instanceof moodle_exception ? $error->errorcode : get_class($error);
    lab_seed_fail('exception', $code.': '.$error->getMessage());
});

// Same guard as seed-moodle.php: the laboratory marker and the fixture id it
// wrote into this database. A restored institutional copy never carries both.
$marker = is_readable($options['marker']) ? json_decode((string) file_get_contents($options['marker']), true) : null;
if (! is_array($marker) || ($marker['schema_version'] ?? null) !== 'synthetic-moodle.v1'
    || ! preg_match('/^[a-f0-9]{32}$/D', (string) ($marker['fixture_id'] ?? ''))
    || get_config(null, 'local_toolkit_fixture_id') !== $marker['fixture_id']) {
    lab_seed_fail('not_a_laboratory_site');
}

/**
 * The two sources share people on purpose. Each user carries its identity case
 * (docs/LAB-ORIGENES-SINTETICOS.md) in the description, after the seeder tag.
 */
function lab_seed_spec(string $variant): array
{
    $shared = 'uni-lab.example';
    if ($variant === 'pregrado') {
        $domain = 'pregrado.uni-lab.example';

        return [
            'categories' => [
                ['ING', 'Ingeniería', null],
                ['CB', 'Ciencias Básicas', null],
                ['CB-MAT', 'Matemáticas', 'CB'],
                ['HUM', 'Humanidades', null],
            ],
            'users' => [
                ['ana.garcia', 'Ana', 'García Torres', 'ana.garcia@'.$shared, 'CC-1001', 'ID-A mismo username y correo'],
                ['laura.gomez', 'Laura', 'Gómez Silva', 'laura.gomez@'.$shared, 'CC-1006', 'ID-B mismo correo, otro username'],
                ['carlos.ramirez', 'Carlos', 'Ramírez Díaz', 'carlos.ramirez@'.$domain, 'CC-1002', 'ID-C mismo nombre, otro correo'],
                ['sofia.martinez', 'Sofía', 'Martínez Rojas', 'sofia.martinez@'.$domain, '', 'ID-D variante de tilde y errata'],
                ['juan.perez', 'Juan', 'Pérez Gómez', 'juan.perez@'.$domain, 'CC-1004', 'ID-E homónimo, otra persona'],
                ['prueba.pregrado', 'Usuario', 'Prueba', 'prueba.pregrado@'.$shared, '', 'ID-F cuenta de prueba'],
                ['luis.moreno', 'Luis', 'Moreno Vargas', 'luis.moreno@'.$domain, 'CC-1007', 'solo pregrado'],
                ['valentina.rios', 'Valentina', 'Ríos Cárdenas', 'valentina.rios@'.$domain, 'CC-1008', 'solo pregrado'],
                ['diego.salazar', 'Diego', 'Salazar Pineda', 'diego.salazar@'.$domain, 'CC-1009', 'solo pregrado'],
                ['camila.ortiz', 'Camila', 'Ortiz Mejía', 'camila.ortiz@'.$domain, 'CC-1010', 'solo pregrado'],
            ],
            // code, shortname, fullname, category, teachers, students (the first ones carry the activity).
            'courses' => [
                ['ING-101', 'ING-101', 'Introducción a la Programación', 'ING', ['ana.garcia'],
                    ['laura.gomez', 'carlos.ramirez', 'sofia.martinez', 'juan.perez', 'valentina.rios']],
                ['ING-201', 'ING-201', 'Bases de Datos', 'ING', ['ana.garcia'],
                    ['sofia.martinez', 'diego.salazar', 'camila.ortiz', 'prueba.pregrado', 'laura.gomez']],
                ['MAT-101', 'MAT-101', 'Cálculo Diferencial', 'CB-MAT', ['luis.moreno'],
                    ['carlos.ramirez', 'juan.perez', 'diego.salazar', 'valentina.rios', 'camila.ortiz']],
                ['MAT-102', 'MAT-102', 'Álgebra Lineal', 'CB-MAT', ['luis.moreno', 'ana.garcia'],
                    ['juan.perez', 'laura.gomez', 'sofia.martinez', 'camila.ortiz', 'prueba.pregrado']],
                ['HUM-101', 'HUM-101', 'Comunicación Oral y Escrita', 'HUM', ['luis.moreno'],
                    ['valentina.rios', 'carlos.ramirez', 'diego.salazar', 'laura.gomez', 'juan.perez']],
                ['HUM-102', 'HUM-102', 'Ética Profesional', 'HUM', ['luis.moreno'],
                    ['camila.ortiz', 'sofia.martinez', 'valentina.rios', 'diego.salazar', 'carlos.ramirez']],
            ],
        ];
    }
    $domain = 'posgrado.uni-lab.example';

    return [
        'categories' => [
            ['ING', 'Ingeniería', null],
            ['CB', 'Ciencias basicas', null],
            ['CB-EST', 'Estadística', 'CB'],
            ['EDU', 'Educación', null],
        ],
        'users' => [
            ['ana.garcia', 'Ana', 'García Torres', 'ana.garcia@'.$shared, 'CC-1001', 'ID-A mismo username y correo'],
            ['lgomez', 'Laura', 'Gómez Silva', 'laura.gomez@'.$shared, 'CC-1006', 'ID-B mismo correo, otro username'],
            ['cramirez', 'Carlos', 'Ramírez Díaz', 'c.ramirez@'.$domain, 'CC-1002', 'ID-C mismo nombre, otro correo'],
            ['smartines', 'Sofia', 'Martines Rojas', 'sofia.martines@'.$domain, '', 'ID-D variante de tilde y errata'],
            ['jperez', 'Juan', 'Pérez Gómez', 'jperez@'.$domain, 'CC-2004', 'ID-E homónimo, otra persona'],
            ['prueba.posgrado', 'Usuario', 'Prueba', 'prueba.posgrado@'.$shared, '', 'ID-F cuenta de prueba'],
            ['patricia.navarro', 'Patricia', 'Navarro Quintero', 'patricia.navarro@'.$domain, 'CC-2007', 'solo posgrado'],
            ['andres.castillo', 'Andrés', 'Castillo Ruiz', 'andres.castillo@'.$domain, 'CC-2008', 'solo posgrado'],
            ['daniela.vega', 'Daniela', 'Vega Montoya', 'daniela.vega@'.$domain, 'CC-2009', 'solo posgrado'],
            ['felipe.arias', 'Felipe', 'Arias Londoño', 'felipe.arias@'.$domain, 'CC-2010', 'solo posgrado'],
        ],
        'courses' => [
            // Same shortname as in pregrado, different course: the target must not collide.
            ['ING-101', 'ING-101', 'Introducción a la Programación', 'ING', ['ana.garcia'],
                ['lgomez', 'smartines', 'jperez', 'andres.castillo', 'daniela.vega']],
            ['ING-501', 'ING-501', 'Arquitectura de Software', 'ING', ['cramirez'],
                ['jperez', 'felipe.arias', 'andres.castillo', 'prueba.posgrado', 'lgomez']],
            ['EST-501', 'EST-501', 'Estadística Avanzada', 'CB-EST', ['cramirez'],
                ['smartines', 'daniela.vega', 'felipe.arias', 'lgomez', 'jperez']],
            ['EST-502', 'EST-502', 'Diseño de Experimentos', 'CB-EST', ['patricia.navarro'],
                ['andres.castillo', 'smartines', 'daniela.vega', 'prueba.posgrado', 'felipe.arias']],
            ['EDU-501', 'EDU-501', 'Didáctica Universitaria', 'EDU', ['patricia.navarro', 'ana.garcia'],
                ['lgomez', 'jperez', 'daniela.vega', 'andres.castillo', 'smartines']],
            ['EDU-502', 'EDU-502', 'Evaluación del Aprendizaje', 'EDU', ['patricia.navarro'],
                ['felipe.arias', 'smartines', 'jperez', 'andres.castillo', 'daniela.vega']],
        ],
    ];
}

// Courses and categories share the prefix; --reset and --remove select by it.
function lab_seed_idnumber(string $variant, string $code): string
{
    return LAB_SEED_PREFIX.strtoupper(substr($variant, 0, 3)).'-'.$code;
}

function lab_seed_manifest(): ?array
{
    $raw = get_config(LAB_SEED_PLUGIN, 'manifest');

    return is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
}

function lab_seed_save_manifest(array $manifest): void
{
    set_config('manifest', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LAB_SEED_PLUGIN);
}

function lab_seed_user(array $spec): int
{
    global $CFG, $DB;
    [$username, $firstname, $lastname, $email, $idnumber, $case] = $spec;
    $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
    if ($existing) {
        // Never adopt an account this seeder did not create.
        if (! str_starts_with((string) $existing->description, LAB_SEED_TAG)) {
            lab_seed_fail('username_taken', $username);
        }

        return (int) $existing->id;
    }

    // Manual accounts: Consolidador V8 reconciles them by username. The random
    // password is never printed; nobody signs in with these accounts.
    return (int) user_create_user((object) ['username' => $username, 'auth' => 'manual', 'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id, 'password' => bin2hex(random_bytes(16)).'aA1!',
        'firstname' => $firstname, 'lastname' => $lastname, 'email' => $email, 'idnumber' => $idnumber,
        'city' => 'Bucaramanga', 'country' => 'CO',
        'description' => LAB_SEED_TAG.' '.$case, 'descriptionformat' => FORMAT_PLAIN], true, false);
}

function lab_seed_category(string $variant, array $spec, array $ids): int
{
    global $DB;
    [$code, $name, $parent] = $spec;
    $idnumber = lab_seed_idnumber($variant, $code);
    $existing = $DB->get_field('course_categories', 'id', ['idnumber' => $idnumber]);
    if ($existing) {
        return (int) $existing;
    }

    return (int) core_course_category::create(['name' => $name, 'idnumber' => $idnumber,
        'parent' => $parent === null ? 0 : $ids[$parent],
        'description' => LAB_SEED_TAG.' Categoría sintética', 'descriptionformat' => FORMAT_PLAIN])->id;
}

/**
 * Two questions saved through the question type API. The core_question data
 * generator is not used here: it loads PHPUnit helpers that a source cloned
 * from git does not have.
 */
function lab_seed_questions(int $bank): array
{
    $feedback = fn (string $text): array => ['text' => $text, 'format' => FORMAT_HTML];
    $forms = [
        'truefalse' => ['name' => 'Verdadero o falso', 'questiontext' => $feedback('Moodle es una plataforma de aprendizaje.'),
            'correctanswer' => '1', 'feedbacktrue' => $feedback('Correcto.'), 'feedbackfalse' => $feedback('Incorrecto.'),
            'penalty' => 1],
        'shortanswer' => ['name' => 'Respuesta corta', 'questiontext' => $feedback('¿Qué lenguaje usa Moodle en el servidor?'),
            'usecase' => 0, 'answer' => ['PHP', '*'], 'fraction' => ['1.0', '0.0'],
            'feedback' => [$feedback('Correcto.'), $feedback('Revise la guía de la unidad.')], 'penalty' => 0.3333333],
    ];
    $ids = [];
    foreach ($forms as $type => $form) {
        $question = (object) ['qtype' => $type, 'createdby' => 0, 'idnumber' => null,
            'status' => question_version_status::QUESTION_STATUS_READY];
        $form = (object) ($form + ['category' => $bank, 'defaultmark' => 1, 'generalfeedback' => $feedback(''),
            'status' => question_version_status::QUESTION_STATUS_READY]);
        $ids[] = (int) question_bank::get_qtype($type)->save_question($question, $form)->id;
    }

    return $ids;
}

/**
 * Creates one course with its sections, core activities, enrolments and the
 * user activity that makes the export carry user data.
 */
function lab_seed_course(testing_data_generator $generator, string $variant, array $spec, array $categories, array $users): void
{
    global $DB, $USER;
    [$code, $shortname, $fullname, $category, $teachers, $students] = $spec;
    $course = create_course((object) ['category' => $categories[$category], 'shortname' => $shortname,
        'fullname' => $fullname, 'idnumber' => lab_seed_idnumber($variant, $code), 'format' => 'topics',
        'numsections' => 3, 'summary' => '<p>'.LAB_SEED_TAG.' Curso sintético de '.$variant.'.</p>',
        'summaryformat' => FORMAT_HTML, 'enablecompletion' => 1, 'startdate' => make_timestamp(2026, 2, 2)]);
    foreach ([1 => 'Unidad 1: Fundamentos', 2 => 'Unidad 2: Práctica', 3 => 'Unidad 3: Evaluación'] as $number => $name) {
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $number], '*', MUST_EXIST);
        course_update_section($course, $section, ['name' => $name]);
    }
    $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
    $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    foreach ($teachers as $username) {
        enrol_try_internal_enrol($course->id, $users[$username], $teacherrole);
    }
    foreach ($students as $username) {
        enrol_try_internal_enrol($course->id, $users[$username], $studentrole);
    }
    $teacher = $users[$teachers[0]];
    [$first, $second] = [$users[$students[0]], $users[$students[1]]];

    $generator->create_module('label', ['course' => $course->id, 'section' => 0,
        'intro' => '<p>Bienvenida a '.s($fullname).'. Contenido sintético de laboratorio.</p>']);
    $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Guía de la unidad',
        'content' => '<p>Objetivos, cronograma y criterios de evaluación de '.s($fullname).'.</p>']);
    $draft = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_string(['contextid' => context_user::instance($USER->id)->id,
        'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/',
        'filename' => 'lectura-'.strtolower($code).'.txt'], 'Lectura sintética de '.$fullname." ($variant).\n");
    $generator->create_module('resource', ['course' => $course->id, 'section' => 1,
        'name' => 'Material de lectura', 'files' => $draft]);

    $forum = $generator->create_module('forum', ['course' => $course->id, 'section' => 1,
        'name' => 'Foro de la unidad', 'type' => 'general']);
    $forums = $generator->get_plugin_generator('mod_forum');
    $discussion = $forums->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $teacher,
        'name' => 'Presentación', 'message' => '<p>Preséntense y cuenten qué esperan del curso.</p>',
        'messageformat' => FORMAT_HTML]);
    $root = (int) $DB->get_field('forum_posts', 'id', ['discussion' => $discussion->id, 'parent' => 0], MUST_EXIST);
    foreach ([$first, $second] as $index => $student) {
        $forums->create_post(['discussion' => $discussion->id, 'parent' => $root, 'userid' => $student,
            'subject' => 'Re: Presentación', 'message' => '<p>Respuesta sintética '.($index + 1).'.</p>',
            'messageformat' => FORMAT_HTML]);
    }

    $assign = $generator->create_module('assign', ['course' => $course->id, 'section' => 2,
        'name' => 'Taller práctico', 'assignsubmission_onlinetext_enabled' => 1,
        'assignsubmission_file_enabled' => 0, 'submissiondrafts' => 0]);
    $generator->get_plugin_generator('mod_assign')->create_submission(['cmid' => $assign->cmid, 'userid' => $first,
        'onlinetext' => '<p>Entrega sintética del taller de '.s($fullname).'.</p>']);

    $quiz = $generator->create_module('quiz', ['course' => $course->id, 'section' => 3,
        'name' => 'Cuestionario de repaso', 'questionsperpage' => 0, 'grade' => 10, 'sumgrades' => 2,
        'preferredbehaviour' => 'deferredfeedback']);
    $bank = $generator->get_plugin_generator('core_question')->create_question_category(
        ['contextid' => context_course::instance($course->id)->id, 'name' => 'Banco '.$shortname]);
    foreach (lab_seed_questions((int) $bank->id) as $question) {
        quiz_add_quiz_question($question, $quiz);
    }
    quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    $admin = $USER;
    manager::set_user(core_user::get_user($second, '*', MUST_EXIST));
    $quizzes = $generator->get_plugin_generator('mod_quiz');
    $attempt = $quizzes->create_attempt($quiz->id, $second);
    $quizzes->submit_responses($attempt->id, [1 => get_string('true', 'qtype_truefalse'), 2 => 'PHP'], false, true);
    manager::set_user($admin);
}

function lab_seed_remove(): array
{
    global $DB;
    $like = $DB->sql_like('idnumber', '?');
    $courses = $DB->get_records_select('course', $like, [LAB_SEED_PREFIX.'%']);
    foreach ($courses as $course) {
        delete_course($course, false);
    }
    // Deepest first, so a parent is empty when its turn comes.
    $categories = $DB->get_records_select('course_categories', $like, [LAB_SEED_PREFIX.'%'], 'depth DESC');
    foreach ($categories as $category) {
        core_course_category::get($category->id, MUST_EXIST, true)->delete_full(false);
    }
    $users = $DB->get_records_select('user', 'deleted = 0 AND '.$DB->sql_like('description', '?'), [LAB_SEED_TAG.'%']);
    foreach ($users as $user) {
        delete_user($user);
    }
    unset_config('manifest', LAB_SEED_PLUGIN);

    return ['courses' => count($courses), 'categories' => count($categories), 'users' => count($users)];
}

function lab_seed_status(): array
{
    global $DB;
    $courseids = $DB->get_fieldset_select('course', 'id', $DB->sql_like('idnumber', '?'), [LAB_SEED_PREFIX.'%']);
    [$insql, $params] = $courseids ? $DB->get_in_or_equal($courseids) : ['= 0', []];

    return [
        'manifest' => lab_seed_manifest(),
        'categories' => $DB->count_records_select('course_categories', $DB->sql_like('idnumber', '?'), [LAB_SEED_PREFIX.'%']),
        'courses' => count($courseids),
        'users' => $DB->count_records_select('user', 'deleted = 0 AND '.$DB->sql_like('description', '?'), [LAB_SEED_TAG.'%']),
        'modules' => $DB->count_records_select('course_modules', "course $insql", $params),
        'forum_posts' => $DB->count_records_select('forum_posts', "discussion IN (SELECT id FROM {forum_discussions} WHERE course $insql)", $params),
        'assign_submissions' => $DB->count_records_select('assign_submission',
            "status = 'submitted' AND assignment IN (SELECT id FROM {assign} WHERE course $insql)", $params),
        'quiz_attempts' => $DB->count_records_select('quiz_attempts',
            "state = 'finished' AND quiz IN (SELECT id FROM {quiz} WHERE course $insql)", $params),
    ];
}

manager::set_user(get_admin());
$variant = $options['variant'];
$manifest = lab_seed_manifest();
if ($options['mode'] === 'status') {
    echo json_encode(['variant' => $variant, 'status' => lab_seed_status()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    exit(0);
}
if ($manifest !== null && $manifest['variant'] !== $variant && $options['mode'] === 'seed') {
    lab_seed_fail('variant_mismatch', 'this site holds '.$manifest['variant'].'; use --reset to replace it');
}
if ($options['mode'] !== 'seed') {
    $removed = lab_seed_remove();
    echo 'REMOVED '.json_encode($removed, JSON_THROW_ON_ERROR)."\n";
    if ($options['mode'] === 'remove') {
        exit(0);
    }
    $manifest = null;
}
if ($manifest !== null && $manifest['version'] !== LAB_SEED_VERSION) {
    lab_seed_fail('seed_version_changed', $manifest['version'].' -> '.LAB_SEED_VERSION.'; use --reset');
}
if (($manifest['complete'] ?? false) === true) {
    echo 'ALREADY_SEEDED '.json_encode(lab_seed_status(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    exit(0);
}

$spec = lab_seed_spec($variant);
$manifest ??= ['version' => LAB_SEED_VERSION, 'variant' => $variant, 'courses' => [], 'complete' => false];
lab_seed_save_manifest($manifest);
$users = [];
foreach ($spec['users'] as $user) {
    $users[$user[0]] = lab_seed_user($user);
}
$categories = [];
foreach ($spec['categories'] as $category) {
    $categories[$category[0]] = lab_seed_category($variant, $category, $categories);
}
$generator = new testing_data_generator;
foreach ($spec['courses'] as $course) {
    $idnumber = lab_seed_idnumber($variant, $course[0]);
    if (in_array($idnumber, $manifest['courses'], true)) {
        continue;
    }
    // A course left half-built by an interrupted run is rebuilt from scratch.
    if ($partial = $DB->get_record('course', ['idnumber' => $idnumber])) {
        delete_course($partial, false);
    }
    lab_seed_course($generator, $variant, $course, $categories, $users);
    $manifest['courses'][] = $idnumber;
    lab_seed_save_manifest($manifest);
    echo 'COURSE '.$idnumber."\n";
}
$manifest['complete'] = true;
lab_seed_save_manifest($manifest);
echo 'SEEDED '.json_encode(lab_seed_status(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
