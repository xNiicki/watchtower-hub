<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_key');
            $table->string('state')->default('pending');
            $table->string('tier');
            $table->string('title');
            $table->text('message');
            $table->timestampTz('pending_since')->nullable();
            $table->timestampTz('fired_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('state');
            $table->index(['target_id', 'rule_key']);
        });

        DB::statement("CREATE INDEX alerts_open_idx ON alerts (target_id, rule_key) WHERE state IN ('pending', 'firing')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
