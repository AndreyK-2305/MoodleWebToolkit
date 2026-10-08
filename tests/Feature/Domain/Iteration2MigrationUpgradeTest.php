<?php

namespace Tests\Feature\Domain;

use Database\Seeders\ToolCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Iteration2MigrationUpgradeTest extends TestCase
{
    public function test_iteration_2_migrations_upgrade_rollback_and_reapply_while_preserving_legacy_versions(): void
    {
        $schema = 'iteration_2_upgrade_'.Str::lower(Str::random(12));
        DB::statement(sprintf('CREATE SCHEMA "%s"', $schema));
        DB::statement(sprintf('SET search_path TO "%s"', $schema));

        try {
            $this->runMigrationsBeforeIteration2();
            $now = now()->utc();
            $legacyToolId = DB::table('tools')->insertGetId([
                'key' => 'legacy-archive-tool',
                'name' => 'Legacy archive tool',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $legacyVersionId = DB::table('tool_versions')->insertGetId([
                'tool_id' => $legacyToolId,
                'version' => '1.0.0-archive',
                'archive_name' => 'legacy.zip',
                'archive_sha256' => hash('sha256', 'legacy archive'),
                'tree_sha256' => null,
                'enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $foundation = require database_path('migrations/2026_10_07_010000_add_iteration_2_tool_runtime_foundation.php');
            $runtime = require database_path('migrations/2026_10_07_020000_harden_iteration_2_execution_runtime.php');
            $foundation->up();
            $treeToolId = DB::table('tools')->insertGetId([
                'key' => 'moodle-recolector',
                'name' => 'Recolector',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $treeVersionId = DB::table('tool_versions')->insertGetId([
                'tool_id' => $treeToolId,
                'version' => '7.4.2-linux',
                'archive_name' => null,
                'archive_sha256' => null,
                'tree_sha256' => hash('sha256', 'tree distribution'),
                'enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = DB::table('users')->insertGetId([
                'name' => 'Migration test operator',
                'email' => 'iteration-2-migration@example.test',
                'password' => 'not-used',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $projectId = DB::table('projects')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => 'Migration backfill project',
                'type' => 'COLLECT',
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $executionId = DB::table('executions')->insertGetId([
                'project_id' => $projectId,
                'uuid' => (string) Str::uuid(),
                'attempt' => 1,
                'workspace_key' => (string) Str::uuid(),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $distributionSha256 = hash('sha256', 'tree distribution');
            $distributionId = DB::table('tool_distributions')->insertGetId([
                'tool_version_id' => $treeVersionId,
                'key' => 'tree-distribution',
                'kind' => 'TREE',
                'source_path' => 'BaseLine/Recolector',
                'distribution_sha256' => $distributionSha256,
                'file_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('execution_tool_bindings')->insert([
                'execution_id' => $executionId,
                'tool_version_id' => $treeVersionId,
                'tool_distribution_id' => $distributionId,
                'workflow_key' => 'collect',
                'adapter_key' => 'recolector',
                'provider_key' => 'fake',
                'distribution_sha256' => $distributionSha256,
                'configuration_sha256' => hash('sha256', 'configuration'),
                'capabilities_snapshot' => '[]',
                'created_at' => $now,
            ]);
            $runtime->up();
            $this->seed(ToolCatalogSeeder::class);

            $this->assertTrue(Schema::hasTable('source_packages'));
            $this->assertSame((int) $projectId, (int) DB::table('execution_tool_bindings')->where('execution_id', $executionId)->value('project_id'));
            $this->assertSame(1, DB::table('pg_trigger')
                ->where('tgname', 'execution_tool_bindings_append_only')
                ->whereRaw('tgrelid = ?::regclass', ['execution_tool_bindings'])
                ->where('tgenabled', '<>', 'D')
                ->count());
            $this->assertSame(4, DB::table('tool_versions')->count());
            Schema::create('legacy_version_references', function (Blueprint $table): void {
                $table->foreignId('tool_version_id')->constrained('tool_versions')->restrictOnDelete();
            });
            DB::table('legacy_version_references')->insert(['tool_version_id' => $treeVersionId]);
            try {
                DB::transaction(function () use ($runtime, $foundation): void {
                    $runtime->down();
                    $foundation->down();
                });
                $this->fail('A referenced tree-only version must block destructive rollback.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('SQLSTATE[23503]', $exception->getMessage());
                $this->assertTrue(Schema::hasTable('source_packages'));
                $this->assertTrue(Schema::hasTable('execution_tool_bindings'));
                $this->assertSame(4, DB::table('tool_versions')->count());
                $this->assertSame(1, DB::table('pg_trigger')->where('tgname', 'source_packages_identity_immutable')
                    ->whereRaw('tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace = ?::regnamespace)', [$schema])->count());
            }
            Schema::drop('legacy_version_references');
            DB::transaction(function () use ($runtime, $foundation): void {
                $runtime->down();
                $foundation->down();
            });

            $this->assertFalse(Schema::hasTable('source_packages'));
            $this->assertSame(1, DB::table('tool_versions')->count());
            $this->assertSame($legacyVersionId, DB::table('tool_versions')->value('id'));
            $this->assertSame(0, DB::table('tools')->where('id', $treeToolId)->count());
            $this->assertSame('NO', DB::table('information_schema.columns')
                ->where('table_schema', $schema)
                ->where('table_name', 'tool_versions')
                ->where('column_name', 'archive_name')
                ->value('is_nullable'));
            $this->assertSame('NO', DB::table('information_schema.columns')
                ->where('table_schema', $schema)->where('table_name', 'tool_versions')
                ->where('column_name', 'archive_sha256')->value('is_nullable'));
            $this->assertSame(1, DB::table('pg_trigger')
                ->where('tgname', 'execution_commands_completed_project_read_only')
                ->whereRaw('tgrelid = ?::regclass', ['execution_commands'])
                ->where('tgenabled', '<>', 'D')
                ->count());
            $this->assertSame(0, DB::table('pg_trigger')
                ->where('tgname', 'source_packages_identity_immutable')
                ->whereRaw('tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace = ?::regnamespace)', [$schema])
                ->count());

            $foundation->up();
            $runtime->up();
            $this->assertTrue(Schema::hasTable('source_packages'));
            $this->assertSame(1, DB::table('tool_versions')->where('id', $legacyVersionId)->count());
            $this->assertSame('YES', DB::table('information_schema.columns')
                ->where('table_schema', $schema)
                ->where('table_name', 'tool_versions')
                ->where('column_name', 'archive_name')
                ->value('is_nullable'));
            $this->assertSame(1, DB::table('pg_trigger')
                ->where('tgname', 'source_packages_identity_immutable')
                ->whereRaw('tgrelid IN (SELECT oid FROM pg_class WHERE relnamespace = ?::regnamespace)', [$schema])
                ->count());
            $this->assertSame(0, DB::table('tool_versions')->where('id', $treeVersionId)->count());
        } finally {
            DB::statement('SET search_path TO public');
            DB::statement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        }
    }

    private function runMigrationsBeforeIteration2(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        foreach ($files as $file) {
            if (basename($file) === '2026_10_07_010000_add_iteration_2_tool_runtime_foundation.php') {
                break;
            }

            $migration = require $file;
            $migration->up();
        }
    }
}
