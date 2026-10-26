<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additional email recipients chosen by an administrator, who may have no account
 * on the platform. A delivery is now keyed by address as well as by user, so one
 * address receives a notification once even when it is also a user's email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->unique();
            $table->boolean('interventions')->default(true);
            $table->boolean('outcomes')->default(true);
            $table->boolean('backups')->default(false);
            $table->boolean('progress')->default(false);
            $table->timestampsTz();
        });

        Schema::table('execution_notification_deliveries', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('email', 255)->nullable();
        });
        DB::table('execution_notification_deliveries')->whereNull('email')->update([
            'email' => DB::raw('(SELECT LOWER(users.email) FROM users WHERE users.id = execution_notification_deliveries.user_id)'),
        ]);
        Schema::table('execution_notification_deliveries', function (Blueprint $table): void {
            $table->unique(['execution_notification_id', 'email']);
        });
    }

    public function down(): void
    {
        DB::table('execution_notification_deliveries')->whereNull('user_id')->delete();
        Schema::table('execution_notification_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['execution_notification_id', 'email']);
            $table->dropColumn('email');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
        Schema::dropIfExists('notification_recipients');
    }
};
