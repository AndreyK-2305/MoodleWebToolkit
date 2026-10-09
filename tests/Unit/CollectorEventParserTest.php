<?php

namespace Tests\Unit;

use App\Domain\Collector\CollectorEventParser;
use Tests\TestCase;

class CollectorEventParserTest extends TestCase
{
    private const OPERATION = '00000000-0000-4000-8000-000000000001';

    /** @param array<string, mixed> $overrides */
    private function line(array $overrides = []): string
    {
        return json_encode([...[
            'schema_version' => 'collector-event.v1', 'operation_uuid' => self::OPERATION, 'sequence' => 1,
            'type' => 'snapshot', 'stage' => 'course-backups', 'total_courses' => 3, 'completed_courses' => 1, 'failed_courses' => 0,
        ], ...$overrides], JSON_THROW_ON_ERROR)."\n";
    }

    public function test_fragmented_known_units_produce_true_progress_without_time_estimates(): void
    {
        $parser = new CollectorEventParser(self::OPERATION);
        $line = $this->line(['percent_complete' => 99, 'estimated_remaining_seconds' => 0]);
        $this->assertSame([], $parser->feed(substr($line, 0, 35)));
        $events = $parser->feed(substr($line, 35));
        $this->assertCount(1, $events);
        $this->assertSame(33, $events[0]->progress);
        $this->assertSame(['kind' => 'courses', 'completed' => 1, 'total' => 3, 'failed' => 0], $events[0]->payload['units']);
        $this->assertArrayNotHasKey('estimated_remaining_seconds', $events[0]->payload);
    }

    public function test_unknown_denominator_or_non_course_stage_is_indeterminate(): void
    {
        foreach ([['total_courses' => 0], ['total_courses' => null], ['completed_courses' => 4], ['total_courses' => '3'], ['stage' => 'sealing']] as $overrides) {
            $events = (new CollectorEventParser(self::OPERATION))->feed($this->line($overrides));
            $this->assertNull($events[0]->progress);
        }
    }

    public function test_duplicate_late_and_restarted_sequences_are_not_replayed(): void
    {
        $parser = new CollectorEventParser(self::OPERATION);
        $this->assertCount(1, $parser->feed($this->line(['sequence' => 2])));
        $this->assertSame([], $parser->feed($this->line(['sequence' => 2]).$this->line(['sequence' => 1])));
        $restarted = new CollectorEventParser(self::OPERATION, $parser->lastSequence());
        $this->assertSame([], $restarted->feed($this->line(['sequence' => 2])));
        $this->assertCount(1, $restarted->feed($this->line(['sequence' => 3])));
    }

    public function test_wrong_operation_does_not_advance_cursor(): void
    {
        $parser = new CollectorEventParser(self::OPERATION);
        $events = $parser->feed($this->line(['operation_uuid' => 'other-operation', 'sequence' => 999]));
        $this->assertSame('collector.log', $events[0]->type);
        $this->assertSame(0, $parser->lastSequence());
    }

    public function test_unknown_fragmented_and_invalid_utf8_logs_are_sanitized(): void
    {
        $parser = new CollectorEventParser(self::OPERATION);
        $this->assertSame([], $parser->feed('pass'));
        $events = $parser->feed("word=synthetic-material \xFF\n");
        $this->assertSame('collector.log', $events[0]->type);
        $this->assertStringNotContainsString('synthetic-material', $events[0]->message);
        $this->assertTrue(mb_check_encoding($events[0]->message, 'UTF-8'));
        $this->assertSame('collector.log', $parser->feed($this->line(['type' => 'new-type', 'sequence' => 2]))[0]->type);
    }

    public function test_truncated_or_overlong_lines_never_infer_success(): void
    {
        $parser = new CollectorEventParser(self::OPERATION, maximumLineBytes: 128);
        $events = $parser->feed(str_repeat('x', 130).'password=synthetic-material');
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->progress);
        $this->assertCount(1, $parser->feed('', final: true));
        $parser = new CollectorEventParser(self::OPERATION);
        $events = $parser->feed(rtrim($this->line(['type' => 'package_validated'])), final: true);
        $this->assertSame('collector.log', $events[0]->type);
        $this->assertSame(0, $parser->lastSequence());
    }

    public function test_tool_validation_signal_is_not_execution_success(): void
    {
        $events = (new CollectorEventParser(self::OPERATION))->feed($this->line(['type' => 'package_validated']));
        $this->assertSame('collector.package_validated', $events[0]->type);
        $this->assertNull($events[0]->progress);
        $this->assertNull($events[0]->payload);
    }
}
