<?php

namespace Tests\Unit;

use App\Domain\Collector\CollectorBridge;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * The bridge measures its workspace every second while the collector replaces
 * export-progress.json through tmp.<pid> + rename and Moodle removes temporary
 * backup directories. A failed measurement makes child() SIGTERM the bridge's
 * own group, which closes the export as cancelled with exit 143.
 */
class CollectorBridgeWorkspaceUsageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/collector-usage-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/state/collector-work', 0700, true);
        mkdir($this->root.'/temporary', 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_usage_survives_collector_renames_and_removed_temporary_directories(): void
    {
        // Same write pattern as collector_atomic_json in Recolector 7.4.2 and
        // the per-course temporary directories created by Moodle backups.
        $churn = new Process([PHP_BINARY, '-r', <<<'PHP'
            [$root, $seconds] = [$argv[1], (float) $argv[2]];
            $until = microtime(true) + $seconds;
            $iterations = 0;
            while (microtime(true) < $until) {
                $target = $root.'/state/collector-work/export-progress.json';
                file_put_contents($target.'.tmp.'.getmypid(), '{"stage":"course-backups"}', LOCK_EX);
                rename($target.'.tmp.'.getmypid(), $target);
                $directory = $root.'/temporary/backup-'.($iterations++ % 4);
                @mkdir($directory, 0700);
                @file_put_contents($directory.'/part', 'course');
                @unlink($directory.'/part');
                @rmdir($directory);
            }
            echo $iterations;
            PHP, $this->root, '3']);
        $churn->start();
        $usage = new ReflectionMethod(CollectorBridge::class, 'usage');
        $bridge = new CollectorBridge;
        $walks = 0;
        $failures = [];
        while ($churn->isRunning()) {
            $walks++;
            try {
                $usage->invoke($bridge, $this->root);
            } catch (Throwable $exception) {
                $failures[] = $exception::class.': '.$exception->getMessage();
            }
        }

        $this->assertSame(0, $churn->getExitCode(), $churn->getErrorOutput());
        $this->assertGreaterThan(1000, (int) $churn->getOutput(), 'The workspace was not churned concurrently.');
        $this->assertGreaterThan(100, $walks);
        $this->assertSame([], array_slice(array_unique($failures), 0, 5), count($failures).' of '.$walks.' measurements failed.');
    }

    public function test_usage_counts_regular_files_and_still_rejects_links_and_special_files(): void
    {
        $usage = new ReflectionMethod(CollectorBridge::class, 'usage');
        file_put_contents($this->root.'/state/collector-work/status.json', str_repeat('a', 10));
        mkdir($this->root.'/temporary/nested');
        file_put_contents($this->root.'/temporary/nested/part', str_repeat('b', 32));
        $this->assertSame(42, $usage->invoke(new CollectorBridge, $this->root));

        symlink($this->root.'/state/collector-work/status.json', $this->root.'/temporary/link');
        $this->assertUsageRejected($usage);
        unlink($this->root.'/temporary/link');
        symlink($this->root.'/missing', $this->root.'/temporary/dangling');
        $this->assertUsageRejected($usage);
        unlink($this->root.'/temporary/dangling');
        if (function_exists('posix_mkfifo')) {
            posix_mkfifo($this->root.'/temporary/fifo', 0600);
            $this->assertUsageRejected($usage);
            unlink($this->root.'/temporary/fifo');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            // Listed but not searchable must not be mistaken for a removed entry.
            chmod($this->root.'/temporary/nested', 0400);
            try {
                $this->assertUsageRejected($usage);
            } finally {
                chmod($this->root.'/temporary/nested', 0700);
            }
        }
    }

    private function assertUsageRejected(ReflectionMethod $usage): void
    {
        try {
            $usage->invoke(new CollectorBridge, $this->root);
            $this->fail('Unsafe workspace content was measured.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unsafe workspace content.', $exception->getMessage());
        }
    }
}
