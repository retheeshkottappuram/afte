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
        Schema::table('trades', function (Blueprint $table): void {
            $table->string('setup_tag', 50)->nullable()->after('symbol');
            $table->string('btc_trend_1h', 20)->nullable()->after('setup_tag');
            $table->decimal('stop_distance', 20, 8)->nullable()->after('current_sl');
            $table->decimal('gross_pnl', 16, 4)->default(0.0000)->after('realized_pnl');
            $table->decimal('commission', 16, 4)->default(0.0000)->after('gross_pnl');
            $table->decimal('funding_fee', 16, 4)->default(0.0000)->after('commission');
            $table->decimal('net_pnl', 16, 4)->default(0.0000)->after('funding_fee');
            $table->decimal('mae', 16, 4)->nullable()->after('fee_paid');
            $table->decimal('mfe', 16, 4)->nullable()->after('mae');
            $table->string('binance_exit_order_id', 50)->nullable()->after('binance_order_id');
            $table->json('binance_trade_ids')->nullable()->after('binance_exit_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropColumn([
                'setup_tag',
                'btc_trend_1h',
                'stop_distance',
                'gross_pnl',
                'commission',
                'funding_fee',
                'net_pnl',
                'mae',
                'mfe',
                'binance_exit_order_id',
                'binance_trade_ids',
            ]);
        });
    }
};
