<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_operations', function (Blueprint $table): void {
            $table->string('process_group_id', 255)->nullable()->after('process_id');
            $table->string('process_start_identity', 128)->nullable()->after('process_group_id');
            $table->unsignedSmallInteger('reconcile_attempts')->default(0)->after('next_poll_at');
            $table->text('last_reconcile_error')->nullable()->after('last_error');
            $table->boolean('manual_intervention_required')->default(false)->after('last_reconcile_error');
            $table->unique(['id', 'execution_id'], 'remote_operations_id_execution_unique');
        });

        Schema::table('execution_tool_bindings', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable();
            $table->jsonb('source_package_ids')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('source_package_hashes')->nullable();
            $table->unsignedBigInteger('capacity_approval_id')->nullable();
            $table->unsignedBigInteger('approved_quota_bytes')->nullable();
            $table->unsignedBigInteger('runtime_configuration_id')->nullable();
        });

        DB::table('execution_tool_bindings')
            ->join('executions', 'executions.id', '=', 'execution_tool_bindings.execution_id')
            ->update(['execution_tool_bindings.project_id' => DB::raw('executions.project_id')]);
        Schema::table('execution_tool_bindings', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
            $table->unique(['id', 'project_id'], 'execution_tool_bindings_id_project_unique');
        });
        Schema::table('executions', function (Blueprint $table): void {
            $table->unique(['id', 'project_id'], 'executions_id_project_unique');
        });
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->unique(['id', 'execution_id'], 'artifacts_id_execution_unique');
        });

        Schema::create('source_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('producer_execution_id')->constrained('executions')->restrictOnDelete();
            $table->foreignId('artifact_id')->constrained()->restrictOnDelete();
            $table->string('producer_tool_version', 120);
            $table->string('schema_version', 80);
            $table->string('source_id', 160);
            $table->string('name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('manifest_sha256', 64)->nullable();
            $table->string('validation_state', 16)->default('REGISTERED');
            $table->timestampTz('validated_at')->nullable();
            $table->jsonb('capabilities')->nullable();
            $table->jsonb('compatibility')->nullable();
            $table->string('sensitivity', 24)->default('INTERNAL');
            $table->string('availability', 16)->default('AVAILABLE');
            $table->timestampTz('revoked_at')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'source_id']);
            $table->unique(['id', 'project_id', 'sha256'], 'source_packages_id_project_sha_unique');
            $table->index(['project_id', 'validation_state', 'availability']);
            $table->foreign(['producer_execution_id', 'project_id'])
                ->references(['id', 'project_id'])->on('executions')->restrictOnDelete();
            $table->foreign(['artifact_id', 'producer_execution_id'])
                ->references(['id', 'execution_id'])->on('artifacts')->restrictOnDelete();
        });

        Schema::create('execution_tool_binding_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_tool_binding_id');
            $table->foreignId('source_package_id');
            $table->unsignedBigInteger('project_id');
            $table->string('package_sha256', 64);
            $table->unique(['execution_tool_binding_id', 'source_package_id']);
            $table->foreign(['execution_tool_binding_id', 'project_id'])
                ->references(['id', 'project_id'])->on('execution_tool_bindings')->restrictOnDelete();
            $table->foreign(['source_package_id', 'project_id', 'package_sha256'])
                ->references(['id', 'project_id', 'sha256'])->on('source_packages')->restrictOnDelete();
        });

        Schema::create('execution_capacity_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('estimate_bytes');
            $table->unsignedBigInteger('available_bytes_observed');
            $table->unsignedBigInteger('approved_quota_bytes');
            $table->unsignedInteger('margin_percent');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->string('fingerprint', 64);
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['id', 'execution_id'], 'capacity_approvals_id_execution_unique');
        });

        Schema::create('execution_runtime_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->constrained()->restrictOnDelete();
            $table->foreignId('tool_version_id')->constrained()->restrictOnDelete();
            $table->string('schema_version', 80);
            $table->string('source', 255);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('relative_path', 1024);
            $table->string('content_sha256', 64);
            $table->string('fingerprint', 64);
            $table->string('approval_state', 16)->default('APPROVED');
            $table->timestampTz('approved_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['execution_id', 'tool_version_id']);
            $table->unique(['id', 'execution_id', 'tool_version_id'], 'runtime_configs_id_execution_tool_unique');
        });

        Schema::table('execution_tool_bindings', function (Blueprint $table): void {
            $table->foreign(['capacity_approval_id', 'execution_id'])
                ->references(['id', 'execution_id'])->on('execution_capacity_approvals')->restrictOnDelete();
            $table->foreign(['runtime_configuration_id', 'execution_id', 'tool_version_id'])
                ->references(['id', 'execution_id', 'tool_version_id'])->on('execution_runtime_configurations')->restrictOnDelete();
            $table->foreign(['execution_id', 'project_id'])
                ->references(['id', 'project_id'])->on('executions')->restrictOnDelete();
        });

        Schema::table('tool_distributions', function (Blueprint $table): void {
            $table->jsonb('deployment_exclusions')->nullable();
        });

        foreach (['execution_events', 'execution_logs', 'artifacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('remote_operation_id')->nullable()->index();
                $table->foreign(['remote_operation_id', 'execution_id'])
                    ->references(['id', 'execution_id'])
                    ->on('remote_operations')
                    ->restrictOnDelete();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION protect_remote_process_identity() RETURNS trigger AS $$
                BEGIN
                    IF (OLD.process_group_id IS NOT NULL AND NEW.process_group_id IS DISTINCT FROM OLD.process_group_id)
                        OR (OLD.process_start_identity IS NOT NULL AND NEW.process_start_identity IS DISTINCT FROM OLD.process_start_identity)
                    THEN
                        RAISE EXCEPTION 'Remote process identity is immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER remote_operations_process_identity_immutable
                    BEFORE UPDATE ON remote_operations
                    FOR EACH ROW EXECUTE FUNCTION protect_remote_process_identity();
                SQL);
            DB::statement("ALTER TABLE remote_operations ADD CONSTRAINT remote_operations_reconcile_attempts_check CHECK (reconcile_attempts >= 0)");
            DB::statement("ALTER TABLE source_packages ADD CONSTRAINT source_packages_hash_check CHECK (sha256 ~ '^[0-9a-f]{64}$' AND (manifest_sha256 IS NULL OR manifest_sha256 ~ '^[0-9a-f]{64}$'))");
            DB::statement("ALTER TABLE source_packages ADD CONSTRAINT source_packages_state_check CHECK (validation_state IN ('REGISTERED', 'VALIDATING', 'VALID', 'INVALID', 'REVOKED'))");
            DB::statement("ALTER TABLE source_packages ADD CONSTRAINT source_packages_sensitivity_check CHECK (sensitivity IN ('PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'))");
            DB::statement("ALTER TABLE source_packages ADD CONSTRAINT source_packages_availability_check CHECK (availability IN ('AVAILABLE', 'UNAVAILABLE', 'REVOKED'))");
            DB::statement("ALTER TABLE execution_capacity_approvals ADD CONSTRAINT execution_capacity_positive_check CHECK (estimate_bytes > 0 AND approved_quota_bytes > 0 AND available_bytes_observed >= 0 AND margin_percent <= 500)");
            DB::statement("ALTER TABLE execution_capacity_approvals ADD CONSTRAINT execution_capacity_fingerprint_check CHECK (fingerprint ~ '^[0-9a-f]{64}$')");
            DB::statement("ALTER TABLE execution_runtime_configurations ADD CONSTRAINT execution_runtime_configuration_hash_check CHECK (content_sha256 ~ '^[0-9a-f]{64}$' AND fingerprint ~ '^[0-9a-f]{64}$')");
            DB::statement("ALTER TABLE execution_runtime_configurations ADD CONSTRAINT execution_runtime_configuration_state_check CHECK (approval_state IN ('APPROVED', 'REVOKED'))");
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_immutable_capacity_approval_change() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Execution capacity approvals are immutable' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER execution_capacity_approvals_append_only
                    BEFORE UPDATE OR DELETE ON execution_capacity_approvals
                    FOR EACH ROW EXECUTE FUNCTION reject_immutable_capacity_approval_change();
                SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION protect_source_package_identity() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Source packages are durable and cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF ROW(NEW.uuid, NEW.project_id, NEW.producer_execution_id, NEW.artifact_id, NEW.producer_tool_version, NEW.schema_version, NEW.source_id, NEW.name, NEW.size_bytes, NEW.sha256, NEW.manifest_sha256, NEW.capabilities, NEW.compatibility, NEW.sensitivity)
                        IS DISTINCT FROM ROW(OLD.uuid, OLD.project_id, OLD.producer_execution_id, OLD.artifact_id, OLD.producer_tool_version, OLD.schema_version, OLD.source_id, OLD.name, OLD.size_bytes, OLD.sha256, OLD.manifest_sha256, OLD.capabilities, OLD.compatibility, OLD.sensitivity) THEN
                        RAISE EXCEPTION 'Source package identity and content hash are immutable' USING ERRCODE = '23514';
                    END IF;
                    IF (OLD.validation_state = 'REVOKED' AND NEW.validation_state <> 'REVOKED')
                        OR (OLD.availability = 'REVOKED' AND NEW.availability <> 'REVOKED')
                        OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at) THEN
                        RAISE EXCEPTION 'Revoked source packages cannot be restored' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER source_packages_identity_immutable
                    BEFORE UPDATE OR DELETE ON source_packages
                    FOR EACH ROW EXECUTE FUNCTION protect_source_package_identity();
                SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION protect_execution_runtime_configuration() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Runtime configuration approvals cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF ROW(NEW.execution_id, NEW.tool_version_id, NEW.schema_version, NEW.source, NEW.approved_by, NEW.relative_path, NEW.content_sha256, NEW.fingerprint, NEW.approved_at)
                        IS DISTINCT FROM ROW(OLD.execution_id, OLD.tool_version_id, OLD.schema_version, OLD.source, OLD.approved_by, OLD.relative_path, OLD.content_sha256, OLD.fingerprint, OLD.approved_at)
                        OR (OLD.approval_state = 'REVOKED' AND NEW.approval_state <> 'REVOKED') THEN
                        RAISE EXCEPTION 'Runtime configuration approvals are immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER execution_runtime_configurations_immutable
                    BEFORE UPDATE OR DELETE ON execution_runtime_configurations
                    FOR EACH ROW EXECUTE FUNCTION protect_execution_runtime_configuration();
                SQL);
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_execution_tool_binding_source_change() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Execution tool binding source snapshots are immutable' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER execution_tool_binding_sources_append_only
                    BEFORE UPDATE OR DELETE ON execution_tool_binding_sources
                    FOR EACH ROW EXECUTE FUNCTION reject_execution_tool_binding_source_change();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS remote_operations_process_identity_immutable ON remote_operations');
            DB::statement('DROP FUNCTION IF EXISTS protect_remote_process_identity()');
            DB::statement('DROP TRIGGER IF EXISTS source_packages_identity_immutable ON source_packages');
            DB::statement('DROP FUNCTION IF EXISTS protect_source_package_identity()');
            DB::statement('DROP TRIGGER IF EXISTS execution_runtime_configurations_immutable ON execution_runtime_configurations');
            DB::statement('DROP FUNCTION IF EXISTS protect_execution_runtime_configuration()');
            DB::statement('DROP TRIGGER IF EXISTS execution_tool_binding_sources_append_only ON execution_tool_binding_sources');
            DB::statement('DROP FUNCTION IF EXISTS reject_execution_tool_binding_source_change()');
            DB::statement('DROP TRIGGER IF EXISTS execution_capacity_approvals_append_only ON execution_capacity_approvals');
            DB::statement('DROP FUNCTION IF EXISTS reject_immutable_capacity_approval_change()');
        }

        foreach (['execution_events', 'execution_logs', 'artifacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['remote_operation_id', 'execution_id']);
                $table->dropIndex($tableName.'_remote_operation_id_index');
                $table->dropColumn('remote_operation_id');
            });
        }

        Schema::dropIfExists('execution_tool_binding_sources');

        Schema::table('execution_tool_bindings', function (Blueprint $table): void {
            $table->dropForeign(['capacity_approval_id', 'execution_id']);
            $table->dropForeign(['runtime_configuration_id', 'execution_id', 'tool_version_id']);
            $table->dropForeign(['execution_id', 'project_id']);
            $table->dropUnique('execution_tool_bindings_id_project_unique');
            $table->dropColumn(['project_id', 'source_package_ids', 'source_package_hashes', 'capacity_approval_id', 'approved_quota_bytes', 'runtime_configuration_id']);
        });
        Schema::table('tool_distributions', function (Blueprint $table): void {
            $table->dropColumn('deployment_exclusions');
        });
        Schema::dropIfExists('execution_runtime_configurations');
        Schema::dropIfExists('execution_capacity_approvals');
        Schema::dropIfExists('source_packages');
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique('artifacts_id_execution_unique');
        });
        Schema::table('executions', function (Blueprint $table): void {
            $table->dropUnique('executions_id_project_unique');
        });
        Schema::table('remote_operations', function (Blueprint $table): void {
            $table->dropUnique('remote_operations_id_execution_unique');
            $table->dropColumn(['process_group_id', 'process_start_identity', 'reconcile_attempts', 'last_reconcile_error', 'manual_intervention_required']);
        });
    }
};
