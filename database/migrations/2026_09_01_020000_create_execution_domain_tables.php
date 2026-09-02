<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('attempt');
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedSmallInteger('progress')->nullable();
            $table->unsignedBigInteger('last_event_sequence')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resumed_from_execution_id')->nullable()->constrained('executions')->restrictOnDelete();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'attempt']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('execution_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('step_key', 100)->default('__execution__');
            $table->unsignedInteger('attempt')->default(1);
            $table->string('command_type', 32);
            $table->string('idempotency_key', 120)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->jsonb('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['execution_id', 'step_key', 'attempt', 'command_type'],
                'execution_commands_logical_key_unique',
            );
            $table->unique(['execution_id', 'idempotency_key']);
        });

        Schema::create('execution_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('step_key', 100);
            $table->unsignedInteger('attempt')->default(1);
            $table->string('name', 160);
            $table->unsignedInteger('position');
            $table->string('status', 24)->default('PENDING');
            $table->unsignedSmallInteger('progress')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['execution_id', 'step_key', 'attempt']);
            $table->unique(['execution_id', 'position', 'attempt']);
            $table->unique(['id', 'execution_id']);
        });

        Schema::create('execution_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 80);
            $table->string('step_key', 100)->nullable();
            $table->string('severity', 16)->default('INFO');
            $table->unsignedSmallInteger('progress')->nullable();
            $table->text('message')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['execution_id', 'sequence']);
            $table->index(['execution_id', 'created_at']);
        });

        Schema::create('execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('execution_step_id')->nullable();
            $table->string('stream', 16)->default('SYSTEM');
            $table->string('level', 16)->default('INFO');
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestampTz('logged_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['execution_step_id', 'execution_id'])
                ->references(['id', 'execution_id'])
                ->on('execution_steps')
                ->restrictOnDelete();
            $table->index(['execution_id', 'logged_at']);
        });

        Schema::create('checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('step_key', 100);
            $table->string('type', 80);
            $table->text('resume_token');
            $table->boolean('validated')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['execution_id', 'step_key', 'validated']);
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->foreignId('resume_checkpoint_id')
                ->nullable()
                ->after('resumed_from_execution_id')
                ->constrained('checkpoints')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE executions ADD CONSTRAINT executions_status_check CHECK (status IN ('QUEUED', 'RUNNING', 'WAITING_USER_ACTION', 'CANCELLING', 'CANCELLED', 'FAILED', 'VERIFYING', 'REVIEW', 'COMPLETED'))");
            DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_attempt_check CHECK (attempt > 0)');
            DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_last_event_sequence_check CHECK (last_event_sequence >= 0)');
            DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_progress_check CHECK (progress IS NULL OR progress BETWEEN 0 AND 100)');
            DB::statement("CREATE UNIQUE INDEX executions_one_active_per_project_unique ON executions (project_id) WHERE status IN ('QUEUED', 'RUNNING', 'WAITING_USER_ACTION', 'CANCELLING', 'VERIFYING')");
            DB::statement("ALTER TABLE execution_commands ADD CONSTRAINT execution_commands_type_check CHECK (command_type IN ('START', 'CONTINUE', 'RESOLVE_CONFLICT', 'RESUME', 'CANCEL', 'VALIDATE', 'FINALIZE'))");
            DB::statement('ALTER TABLE execution_commands ADD CONSTRAINT execution_commands_attempt_check CHECK (attempt > 0)');
            DB::statement('ALTER TABLE execution_commands ADD CONSTRAINT execution_commands_payload_hash_check CHECK (payload_hash IS NULL OR payload_hash ~ \'^[0-9a-f]{64}$\')');
            DB::statement("ALTER TABLE execution_steps ADD CONSTRAINT execution_steps_status_check CHECK (status IN ('PENDING', 'RUNNING', 'WAITING_USER', 'SUCCESS', 'FAILED', 'CANCELLED', 'REUSED'))");
            DB::statement('ALTER TABLE execution_steps ADD CONSTRAINT execution_steps_attempt_check CHECK (attempt > 0)');
            DB::statement('ALTER TABLE execution_steps ADD CONSTRAINT execution_steps_position_check CHECK (position > 0)');
            DB::statement('ALTER TABLE execution_steps ADD CONSTRAINT execution_steps_progress_check CHECK (progress IS NULL OR progress BETWEEN 0 AND 100)');
            DB::statement("ALTER TABLE execution_events ADD CONSTRAINT execution_events_severity_check CHECK (severity IN ('INFO', 'WARNING', 'ERROR'))");
            DB::statement('ALTER TABLE execution_events ADD CONSTRAINT execution_events_sequence_check CHECK (sequence > 0)');
            DB::statement('ALTER TABLE execution_events ADD CONSTRAINT execution_events_progress_check CHECK (progress IS NULL OR progress BETWEEN 0 AND 100)');
            DB::statement("ALTER TABLE execution_logs ADD CONSTRAINT execution_logs_stream_check CHECK (stream IN ('STDOUT', 'STDERR', 'SYSTEM'))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('executions') && Schema::hasColumn('executions', 'resume_checkpoint_id')) {
            Schema::table('executions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('resume_checkpoint_id');
            });
        }

        Schema::dropIfExists('checkpoints');
        Schema::dropIfExists('execution_logs');
        Schema::dropIfExists('execution_events');
        Schema::dropIfExists('execution_steps');
        Schema::dropIfExists('execution_commands');
        Schema::dropIfExists('executions');
    }
};
