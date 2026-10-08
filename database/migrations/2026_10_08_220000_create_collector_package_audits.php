<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_package_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('remote_operation_id')->unique();
            $table->unsignedBigInteger('execution_id');
            $table->foreign(['remote_operation_id', 'execution_id'])->references(['id', 'execution_id'])->on('remote_operations')->restrictOnDelete();
            $table->char('package_sha256', 64);
            $table->unsignedBigInteger('package_bytes');
            $table->jsonb('snapshot');
            $table->timestampTz('created_at')->useCurrent();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE collector_package_audits ADD CONSTRAINT collector_package_audit_bounds CHECK (package_bytes BETWEEN 1 AND 21474836480 AND package_sha256 ~ '^[a-f0-9]{64}$' AND snapshot->>'result' = 'VALID')");
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION toolkit_guard_collector_package_audit() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Collector package audits are immutable';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER collector_package_audit_immutable BEFORE UPDATE OR DELETE ON collector_package_audits FOR EACH ROW EXECUTE FUNCTION toolkit_guard_collector_package_audit();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_package_audits');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS toolkit_guard_collector_package_audit()');
        }
    }
};
