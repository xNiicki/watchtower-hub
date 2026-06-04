<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('unknown');
            $table->integer('latency_ms')->nullable();
            $table->unsignedInteger('fail_streak')->default(0);
            $table->timestampTz('last_ok_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
