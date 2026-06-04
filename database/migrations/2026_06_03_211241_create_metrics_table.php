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
        Schema::create('metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->double('value');
            $table->timestampTz('captured_at');

            $table->index(['target_id', 'key', 'captured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
