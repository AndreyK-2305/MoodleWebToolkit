<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email notifications sent by the platform itself: one SMTP configuration kept
 * here (never on the servers), each user's choices, and a notification outbox.
 * A notifiable event writes its row in the same commit, keyed by a stable event
 * key, so retries and replays cannot produce a second email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('smtp_host', 255);
            $table->unsignedInteger('smtp_port');
            $table->string('smtp_encryption', 16);
            $table->string('smtp_username', 255)->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('from_address', 255);
            $table->string('from_name', 120);
            $table->unsignedSmallInteger('progress_interval_minutes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('receive_email')->default(true);
            $table->boolean('interventions')->default(true);
            $table->boolean('outcomes')->default(true);
            $table->boolean('backups')->default(true);
            $table->boolean('progress')->default(false);
            $table->timestampsTz();
        });

        Schema::create('execution_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->constrained('executions')->cascadeOnDelete();
            $table->foreignId('execution_event_id')->nullable()->constrained('execution_events')->nullOnDelete();
            $table->string('dedupe_key', 191);
            $table->string('group', 16);
            $table->string('kind', 48);
            $table->string('title', 191);
            $table->string('line', 500);
            $table->unsignedInteger('recipients')->default(0);
            $table->string('outcome', 16)->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['execution_id', 'dedupe_key']);
            $table->index(['dispatched_at', 'id']);
        });

        Schema::create('execution_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_notification_id')->constrained('execution_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['execution_notification_id', 'user_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE notification_settings ADD CONSTRAINT notification_settings_encryption_check CHECK (smtp_encryption IN ('TLS', 'STARTTLS', 'NONE'))");
            DB::statement("ALTER TABLE execution_notifications ADD CONSTRAINT execution_notifications_group_check CHECK (\"group\" IN ('INTERVENTIONS', 'OUTCOMES', 'BACKUPS', 'PROGRESS'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_notification_deliveries');
        Schema::dropIfExists('execution_notifications');
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('notification_settings');
    }
};
