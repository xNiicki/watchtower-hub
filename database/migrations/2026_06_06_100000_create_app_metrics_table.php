<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('key');
            $table->double('value');
            $table->timestampTz('bucket_at');
            $table->unique(['app_id', 'key', 'bucket_at']);
            $table->index(['app_id', 'key', 'bucket_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_metrics');
    }
};
