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
        if (! Schema::hasTable('crypto_signals')) {
            Schema::create('crypto_signals', function (Blueprint $table) {
                $table->id();
                $table->string('symbol', 30)->index();
                $table->string('market', 60)->default('Binance USDⓈ-M Futures');
                $table->string('interval', 10)->index();
                $table->string('side', 10)->index(); // BUY or SELL
                $table->string('setup_type', 30)->default('TREND')->index(); // TREND or REVERSAL
                $table->unsignedSmallInteger('score')->default(0);
                $table->string('grade', 5)->default('C');
                $table->decimal('entry_price', 18, 8);
                $table->decimal('stop_loss', 18, 8);
                $table->decimal('take_profit_1', 18, 8);
                $table->decimal('take_profit_2', 18, 8);
                $table->decimal('take_profit_3', 18, 8);
                $table->string('risk_reward', 20)->nullable();
                $table->decimal('rsi', 6, 2)->nullable();
                $table->decimal('adx', 6, 2)->nullable();
                $table->decimal('volume_ratio', 6, 2)->nullable();
                $table->decimal('atr_pct', 6, 2)->nullable();
                $table->string('leverage', 30)->nullable();
                $table->string('margin_mode', 30)->default('Isolated Margin');
                $table->timestamp('candle_close_time')->nullable();
                $table->string('source', 30)->default('cron_scanner')->index(); // cron_scanner, manual_alert, test_alert
                $table->boolean('telegram_sent')->default(true);
                $table->timestamp('sent_at')->useCurrent()->index();
                $table->timestamps();

                $table->index(['symbol', 'interval', 'side']);
                $table->index(['created_at', 'sent_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_signals');
    }
};
