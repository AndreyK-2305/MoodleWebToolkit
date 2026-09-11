<?php

namespace Tests\Feature\Domain;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Iteration1FMigrationUpgradeTest extends TestCase
{
    public function test_iteration_1f_upgrades_rolls_back_and_reapplies_the_1e_schema(): void
    {
        $schema = 'iteration_1f_upgrade_'.Str::lower(Str::random(12));
        DB::statement(sprintf('CREATE SCHEMA "%s"', $schema));
        DB::statement(sprintf('SET search_path TO "%s"', $schema));

        try {
            $this->runMigrationsBeforeIteration1F();
            $seed = $this->seedIteration1EData();
            $migration = require database_path('migrations/2026_09_04_010000_add_iteration_1f_verification_closure.php');
            $receiptsMigration = require database_path('migrations/2026_09_05_010000_add_idempotency_receipts.php');
            $resumableMigration = require database_path('migrations/2026_09_06_010000_add_resumable_finalizations.php');
            $migration->up();
            $receiptsMigration->up();
            $resumableMigration->up();

            $this->assertMigratedState($seed);
            $this->seedIteration1FUsage($seed);
            $this->assertSame(1, DB::table('execution_finalizations')->where('stage', 'COMPLETED')->count());
            $resumableMigration->down();
            $receiptsMigration->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('academic_snapshots'));
            $this->assertFalse(Schema::hasTable('academic_proposals'));
            $this->assertFalse(Schema::hasTable('artifact_downloads'));
            $this->assertFalse(Schema::hasColumn('executions', 'proposal_version'));
            $this->assertFalse(Schema::hasColumn('verifications', 'fingerprint'));
            $this->assertFalse(Schema::hasTable('execution_finalizations'));
            $this->assertFalse(Schema::hasTable('idempotency_receipts'));
            $this->assertSame(0, DB::table('execution_commands')->where('command_type', 'PROPOSE')->count());
            $commandConstraint = DB::table('pg_constraint')
                ->where('conname', 'execution_commands_type_check')
                ->whereRaw('connamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema())')
                ->value(DB::raw('pg_get_constraintdef(oid)'));
            $this->assertIsString($commandConstraint);
            $this->assertStringNotContainsString('PROPOSE', $commandConstraint);
            $this->assertSame(1, DB::table('pg_trigger')
                ->where('tgname', 'execution_commands_completed_project_read_only')
                ->whereRaw('tgrelid = ?::regclass', ['execution_commands'])
                ->where('tgenabled', '<>', 'D')
                ->count());

            $migration->up();
            $receiptsMigration->up();
            $resumableMigration->up();
            $this->assertMigratedState($seed);
            $this->assertTrue(Schema::hasTable('execution_finalizations'));
        } finally {
            DB::statement('SET search_path TO public');
            DB::statement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        }
    }

    /** @param array{user_id: int, execution_id: int, other_execution_id: int, verification_id: int, artifact_id: int} $seed */
    private function seedIteration1FUsage(array $seed): void
    {
        $now = now();
        $fingerprint0 = hash('sha256', 'iteration-1f-snapshot');
        $fingerprint1 = hash('sha256', 'iteration-1f-proposal');
        $projectId = (int) DB::table('executions')->where('id', $seed['execution_id'])->value('project_id');
        DB::table('projects')->where('id', $projectId)->update(['status' => 'REVIEW', 'updated_at' => $now]);
        DB::table('executions')->where('id', $seed['execution_id'])->update([
            'status' => 'REVIEW',
            'progress' => 75,
            'proposal_version' => 1,
            'review_fingerprint' => $fingerprint1,
            'validated_proposal_version' => 1,
            'validated_fingerprint' => $fingerprint1,
            'updated_at' => $now,
        ]);
        DB::table('academic_snapshots')->insert([
            'execution_id' => $seed['execution_id'],
            'project_type' => 'COLLECT',
            'schema_version' => 1,
            'fingerprint' => $fingerprint0,
            'tree' => json_encode([[
                'id' => 'cat:root',
                'type' => 'category',
                'parent_id' => null,
                'short_name' => null,
                'name' => 'Raíz',
            ]], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        DB::table('academic_proposals')->insert([
            'execution_id' => $seed['execution_id'],
            'version' => 1,
            'operation' => 'RENAME_CATEGORY',
            'node_id' => 'cat:root',
            'node_type' => 'category',
            'old_value' => json_encode(['name' => 'Raíz'], JSON_THROW_ON_ERROR),
            'new_value' => json_encode(['name' => 'Raíz revisada'], JSON_THROW_ON_ERROR),
            'base_fingerprint' => $fingerprint0,
            'resulting_fingerprint' => $fingerprint1,
            'status' => 'ACTIVE',
            'proposed_by' => $seed['user_id'],
            'created_at' => $now,
        ]);
        DB::table('execution_commands')->insert([
            'execution_id' => $seed['execution_id'],
            'step_key' => 'academic-review',
            'attempt' => 1,
            'command_type' => 'PROPOSE',
            'idempotency_key' => 'rollback-proposal-1f',
            'idempotency_scope' => "execution:{$seed['execution_id']}:proposal:1",
            'payload_hash' => hash('sha256', 'rollback-proposal-1f'),
            'payload' => json_encode(['operation' => 'RENAME_CATEGORY'], JSON_THROW_ON_ERROR),
            'created_by' => $seed['user_id'],
            'dispatch_attempts' => 0,
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('verifications')->insert([
            'execution_id' => $seed['execution_id'],
            'key' => 'academic-review-v1',
            'proposal_version' => 1,
            'fingerprint' => $fingerprint1,
            'status' => 'PASSED',
            'approved' => true,
            'requested_by' => $seed['user_id'],
            'summary' => 'Validación 1F real',
            'details' => json_encode(['overall_status' => 'APPROVED'], JSON_THROW_ON_ERROR),
            'checked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('artifact_downloads')->insert([
            'artifact_id' => $seed['artifact_id'],
            'execution_id' => $seed['execution_id'],
            'user_id' => $seed['user_id'],
            'idempotency_key' => 'rollback-download-1f',
            'payload_hash' => hash('sha256', 'rollback-download-1f'),
            'downloaded_at' => $now,
        ]);
        $finalizeCommandId = DB::table('execution_commands')->insertGetId([
            'execution_id' => $seed['execution_id'],
            'step_key' => 'finalization',
            'attempt' => 1,
            'command_type' => 'FINALIZE',
            'idempotency_key' => 'rollback-finalize-1f',
            'idempotency_scope' => "execution:{$seed['execution_id']}:finalize",
            'payload_hash' => hash('sha256', 'rollback-finalize-1f'),
            'payload' => json_encode(['operation' => 'FINALIZE'], JSON_THROW_ON_ERROR),
            'created_by' => $seed['user_id'],
            'dispatch_attempts' => 1,
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('execution_finalizations')->insert([
            'execution_id' => $seed['execution_id'],
            'execution_command_id' => $finalizeCommandId,
            'stage' => 'COMPLETED',
            'log_cursor' => 0,
            'event_cursor' => 0,
            'staging_prefix' => 'executions/upgrade/.staging/'.$finalizeCommandId,
            'final_prefix' => 'executions/upgrade/final/'.$finalizeCommandId,
            'temporary_files' => '[]',
            'artifacts' => '[]',
            'verified_types' => '[]',
            'promoted_types' => '[]',
            'verification_offset' => 0,
            'finalization_started_at' => $now->copy()->subMinute(),
            'closure_ready_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('executions')->where('id', $seed['execution_id'])->update([
            'status' => 'COMPLETED',
            'progress' => 100,
            'finalized_by' => $seed['user_id'],
            'completion_summary' => json_encode(['result' => 'COMPLETED'], JSON_THROW_ON_ERROR),
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('projects')->where('id', $projectId)->update(['status' => 'COMPLETED', 'updated_at' => $now]);
    }

    /** @param array{user_id: int, execution_id: int, other_execution_id: int, verification_id: int, artifact_id: int} $seed */
    private function assertMigratedState(array $seed): void
    {
        $this->assertTrue(Schema::hasTable('academic_snapshots'));
        $this->assertTrue(Schema::hasTable('academic_proposals'));
        $this->assertTrue(Schema::hasTable('artifact_downloads'));
        $this->assertSame(0, (int) DB::table('executions')->where('id', $seed['execution_id'])->value('proposal_version'));
        $verification = DB::table('verifications')->where('id', $seed['verification_id'])->first();
        $this->assertNotNull($verification);
        $this->assertSame(0, (int) $verification->proposal_version);
        $this->assertNull($verification->fingerprint);
        $this->assertNull($verification->approved);
        $index = DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('indexname', 'executions_one_active_per_project_unique')
            ->value('indexdef');
        $this->assertIsString($index);
        $this->assertStringContainsString('VERIFYING', $index);
        $artifactIndex = DB::table('pg_indexes')
            ->where('schemaname', DB::raw('current_schema()'))
            ->where('indexname', 'artifacts_required_execution_type_unique')
            ->value('indexdef');
        $this->assertIsString($artifactIndex);
        $this->assertStringContainsString('FINAL_SUMMARY', $artifactIndex);
        $this->assertSame(2, DB::table('artifacts')
            ->where('execution_id', $seed['execution_id'])
            ->where('type', 'LEGACY_REPORT')
            ->count());

        try {
            DB::table('artifact_downloads')->insert([
                'artifact_id' => $seed['artifact_id'],
                'execution_id' => $seed['other_execution_id'],
                'user_id' => $seed['user_id'],
                'idempotency_key' => 'cross-execution-download',
                'payload_hash' => hash('sha256', 'cross-execution-download'),
                'downloaded_at' => now(),
            ]);
            $this->fail('Una descarga no debe apuntar a una ejecución distinta de su artefacto.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }

        foreach ([
            ['verifications', $seed['verification_id'], ['summary' => 'alterada']],
            ['artifacts', $seed['artifact_id'], ['filename' => 'alterado.json']],
        ] as [$table, $id, $change]) {
            try {
                DB::table($table)->where('id', $id)->update($change);
                $this->fail("{$table} debía permanecer append-only.");
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
    }

    private function runMigrationsBeforeIteration1F(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        foreach ($files as $file) {
            if (basename($file) === '2026_09_04_010000_add_iteration_1f_verification_closure.php') {
                break;
            }

            $migration = require $file;
            $migration->up();
        }
    }

    /** @return array{user_id: int, execution_id: int, other_execution_id: int, verification_id: int, artifact_id: int} */
    private function seedIteration1EData(): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Admin de actualización 1F',
            'email' => 'upgrade-1f@example.test',
            'email_verified_at' => $now,
            'password' => bcrypt('password'),
            'role' => 'ADMIN',
            'is_active' => true,
            'must_change_password' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $projectId = DB::table('projects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proyecto existente 1E',
            'type' => 'COLLECT',
            'status' => 'RUNNING',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $executionId = DB::table('executions')->insertGetId([
            'project_id' => $projectId,
            'uuid' => (string) Str::uuid(),
            'workspace_key' => (string) Str::uuid(),
            'attempt' => 1,
            'status' => 'RUNNING',
            'progress' => 50,
            'created_by' => $userId,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $verificationId = DB::table('verifications')->insertGetId([
            'execution_id' => $executionId,
            'key' => 'legacy-check',
            'status' => 'PASSED',
            'summary' => 'Verificación previa',
            'checked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $artifactId = DB::table('artifacts')->insertGetId([
            'execution_id' => $executionId,
            'type' => 'LEGACY_REPORT',
            'disk' => 'local',
            'path' => 'executions/legacy/report.json',
            'filename' => 'report.json',
            'mime_type' => 'application/json',
            'size' => 2,
            'sha256' => hash('sha256', '{}'),
            'created_at' => $now,
        ]);
        DB::table('artifacts')->insert([
            'execution_id' => $executionId,
            'type' => 'LEGACY_REPORT',
            'disk' => 'local',
            'path' => 'executions/legacy/report-copy.json',
            'filename' => 'report-copy.json',
            'mime_type' => 'application/json',
            'size' => 2,
            'sha256' => hash('sha256', '{}'),
            'created_at' => $now,
        ]);
        $otherProjectId = DB::table('projects')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Proyecto ajeno existente 1E',
            'type' => 'COLLECT',
            'status' => 'RUNNING',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $otherExecutionId = DB::table('executions')->insertGetId([
            'project_id' => $otherProjectId,
            'uuid' => (string) Str::uuid(),
            'workspace_key' => (string) Str::uuid(),
            'attempt' => 1,
            'status' => 'RUNNING',
            'progress' => 50,
            'created_by' => $userId,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'execution_id' => $executionId,
            'other_execution_id' => $otherExecutionId,
            'verification_id' => $verificationId,
            'artifact_id' => $artifactId,
        ];
    }
}
