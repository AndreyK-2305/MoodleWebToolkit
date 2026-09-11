<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('resource_type', 32);
            $table->unsignedBigInteger('resource_id');
            $table->string('scope', 180);
            $table->string('idempotency_key', 120);
            $table->string('payload_hash', 64);
            $table->string('result_type', 32);
            $table->unsignedBigInteger('result_id');
            $table->unsignedSmallInteger('response_status');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['execution_id', 'user_id', 'action', 'idempotency_key'],
                'idempotency_receipts_actor_action_key_unique',
            );
            $table->index(['result_type', 'result_id']);
        });

        DB::table('execution_commands')
            ->whereIn('command_type', ['PROPOSE', 'VALIDATE', 'FINALIZE'])
            ->orderBy('id')
            ->eachById(function (object $command): void {
                DB::table('idempotency_receipts')->insertOrIgnore([
                    'execution_id' => $command->execution_id,
                    'user_id' => $command->created_by,
                    'action' => $command->command_type,
                    'resource_type' => 'execution',
                    'resource_id' => $command->execution_id,
                    'scope' => $command->idempotency_scope,
                    'idempotency_key' => $command->idempotency_key,
                    'payload_hash' => $command->payload_hash,
                    'result_type' => 'execution_command',
                    'result_id' => $command->id,
                    'response_status' => $command->command_type === 'PROPOSE' ? 201 : 202,
                    'created_at' => $command->created_at,
                ]);
            });

        DB::table('artifact_downloads')->orderBy('id')->eachById(function (object $download): void {
            DB::table('idempotency_receipts')->insertOrIgnore([
                'execution_id' => $download->execution_id,
                'user_id' => $download->user_id,
                'action' => 'DOWNLOAD',
                'resource_type' => 'artifact',
                'resource_id' => $download->artifact_id,
                'scope' => "execution:{$download->execution_id}:artifact:{$download->artifact_id}:download",
                'idempotency_key' => $download->idempotency_key,
                'payload_hash' => $download->payload_hash,
                'result_type' => 'artifact_download',
                'result_id' => $download->id,
                'response_status' => 200,
                'created_at' => $download->downloaded_at,
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE idempotency_receipts ADD CONSTRAINT idempotency_receipts_payload_hash_check CHECK (payload_hash ~ '^[0-9a-f]{64}$')");
            DB::statement('ALTER TABLE idempotency_receipts ADD CONSTRAINT idempotency_receipts_response_status_check CHECK (response_status BETWEEN 100 AND 599)');
            DB::statement('CREATE TRIGGER idempotency_receipts_append_only BEFORE UPDATE OR DELETE ON idempotency_receipts FOR EACH ROW EXECUTE FUNCTION reject_iteration_1f_result_change()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_receipts');
    }
};
