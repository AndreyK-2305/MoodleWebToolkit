<?php

namespace Tests\Unit;

use App\Domain\Collector\CollectorPackageMetadata;
use App\Exceptions\ToolOperationBlocked;
use Tests\TestCase;

class CollectorPackageMetadataTest extends TestCase
{
    public function test_legacy_producer_is_preserved_and_missing_display_name_uses_verified_source_identity(): void
    {
        $manifest = $this->manifest();
        $manifest['collector_version'] = '7.4.1-linux';
        unset($manifest['capabilities']);
        $result = app(CollectorPackageMetadata::class)->recognize($manifest);
        $this->assertSame('7.4.1-linux', $result['producer_version']);
        $this->assertSame('LEGACY', $result['metadata_state']);
        $this->assertSame('synthetic-lab', $result['name']);
        $this->assertSame([], $result['capabilities']);
    }

    public function test_new_producer_requires_verified_theme_contract(): void
    {
        $result = app(CollectorPackageMetadata::class)->recognize($this->manifest());
        $this->assertSame('7.4.2-linux', $result['producer_version']);
        $this->assertSame(['theme_inventory' => '1.0'], $result['capabilities']);
    }

    public function test_unknown_incomplete_and_incompatible_metadata_is_blocked(): void
    {
        foreach ([['collector_version' => '7.4.3-linux'], ['schema_version' => '2.0'], ['source_id' => '../outside'],
            ['courses_expected' => null], ['courses_expected' => 0], ['package_status' => 'pending'],
            ['source_write_performed' => true], ['destination_write_performed' => true],
            ['capabilities' => []], ['collector_version' => '7.4.1-linux', 'capabilities' => ['theme_inventory' => null]], ['capabilities' => ['theme_inventory' => '2.0']],
            ['capabilities' => ['theme_inventory' => '1.0', 'unknown' => '1.0']]] as $change) {
            try {
                app(CollectorPackageMetadata::class)->recognize([...$this->manifest(), ...$change]);
                $this->fail('Unsupported metadata must be rejected.');
            } catch (ToolOperationBlocked $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return ['schema_version' => '1.0', 'package_type' => 'moodle-consolidation-source', 'collector_version' => '7.4.2-linux',
            'package_status' => 'sealed', 'source_id' => 'synthetic-lab', 'courses_expected' => 2,
            'source_write_performed' => false, 'destination_write_performed' => false, 'capabilities' => ['theme_inventory' => '1.0']];
    }
}
