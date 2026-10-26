<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CONSOLIDATE project records the COLLECT project its sources come from.
 * With «Recolectar ahora» the link also keeps the consolidation choices made
 * while the linked collection runs, until its packages are picked up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('source_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->jsonb('source_link')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_project_id');
            $table->dropColumn('source_link');
        });
    }
};
