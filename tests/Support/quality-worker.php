<?php

use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

require __DIR__.'/quality-bootstrap.php';

// The coordinator is test infrastructure. Every unit is a fresh, real Laravel
// queue worker process consuming persisted Redis jobs; no adapter is mocked.
while (true) {
    Redis::setex('quality:worker:heartbeat', 60, (string) time());
    $request = Redis::blpop(['quality:worker:requests'], 1);
    if (! is_array($request)) {
        continue;
    }
    $data = json_decode($request[1], true, flags: JSON_THROW_ON_ERROR);
    $process = new Process([
        PHP_BINARY, __DIR__.'/quality-worker-unit.php',
        $data['mode'] === 'once' ? 'once' : 'drain',
        $data['clock'] ?? '',
    ], base_path(), timeout: 45);
    $process->start();
    $pid = $process->getPid();
    try {
        $exit = $process->wait();
        $error = $process->getErrorOutput();
    } catch (Throwable) {
        // A failed browser case must fail explicitly without killing the
        // coordinator and poisoning every subsequent independent case.
        $exit = 124;
        $error = 'The bounded quality worker unit did not complete.';
    }
    Redis::rpush('quality:worker:result:'.$data['id'], json_encode([
        'exit' => $exit, 'pid' => $pid,
        'error' => $error,
    ], JSON_THROW_ON_ERROR));
    Redis::expire('quality:worker:result:'.$data['id'], 120);
}
