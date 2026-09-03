<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_commands', function (Blueprint $table) {
            $table->uuid('lease_owner')->nullable()->after('processing_started_at');
            $table->timestampTz('lease_expires_at')->nullable()->after('lease_owner');
            $table->index(
                ['processed_at', 'lease_expires_at'],
                'execution_commands_abandoned_lease_index',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE execution_commands
                ADD CONSTRAINT execution_commands_lease_pair_check CHECK (
                    (lease_owner IS NULL) = (lease_expires_at IS NULL)
                    AND (lease_owner IS NULL OR processing_started_at IS NOT NULL)
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE execution_commands DROP CONSTRAINT IF EXISTS execution_commands_lease_pair_check');
        }

        Schema::table('execution_commands', function (Blueprint $table) {
            $table->dropIndex('execution_commands_abandoned_lease_index');
            $table->dropColumn(['lease_owner', 'lease_expires_at']);
        });
    }
};
