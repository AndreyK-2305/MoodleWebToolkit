<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SessionAuthorizationConcurrencyTest extends TestCase
{
    public function test_observation_cannot_overwrite_a_concurrent_password_confirmation(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $sessionId = Str::random(40);
        $prefix = 'quality-session:'.Str::uuid();
        $confirmedAt = now()->timestamp;
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'payload' => base64_encode(json_encode(['auth' => ['password_confirmed_at' => $confirmedAt - 10800]], JSON_THROW_ON_ERROR)),
            'last_activity' => $confirmedAt,
        ]);
        $processes = [];
        try {
            foreach (['observe', 'confirm'] as $mode) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-session-worker.php'), $mode, $sessionId, $prefix, (string) $confirmedAt], base_path(), timeout: 15);
                $processes[$mode] = $process;
                $process->start();
                $marker = $prefix.':'.$mode.($mode === 'observe' ? ':loaded' : ':attempted');
                $deadline = microtime(true) + 5;
                while (! Redis::exists($marker) && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertTrue((bool) Redis::exists($marker), $process->getErrorOutput());
            }
            // Without session locking the confirmation finishes first and the
            // held observation overwrites it. With locking it must wait here.
            $deadline = microtime(true) + 2;
            while ($processes['confirm']->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            Redis::rpush($prefix.':release', '1');
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            }
            $payload = json_decode(base64_decode(DB::table('sessions')->where('id', $sessionId)->value('payload')), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($confirmedAt, $payload['auth']['password_confirmed_at'], implode("\n", array_map(fn (Process $process): string => $process->getOutput(), $processes)));
            $this->assertTrue($payload['observation_seen']);
        } finally {
            Redis::rpush($prefix.':release', '1');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            Redis::del($prefix.':release', $prefix.':observe:loaded', $prefix.':observe:attempted', $prefix.':confirm:attempted');
            DB::table('sessions')->where('id', $sessionId)->delete();
        }
    }
}
