<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_finalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('execution_command_id')->unique()->constrained('execution_commands')->cascadeOnDelete();
            $table->string('stage', 32)->default('PREPARE');
            $table->unsignedBigInteger('log_cursor')->default(0);
            $table->unsignedBigInteger('event_cursor')->default(0);
            $table->uuid('lease_owner')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('staging_prefix', 500);
            $table->string('final_prefix', 500);
            $table->jsonb('temporary_files')->default('[]');
            $table->jsonb('artifacts')->default('[]');
            $table->jsonb('verified_types')->default('[]');
            $table->jsonb('promoted_types')->default('[]');
            $table->string('verification_type', 32)->nullable();
            $table->unsignedBigInteger('verification_offset')->default(0);
            $table->jsonb('verification_hash_state')->nullable();
            $table->timestampTz('finalization_started_at')->nullable();
            $table->timestampTz('closure_ready_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['stage', 'updated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE execution_finalizations ADD CONSTRAINT execution_finalizations_stage_check CHECK (stage IN ('PREPARE', 'EXPORT_LOGS', 'EXPORT_EVENTS', 'GENERATE_REPORTS', 'VERIFY_STAGING', 'PROMOTE_ARTIFACTS', 'FINAL_SUMMARY', 'PROMOTE_SUMMARY', 'COMMIT', 'COMPLETED'))");
            DB::statement('ALTER TABLE execution_finalizations ADD CONSTRAINT execution_finalizations_cursor_check CHECK (log_cursor >= 0 AND event_cursor >= 0 AND verification_offset >= 0)');
            DB::statement("ALTER TABLE execution_finalizations ADD CONSTRAINT execution_finalizations_completed_check CHECK ((stage = 'COMPLETED') = (completed_at IS NOT NULL))");
            DB::statement('CREATE TRIGGER execution_finalizations_completed_project_read_only BEFORE INSERT OR UPDATE OR DELETE ON execution_finalizations FOR EACH ROW EXECUTE FUNCTION reject_completed_project_child_change()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_finalizations');
    }
};
