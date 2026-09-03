<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->uuid('workspace_key')->nullable()->unique()->after('uuid');
        });

        Schema::table('checkpoints', function (Blueprint $table) {
            $table->string('adapter_key', 80)->default('fake')->after('type');
            $table->unique(['execution_id', 'step_key', 'type'], 'checkpoints_execution_step_type_unique');
        });

        Schema::table('conflicts', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('status');
        });

        DB::table('executions')->whereNull('workspace_key')->orderBy('id')->eachById(function ($execution): void {
            DB::table('executions')->where('id', $execution->id)->update(['workspace_key' => (string) Str::uuid()]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE executions ALTER COLUMN workspace_key SET NOT NULL');
            DB::statement('ALTER TABLE conflicts ADD CONSTRAINT conflicts_version_check CHECK (version > 0)');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION protect_execution_identity() RETURNS trigger AS $$
                BEGIN
                    IF NEW.project_id IS DISTINCT FROM OLD.project_id
                        OR NEW.uuid IS DISTINCT FROM OLD.uuid
                        OR NEW.workspace_key IS DISTINCT FROM OLD.workspace_key
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

                DROP TRIGGER IF EXISTS executions_identity_immutable ON executions;
                CREATE TRIGGER executions_identity_immutable
                    BEFORE UPDATE OF project_id, uuid, workspace_key, attempt, resumed_from_execution_id, resume_checkpoint_id
                    ON executions
                    FOR EACH ROW EXECUTE FUNCTION protect_execution_identity();

                CREATE OR REPLACE FUNCTION protect_checkpoint_identity() RETURNS trigger AS $$
                BEGIN
                    IF NEW.execution_id IS DISTINCT FROM OLD.execution_id THEN
                        RAISE EXCEPTION 'Checkpoint execution identity is immutable'
                            USING ERRCODE = '23514';
                    END IF;

                    IF OLD.validated = true AND NEW IS DISTINCT FROM OLD THEN
                        RAISE EXCEPTION 'Validated checkpoint is immutable'
                            USING ERRCODE = '23514';
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM executions WHERE resume_checkpoint_id = OLD.id
                    ) AND NEW IS DISTINCT FROM OLD THEN
                        RAISE EXCEPTION 'Referenced checkpoint is immutable'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION reject_validated_checkpoint_delete() RETURNS trigger AS $$
                BEGIN
                    IF OLD.validated = true THEN
                        RAISE EXCEPTION 'Validated checkpoint cannot be deleted'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER checkpoints_validated_delete_guard
                    BEFORE DELETE ON checkpoints
                    FOR EACH ROW EXECUTE FUNCTION reject_validated_checkpoint_delete();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS checkpoints_validated_delete_guard ON checkpoints');
            DB::statement('DROP FUNCTION IF EXISTS reject_validated_checkpoint_delete()');
            DB::statement('ALTER TABLE conflicts DROP CONSTRAINT IF EXISTS conflicts_version_check');
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS executions_identity_immutable ON executions;
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
                    IF EXISTS (SELECT 1 FROM executions WHERE resume_checkpoint_id = OLD.id)
                        AND NEW IS DISTINCT FROM OLD THEN
                        RAISE EXCEPTION 'Referenced checkpoint is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);
        }

        Schema::table('conflicts', function (Blueprint $table) {
            $table->dropColumn('version');
        });

        Schema::table('checkpoints', function (Blueprint $table) {
            $table->dropUnique('checkpoints_execution_step_type_unique');
            $table->dropColumn('adapter_key');
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->dropUnique(['workspace_key']);
            $table->dropColumn('workspace_key');
        });
    }
};
