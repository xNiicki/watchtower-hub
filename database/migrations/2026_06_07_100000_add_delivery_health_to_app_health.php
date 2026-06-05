<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_health', function (Blueprint $table) {
            $table->unsignedInteger('buffer_depth')->default(0);
            $table->string('last_ship_error', 500)->nullable();
            $table->timestampTz('degraded_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_health', function (Blueprint $table) {
            $table->dropColumn(['buffer_depth', 'last_ship_error', 'degraded_since']);
        });
    }
};
