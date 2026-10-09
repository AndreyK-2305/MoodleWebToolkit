<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('executions', 'retried_from_execution_id')) {
            Schema::table('executions', fn (Blueprint $table) => $table->foreignId('retried_from_execution_id')->nullable()->constrained('executions')->restrictOnDelete());
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION toolkit_guard_collector_retry_lineage() RETURNS trigger AS $$
                DECLARE previous_execution executions%ROWTYPE;
                BEGIN
                    IF TG_OP = 'UPDATE' AND OLD.retried_from_execution_id IS NOT NULL
                        AND NEW.retried_from_execution_id IS DISTINCT FROM OLD.retried_from_execution_id THEN
                        RAISE EXCEPTION 'Collector retry lineage is immutable';
                    END IF;
                    IF NEW.retried_from_execution_id IS NOT NULL THEN
                        SELECT * INTO previous_execution FROM executions WHERE id = NEW.retried_from_execution_id;
                        IF NOT FOUND OR previous_execution.project_id <> NEW.project_id
                            OR previous_execution.attempt >= NEW.attempt OR previous_execution.status NOT IN ('FAILED', 'CANCELLED')
                            OR NEW.resumed_from_execution_id IS NOT NULL OR NEW.resume_checkpoint_id IS NOT NULL
                            OR NOT EXISTS (SELECT 1 FROM execution_tool_bindings WHERE execution_id = previous_execution.id AND adapter_key = 'collector-742-lab') THEN
                            RAISE EXCEPTION 'Collector retry requires a terminated real predecessor in the same project';
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER collector_retry_lineage_guard BEFORE INSERT OR UPDATE ON executions FOR EACH ROW EXECUTE FUNCTION toolkit_guard_collector_retry_lineage();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS collector_retry_lineage_guard ON executions');
            DB::statement('DROP FUNCTION IF EXISTS toolkit_guard_collector_retry_lineage()');
        }
        // Retain the nullable column and FK so rollback cannot erase historical lineage.
    }
};
