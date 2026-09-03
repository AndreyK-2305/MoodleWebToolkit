<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_commands', function (Blueprint $table) {
            $table->string('idempotency_scope', 180)->nullable()->after('idempotency_key');
            $table->timestampTz('dispatched_at')->nullable()->after('created_by');
            $table->timestampTz('processing_started_at')->nullable()->after('dispatched_at');
            $table->unsignedInteger('dispatch_attempts')->default(0)->after('processing_started_at');

            $table->unique(
                ['idempotency_scope', 'idempotency_key'],
                'execution_commands_idempotency_scope_unique',
            );
            $table->index(
                ['processed_at', 'dispatched_at'],
                'execution_commands_dispatch_recovery_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE execution_commands ADD CONSTRAINT execution_commands_dispatch_attempts_check CHECK (dispatch_attempts >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE execution_commands DROP CONSTRAINT IF EXISTS execution_commands_dispatch_attempts_check');
        }

        Schema::table('execution_commands', function (Blueprint $table) {
            $table->dropIndex('execution_commands_dispatch_recovery_index');
            $table->dropUnique('execution_commands_idempotency_scope_unique');
            $table->dropColumn([
                'idempotency_scope',
                'dispatched_at',
                'processing_started_at',
                'dispatch_attempts',
            ]);
        });
    }
};
