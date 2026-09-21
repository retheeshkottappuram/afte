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
        Schema::create('trading_accounts', function (Blueprint $table): void {
            $table->id();
            $table->enum('mode', ['paper', 'testnet', 'shadow', 'live'])->unique();
            $table->decimal('initial_balance', 16, 4)->default(5.0000);
            $table->decimal('balance', 16, 4)->default(5.0000);
            $table->decimal('equity', 16, 4)->default(5.0000);
            $table->decimal('peak_equity', 16, 4)->default(5.0000);
            $table->unsignedInteger('total_trades')->default(0);
            $table->unsignedInteger('winning_trades')->default(0);
            $table->unsignedInteger('losing_trades')->default(0);
            $table->unsignedInteger('consecutive_losses')->default(0);
            $table->unsignedInteger('consecutive_wins')->default(0);
            $table->timestamp('paused_until')->nullable();
            $table->boolean('kill_switch')->default(false);
            $table->timestamps();
        });

        Schema::create('trades', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 20)->index();
            $table->enum('side', ['LONG', 'SHORT']);
            $table->enum('mode', ['paper', 'testnet', 'shadow', 'live'])->index();
            $table->enum('status', ['OPEN', 'CLOSED', 'CANCELLED'])->default('OPEN')->index();
            $table->enum('stage', ['ENTRY', 'BE_LOCKED', 'TP1_HIT', 'TP2_HIT', 'TRAILING', 'CLOSED'])->default('ENTRY');
            $table->decimal('entry_price', 20, 8);
            $table->decimal('quantity', 20, 8);
            $table->decimal('remaining_quantity', 20, 8);
            $table->decimal('margin_used', 16, 4);
            $table->unsignedSmallInteger('leverage')->default(10);
            $table->decimal('initial_sl', 20, 8);
            $table->decimal('current_sl', 20, 8);
            $table->decimal('tp1_price', 20, 8);
            $table->decimal('tp2_price', 20, 8);
            $table->boolean('be_locked')->default(false);
            $table->boolean('tp1_hit')->default(false);
            $table->boolean('tp2_hit')->default(false);
            $table->decimal('exit_price', 20, 8)->nullable();
            $table->string('exit_reason', 50)->nullable();
            $table->decimal('realized_pnl', 16, 4)->default(0.0000);
            $table->decimal('pnl_percent', 10, 4)->default(0.0000);
            $table->decimal('fee_paid', 16, 4)->default(0.0000);
            $table->decimal('highest_price', 20, 8)->nullable();
            $table->decimal('lowest_price', 20, 8)->nullable();
            $table->string('binance_order_id', 50)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('trading_signals', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 20)->index();
            $table->enum('direction', ['LONG', 'SHORT']);
            $table->unsignedSmallInteger('score');
            $table->string('grade', 5);
            $table->decimal('price', 20, 8);
            $table->string('timeframe', 10)->default('15m');
            $table->json('indicators')->nullable();
            $table->enum('ai_status', ['APPROVED', 'REJECTED', 'PENDING'])->default('PENDING');
            $table->unsignedSmallInteger('ai_confidence')->default(0);
            $table->string('ai_regime', 50)->nullable();
            $table->text('ai_reason')->nullable();
            $table->boolean('executed')->default(false);
            $table->unsignedBigInteger('trade_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('equity_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->enum('mode', ['paper', 'testnet', 'shadow', 'live'])->index();
            $table->decimal('balance', 16, 4);
            $table->decimal('equity', 16, 4);
            $table->unsignedSmallInteger('open_positions')->default(0);
            $table->timestamps();
        });

        Schema::create('system_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 20)->default('info');
            $table->string('category', 50)->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_logs');
        Schema::dropIfExists('equity_snapshots');
        Schema::dropIfExists('trading_signals');
        Schema::dropIfExists('trades');
        Schema::dropIfExists('trading_accounts');
    }
};
