<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_configuration_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('configuration_version');
            $table->string('schema_version', 40);
            $table->char('fingerprint', 64);
            $table->jsonb('snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['project_id', 'configuration_version'], 'collector_revision_project_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_configuration_revisions');
    }
};
