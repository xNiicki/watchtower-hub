<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_health', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('app_id')->unique()->constrained('apps')->cascadeOnDelete();
            $table->boolean('healthy')->default(false);
            $table->unsignedInteger('errors_last_hour')->default(0);
            $table->unsignedInteger('queue_depth')->default(0);
            $table->unsignedInteger('failed_jobs_24h')->default(0);
            $table->unsignedInteger('mail_sent_24h')->default(0);
            $table->timestampTz('last_deploy_at')->nullable();
            $table->timestampTz('snapshot_at');
            $table->timestampTz('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_health');
    }
};
