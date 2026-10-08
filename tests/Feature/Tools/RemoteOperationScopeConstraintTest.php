<?php

namespace Tests\Feature\Tools;

use App\Domain\Workspaces\ApproveExecutionCapacity;
use App\Models\RemoteOperation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Domain\DomainTestCase;

class RemoteOperationScopeConstraintTest extends DomainTestCase
{
    #[DataProvider('scopedRows')]
    public function test_postgresql_rejects_an_operation_from_another_execution(string $table, array $attributes): void
    {
        $execution = $this->execution($this->project());
        $foreign = $this->execution($this->project());
        $operation = RemoteOperation::query()->create([
            'execution_id' => $foreign->getKey(), 'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'scope-fixture', 'provider_key' => 'local-registered-process',
            'runtime_key' => 'workspace-process-v2', 'command_key' => 'synthetic',
            'command_sha256' => hash('sha256', 'synthetic'),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SQLSTATE[23503]');
        DB::table($table)->insert([...$attributes, 'execution_id' => $execution->getKey(), 'remote_operation_id' => $operation->getKey()]);
    }

    public static function scopedRows(): array
    {
        return [
            ['execution_events', ['sequence' => 1, 'type' => 'scope-fixture', 'severity' => 'INFO']],
            ['execution_logs', ['stream' => 'STDOUT', 'level' => 'INFO', 'message' => 'scope-fixture']],
            ['artifacts', ['type' => 'report', 'disk' => 'local', 'path' => 'artifacts/scope.txt', 'filename' => 'scope.txt', 'size' => 1, 'sha256' => str_repeat('a', 64)]],
        ];
    }

    public function test_postgresql_rejects_direct_modification_of_an_approved_quota(): void
    {
        $execution = $this->execution($this->project());
        $approval = app(ApproveExecutionCapacity::class)->approve($execution, 1024, 10, $execution->creator);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Execution capacity approvals are immutable');
        DB::table('execution_capacity_approvals')->where('id', $approval->getKey())->update(['approved_quota_bytes' => 4096]);
    }
}
