<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->string('type', 24);
            $table->string('status', 32)->default('DRAFT');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['type', 'status']);
        });

        Schema::create('project_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
        });

        Schema::create('project_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'project_id']);
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('role', 24);
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'name']);
            $table->unique(['id', 'project_id']);
        });

        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 40);
            $table->string('status', 16)->default('UNTESTED');
            $table->jsonb('configuration')->nullable();
            $table->string('secret_reference')->nullable();
            $table->timestampsTz();

            $table->unique(['server_id', 'name']);
        });

        Schema::create('moodle_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('server_id');
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('role', 24);
            $table->string('base_url')->nullable();
            $table->string('moodle_version', 40)->nullable();
            $table->string('database_name')->nullable();
            $table->boolean('validated')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign(['server_id', 'project_id'])
                ->references(['id', 'project_id'])
                ->on('servers')
                ->restrictOnDelete();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'role']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_domain_check CHECK (role IN ('ADMIN', 'OPERATOR', 'AUDITOR'))");
            DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_type_check CHECK (type IN ('COLLECT', 'CONSOLIDATE', 'INTEGRATE'))");
            DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_status_check CHECK (status IN ('DRAFT', 'CONFIGURING', 'READY', 'QUEUED', 'RUNNING', 'WAITING_USER_ACTION', 'CANCELLING', 'CANCELLED', 'FAILED', 'VERIFYING', 'REVIEW', 'COMPLETED'))");
            DB::statement('ALTER TABLE project_configurations ADD CONSTRAINT project_configurations_version_check CHECK (version > 0)');
            DB::statement("ALTER TABLE servers ADD CONSTRAINT servers_role_check CHECK (role IN ('SOURCE', 'DESTINATION', 'AUXILIARY'))");
            DB::statement('ALTER TABLE servers ADD CONSTRAINT servers_port_check CHECK (port IS NULL OR port BETWEEN 1 AND 65535)');
            DB::statement("ALTER TABLE connections ADD CONSTRAINT connections_status_check CHECK (status IN ('UNTESTED', 'VALID', 'INVALID'))");
            DB::statement("ALTER TABLE moodle_instances ADD CONSTRAINT moodle_instances_role_check CHECK (role IN ('SOURCE', 'DESTINATION'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('moodle_instances');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('servers');
        Schema::dropIfExists('project_assignments');
        Schema::dropIfExists('project_configurations');
        Schema::dropIfExists('projects');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_domain_check');
        }
    }
};
