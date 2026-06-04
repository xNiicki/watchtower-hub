<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('app_id')->nullable()->after('target_id')->constrained('apps')->nullOnDelete();
        });

        DB::statement("CREATE INDEX alerts_app_open_idx ON alerts (app_id, rule_key) WHERE state IN ('pending', 'firing')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS alerts_app_open_idx');

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_id');
        });
    }
};
