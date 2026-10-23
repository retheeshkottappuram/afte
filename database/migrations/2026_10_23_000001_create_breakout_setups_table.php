<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EBD v1 setups: one row per compression range, carried through its lifecycle states.
     * Prices are decimal(24,12) so sub-cent coins (PEPE, SHIB) keep their precision. Times are UTC.
     */
    public function up(): void
    {
        Schema::create('breakout_setups', function (Blueprint $table): void {
            $table->id();
            $table->string('setup_key', 80)->unique();
            $table->string('symbol', 30);
            $table->string('exchange', 30)->default('Binance');
            $table->string('market_type', 40)->default('USDT-M Perpetual');
            $table->string('strategy', 20)->default('EBD');
            $table->string('strategy_version', 10)->default('v1');
            $table->string('timeframe', 10)->default('5m');
            $table->string('state', 30);
            $table->string('direction', 5)->nullable();

            $table->decimal('support', 24, 12);
            $table->decimal('resistance', 24, 12);
            $table->decimal('atr', 24, 12);
            $table->decimal('range_ratio', 10, 4);
            $table->decimal('bbw_pct', 8, 3);
            $table->decimal('vol_baseline', 24, 4);
            $table->decimal('long_trigger', 24, 12);
            $table->decimal('short_trigger', 24, 12);
            $table->decimal('long_confirm', 24, 12);
            $table->decimal('short_confirm', 24, 12);

            $table->decimal('observed_trigger_price', 24, 12)->nullable();
            $table->string('detection_mode', 15)->nullable();
            $table->unsignedInteger('detection_latency_s')->nullable();
            $table->decimal('entry', 24, 12)->nullable();
            $table->decimal('stop_loss', 24, 12)->nullable();
            $table->decimal('tp1', 24, 12)->nullable();
            $table->decimal('tp2', 24, 12)->nullable();
            $table->decimal('net_rr', 10, 4)->nullable();
            $table->decimal('rvol', 10, 4)->nullable();
            $table->decimal('rvol_pace', 10, 4)->nullable();
            $table->decimal('extension_atr', 10, 4)->nullable();
            $table->unsignedTinyInteger('early_score')->nullable();
            $table->unsignedTinyInteger('confirmed_score')->nullable();
            $table->boolean('entry_valid')->default(false);
            $table->string('cancel_reason', 160)->nullable();

            $table->unsignedBigInteger('compressed_ms');
            $table->unsignedBigInteger('state_since_ms');
            $table->timestamp('compressed_at');
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('retest_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->json('state_data')->nullable();   // engine working state (inside closes, score components, costs, ...)
            $table->foreignId('crypto_signal_id')->nullable()->constrained('crypto_signals')->nullOnDelete();
            $table->string('telegram_message_id', 40)->nullable();
            $table->timestamps();

            $table->index(['symbol', 'state']);
            $table->index(['state', 'updated_at']);
            $table->index(['direction', 'confirmed_at']);
        });

        Schema::create('breakout_setup_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('breakout_setup_id')->constrained()->cascadeOnDelete();
            $table->string('event', 20);
            $table->string('from_state', 30)->nullable();
            $table->string('to_state', 30);
            $table->json('payload')->nullable();
            $table->boolean('notify')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->string('telegram_message_id', 40)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            // One event of each kind per setup: repeated or concurrent cron runs cannot alert twice.
            $table->unique(['breakout_setup_id', 'event']);
            $table->index(['occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breakout_setup_events');
        Schema::dropIfExists('breakout_setups');
    }
};
