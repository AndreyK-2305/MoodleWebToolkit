<?php

namespace Tests\Unit;

use App\Domain\Collector\CollectorLogReader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CollectorLogReaderTest extends TestCase
{
    private const OPERATION = '00000000-0000-4000-8000-000000000001';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/it3-reader-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** @return array{offset: int, wire_sequence: int, device: ?string, inode: ?string, prefix_sha256: string, discarding: bool} */
    private function cursor(): array
    {
        return ['offset' => 0, 'wire_sequence' => 0, 'device' => null, 'inode' => null,
            'prefix_sha256' => hash('sha256', ''), 'discarding' => false];
    }

    private function line(int $sequence): string
    {
        return json_encode(['schema_version' => 'collector-event.v1', 'operation_uuid' => self::OPERATION,
            'sequence' => $sequence, 'type' => 'snapshot', 'stage' => 'course-backups',
            'total_courses' => 2, 'completed_courses' => 1, 'failed_courses' => 0], JSON_THROW_ON_ERROR)."\n";
    }

    public function test_restart_rereads_partial_line_and_deduplicates_wire_sequence(): void
    {
        $path = $this->root.'/stdout.log';
        $second = $this->line(2);
        file_put_contents($path, $this->line(1).substr($second, 0, 70));
        $first = (new CollectorLogReader)->read($path, self::OPERATION, $this->cursor());
        $this->assertSame('OK', $first['health']);
        $this->assertFalse($first['complete']);
        $this->assertSame(strlen($this->line(1)), $first['cursor']['offset']);
        $this->assertSame(1, $first['cursor']['wire_sequence']);
        file_put_contents($path, substr($second, 70).$this->line(1), FILE_APPEND);
        $next = (new CollectorLogReader)->read($path, self::OPERATION, $first['cursor']);
        $this->assertTrue($next['complete']);
        $this->assertCount(1, $next['events']);
        $this->assertSame(2, $next['events'][0]['wire_sequence']);
        $this->assertSame(50, $next['events'][0]['event']->progress);
        $this->assertSame([], (new CollectorLogReader)->read($path, self::OPERATION, $next['cursor'])['events']);
    }

    public function test_bounded_batches_do_not_lose_events(): void
    {
        $path = $this->root.'/stdout.log';
        file_put_contents($path, implode('', array_map($this->line(...), range(1, 40))));
        $cursor = $this->cursor();
        $seen = [];
        do {
            $result = (new CollectorLogReader)->read($path, self::OPERATION, $cursor);
            $cursor = $result['cursor'];
            $this->assertLessThanOrEqual(16, count($result['events']));
            array_push($seen, ...array_column($result['events'], 'wire_sequence'));
        } while (! $result['complete']);
        $this->assertSame(range(1, 40), $seen);
        $this->assertSame(hash_file('sha256', $path), $cursor['prefix_sha256']);
    }

    public function test_fragmented_utf8_private_text_and_terminal_tail_are_never_persisted(): void
    {
        $path = $this->root.'/stdout.log';
        file_put_contents($path, "password=material-privado\xC3");
        $result = (new CollectorLogReader)->read($path, self::OPERATION, $this->cursor());
        $this->assertSame(0, $result['cursor']['offset']);
        $this->assertSame([], $result['events']);
        file_put_contents($path, "\xB1\n".rtrim($this->line(1)), FILE_APPEND);
        $result = (new CollectorLogReader)->read($path, self::OPERATION, $result['cursor'], final: true);
        $this->assertTrue($result['complete']);
        $this->assertCount(2, $result['events']);
        foreach ($result['events'] as $entry) {
            $this->assertSame('collector.log', $entry['event']->type);
            $this->assertStringNotContainsString('material-privado', $entry['event']->message);
            $this->assertNull($entry['event']->progress);
        }
        $this->assertSame(0, $result['cursor']['wire_sequence']);
        $this->assertStringNotContainsString('material-privado', json_encode($result['cursor'], JSON_THROW_ON_ERROR));
    }

    public function test_overlong_fragment_is_discarded_across_restart_without_interpreting_its_tail(): void
    {
        $path = $this->root.'/stdout.log';
        file_put_contents($path, str_repeat('x', 20000));
        $result = (new CollectorLogReader)->read($path, self::OPERATION, $this->cursor());
        $this->assertTrue($result['cursor']['discarding']);
        $this->assertCount(1, $result['events']);
        file_put_contents($path, rtrim($this->line(999))."\n".$this->line(1), FILE_APPEND);
        $next = (new CollectorLogReader)->read($path, self::OPERATION, $result['cursor']);
        $this->assertFalse($next['cursor']['discarding']);
        $this->assertTrue($next['complete']);
        $this->assertSame(1, $next['cursor']['wire_sequence']);
        $this->assertCount(1, $next['events']);
    }

    public function test_committed_prefix_truncation_mutation_and_rotation_block_further_interpretation(): void
    {
        foreach (['TRUNCATED', 'ALTERED', 'ROTATED'] as $health) {
            $path = $this->root.'/'.$health.'.log';
            file_put_contents($path, $this->line(1));
            $result = (new CollectorLogReader)->read($path, self::OPERATION, $this->cursor());
            if ($health === 'TRUNCATED') {
                file_put_contents($path, 'short');
            } elseif ($health === 'ALTERED') {
                file_put_contents($path, str_replace('snapshot', 'tampered', $this->line(1)).$this->line(2));
            } else {
                rename($path, $path.'.old');
                file_put_contents($path, $this->line(2));
            }
            $next = (new CollectorLogReader)->read($path, self::OPERATION, $result['cursor']);
            $this->assertSame($health, $next['health']);
            $this->assertSame($result['cursor'], $next['cursor']);
            $this->assertSame([], $next['events']);
        }
    }

    public function test_missing_links_and_special_files_are_not_opened(): void
    {
        $reader = new CollectorLogReader;
        $this->assertSame('MISSING', $reader->read($this->root.'/missing', self::OPERATION, $this->cursor())['health']);
        $path = $this->root.'/original';
        file_put_contents($path, $this->line(1));
        symlink($path, $this->root.'/symbolic');
        link($path, $this->root.'/hard');
        posix_mkfifo($this->root.'/fifo', 0600);
        foreach (['original', 'hard', 'symbolic', 'fifo'] as $name) {
            $this->assertSame('UNSAFE', $reader->read($this->root.'/'.$name, self::OPERATION, $this->cursor())['health']);
        }
    }
}
