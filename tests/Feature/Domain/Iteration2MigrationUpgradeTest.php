<?php

namespace Tests\Feature\Domain;

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
            $runtime->up();

            $this->assertTrue(Schema::hasTable('source_packages'));
            $this->assertSame(2, DB::table('tool_versions')->count());
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
            $this->assertSame(1, DB::table('pg_trigger')
                ->where('tgname', 'execution_commands_completed_project_read_only')
                ->whereRaw('tgrelid = ?::regclass', ['execution_commands'])
                ->where('tgenabled', '<>', 'D')
                ->count());
            $this->assertSame(0, DB::table('pg_trigger')
                ->where('tgname', 'source_packages_identity_immutable')
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
