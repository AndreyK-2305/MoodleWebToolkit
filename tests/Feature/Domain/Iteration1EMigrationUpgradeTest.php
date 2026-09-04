<?php

namespace Tests\Feature\Domain;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Iteration1EMigrationUpgradeTest extends TestCase
{
    public function test_iteration_1e_migrates_existing_completed_projects_without_weakening_guards(): void
    {
        $schema = 'iteration_1e_upgrade_'.Str::lower(Str::random(12));
        DB::statement(sprintf('CREATE SCHEMA "%s"', $schema));
        DB::statement(sprintf('SET search_path TO "%s"', $schema));

        try {
            $this->runMigrationsBeforeIteration1E();
            $lineage = $this->seedPreIteration1EData();

            $migration = require database_path('migrations/2026_09_03_020000_add_iteration_1e_fields.php');
            $migration->up();

            $this->assertMigratedState($lineage);
            $this->assertCompletedExecutionIsReadOnly($lineage['completed_execution_id']);

            $migration->down();
            $this->assertFalse(Schema::hasColumn('executions', 'workspace_key'));
            $this->assertFalse(Schema::hasColumn('checkpoints', 'adapter_key'));
            $this->assertFalse(Schema::hasColumn('conflicts', 'version'));

            $migration->up();
            $this->assertMigratedState($lineage);
            $this->assertCompletedExecutionIsReadOnly($lineage['completed_execution_id']);
        } finally {
            DB::statement('SET search_path TO public');
            DB::statement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        }
    }

    /** @param array{completed_execution_id: int, source_execution_id: int, checkpoint_id: int, resumed_execution_id: int} $lineage */
    private function assertMigratedState(array $lineage): void
    {
        $executions = DB::table('executions')->orderBy('id')->get();
        $this->assertCount(3, $executions);
        $this->assertSame(3, $executions->pluck('workspace_key')->filter()->unique()->count());
        $this->assertSame(
            $lineage['source_execution_id'],
            (int) DB::table('executions')->where('id', $lineage['resumed_execution_id'])->value('resumed_from_execution_id'),
        );
        $this->assertSame(
            $lineage['checkpoint_id'],
            (int) DB::table('executions')->where('id', $lineage['resumed_execution_id'])->value('resume_checkpoint_id'),
        );
    }

    private function assertCompletedExecutionIsReadOnly(int $executionId): void
    {
        try {
            DB::table('executions')->where('id', $executionId)->update(['progress' => 99]);
            $this->fail('El guard de proyecto completado debía seguir activo después de la migración.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    private function runMigrationsBeforeIteration1E(): void
    {
        $cutoff = '2026_09_03_020000_add_iteration_1e_fields.php';
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        foreach ($files as $file) {
            if (basename($file) === $cutoff) {
                break;
            }

            $migration = require $file;
            $migration->up();
        }
    }

    /** @return array{completed_execution_id: int, source_execution_id: int, checkpoint_id: int, resumed_execution_id: int} */
    private function seedPreIteration1EData(): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Migration Admin',
            'email' => 'migration-admin@example.test',
            'email_verified_at' => $now,
            'password' => bcrypt('password'),
            'role' => 'ADMIN',
            'is_active' => true,
            'must_change_password' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $completedProjectId = DB::table('projects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proyecto completado previo a 1E',
            'type' => 'COLLECT',
            'status' => 'RUNNING',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $completedExecutionId = DB::table('executions')->insertGetId([
            'project_id' => $completedProjectId,
            'uuid' => (string) Str::uuid(),
            'attempt' => 1,
            'status' => 'RUNNING',
            'progress' => 50,
            'created_by' => $userId,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('executions')->where('id', $completedExecutionId)->update([
            'status' => 'COMPLETED',
            'progress' => 100,
            'finished_at' => $now,
        ]);
        DB::table('projects')->where('id', $completedProjectId)->update(['status' => 'COMPLETED']);

        $failedProjectId = DB::table('projects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proyecto con linaje previo a 1E',
            'type' => 'COLLECT',
            'status' => 'FAILED',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sourceExecutionId = DB::table('executions')->insertGetId([
            'project_id' => $failedProjectId,
            'uuid' => (string) Str::uuid(),
            'attempt' => 1,
            'status' => 'FAILED',
            'progress' => 25,
            'created_by' => $userId,
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $checkpointId = DB::table('checkpoints')->insertGetId([
            'execution_id' => $sourceExecutionId,
            'step_key' => 'operation',
            'type' => 'SIMULATED_FAILURE',
            'resume_token' => 'pre-1e-token',
            'validated' => true,
            'metadata' => json_encode(['source' => 'upgrade-test'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        $resumedExecutionId = DB::table('executions')->insertGetId([
            'project_id' => $failedProjectId,
            'uuid' => (string) Str::uuid(),
            'attempt' => 2,
            'status' => 'FAILED',
            'progress' => 25,
            'created_by' => $userId,
            'resumed_from_execution_id' => $sourceExecutionId,
            'resume_checkpoint_id' => $checkpointId,
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'completed_execution_id' => $completedExecutionId,
            'source_execution_id' => $sourceExecutionId,
            'checkpoint_id' => $checkpointId,
            'resumed_execution_id' => $resumedExecutionId,
        ];
    }
}
