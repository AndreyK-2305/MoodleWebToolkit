<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_event_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_event_id')->unique()->constrained('execution_events')->cascadeOnDelete();
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->timestampTz('available_at')->index();
            $table->timestampTz('published_at')->nullable();
            $table->string('last_failure_code', 32)->nullable();
            $table->timestampTz('created_at');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE execution_event_outbox ADD CONSTRAINT execution_event_outbox_delivery_check CHECK (delivery_attempts >= 0 AND (last_failure_code IS NULL OR last_failure_code = 'BROADCAST_UNAVAILABLE'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_event_outbox');
    }
};
