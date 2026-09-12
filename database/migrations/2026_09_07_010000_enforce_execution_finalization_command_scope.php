<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_commands', function (Blueprint $table) {
            $table->unique(
                ['id', 'execution_id'],
                'execution_commands_id_execution_unique',
            );
        });

        Schema::table('execution_finalizations', function (Blueprint $table) {
            $table->foreign(
                ['execution_command_id', 'execution_id'],
                'execution_finalizations_command_execution_foreign',
            )->references(['id', 'execution_id'])
                ->on('execution_commands')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('execution_finalizations', function (Blueprint $table) {
            $table->dropForeign('execution_finalizations_command_execution_foreign');
        });

        Schema::table('execution_commands', function (Blueprint $table) {
            $table->dropUnique('execution_commands_id_execution_unique');
        });
    }
};
