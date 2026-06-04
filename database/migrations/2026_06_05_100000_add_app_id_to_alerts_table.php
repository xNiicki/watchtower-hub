<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('app_id')->nullable()->after('target_id')->constrained('apps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_id');
        });
    }
};
