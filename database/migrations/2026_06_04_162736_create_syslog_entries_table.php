<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only log store (no timestamps, no updates). FOREVER retention by
     * design — there is intentionally no pruning. Monthly partitioning is a
     * future optimization that is deliberately deferred.
     */
    public function up(): void
    {
        // pg_trgm is a "trusted" extension on PG13+, so the database owner can
        // create it without superuser. Required for the GIN trigram index below.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('syslog_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('host')->index();
            $table->string('facility')->nullable();
            $table->string('severity')->index();
            $table->text('message');
            $table->text('raw')->nullable();
            $table->timestampTz('logged_at');
            $table->timestampTz('received_at');
        });

        // Composite index ordered logged_at DESC for the common
        // "this host's recent logs" query.
        DB::statement('CREATE INDEX syslog_host_recent_idx ON syslog_entries (host, logged_at DESC)');

        // Standalone logged_at index for global time-range scans.
        DB::statement('CREATE INDEX syslog_logged_at_idx ON syslog_entries (logged_at DESC)');

        // GIN trigram index so ILIKE '%term%' full-text searches stay fast.
        DB::statement('CREATE INDEX syslog_message_trgm ON syslog_entries USING gin (message gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     *
     * Dropping the table removes its indexes too. The pg_trgm extension is left
     * in place — it may be shared by other objects and is harmless to keep.
     */
    public function down(): void
    {
        Schema::dropIfExists('syslog_entries');
    }
};
