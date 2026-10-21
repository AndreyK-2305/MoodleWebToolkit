<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A remote collection can also be exported again from zero. The guard keeps
 * every other rule: immutable lineage, same project, a later attempt and a
 * terminated real predecessor; it now accepts either collector adapter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared($this->guard("'collector-742-lab', 'collector-742-remote'"));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared($this->guard("'collector-742-lab'"));
        }
    }

    private function guard(string $adapters): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION toolkit_guard_collector_retry_lineage() RETURNS trigger AS \$\$
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
                        OR NOT EXISTS (SELECT 1 FROM execution_tool_bindings WHERE execution_id = previous_execution.id AND adapter_key IN ({$adapters})) THEN
                        RAISE EXCEPTION 'Collector retry requires a terminated real predecessor in the same project';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            SQL;
    }
};
