<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CryptoSignal extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'crypto_signals';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'symbol',
        'market',
        'interval',
        'side',
        'setup_type',
        'setup',
        'is_shadow',
        'passed_filters',
        'score',
        'grade',
        'ai_probability',
        'entry_price',
        'stop_loss',
        'take_profit_1',
        'take_profit_2',
        'take_profit_3',
        'risk_reward',
        'rsi',
        'adx',
        'volume_ratio',
        'atr_pct',
        'features',
        'outcome',
        'r_multiple',
        'mfe_r',
        'mae_r',
        'resolved_at',
        'leverage',
        'margin_mode',
        'candle_close_time',
        'source',
        'telegram_sent',
        'telegram_message_id',
        'auto_trade_status',
        'sent_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'entry_price' => 'float',
            'stop_loss' => 'float',
            'take_profit_1' => 'float',
            'take_profit_2' => 'float',
            'take_profit_3' => 'float',
            'rsi' => 'float',
            'adx' => 'float',
            'volume_ratio' => 'float',
            'atr_pct' => 'float',
            'is_shadow' => 'boolean',
            'passed_filters' => 'boolean',
            'ai_probability' => 'float',
            'features' => 'array',
            'r_multiple' => 'float',
            'mfe_r' => 'float',
            'mae_r' => 'float',
            'resolved_at' => 'datetime',
            'telegram_sent' => 'boolean',
            'candle_close_time' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Check if this is a BUY (LONG) setup.
     */
    public function isBuy(): bool
    {
        return strtoupper($this->side) === 'BUY';
    }

    /**
     * Check if this is a SELL (SHORT) setup.
     */
    public function isSell(): bool
    {
        return strtoupper($this->side) === 'SELL';
    }

    /**
     * Check if this is an Exhaustion Reversal setup.
     */
    public function isReversal(): bool
    {
        return strtoupper($this->setup_type) === 'REVERSAL';
    }

    /**
     * Scope query to order by most recent sent alert first.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('sent_at')->orderByDesc('id');
    }

    /**
     * Scope query to filter by symbol.
     */
    public function scopeBySymbol(Builder $query, ?string $symbol): Builder
    {
        if (empty($symbol)) {
            return $query;
        }

        $clean = strtoupper(str_replace('.P', '', trim($symbol)));

        return $query->where('symbol', 'like', "%{$clean}%");
    }

    /**
     * Scope query to filter by side.
     */
    public function scopeBySide(Builder $query, ?string $side): Builder
    {
        if (empty($side) || strtoupper($side) === 'ALL') {
            return $query;
        }

        return $query->where('side', strtoupper($side));
    }

    /**
     * Scope query to filter by timeframe interval.
     */
    public function scopeByInterval(Builder $query, ?string $interval): Builder
    {
        if (empty($interval) || strtolower($interval) === 'all') {
            return $query;
        }

        return $query->where('interval', strtolower($interval));
    }

    /**
     * Scope query to filter by setup type.
     */
    public function scopeBySetupType(Builder $query, ?string $setupType): Builder
    {
        if (empty($setupType) || strtoupper($setupType) === 'ALL') {
            return $query;
        }

        return $query->where('setup_type', strtoupper($setupType));
    }
}
