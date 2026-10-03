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
        Schema::table('crypto_signals', function (Blueprint $table): void {
            $table->string('setup', 40)->nullable()->after('setup_type')->index();
            $table->boolean('is_shadow')->default(false)->after('setup');
            $table->boolean('passed_filters')->default(false)->after('is_shadow')->index();
            $table->decimal('ai_probability', 5, 4)->nullable()->after('grade');
            $table->json('features')->nullable()->after('atr_pct');
            $table->string('outcome', 12)->default('OPEN')->after('features')->index();
            $table->decimal('r_multiple', 8, 3)->nullable()->after('outcome');
            $table->decimal('mfe_r', 8, 3)->nullable()->after('r_multiple');
            $table->decimal('mae_r', 8, 3)->nullable()->after('mfe_r');
            $table->timestamp('resolved_at')->nullable()->after('mae_r');
            $table->string('telegram_message_id', 40)->nullable()->after('telegram_sent');
            $table->string('auto_trade_status', 120)->nullable()->after('telegram_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crypto_signals', function (Blueprint $table): void {
            $table->dropColumn([
                'setup',
                'is_shadow',
                'passed_filters',
                'ai_probability',
                'features',
                'outcome',
                'r_multiple',
                'mfe_r',
                'mae_r',
                'resolved_at',
                'telegram_message_id',
                'auto_trade_status',
            ]);
        });
    }
};
