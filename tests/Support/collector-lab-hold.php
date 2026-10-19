<?php

use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Collector\LabMoodleProfiles;

// LAB-only E2E hold: keeps the real export alive at a known point, independent
// of its speed. A fresh Recolector 7.4.2 export always runs extract-identities,
// which reads {prefix}course_categories, so an ACCESS EXCLUSIVE lock blocks it
// before it can finish. The preflight probe never reads that table, so the
// application's own requests are not blocked. Protocol on stdout, one JSON per line:
// {"held":true} once locked, {"blocked":true} once an export connection waits
// on this lock. Closing stdin releases the lock: {"released":true}.
require __DIR__.'/quality-bootstrap.php';
if (! config('toolkit.features.recolector_742.enabled') || ! config('collector.profiles.synthetic-moodle')) {
    throw new RuntimeException('LAB-only export hold.');
}
$profile = app(LabMoodleProfiles::class)->get('synthetic-moodle');
app(SecretProvider::class)->consume($profile['credential_reference'], $profile['credential_version'], function (string $value) use ($profile): void {
    $dsn = 'pgsql:host='.$profile['db_host'].';port='.$profile['db_port'].';dbname='.$profile['db_name'].';connect_timeout=3';
    $holder = new PDO($dsn, $profile['db_user'], $value, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // pg_stat_activity is snapshotted per transaction; observe from autocommit.
    $observer = new PDO($dsn, $profile['db_user'], $value, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder->beginTransaction();
    $holder->exec('LOCK TABLE '.$profile['db_prefix'].'course_categories IN ACCESS EXCLUSIVE MODE');
    $pid = (int) $holder->query('SELECT pg_backend_pid()')->fetchColumn();
    $emit = function (array $line): void {
        fwrite(STDOUT, json_encode($line, JSON_THROW_ON_ERROR).PHP_EOL);
        fflush(STDOUT);
    };
    $emit(['held' => true]);
    $waiters = $observer->prepare('SELECT COUNT(*) FROM pg_stat_activity WHERE usename = :user AND CAST(:pid AS integer) = ANY(pg_blocking_pids(pid))');
    stream_set_blocking(STDIN, false);
    $blocked = false;
    // Leak guard only: the spec releases by closing stdin, also when its process exits.
    $deadline = hrtime(true) + 300_000_000_000;
    while (! feof(STDIN)) {
        fread(STDIN, 1024);
        if (! $blocked) {
            $waiters->execute(['user' => $profile['db_user'], 'pid' => $pid]);
            if ((int) $waiters->fetchColumn() > 0) {
                $blocked = true;
                $emit(['blocked' => true]);
            }
        }
        if (hrtime(true) >= $deadline) {
            $holder->rollBack();
            throw new RuntimeException('The LAB export hold was never released.');
        }
        usleep(100_000);
    }
    $holder->rollBack();
    $emit(['released' => true]);
});
