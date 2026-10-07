<?php

namespace Tests\Feature\Tools;

use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Tools\BindExecutionTool;
use App\Enums\ToolCompatibilityStatus;
use App\Exceptions\ToolOperationBlocked;
use App\Models\Tool;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use RuntimeException;
use Tests\Feature\Domain\DomainTestCase;

class ToolCatalogTest extends DomainTestCase
{
    public function test_verified_final_distributions_are_registered_with_compatibility_gates_closed(): void
    {
        $this->seed(ToolCatalogSeeder::class);

        $recolector = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        $consolidador = Tool::query()->where('key', 'moodle-consolidador')->firstOrFail()->versions()->where('version', '8.0.0-linux-rc12')->firstOrFail();
        $integrador = Tool::query()->where('key', 'moodle-integrador-incremental')->firstOrFail()->versions()->where('version', '1.1.5-linux')->firstOrFail();

        $this->assertFalse($recolector->enabled);
        $this->assertFalse($consolidador->enabled);
        $this->assertFalse($integrador->enabled);
        $this->assertSame('VERIFIED', $recolector->distributions()->firstOrFail()->verification_state);
        $this->assertSame('VERIFIED', $consolidador->distributions()->firstOrFail()->verification_state);
        $this->assertSame('VERIFIED', $integrador->distributions()->firstOrFail()->verification_state);
        $this->assertSame(ToolCompatibilityStatus::LABORATORY, $recolector->compatibilities()->firstOrFail()->status);
        $this->assertSame(ToolCompatibilityStatus::BLOCKED, $consolidador->compatibilities()->firstOrFail()->status);
        $this->assertSame(ToolCompatibilityStatus::INCOMPATIBLE, $integrador->compatibilities()->firstOrFail()->status);
    }

    public function test_distribution_verifier_rejects_a_catalog_hash_that_does_not_match_the_tree(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->firstOrFail();
        $distribution->forceFill(['distribution_sha256' => str_repeat('f', 64)]);

        $this->expectException(RuntimeException::class);
        app(ToolDistributionVerifier::class)->verify($distribution);
    }

    public function test_execution_binding_is_pinned_once_and_secret_values_are_redacted(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        $version->forceFill(['enabled' => true])->save();
        config(['toolkit.features.recolector_742.enabled' => true]);
        $distribution = $version->distributions()->firstOrFail();
        $execution = $this->execution($this->project());
        $binder = app(BindExecutionTool::class);
        $configuration = ['workers' => 2, 'smtp_password' => 'never-store-this-secret'];

        $binding = $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', $configuration);
        $sameBinding = $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', ['smtp_password' => 'never-store-this-secret', 'workers' => 2]);

        $this->assertSame($binding->getKey(), $sameBinding->getKey());
        $this->assertSame('[REDACTED]', $binding->configuration_snapshot['smtp_password']);
        $this->assertSame($distribution->distribution_sha256, $binding->distribution_sha256);

        try {
            $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'other-adapter', 'local-registered-process', $configuration);
            $this->fail('A different tool binding replaced an immutable execution snapshot.');
        } catch (ToolOperationBlocked) {
            $this->assertSame(1, $execution->toolBinding()->count());
        }
    }

    public function test_real_tool_execution_is_blocked_until_both_catalog_and_feature_flag_allow_it(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        config(['toolkit.features.recolector_742.enabled' => true]);

        $this->expectException(ToolOperationBlocked::class);
        app(\App\Domain\Tools\ToolOperationGate::class)->assertRunnable($version, 'moodle.source.export');
    }
}
