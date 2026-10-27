<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AWS identities the platform uses and its single package bucket, managed
 * by administrators in the web instead of the installation's environment.
 * Secrets are encrypted by the models; nothing here is ever sent to a server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aws_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120)->unique();
            $table->string('mode', 16);
            $table->text('access_key_id')->nullable();
            $table->text('secret_access_key')->nullable();
            $table->string('role_arn', 2048)->nullable();
            $table->text('external_id')->nullable();
            $table->string('session_name', 64)->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('aws_storage_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('bucket', 63);
            $table->string('region', 32);
            $table->foreignId('aws_account_id')->nullable()->constrained('aws_accounts')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE aws_accounts ADD CONSTRAINT aws_accounts_mode_check CHECK (mode IN ('ENVIRONMENT', 'ACCESS_KEYS', 'ASSUME_ROLE'))");
            // At most one default account.
            DB::statement('CREATE UNIQUE INDEX aws_accounts_single_default ON aws_accounts (is_default) WHERE is_default');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aws_storage_settings');
        Schema::dropIfExists('aws_accounts');
    }
};
