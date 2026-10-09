<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX artifacts_collector_operation_path_unique ON artifacts (remote_operation_id, (metadata->>'source_relative_path')) WHERE metadata->>'command_key' = 'collector.742.lab'");
            DB::statement("CREATE UNIQUE INDEX source_packages_collector_artifact_unique ON source_packages (artifact_id) WHERE schema_version = '1.0'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS artifacts_collector_operation_path_unique');
            DB::statement('DROP INDEX IF EXISTS source_packages_collector_artifact_unique');
        }
    }
};
