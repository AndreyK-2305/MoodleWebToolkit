<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_versions', function (Blueprint $table): void {
            $table->string('archive_name')->nullable()->change();
            $table->string('archive_sha256', 64)->nullable()->change();
        });

        Schema::create('tool_distributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_version_id')->constrained()->restrictOnDelete();
            $table->string('key', 120)->unique();
            $table->string('kind', 16);
            $table->string('source_path', 512);
            $table->string('manifest_name', 160)->nullable();
            $table->string('manifest_sha256', 64)->nullable();
            $table->string('distribution_sha256', 64);
            $table->unsignedInteger('file_count');
            $table->string('verification_state', 16)->default('UNVERIFIED');
            $table->timestampTz('verified_at')->nullable();
            $table->jsonb('mutable_paths')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestampsTz();
            $table->unique(['tool_version_id', 'key']);
            $table->index(['tool_version_id', 'verification_state']);
        });

        Schema::create('tool_capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_version_id')->constrained()->restrictOnDelete();
            $table->string('key', 120);
            $table->string('support_state', 16);
            $table->string('evidence_level', 16);
            $table->string('source_path', 512)->nullable();
            $table->text('details')->nullable();
            $table->timestampsTz();
            $table->unique(['tool_version_id', 'key']);
        });

        Schema::create('tool_compatibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_version_id')->constrained()->restrictOnDelete();
            $table->string('workflow_key', 120);
            $table->string('status', 20);
            $table->string('feature_flag', 160);
            $table->text('reason')->nullable();
            $table->jsonb('requirements')->nullable();
            $table->timestampsTz();
            $table->unique(['tool_version_id', 'workflow_key']);
            $table->index(['workflow_key', 'status']);
        });

        Schema::create('execution_tool_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tool_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('tool_distribution_id')->constrained()->restrictOnDelete();
            $table->string('workflow_key', 120);
            $table->string('adapter_key', 120);
            $table->string('provider_key', 120);
            $table->string('distribution_sha256', 64);
            $table->string('configuration_sha256', 64);
            $table->jsonb('capabilities_snapshot');
            $table->jsonb('input_artifact_ids')->nullable();
            $table->jsonb('configuration_snapshot')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tool_version_id', 'created_at']);
        });

        Schema::create('execution_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('relative_path', 1024)->unique();
            $table->unsignedBigInteger('quota_bytes');
            $table->unsignedBigInteger('usage_bytes')->default(0);
            $table->string('status', 16)->default('READY');
            $table->timestampTz('last_measured_at')->nullable();
            $table->timestampTz('cleaned_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'last_measured_at']);
        });

        Schema::create('remote_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_uuid')->unique();
            $table->string('idempotency_key', 160);
            $table->string('provider_key', 120);
            $table->string('host_id', 255)->nullable();
            $table->string('runtime_key', 120);
            $table->string('process_id', 255)->nullable();
            $table->string('command_key', 120);
            $table->string('command_sha256', 64);
            $table->string('communication_state', 16)->default('RECONCILING');
            $table->string('functional_state', 16)->default('PREPARING');
            $table->timestampTz('launch_claimed_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('last_observed_at')->nullable();
            $table->timestampTz('next_poll_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->unique(['execution_id', 'idempotency_key']);
            $table->index(['communication_state', 'next_poll_at']);
            $table->index(['functional_state', 'last_heartbeat_at']);
        });

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->index();
            $table->string('storage_mode', 16)->default('MANAGED');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tool_versions ADD CONSTRAINT tool_versions_identity_check CHECK (archive_sha256 IS NOT NULL OR tree_sha256 IS NOT NULL)');
            DB::statement('ALTER TABLE tool_distributions ADD CONSTRAINT tool_distributions_kind_check CHECK (kind IN (\'TREE\', \'ARCHIVE\'))');
            DB::statement('ALTER TABLE tool_distributions ADD CONSTRAINT tool_distributions_state_check CHECK (verification_state IN (\'UNVERIFIED\', \'VERIFIED\', \'REVOKED\'))');
            DB::statement('ALTER TABLE tool_distributions ADD CONSTRAINT tool_distributions_sha_check CHECK (distribution_sha256 ~ \'^[0-9a-f]{64}$\' AND (manifest_sha256 IS NULL OR manifest_sha256 ~ \'^[0-9a-f]{64}$\'))');
            DB::statement('ALTER TABLE tool_distributions ADD CONSTRAINT tool_distributions_file_count_check CHECK (file_count > 0)');
            DB::statement('ALTER TABLE tool_capabilities ADD CONSTRAINT tool_capabilities_state_check CHECK (support_state IN (\'SUPPORTED\', \'PARTIAL\', \'UNSUPPORTED\', \'UNKNOWN\'))');
            DB::statement('ALTER TABLE tool_capabilities ADD CONSTRAINT tool_capabilities_evidence_check CHECK (evidence_level IN (\'CODE\', \'DOC\', \'TEST\', \'INFERRED\'))');
            DB::statement('ALTER TABLE tool_compatibilities ADD CONSTRAINT tool_compatibilities_status_check CHECK (status IN (\'AVAILABLE\', \'EXPERIMENTAL\', \'LABORATORY\', \'BLOCKED\', \'INCOMPATIBLE\', \'RETIRED\'))');
            DB::statement('ALTER TABLE execution_workspaces ADD CONSTRAINT execution_workspaces_status_check CHECK (status IN (\'READY\', \'ACTIVE\', \'CLEANING\', \'CLEANED\', \'FAILED\'))');
            DB::statement('ALTER TABLE execution_workspaces ADD CONSTRAINT execution_workspaces_quota_check CHECK (quota_bytes > 0 AND usage_bytes >= 0)');
            DB::statement('ALTER TABLE remote_operations ADD CONSTRAINT remote_operations_communication_check CHECK (communication_state IN (\'CONNECTED\', \'DEGRADED\', \'UNREACHABLE\', \'RECONCILING\', \'TERMINATED\'))');
            DB::statement('ALTER TABLE remote_operations ADD CONSTRAINT remote_operations_functional_check CHECK (functional_state IN (\'PREPARING\', \'STARTING\', \'RUNNING\', \'WAITING\', \'SUCCEEDED\', \'FAILED\', \'CANCELLED\', \'UNKNOWN\'))');
            DB::statement('ALTER TABLE remote_operations ADD CONSTRAINT remote_operations_command_sha_check CHECK (command_sha256 ~ \'^[0-9a-f]{64}$\')');
            DB::statement('ALTER TABLE artifacts ADD CONSTRAINT artifacts_storage_mode_check CHECK (storage_mode IN (\'MANAGED\', \'REFERENCE\'))');
            DB::statement('ALTER TABLE execution_tool_bindings ADD CONSTRAINT execution_tool_bindings_hash_check CHECK (distribution_sha256 ~ \'^[0-9a-f]{64}$\' AND configuration_sha256 ~ \'^[0-9a-f]{64}$\')');
            DB::statement('ALTER TABLE artifacts ADD CONSTRAINT artifacts_category_check CHECK (category IS NULL OR category IN (\'REPORT\', \'LOG\', \'MANIFEST\', \'SOURCE_PACKAGE\', \'COURSE_PACKAGE\', \'FULL_BACKUP\', \'TECHNICAL_EVIDENCE\'))');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_execution_tool_binding_change() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Execution tool bindings are immutable' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER execution_tool_bindings_append_only
                    BEFORE UPDATE OR DELETE ON execution_tool_bindings
                    FOR EACH ROW EXECUTE FUNCTION reject_execution_tool_binding_change();
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION protect_remote_operation_identity() RETURNS trigger AS $$
                BEGIN
                    IF NEW.execution_id IS DISTINCT FROM OLD.execution_id
                        OR NEW.operation_uuid IS DISTINCT FROM OLD.operation_uuid
                        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                        OR NEW.provider_key IS DISTINCT FROM OLD.provider_key
                        OR NEW.host_id IS DISTINCT FROM OLD.host_id
                        OR NEW.runtime_key IS DISTINCT FROM OLD.runtime_key
                        OR (OLD.process_id IS NOT NULL AND NEW.process_id IS DISTINCT FROM OLD.process_id)
                        OR NEW.command_key IS DISTINCT FROM OLD.command_key
                        OR NEW.command_sha256 IS DISTINCT FROM OLD.command_sha256
                    THEN
                        RAISE EXCEPTION 'Remote operation identity is immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER remote_operations_identity_immutable
                    BEFORE UPDATE ON remote_operations
                    FOR EACH ROW EXECUTE FUNCTION protect_remote_operation_identity();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS execution_tool_bindings_append_only ON execution_tool_bindings');
            DB::statement('DROP FUNCTION IF EXISTS reject_execution_tool_binding_change()');
            DB::statement('DROP TRIGGER IF EXISTS remote_operations_identity_immutable ON remote_operations');
            DB::statement('DROP FUNCTION IF EXISTS protect_remote_operation_identity()');
            DB::statement('ALTER TABLE tool_versions DROP CONSTRAINT IF EXISTS tool_versions_identity_check');
        }

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropColumn(['category', 'storage_mode']);
        });
        Schema::dropIfExists('remote_operations');
        Schema::dropIfExists('execution_workspaces');
        Schema::dropIfExists('execution_tool_bindings');
        Schema::dropIfExists('tool_compatibilities');
        Schema::dropIfExists('tool_capabilities');
        Schema::dropIfExists('tool_distributions');

        // Iteration 2 introduced tree-only catalog versions while relaxing these
        // archive columns. Remove only those new tree-only rows before restoring
        // the original not-null contract.
        DB::table('tool_versions')
            ->whereNull('archive_name')
            ->orWhereNull('archive_sha256')
            ->delete();

        DB::table('tools')
            ->whereIn('key', ['moodle-recolector', 'moodle-consolidador', 'moodle-integrador-incremental'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('tool_versions')->whereColumn('tool_versions.tool_id', 'tools.id');
            })
            ->delete();

        Schema::table('tool_versions', function (Blueprint $table): void {
            $table->string('archive_name')->nullable(false)->change();
            $table->string('archive_sha256', 64)->nullable(false)->change();
        });
    }
};
