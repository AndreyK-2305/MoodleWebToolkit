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
            $migration->up();

            $this->assertMigratedState($seed);
            $migration->down();
            $this->assertFalse(Schema::hasTable('academic_snapshots'));
            $this->assertFalse(Schema::hasTable('academic_proposals'));
            $this->assertFalse(Schema::hasTable('artifact_downloads'));
            $this->assertFalse(Schema::hasColumn('executions', 'proposal_version'));
            $this->assertFalse(Schema::hasColumn('verifications', 'fingerprint'));

            $migration->up();
            $this->assertMigratedState($seed);
        } finally {
            DB::statement('SET search_path TO public');
            DB::statement(sprintf('DROP SCHEMA IF EXISTS "%s" CASCADE', $schema));
        }
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
