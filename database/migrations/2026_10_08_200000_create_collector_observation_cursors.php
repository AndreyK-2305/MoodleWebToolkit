<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_observation_cursors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('remote_operation_id')->unique();
            $table->foreignId('execution_id')->constrained('executions')->restrictOnDelete();
            $table->foreign(['remote_operation_id', 'execution_id'])->references(['id', 'execution_id'])->on('remote_operations')->restrictOnDelete();
            $table->unsignedBigInteger('stdout_offset')->default(0);
            $table->unsignedBigInteger('last_wire_sequence')->default(0);
            $table->string('file_device', 32)->nullable();
            $table->string('file_inode', 32)->nullable();
            $table->char('prefix_sha256', 64);
            $table->boolean('discarding')->default(false);
            $table->string('reader_health', 16)->default('OK');
            $table->boolean('read_complete')->default(false);
            $table->timestampsTz();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE collector_observation_cursors ADD CONSTRAINT collector_cursor_bounds_check CHECK (stdout_offset BETWEEN 0 AND 8388608 AND last_wire_sequence >= 0 AND prefix_sha256 ~ '^[a-f0-9]{64}$' AND reader_health IN ('OK','MISSING','UNSAFE','ROTATED','TRUNCATED','UNREADABLE','ALTERED'))");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION toolkit_guard_collector_cursor() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Collector cursor evidence cannot be deleted'; END IF;
                    IF ROW(NEW.remote_operation_id, NEW.execution_id, NEW.created_at) IS DISTINCT FROM ROW(OLD.remote_operation_id, OLD.execution_id, OLD.created_at)
                        OR NEW.stdout_offset < OLD.stdout_offset OR NEW.last_wire_sequence < OLD.last_wire_sequence
                        OR (OLD.file_inode IS NOT NULL AND ROW(NEW.file_inode, NEW.file_device) IS DISTINCT FROM ROW(OLD.file_inode, OLD.file_device)) THEN
                        RAISE EXCEPTION 'Collector cursor identity and committed position are immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER collector_cursor_identity_immutable BEFORE UPDATE OR DELETE ON collector_observation_cursors FOR EACH ROW EXECUTE FUNCTION toolkit_guard_collector_cursor();
                CREATE OR REPLACE FUNCTION toolkit_guard_collector_configuration_revision() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Collector configuration revisions are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER collector_configuration_revision_immutable BEFORE UPDATE OR DELETE ON collector_configuration_revisions FOR EACH ROW EXECUTE FUNCTION toolkit_guard_collector_configuration_revision();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS collector_configuration_revision_immutable ON collector_configuration_revisions');
            DB::statement('DROP FUNCTION IF EXISTS toolkit_guard_collector_configuration_revision()');
            DB::statement('DROP TRIGGER IF EXISTS collector_cursor_identity_immutable ON collector_observation_cursors');
            DB::statement('DROP FUNCTION IF EXISTS toolkit_guard_collector_cursor()');
        }
        Schema::dropIfExists('collector_observation_cursors');
    }
};
