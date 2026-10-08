<?php

namespace Tests\Feature\Tools;

use App\Domain\Collector\CollectorConfiguration;
use App\Domain\Collector\CollectorPhpRuntime;
use App\Domain\Collector\CollectorPreflight;
use App\Domain\Collector\CollectorRuntimeConfiguration;
use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Collector\EphemeralMoodleConfiguration;
use App\Domain\Collector\LabFileSecretProvider;
use App\Domain\Collector\LabMoodleProfiles;
use App\Domain\Collector\MoodleConfigurationMaterializer;
use App\Domain\Collector\TestingSecretProvider;
use App\Domain\Projects\ProjectWizard;
use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CollectorConfigurationRevision;
use App\Models\ToolDistribution;
use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Tests\Feature\Domain\DomainTestCase;

class CollectorConfigurationTest extends DomainTestCase
{
    private string $labRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->labRoot = sys_get_temp_dir().'/it3-profile-'.bin2hex(random_bytes(8));
        foreach (['code', 'data', 'secrets', 'workspaces'] as $directory) {
            mkdir($this->labRoot.'/'.$directory, 0700, true);
        }
        config(['collector.secret_root' => $this->labRoot.'/secrets', 'toolkit.workspaces.root' => $this->labRoot.'/workspaces',
            'collector.profiles' => ['test-lab' => [
                'name' => 'Moodle sintético de prueba', 'root' => $this->labRoot, 'code' => $this->labRoot.'/code', 'data' => $this->labRoot.'/data',
                'base_url' => 'http://moodle-lab.test', 'db_host' => 'moodle-lab-db', 'db_port' => 5432,
                'db_name' => 'moodle_lab', 'db_user' => 'moodle_lab', 'db_prefix' => 'mdl_',
                'credential_reference' => 'test-db', 'credential_version' => '1', 'source_id' => 'test-lab', 'moodle_series' => '4.5',
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->labRoot);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['profile_id' => 'test-lab', 'workers' => 1, 'package_name' => 'test-package', 'capacity_bytes' => 33_554_432, 'safety_margin_percent' => 20];
    }

    public function test_configuration_is_versioned_idempotent_and_invalidates_confirmation(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $service = app(CollectorConfiguration::class);
        $saved = $service->save($project, $actor, $this->input());
        $revision = CollectorConfigurationRevision::query()->sole();
        $this->assertSame(2, $saved->configuration->version);
        $this->assertSame($actor->id, $revision->created_by);
        $this->assertSame($service->hash($revision->snapshot), $revision->fingerprint);
        $this->assertSame(2, $service->save($saved, $actor, $this->input())->configuration->version);
        $settings = $saved->configuration->settings;
        $settings['preflight'] = ['configuration_version' => 2];
        $settings['confirmation'] = ['configuration_version' => 2];
        $saved->configuration->update(['settings' => $settings]);
        $saved->transitionTo(ProjectStatus::READY);
        $changed = $service->save($saved, $actor, [...$this->input(), 'workers' => 2]);
        $this->assertSame(3, $changed->configuration->version);
        $this->assertNull($changed->configuration->settings['preflight']);
        $this->assertNull($changed->configuration->settings['confirmation']);
        $this->assertSame(2, CollectorConfigurationRevision::query()->count());
        $this->assertSame(ProjectStatus::CONFIGURING, $changed->status);
    }

    public function test_configuration_rejects_inline_parameters_and_unknown_profile(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        foreach ([['db_password' => 'testing-only'], ['command' => 'any'], ['profile_id' => 'unknown'], ['workers' => 0], ['package_name' => '../outside']] as $invalid) {
            try {
                app(CollectorConfiguration::class)->save($project, $actor, [...$this->input(), ...$invalid]);
                $this->fail('Invalid configuration was accepted.');
            } catch (ValidationException|RuntimeException) {
                $this->assertSame(0, CollectorConfigurationRevision::query()->count());
            }
        }
    }

    public function test_profile_change_or_revision_tampering_invalidates_configuration(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $saved = app(CollectorConfiguration::class)->save($project, $actor, $this->input());
        $this->assertSame(1, app(CollectorConfiguration::class)->settings($saved->configuration)['workers']);
        config(['collector.profiles.test-lab.credential_version' => '2']);
        $this->expectException(RuntimeException::class);
        app(CollectorConfiguration::class)->settings($saved->configuration);
    }

    public function test_revision_is_immutable(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        app(CollectorConfiguration::class)->save($project, $actor, $this->input());
        $this->expectException(LogicException::class);
        CollectorConfigurationRevision::query()->sole()->update(['fingerprint' => str_repeat('0', 64)]);
    }

    public function test_database_rejects_raw_revision_changes_and_deletion(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        app(CollectorConfiguration::class)->save($project, $actor, $this->input());
        $revision = CollectorConfigurationRevision::query()->sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(function () use ($revision, $operation): void {
                    $query = DB::table('collector_configuration_revisions')->where('id', $revision->id);
                    $operation === 'update' ? $query->update(['created_by' => $this->user()->id]) : $query->delete();
                });
                $this->fail('Immutable revision was changed.');
            } catch (QueryException) {
                $this->assertSame($revision->fingerprint, $revision->fresh()->fingerprint);
                $this->assertSame($actor->id, $revision->fresh()->created_by);
            }
        }
    }

    public function test_read_only_actor_cannot_save_configuration(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $this->expectException(AuthorizationException::class);
        app(CollectorConfiguration::class)->save($project, $this->user(UserRole::AUDITOR), $this->input());
    }

    public function test_authorized_profiles_reject_credentials_external_urls_and_outside_paths(): void
    {
        foreach ([['base_url', 'https://user:embedded@moodle-lab.test'], ['base_url', 'https://institution.example'], ['data', '/etc'], ['code', $this->labRoot.'/../other']] as [$key, $value]) {
            $previous = config('collector.profiles.test-lab.'.$key);
            config(['collector.profiles.test-lab.'.$key => $value]);
            try {
                app(LabMoodleProfiles::class)->get('test-lab');
                $this->fail('Unsafe profile accepted.');
            } catch (RuntimeException) {
                $this->assertSame([], app(LabMoodleProfiles::class)->choices());
            } finally {
                config(['collector.profiles.test-lab.'.$key => $previous]);
            }
        }
    }

    public function test_lab_provider_requires_private_regular_versioned_file(): void
    {
        $path = $this->labRoot.'/secrets/test-db.1';
        file_put_contents($path, bin2hex(random_bytes(16)));
        chmod($path, 0644);
        $provider = app(LabFileSecretProvider::class);
        $this->assertFalse($provider->available('test-db', '1'));
        chmod($path, 0600);
        clearstatcache();
        $this->assertTrue($provider->available('test-db', '1'));
        $this->assertSame(32, $provider->consume('test-db', '1', strlen(...)));
        $this->assertFalse($provider->available('../test-db', '1'));
        $this->assertFalse($provider->available('test-db', '2'));
        link($path, $this->labRoot.'/secrets/alias.1');
        clearstatcache();
        $this->assertFalse($provider->available('test-db', '1'));
    }

    public function test_ephemeral_configuration_is_private_and_removed_on_exception(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $execution = $this->execution($this->project($actor));
        app(ApproveExecutionCapacity::class)->approve($execution, 16_777_216, 20, $actor);
        $material = bin2hex(random_bytes(16));
        $this->app->instance(SecretProvider::class, new TestingSecretProvider(['test-db' => ['1' => $material]]));
        $profile = app(LabMoodleProfiles::class)->get('test-lab');
        $path = '';
        try {
            app(EphemeralMoodleConfiguration::class)->consume($execution, $profile, function (string $generated) use (&$path, $material): never {
                $path = $generated;
                $this->assertSame(0600, fileperms($path) & 0777);
                $this->assertTrue(str_contains((string) file_get_contents($path), $material));
                throw new RuntimeException('Controlled failure.');
            });
        } catch (RuntimeException $error) {
            $this->assertSame('Controlled failure.', $error->getMessage());
        }
        $this->assertFileDoesNotExist($path);
        $this->assertStringNotContainsString($material, (string) json_encode($execution->fresh()->toArray()));
        $this->assertSame([], glob($this->labRoot.'/workspaces/*/*/input/*'));
    }

    public function test_lab_preflight_is_real_and_blocks_missing_moodle_without_fake_execution(): void
    {
        config(['toolkit.features.recolector_742.enabled' => true, 'toolkit.features.local_runner.enabled' => true]);
        $this->seed(ToolCatalogSeeder::class);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
        $distribution->toolVersion->update(['enabled' => true]);
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, $this->input());
        $checks = app(ProjectWizard::class)->runPreflight($project, $actor);
        $results = array_column($checks, 'result', 'id');
        $this->assertSame('SUCCESS', $results['collector.distribution']);
        $this->assertSame('ERROR', $results['collector.moodle']);
        $this->assertSame('ERROR', $results['collector.reference']);
        $this->assertSame('WARNING', $results['collector.laboratory']);
        $this->assertFalse(AuditLog::query()->where('action', 'PROJECT_PREFLIGHT_COMPLETED')->sole()->payload['simulated']);
        $this->assertSame(0, $project->executions()->count());
        $this->expectException(ValidationException::class);
        app(ProjectWizard::class)->confirm($project->fresh('configuration'), $actor, 2, ['collector.laboratory']);
    }

    public function test_closed_flag_and_changed_distribution_block_preflight(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, $this->input());
        $preflight = app(CollectorPreflight::class);
        $this->assertSame('ERROR', $preflight->evaluate($project, $project->configuration)[0]['result']);
        config(['toolkit.features.recolector_742.enabled' => true, 'toolkit.features.local_runner.enabled' => true]);
        $this->seed(ToolCatalogSeeder::class);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
        $distribution->toolVersion->update(['enabled' => true]);
        $before = $preflight->fingerprint($project, $project->configuration);
        $distribution->update(['distribution_sha256' => str_repeat('f', 64)]);
        $this->assertNotSame($before, $preflight->fingerprint($project, $project->configuration));
        $this->assertSame('ERROR', array_column($preflight->evaluate($project, $project->configuration), 'result', 'id')['collector.distribution']);
    }

    public function test_lab_props_show_only_profile_choices_without_paths_or_credential_reference(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, $this->input());
        $response = $this->actingAs($actor)->get(route('projects.show', $project->uuid));
        $response->assertInertia(fn (Assert $page) => $page->component('projects/collector')
            ->where('collectorLab.profiles.0.id', 'test-lab')
            ->where('project.options.mode', 'LABORATORY'));
        $this->assertStringNotContainsString($this->labRoot, $response->getContent());
        $this->assertStringNotContainsString('test-db', $response->getContent());
    }

    public function test_http_configuration_requires_flags_assignment_and_fresh_action_authorization(): void
    {
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $url = route('projects.collector.configuration', $project->uuid);
        $this->actingAs($actor)->putJson($url, $this->input())->assertStatus(409);
        config(['toolkit.features.recolector_742.enabled' => true, 'toolkit.features.local_runner.enabled' => true]);
        $this->actingAs($this->user())->putJson($url, $this->input())->assertForbidden();
        $this->actingAs($this->user(UserRole::AUDITOR))->putJson($url, $this->input())->assertForbidden();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp - (int) config('auth.password_timeout') - 1])->putJson($url, $this->input())->assertStatus(423);
        $this->actingAs($actor)->putJson($url, [...$this->input(), 'db_password' => 'testing-only'])->assertUnprocessable();
        $this->assertSame(0, CollectorConfigurationRevision::query()->count());
        $this->actingAs($actor)->putJson($url, $this->input())->assertRedirect(route('projects.show', $project->uuid));
        $this->assertSame(1, CollectorConfigurationRevision::query()->count());
    }

    public function test_runtime_configuration_is_rendered_approved_pinned_and_contains_only_references(): void
    {
        $this->seed(ToolCatalogSeeder::class);
        $actor = $this->user(UserRole::ADMIN);
        $project = app(ProjectWizard::class)->create($actor, ['name' => 'Collect LAB', 'type' => 'COLLECT']);
        $project = app(CollectorConfiguration::class)->save($project, $actor, $this->input());
        $execution = $this->execution($project);
        app(ApproveExecutionCapacity::class)->approve($execution, 33_554_432, 20, $actor);
        $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
        $renderer = app(CollectorRuntimeConfiguration::class);
        $runtime = $renderer->approve($execution, $project->configuration, $distribution, $actor);
        $document = $renderer->verify($execution, $runtime);
        $this->assertSame('lab', $document['scope']);
        $this->assertSame('test-db', $document['profile']['credential_reference']);
        $this->assertSame(0, $document['notify_every']);
        $this->assertSame($runtime->id, $renderer->approve($execution, $project->configuration, $distribution, $actor)->id);
        $this->assertArrayNotHasKey('dbpass', $document['profile']);
        $path = app(ExecutionWorkspaceManager::class)->resolve($execution, 'state', $runtime->relative_path);
        $this->assertSame(0600, fileperms($path) & 0777);
        file_put_contents($path, '{}');
        $this->expectException(RuntimeException::class);
        $renderer->verify($execution, $runtime);
    }

    public function test_standalone_materializer_rejects_paths_outside_execution_input(): void
    {
        $provider = new TestingSecretProvider(['test-db' => ['1' => 'testing-only']]);
        $materializer = new MoodleConfigurationMaterializer($provider, $this->labRoot.'/workspaces');
        $profile = app(LabMoodleProfiles::class)->get('test-lab');
        $this->expectException(RuntimeException::class);
        $materializer->consume($this->labRoot.'/outside.php', $profile, fn (): null => null);
    }

    public function test_web_php_84_does_not_substitute_the_collector_php_83_runtime(): void
    {
        $ini = $this->labRoot.'/probe.ini';
        file_put_contents($ini, 'memory_limit=32M');
        config(['collector.php_binary' => PHP_BINARY, 'collector.php_ini' => $ini, 'collector.php_scan_dir' => $this->labRoot]);
        $this->assertFalse(app(CollectorPhpRuntime::class)->available());
    }
}
