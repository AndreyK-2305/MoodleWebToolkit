<?php
declare(strict_types=1);

define('MOODLE_INTERNAL', true);
$root = sys_get_temp_dir() . '/consolidador rc19 tar ' . bin2hex(random_bytes(6));
$CFG = (object)[
    'libdir' => __DIR__ . '/fixtures',
    'tempdir' => $root . '/temporary files',
];
class core_text {
    public static function strtolower(string $value): string { return mb_strtolower($value); }
    public static function strlen(string $value): int { return mb_strlen($value); }
}
require_once(__DIR__ . '/../scripts/phase5-lib.php');
require_once(__DIR__ . '/../scripts/phase6-backup-users.php');

function rc19_check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('RC19_PHASE6_TAR_FAILED ' . $message);
    }
}

function rc19_remove(string $path): void {
    if (!file_exists($path)) { return; }
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $item) {
        rc19_remove($item->getPathname());
    }
    rmdir($path);
}

function rc19_run(array $command): void {
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    rc19_check(is_resource($process), 'no fue posible ejecutar tar para el fixture');
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    rc19_check($exit === 0,
        'tar fixture exit=' . $exit . ' stdout=' . $stdout . ' stderr=' . $stderr);
}

function rc19_tar_fixture(
    string $root,
    string $name,
    string $member,
    string $contents
): string {
    $source = $root . '/sources/' . $name;
    rc19_check(mkdir($source, 0770, true), 'no creó el directorio fuente');
    rc19_check(file_put_contents($source . '/users.xml', $contents) !== false,
        'no escribió users.xml del fixture');
    $archive = $root . '/archives/' . $name . '.mbz';
    if (!is_dir(dirname($archive))) {
        rc19_check(mkdir(dirname($archive), 0770, true),
            'no creó el directorio de archivos');
    }
    rc19_run([
        'tar', '--create', '--file', $archive,
        '--directory', $source, '--', $member,
    ]);
    return $archive;
}

function rc19_expect_block(callable $action, array $needles, string $message): void {
    try {
        $action();
        throw new RuntimeException('expected_block_missing');
    } catch (RuntimeException $error) {
        foreach ($needles as $needle) {
            if (str_contains($error->getMessage(), $needle)) {
                return;
            }
        }
        throw new RuntimeException(
            'RC19_PHASE6_TAR_FAILED ' . $message . ': ' . $error->getMessage()
        );
    }
}

$single = '<users><user id="101"><username>student</username>' .
    '<email>student@ufps.edu.co</email><deleted>0</deleted></user></users>';
$multiple = '<users>' .
    '<user id="540"><username>auxiliary</username><email>aux@ufps.edu.co</email><deleted>0</deleted></user>' .
    '<user id="202"><username>deleted-202</username><email>old@example.org</email><deleted>1</deleted></user>' .
    '<user id="3"><username>guest</username><email>guest@example.org</email><deleted>0</deleted></user>' .
    '<user id="101"><username>student</username><email>student@ufps.edu.co</email><deleted>0</deleted></user>' .
    '</users>';

rc19_check(mkdir($CFG->tempdir, 0770, true), 'no creó el directorio temporal');
try {
    // Contrato directo: --file consume exactamente el MBZ y -- separa el miembro.
    $expectedbackup = $root . '/archive path/expected backup.mbz';
    $command = p6_tar_member_to_stdout_command($expectedbackup, 'users.xml');
    rc19_check($command === [
        'tar', '--extract', '--to-stdout', '--file',
        $expectedbackup, '--', 'users.xml',
    ], 'los argumentos GNU tar no son exactos');
    $fileindex = array_search('--file', $command, true);
    rc19_check($fileindex === 3 && $command[$fileindex + 1] === $expectedbackup &&
        $command[$fileindex + 2] === '--',
        '--file no recibe exactamente el MBZ antes del separador');
    rc19_check(!in_array('-xOf', $command, true), 'se reintrodujo -xOf');
    $sourcecode = (string)file_get_contents(
        __DIR__ . '/../scripts/phase6-backup-users.php'
    );
    rc19_check(!str_contains($sourcecode, "['tar', '-xOf', '--'") &&
        !str_contains($sourcecode, 'shell_exec(') &&
        !str_contains($sourcecode, 'passthru('),
        'la implementación reintrodujo argumentos ambiguos o shell');

    // A: miembro users.xml.
    $archiveA = rc19_tar_fixture($root, 'case-a', 'users.xml', $single);
    $usersA = p6_backup_users_xml($archiveA);
    rc19_check(count($usersA) === 1 && $usersA[0]['source_user_id'] === 101,
        'no leyó users.xml en la raíz');

    // B: miembro ./users.xml, encontrado en el segundo intento.
    $archiveB = rc19_tar_fixture($root, 'case-b', './users.xml', $single);
    $usersB = p6_backup_users_xml($archiveB);
    rc19_check(count($usersB) === 1 && $usersB[0]['source_username'] === 'student',
        'no leyó ./users.xml');

    // C: tar válido sin users.xml.
    $missingSource = $root . '/sources/case-c';
    rc19_check(mkdir($missingSource, 0770, true), 'no creó case-c');
    file_put_contents($missingSource . '/other.xml', '<other/>');
    $missingArchive = $root . '/archives/case-c.mbz';
    rc19_run([
        'tar', '--create', '--file', $missingArchive,
        '--directory', $missingSource, '--', 'other.xml',
    ]);
    rc19_expect_block(
        static fn(): array => p6_backup_users_xml($missingArchive),
        ['El MBZ no permite leer users.xml', 'El MBZ no contiene un users.xml legible'],
        'un tar sin users.xml no bloqueó'
    );

    // D: archivo corrupto.
    $corrupt = $root . '/archives/case-d-corrupt.mbz';
    file_put_contents($corrupt, "not-a-tar\x00broken");
    rc19_expect_block(
        static fn(): array => p6_backup_users_xml($corrupt),
        ['El MBZ no permite leer users.xml', 'El MBZ no contiene un users.xml legible'],
        'un tar corrupto no bloqueó'
    );

    // E: ruta del MBZ con espacios.
    $spacesSource = $root . '/sources/case e with spaces';
    rc19_check(mkdir($spacesSource, 0770, true), 'no creó el origen con espacios');
    file_put_contents($spacesSource . '/users.xml', $single);
    $spacesArchive = $root . '/archives/case e backup with spaces.mbz';
    rc19_run([
        'tar', '--create', '--file', $spacesArchive,
        '--directory', $spacesSource, '--', 'users.xml',
    ]);
    rc19_check(p6_backup_users_xml($spacesArchive)[0]['source_user_id'] === 101,
        'la ruta con espacios no funcionó');

    // F: múltiples usuarios conservan IDs, orden y clasificación.
    $archiveF = rc19_tar_fixture($root, 'case-f', 'users.xml', $multiple);
    $usersF = p6_backup_users_xml($archiveF);
    rc19_check(array_column($usersF, 'source_user_id') === [3, 101, 202, 540],
        'los IDs no se conservaron de forma determinista');
    $classification = p6_classify_backup_users(
        $usersF,
        'posgrados',
        [101],
        [
            'posgrados:101' => ['canonical_id' => 'CAN-101', 'target_user_id' => 1001],
            'posgrados:540' => ['canonical_id' => 'CAN-540', 'target_user_id' => 1540],
        ]
    );
    $classes = [];
    foreach ($classification['users'] as $user) {
        $classes[$user['source_user_id']] = $user['classification'];
    }
    rc19_check($classes === [
        3 => 'reserved_guest',
        101 => 'academic_mapped',
        202 => 'historical_deleted',
        540 => 'auxiliary_mapped',
    ], 'la clasificación existente cambió');

    // G: IDs inválidos y repetidos continúan bloqueando.
    $duplicate = '<users>' .
        '<user id="7"><username>a</username><email>a@example.org</email><deleted>0</deleted></user>' .
        '<user id="7"><username>b</username><email>b@example.org</email><deleted>0</deleted></user>' .
        '</users>';
    $archiveG1 = rc19_tar_fixture($root, 'case-g-duplicate', 'users.xml', $duplicate);
    rc19_expect_block(
        static fn(): array => p6_backup_users_xml($archiveG1),
        ['ID inválido o repetido: 7'],
        'un ID repetido no bloqueó'
    );
    $invalid = '<users><user id="0"><username>invalid</username>' .
        '<email>invalid@example.org</email><deleted>0</deleted></user></users>';
    $archiveG2 = rc19_tar_fixture($root, 'case-g-invalid', 'users.xml', $invalid);
    rc19_expect_block(
        static fn(): array => p6_backup_users_xml($archiveG2),
        ['ID inválido o repetido: 0'],
        'un ID inválido no bloqueó'
    );

    echo "RC19_PHASE6_TAR_OK root=1 dot_root=1 missing=blocked corrupt=blocked spaces=1 users=4 classification=preserved invalid=blocked arguments=exact\n";
} finally {
    rc19_remove($root);
}
