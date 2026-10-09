<?php

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

require __DIR__.'/quality-bootstrap.php';
// Closed test-only stages aid fault diagnosis without retaining job payloads.
$stage = static function (string $state): void {
    $path = '/tmp/quality-worker-stage.json';
    file_put_contents($path.'.tmp', json_encode(['stage' => $state, 'pid' => getmypid(), 'time' => time()], JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
};
$stage('UNIT_STARTED');
Event::listen(JobProcessing::class, static fn () => $stage('JOB_STARTED'));
Event::listen(JobProcessed::class, static fn () => $stage('JOB_FINISHED'));
if (($argv[2] ?? '') !== '') {
    Carbon::setTestNow($argv[2]);
}
$options = [
    'connection' => 'redis', '--queue' => 'executions,default',
    '--stop-when-empty' => true, '--tries' => 1, '--timeout' => 120,
    '--max-time' => 30, '--sleep' => 0,
];
if (($argv[1] ?? '') === 'once') {
    $options['--once'] = true;
}
$status = Artisan::call('queue:work', $options);
$stage('UNIT_FINISHED');
echo Artisan::output();
exit($status);
