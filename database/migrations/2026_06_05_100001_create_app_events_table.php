<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('app_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('fingerprint');
            $table->string('type');
            $table->string('severity');
            $table->string('title');
            $table->text('message');
            $table->string('exception_class')->nullable();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('trace')->nullable();
            $table->jsonb('context')->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('received_at');

            $table->unique(['app_id', 'fingerprint']);
        });

        DB::statement('CREATE INDEX app_events_recent_idx ON app_events (app_id, last_seen_at DESC)');
        DB::statement('CREATE INDEX app_events_message_trgm ON app_events USING gin (message gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('app_events');
    }
};
