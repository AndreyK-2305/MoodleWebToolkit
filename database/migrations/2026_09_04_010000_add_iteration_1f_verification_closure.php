<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executions', function (Blueprint $table) {
            $table->unsignedInteger('proposal_version')->default(0)->after('progress');
            $table->string('review_fingerprint', 64)->nullable()->after('proposal_version');
            $table->unsignedInteger('validated_proposal_version')->nullable()->after('review_fingerprint');
            $table->string('validated_fingerprint', 64)->nullable()->after('validated_proposal_version');
            $table->foreignId('finalized_by')->nullable()->after('created_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('completion_summary')->nullable()->after('finished_at');
        });

        Schema::table('verifications', function (Blueprint $table) {
            $table->dropUnique('verifications_execution_id_key_unique');
            $table->unsignedInteger('proposal_version')->default(0)->after('key');
            $table->string('fingerprint', 64)->nullable()->after('proposal_version');
            $table->boolean('approved')->nullable()->after('status');
            $table->foreignId('requested_by')->nullable()->after('approved')->constrained('users')->nullOnDelete();
            $table->unique(['execution_id', 'proposal_version'], 'verifications_execution_version_unique');
        });

        Schema::create('academic_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('project_type', 24);
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('fingerprint', 64);
            $table->jsonb('tree');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('academic_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation', 40);
            $table->string('node_id', 160);
            $table->string('node_type', 16);
            $table->jsonb('old_value');
            $table->jsonb('new_value');
            $table->string('base_fingerprint', 64);
            $table->string('resulting_fingerprint', 64);
            $table->string('status', 16)->default('ACTIVE');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['execution_id', 'version']);
            $table->index(['execution_id', 'status']);
        });

        Schema::create('artifact_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('payload_hash', 64);
            $table->timestampTz('downloaded_at')->useCurrent();

            $table->unique(['execution_id', 'user_id', 'idempotency_key'], 'artifact_downloads_idempotency_unique');
            $table->index(['artifact_id', 'downloaded_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgreSqlGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS reject_iteration_1f_result_change() CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS validate_artifact_download_execution() CASCADE');
            DB::statement('DROP INDEX IF EXISTS artifacts_required_execution_type_unique');
            DB::statement('ALTER TABLE executions DROP CONSTRAINT IF EXISTS executions_proposal_version_check');
            DB::statement('ALTER TABLE executions DROP CONSTRAINT IF EXISTS executions_review_fingerprint_check');
            DB::statement('ALTER TABLE executions DROP CONSTRAINT IF EXISTS executions_validated_fingerprint_check');
            DB::statement('ALTER TABLE verifications DROP CONSTRAINT IF EXISTS verifications_proposal_version_check');
            DB::statement('ALTER TABLE verifications DROP CONSTRAINT IF EXISTS verifications_fingerprint_check');
            DB::statement('ALTER TABLE execution_commands DROP CONSTRAINT IF EXISTS execution_commands_type_check');
            DB::statement("ALTER TABLE execution_commands ADD CONSTRAINT execution_commands_type_check CHECK (command_type IN ('START', 'CONTINUE', 'RESOLVE_CONFLICT', 'RESUME', 'CANCEL', 'VALIDATE', 'FINALIZE'))");
        }

        Schema::dropIfExists('artifact_downloads');
        Schema::dropIfExists('academic_proposals');
        Schema::dropIfExists('academic_snapshots');

        Schema::table('verifications', function (Blueprint $table) {
            $table->dropUnique('verifications_execution_version_unique');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['proposal_version', 'fingerprint', 'approved']);
            $table->unique(['execution_id', 'key']);
        });

        Schema::table('executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn([
                'proposal_version',
                'review_fingerprint',
                'validated_proposal_version',
                'validated_fingerprint',
                'completion_summary',
            ]);
        });
    }

    private function addPostgreSqlGuards(): void
    {
        DB::statement('ALTER TABLE execution_commands DROP CONSTRAINT execution_commands_type_check');
        DB::statement("ALTER TABLE execution_commands ADD CONSTRAINT execution_commands_type_check CHECK (command_type IN ('START', 'CONTINUE', 'RESOLVE_CONFLICT', 'RESUME', 'CANCEL', 'PROPOSE', 'VALIDATE', 'FINALIZE'))");
        DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_proposal_version_check CHECK (proposal_version >= 0)');
        DB::statement("ALTER TABLE executions ADD CONSTRAINT executions_review_fingerprint_check CHECK (review_fingerprint IS NULL OR review_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE executions ADD CONSTRAINT executions_validated_fingerprint_check CHECK (validated_fingerprint IS NULL OR validated_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE verifications ADD CONSTRAINT verifications_proposal_version_check CHECK (proposal_version >= 0)');
        DB::statement("ALTER TABLE verifications ADD CONSTRAINT verifications_fingerprint_check CHECK (fingerprint IS NULL OR fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE academic_snapshots ADD CONSTRAINT academic_snapshots_project_type_check CHECK (project_type IN ('COLLECT', 'CONSOLIDATE', 'INTEGRATE'))");
        DB::statement('ALTER TABLE academic_snapshots ADD CONSTRAINT academic_snapshots_schema_version_check CHECK (schema_version > 0)');
        DB::statement("ALTER TABLE academic_snapshots ADD CONSTRAINT academic_snapshots_fingerprint_check CHECK (fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE academic_proposals ADD CONSTRAINT academic_proposals_operation_check CHECK (operation IN ('RENAME_CATEGORY', 'MOVE_CATEGORY', 'MOVE_COURSE', 'CHANGE_VISIBLE_NAME'))");
        DB::statement("ALTER TABLE academic_proposals ADD CONSTRAINT academic_proposals_node_type_check CHECK (node_type IN ('category', 'course'))");
        DB::statement("ALTER TABLE academic_proposals ADD CONSTRAINT academic_proposals_status_check CHECK (status IN ('ACTIVE', 'SUPERSEDED'))");
        DB::statement('ALTER TABLE academic_proposals ADD CONSTRAINT academic_proposals_version_check CHECK (version > 0)');
        DB::statement("ALTER TABLE academic_proposals ADD CONSTRAINT academic_proposals_base_fingerprint_check CHECK (base_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE academic_proposals ADD CONSTRAINT academic_proposals_resulting_fingerprint_check CHECK (resulting_fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE artifact_downloads ADD CONSTRAINT artifact_downloads_payload_hash_check CHECK (payload_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("CREATE UNIQUE INDEX artifacts_required_execution_type_unique ON artifacts (execution_id, type) WHERE type IN ('JSON_REPORT', 'VERIFICATION_REPORT', 'LOG_EXPORT', 'FINAL_SUMMARY')");

        DB::statement('DROP INDEX IF EXISTS executions_one_active_per_project_unique');
        DB::statement("CREATE UNIQUE INDEX executions_one_active_per_project_unique ON executions (project_id) WHERE status IN ('QUEUED', 'RUNNING', 'WAITING_USER_ACTION', 'CANCELLING', 'VERIFYING')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_iteration_1f_result_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER academic_snapshots_append_only
                BEFORE UPDATE OR DELETE ON academic_snapshots
                FOR EACH ROW EXECUTE FUNCTION reject_iteration_1f_result_change();
            CREATE TRIGGER academic_proposals_append_only
                BEFORE UPDATE OR DELETE ON academic_proposals
                FOR EACH ROW EXECUTE FUNCTION reject_iteration_1f_result_change();
            CREATE TRIGGER verifications_append_only
                BEFORE UPDATE OR DELETE ON verifications
                FOR EACH ROW EXECUTE FUNCTION reject_iteration_1f_result_change();
            CREATE TRIGGER artifacts_append_only
                BEFORE UPDATE OR DELETE ON artifacts
                FOR EACH ROW EXECUTE FUNCTION reject_iteration_1f_result_change();
            CREATE TRIGGER artifact_downloads_append_only
                BEFORE UPDATE OR DELETE ON artifact_downloads
                FOR EACH ROW EXECUTE FUNCTION reject_iteration_1f_result_change();

            CREATE OR REPLACE FUNCTION validate_artifact_download_execution() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM artifacts
                    WHERE artifacts.id = NEW.artifact_id
                      AND artifacts.execution_id = NEW.execution_id
                ) THEN
                    RAISE EXCEPTION 'artifact download execution does not match artifact execution' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER artifact_downloads_execution_identity
                BEFORE INSERT ON artifact_downloads
                FOR EACH ROW EXECUTE FUNCTION validate_artifact_download_execution();

            CREATE TRIGGER academic_snapshots_completed_project_read_only
                BEFORE INSERT OR UPDATE OR DELETE ON academic_snapshots
                FOR EACH ROW EXECUTE FUNCTION reject_completed_project_child_change();
            CREATE TRIGGER academic_proposals_completed_project_read_only
                BEFORE INSERT OR UPDATE OR DELETE ON academic_proposals
                FOR EACH ROW EXECUTE FUNCTION reject_completed_project_child_change();
            SQL);
    }
};
