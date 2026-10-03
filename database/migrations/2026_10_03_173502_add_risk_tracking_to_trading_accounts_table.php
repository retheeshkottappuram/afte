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
        Schema::table('trading_accounts', function (Blueprint $table): void {
            $table->decimal('day_start_equity', 16, 4)->nullable()->after('peak_equity');
            $table->date('day_start_date')->nullable()->after('day_start_equity');
            $table->string('pause_reason', 255)->nullable()->after('paused_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_accounts', function (Blueprint $table): void {
            $table->dropColumn(['day_start_equity', 'day_start_date', 'pause_reason']);
        });
    }
};
