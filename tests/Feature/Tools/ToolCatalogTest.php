<?php

namespace Tests\Feature\Tools;

use App\Domain\Tools\BindExecutionTool;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Domain\Tools\ToolOperationGate;
use App\Domain\Workspaces\ApproveExecutionCapacity;
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

    public function test_execution_binding_is_pinned_once_and_inline_secrets_are_blocked(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        $version->forceFill(['enabled' => true])->save();
        config(['toolkit.features.recolector_742.enabled' => true]);
        $distribution = $version->distributions()->firstOrFail();
        $execution = $this->execution($this->project());
        $binder = app(BindExecutionTool::class);
        $configuration = ['workers' => 2];
        app(ApproveExecutionCapacity::class)->approve($execution, 16 * 1024 * 1024, 10, $execution->creator);

        $binding = $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', $configuration);
        $sameBinding = $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', ['workers' => 2]);

        $this->assertSame($binding->getKey(), $sameBinding->getKey());
        $this->assertSame(['workers' => 2], $binding->configuration_snapshot);
        $this->assertSame($distribution->distribution_sha256, $binding->distribution_sha256);

        try {
            $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'other-adapter', 'local-registered-process', $configuration);
            $this->fail('A different tool binding replaced an immutable execution snapshot.');
        } catch (ToolOperationBlocked) {
            $this->assertSame(1, $execution->toolBinding()->count());
        }

        try {
            $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', ['smtp_password' => 'never-store-this-secret']);
            $this->fail('No existe almacén de secretos: la configuración en claro debe bloquearse.');
        } catch (ToolOperationBlocked) {
            $this->assertStringNotContainsString('never-store-this-secret', (string) json_encode($binding->fresh()->configuration_snapshot));
        }

        try {
            $binder->bind($execution, $version, $distribution, 'moodle.source.export', 'recolector-742', 'local-registered-process', [
                'endpoint' => 'https://operator:private-value@example.test',
            ]);
            $this->fail('Credentials embedded in a generic URL must not enter the immutable configuration snapshot.');
        } catch (ToolOperationBlocked) {
            $this->assertStringNotContainsString('private-value', (string) json_encode($binding->fresh()->configuration_snapshot));
        }
    }

    public function test_catalog_sync_is_idempotent_and_keeps_feature_flags_closed(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $firstCounts = [Tool::query()->count(), ToolDistribution::query()->count()];
        $this->seed(ToolCatalogSeeder::class);

        $this->assertSame($firstCounts, [Tool::query()->count(), ToolDistribution::query()->count()]);
        $this->assertSame(3, Tool::query()->count());
        $this->assertFalse((bool) config('toolkit.features.recolector_742.enabled'));
        $this->assertFalse((bool) config('toolkit.features.consolidador_800.enabled'));
        $this->assertFalse((bool) config('toolkit.features.integrador_115.enabled'));
    }

    public function test_real_tool_execution_is_blocked_until_both_catalog_and_feature_flag_allow_it(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        config(['toolkit.features.recolector_742.enabled' => true]);

        $this->expectException(ToolOperationBlocked::class);
        app(ToolOperationGate::class)->assertRunnable($version, 'moodle.source.export');
    }

    public function test_manifest_hash_and_unexpected_mutable_paths_are_rejected_without_changing_baseline(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->firstOrFail();
        $manifestHash = $distribution->manifest_sha256;
        $distribution->forceFill(['manifest_sha256' => str_repeat('0', 64)]);
        try {
            app(ToolDistributionVerifier::class)->verify($distribution);
            $this->fail('An altered manifest identity must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('manifiesto', $exception->getMessage());
        }
        $distribution->forceFill(['manifest_sha256' => $manifestHash, 'mutable_paths' => ['unexpected-overlay.json']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('extra o faltantes');
        app(ToolDistributionVerifier::class)->verify($distribution);
    }

    public function test_enabled_version_still_requires_its_flag_and_allowed_compatibility(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        $version->forceFill(['enabled' => true])->save();
        try {
            app(ToolOperationGate::class)->assertRunnable($version, 'moodle.source.export');
            $this->fail('An enabled version must not bypass its disabled flag.');
        } catch (ToolOperationBlocked) {
            $this->assertFalse((bool) config('toolkit.features.recolector_742.enabled'));
        }
        config(['toolkit.features.recolector_742.enabled' => true]);
        $version->compatibilities()->firstOrFail()->forceFill(['status' => ToolCompatibilityStatus::BLOCKED])->save();
        $this->expectException(ToolOperationBlocked::class);
        app(ToolOperationGate::class)->assertRunnable($version, 'moodle.source.export');
    }

    public function test_capacity_cannot_be_changed_after_an_execution_binding_is_pinned(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $version = Tool::query()->where('key', 'moodle-recolector')->firstOrFail()->versions()->where('version', '7.4.2-linux')->firstOrFail();
        $version->forceFill(['enabled' => true])->save();
        config(['toolkit.features.recolector_742.enabled' => true]);
        $execution = $this->execution($this->project());
        $approver = app(ApproveExecutionCapacity::class);
        $approval = $approver->approve($execution, 1024, 10, $execution->creator);
        $binding = app(BindExecutionTool::class)->bind($execution, $version, $version->distributions()->firstOrFail(), 'moodle.source.export', 'synthetic', 'local-registered-process', []);

        try {
            $approver->approve($execution, 2048, 10, $execution->creator);
            $this->fail('An approved bound quota must never change.');
        } catch (ToolOperationBlocked) {
            $this->assertSame($approval->approved_quota_bytes, $binding->fresh()->approved_quota_bytes);
            $this->assertSame($approval->fingerprint, $approval->fresh()->fingerprint);
        }
    }
}
