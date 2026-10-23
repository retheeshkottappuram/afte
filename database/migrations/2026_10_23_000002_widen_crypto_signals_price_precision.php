<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected const COLUMNS = ['entry_price', 'stop_loss', 'take_profit_1', 'take_profit_2', 'take_profit_3'];

    /**
     * decimal(18,8) cuts sub-cent coins (PEPE 0.0000123 keeps 3 significant digits); widen to (24,12).
     */
    public function up(): void
    {
        Schema::table('crypto_signals', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 24, 12)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('crypto_signals', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 18, 8)->change();
            }
        });
    }
};
