<?php

namespace Tests\Feature\Domain;

use App\Enums\ExecutionStatus;
use App\Models\Execution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The application runs with a local time zone (America/Bogota) over
 * timestamptz columns. A stored instant must be the same instant when read
 * back and when compared in a query, whatever zone the value was written in.
 */
class TimestampStorageTest extends DomainTestCase
{
    private string $previousZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousZone = date_default_timezone_get();
        config(['app.timezone' => 'America/Bogota']);
        date_default_timezone_set('America/Bogota');
        Date::setTestNow(CarbonImmutable::parse('2026-10-21T01:26:38Z'));
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        date_default_timezone_set($this->previousZone);
        parent::tearDown();
    }

    public function test_local_and_utc_writes_keep_the_same_instant(): void
    {
        $execution = $this->execution($this->project(), ExecutionStatus::RUNNING);
        $execution->forceFill(['started_at' => now(), 'finished_at' => now()->utc()])->save();

        $stored = Execution::query()->findOrFail($execution->id);
        $this->assertSame('2026-10-21T01:26:38Z', $stored->started_at->utc()->format('Y-m-d\TH:i:s\Z'), 'Una escritura con la zona local no se corre.');
        $this->assertSame('2026-10-21T01:26:38Z', $stored->finished_at->utc()->format('Y-m-d\TH:i:s\Z'), 'Una escritura en UTC tampoco.');
        $this->assertSame('2026-10-21 01:26:38+00', DB::table('executions')->where('id', $execution->id)->value('started_at'));
    }

    public function test_query_bindings_compare_instants_not_wall_clocks(): void
    {
        $execution = $this->execution($this->project(), ExecutionStatus::RUNNING);
        $execution->forceFill(['started_at' => now()->utc()->subMinute()])->save();

        // One minute ago is earlier than now, in either zone the bound value is written in.
        $this->assertTrue(Execution::query()->whereKey($execution->id)->where('started_at', '<=', now())->exists());
        $this->assertTrue(Execution::query()->whereKey($execution->id)->where('started_at', '<=', now()->utc())->exists());
        $this->assertFalse(Execution::query()->whereKey($execution->id)->where('started_at', '>', now()->subSeconds(30))->exists());
    }
}
