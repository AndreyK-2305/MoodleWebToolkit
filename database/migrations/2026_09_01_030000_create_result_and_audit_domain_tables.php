<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('execution_step_id')->nullable();
            $table->string('key', 120);
            $table->string('type', 80);
            $table->string('status', 16)->default('OPEN');
            $table->jsonb('details');
            $table->jsonb('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['execution_step_id', 'execution_id'])
                ->references(['id', 'execution_id'])
                ->on('execution_steps')
                ->restrictOnDelete();
            $table->unique(['execution_id', 'key']);
            $table->index(['execution_id', 'status']);
        });

        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('status', 16)->default('PENDING');
            $table->text('summary')->nullable();
            $table->jsonb('details')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['execution_id', 'key']);
            $table->index(['execution_id', 'status']);
        });

        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('disk', 80);
            $table->string('path', 1024);
            $table->string('filename');
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['disk', 'path']);
            $table->index(['execution_id', 'type']);
        });

        Schema::create('tools', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('tool_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tool_id')->constrained()->restrictOnDelete();
            $table->string('version', 80);
            $table->string('archive_name');
            $table->string('archive_sha256', 64)->unique();
            $table->string('tree_sha256', 64)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestampsTz();

            $table->unique(['tool_id', 'version']);
            $table->index(['tool_id', 'enabled']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120);
            $table->nullableMorphs('auditable');
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
            $table->index(['execution_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addConstraints();
            $this->addReadOnlyGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->dropReadOnlyGuards();
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('tool_versions');
        Schema::dropIfExists('tools');
        Schema::dropIfExists('artifacts');
        Schema::dropIfExists('verifications');
        Schema::dropIfExists('conflicts');
    }

    private function addConstraints(): void
    {
        DB::statement("ALTER TABLE conflicts ADD CONSTRAINT conflicts_status_check CHECK (status IN ('OPEN', 'RESOLVED', 'IGNORED'))");
        DB::statement("ALTER TABLE verifications ADD CONSTRAINT verifications_status_check CHECK (status IN ('PENDING', 'PASSED', 'WARNING', 'FAILED'))");
        DB::statement("ALTER TABLE artifacts ADD CONSTRAINT artifacts_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE artifacts ADD CONSTRAINT artifacts_size_check CHECK (size >= 0)');
        DB::statement("ALTER TABLE tool_versions ADD CONSTRAINT tool_versions_archive_sha256_check CHECK (archive_sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE tool_versions ADD CONSTRAINT tool_versions_tree_sha256_check CHECK (tree_sha256 IS NULL OR tree_sha256 ~ '^[0-9a-f]{64}$')");
    }

    private function addReadOnlyGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_completed_project_change() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'COMPLETED' THEN
                    RAISE EXCEPTION 'Project % is COMPLETED and read-only', OLD.id
                        USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER projects_completed_read_only
                BEFORE UPDATE OR DELETE ON projects
                FOR EACH ROW EXECUTE FUNCTION reject_completed_project_change();

            CREATE OR REPLACE FUNCTION reject_completed_project_child_change() RETURNS trigger AS $$
            DECLARE
                old_project_id bigint;
                new_project_id bigint;
                old_status varchar;
                new_status varchar;
            BEGIN
                IF TG_TABLE_NAME = 'executions' AND TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Execution history is append-only'
                        USING ERRCODE = '23514';
                END IF;

                IF TG_OP <> 'INSERT' THEN
                    IF TG_TABLE_NAME = 'connections' THEN
                        SELECT project_id INTO old_project_id FROM servers WHERE id = OLD.server_id;
                    ELSIF TG_TABLE_NAME IN ('project_configurations', 'project_assignments', 'servers', 'moodle_instances', 'executions') THEN
                        old_project_id := OLD.project_id;
                    ELSE
                        SELECT project_id INTO old_project_id FROM executions WHERE id = OLD.execution_id;
                    END IF;
                END IF;

                IF TG_OP <> 'DELETE' THEN
                    IF TG_TABLE_NAME = 'connections' THEN
                        SELECT project_id INTO new_project_id FROM servers WHERE id = NEW.server_id;
                    ELSIF TG_TABLE_NAME IN ('project_configurations', 'project_assignments', 'servers', 'moodle_instances', 'executions') THEN
                        new_project_id := NEW.project_id;
                    ELSE
                        SELECT project_id INTO new_project_id FROM executions WHERE id = NEW.execution_id;
                    END IF;
                END IF;

                IF old_project_id IS NOT NULL THEN
                    SELECT status INTO old_status FROM projects WHERE id = old_project_id;
                END IF;

                IF old_status = 'COMPLETED' THEN
                    RAISE EXCEPTION 'Project % is COMPLETED and read-only', old_project_id
                        USING ERRCODE = '23514';
                END IF;

                IF new_project_id IS NOT NULL THEN
                    SELECT status INTO new_status FROM projects WHERE id = new_project_id;
                END IF;

                IF new_status = 'COMPLETED' THEN
                    RAISE EXCEPTION 'Project % is COMPLETED and read-only', new_project_id
                        USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION reject_audit_log_change() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND pg_trigger_depth() > 1
                    AND ROW(
                        NEW.id,
                        NEW.action,
                        NEW.auditable_type,
                        NEW.auditable_id,
                        NEW.ip_address,
                        NEW.user_agent,
                        NEW.old_values,
                        NEW.new_values,
                        NEW.payload,
                        NEW.created_at
                    ) IS NOT DISTINCT FROM ROW(
                        OLD.id,
                        OLD.action,
                        OLD.auditable_type,
                        OLD.auditable_id,
                        OLD.ip_address,
                        OLD.user_agent,
                        OLD.old_values,
                        OLD.new_values,
                        OLD.payload,
                        OLD.created_at
                    )
                    AND (
                        NEW.actor_id IS NOT DISTINCT FROM OLD.actor_id
                        OR (OLD.actor_id IS NOT NULL AND NEW.actor_id IS NULL)
                    )
                    AND (
                        NEW.project_id IS NOT DISTINCT FROM OLD.project_id
                        OR (OLD.project_id IS NOT NULL AND NEW.project_id IS NULL)
                    )
                    AND (
                        NEW.execution_id IS NOT DISTINCT FROM OLD.execution_id
                        OR (OLD.execution_id IS NOT NULL AND NEW.execution_id IS NULL)
                    )
                    AND (
                        NEW.actor_id IS DISTINCT FROM OLD.actor_id
                        OR NEW.project_id IS DISTINCT FROM OLD.project_id
                        OR NEW.execution_id IS DISTINCT FROM OLD.execution_id
                    )
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Audit logs are append-only' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION reject_audit_log_change();

            CREATE OR REPLACE FUNCTION protect_execution_identity() RETURNS trigger AS $$
            BEGIN
                IF NEW.project_id IS DISTINCT FROM OLD.project_id
                    OR NEW.uuid IS DISTINCT FROM OLD.uuid
                    OR NEW.attempt IS DISTINCT FROM OLD.attempt
                THEN
                    RAISE EXCEPTION 'Execution identity is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.resumed_from_execution_id IS NOT NULL
                    AND (
                        NEW.resumed_from_execution_id IS DISTINCT FROM OLD.resumed_from_execution_id
                        OR NEW.resume_checkpoint_id IS DISTINCT FROM OLD.resume_checkpoint_id
                    )
                THEN
                    RAISE EXCEPTION 'Execution resume lineage is immutable once assigned'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER executions_identity_immutable
                BEFORE UPDATE OF project_id, uuid, attempt, resumed_from_execution_id, resume_checkpoint_id
                ON executions
                FOR EACH ROW EXECUTE FUNCTION protect_execution_identity();

            CREATE OR REPLACE FUNCTION protect_checkpoint_identity() RETURNS trigger AS $$
            BEGIN
                IF NEW.execution_id IS DISTINCT FROM OLD.execution_id THEN
                    RAISE EXCEPTION 'Checkpoint execution identity is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.validated = true AND NEW.validated = false THEN
                    RAISE EXCEPTION 'Checkpoint validation cannot be revoked'
                        USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM executions
                    WHERE resume_checkpoint_id = OLD.id
                ) AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'Referenced checkpoint is immutable'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER checkpoints_identity_immutable
                BEFORE UPDATE ON checkpoints
                FOR EACH ROW EXECUTE FUNCTION protect_checkpoint_identity();

            CREATE OR REPLACE FUNCTION validate_execution_resume_reference() RETURNS trigger AS $$
            DECLARE
                checkpoint_execution_id bigint;
                checkpoint_validated boolean;
                previous_project_id bigint;
                previous_attempt integer;
            BEGIN
                IF NEW.resumed_from_execution_id IS NULL AND NEW.resume_checkpoint_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.resumed_from_execution_id IS NULL OR NEW.resume_checkpoint_id IS NULL THEN
                    RAISE EXCEPTION 'Resumed execution requires both previous execution and checkpoint'
                        USING ERRCODE = '23514';
                END IF;

                SELECT execution_id, validated
                    INTO checkpoint_execution_id, checkpoint_validated
                    FROM checkpoints
                    WHERE id = NEW.resume_checkpoint_id
                    FOR SHARE;

                SELECT project_id, attempt
                    INTO previous_project_id, previous_attempt
                    FROM executions
                    WHERE id = NEW.resumed_from_execution_id
                    FOR SHARE;

                IF checkpoint_execution_id IS DISTINCT FROM NEW.resumed_from_execution_id THEN
                    RAISE EXCEPTION 'Resume checkpoint does not belong to the previous execution'
                        USING ERRCODE = '23514';
                END IF;

                IF checkpoint_validated IS DISTINCT FROM true THEN
                    RAISE EXCEPTION 'Resume checkpoint is not validated'
                        USING ERRCODE = '23514';
                END IF;

                IF previous_project_id IS DISTINCT FROM NEW.project_id THEN
                    RAISE EXCEPTION 'Previous execution belongs to another project'
                        USING ERRCODE = '23514';
                END IF;

                IF previous_attempt IS NULL OR previous_attempt >= NEW.attempt THEN
                    RAISE EXCEPTION 'Previous execution attempt must precede resumed attempt'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER executions_resume_reference_consistency
                BEFORE INSERT OR UPDATE OF project_id, attempt, resumed_from_execution_id, resume_checkpoint_id
                ON executions
                FOR EACH ROW EXECUTE FUNCTION validate_execution_resume_reference();
            SQL);

        $tables = [
            'project_configurations',
            'project_assignments',
            'servers',
            'connections',
            'moodle_instances',
            'executions',
            'execution_commands',
            'execution_steps',
            'execution_events',
            'execution_logs',
            'checkpoints',
            'conflicts',
            'verifications',
            'artifacts',
        ];

        foreach ($tables as $table) {
            DB::statement("CREATE TRIGGER {$table}_completed_project_read_only BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_completed_project_child_change()");
        }
    }

    private function dropReadOnlyGuards(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS reject_completed_project_child_change() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS reject_completed_project_change() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS reject_audit_log_change() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS protect_execution_identity() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS protect_checkpoint_identity() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS validate_execution_resume_reference() CASCADE');
    }
};
