<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/quality-bootstrap.php';
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
echo Artisan::output();
exit($status);
