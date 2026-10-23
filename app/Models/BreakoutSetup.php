<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One EBD v1 setup (a 5m compression range) and its lifecycle state.
 * `state_data` holds the engine's full working array (SignalLifecycleService); the other
 * columns are the same values denormalised for queries, the dashboard and analysis.
 *
 * @property int $id
 * @property string $setup_key
 * @property string $symbol
 * @property string $state
 * @property ?string $direction
 * @property array<string, mixed> $state_data
 * @property ?string $telegram_message_id
 */
class BreakoutSetup extends Model
{
    /** @var array<int, string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state_data' => 'array',
            'entry_valid' => 'boolean',
            'compressed_at' => 'datetime',
            'detected_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'retest_at' => 'datetime',
            'failed_at' => 'datetime',
            'expired_at' => 'datetime',
            'compressed_ms' => 'integer',
            'state_since_ms' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(BreakoutSetupEvent::class);
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(CryptoSignal::class, 'crypto_signal_id');
    }

    /** Setups still moving through the lifecycle. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('state', ['RETEST_CONFIRMED', 'FAILED_BREAKOUT', 'EXPIRED']);
    }

    /** @return array<string, mixed> */
    public function toEngine(): array
    {
        return $this->state_data;
    }

    /**
     * Column values for an engine setup array.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function columnsFor(array $s): array
    {
        $at = fn (?int $ms): ?Carbon => $ms === null ? null : Carbon::createFromTimestampMsUTC($ms);

        return [
            'setup_key' => $s['setup_key'],
            'symbol' => $s['symbol'],
            'exchange' => (string) config('breakout.exchange', 'Binance'),
            'market_type' => (string) config('breakout.market_type', 'USDT-M Perpetual'),
            'strategy' => (string) config('breakout.strategy', 'EBD'),
            'strategy_version' => (string) config('breakout.version', 'v1'),
            'timeframe' => (string) config('breakout.timeframes.signal', '5m'),
            'state' => $s['state'],
            'direction' => $s['direction'],
            'support' => $s['support'],
            'resistance' => $s['resistance'],
            'atr' => $s['atr'],
            'range_ratio' => round($s['range_ratio'], 4),
            'bbw_pct' => round($s['bbw_pct'], 3),
            'vol_baseline' => round($s['vol_baseline'], 4),
            'long_trigger' => $s['long_trigger'],
            'short_trigger' => $s['short_trigger'],
            'long_confirm' => $s['long_confirm'],
            'short_confirm' => $s['short_confirm'],
            'observed_trigger_price' => $s['observed_trigger_price'] ?? null,
            'detection_mode' => $s['detection_mode'] ?? null,
            'detection_latency_s' => $s['detection_latency_s'] ?? null,
            'entry' => $s['entry'] ?? null,
            'stop_loss' => $s['sl'] ?? null,
            'tp1' => $s['tp1'] ?? null,
            'tp2' => $s['tp2'] ?? null,
            'net_rr' => isset($s['net_rr']) ? round($s['net_rr'], 4) : null,
            'rvol' => isset($s['rvol']) ? round($s['rvol'], 4) : null,
            'rvol_pace' => isset($s['rvol_pace']) ? round($s['rvol_pace'], 4) : null,
            'extension_atr' => isset($s['extension_atr']) ? round($s['extension_atr'], 4) : null,
            'early_score' => $s['early_score'] ?? null,
            'confirmed_score' => $s['confirmed_score'] ?? null,
            'entry_valid' => (bool) ($s['entry_valid'] ?? false),
            'cancel_reason' => isset($s['cancel_reason']) ? mb_substr((string) $s['cancel_reason'], 0, 160) : null,
            'compressed_ms' => $s['compressed_ms'],
            'state_since_ms' => $s['state_since_ms'],
            'compressed_at' => $at($s['compressed_ms']),
            'detected_at' => $at($s['detected_ms'] ?? null),
            'confirmed_at' => $at($s['confirmed_ms'] ?? null),
            'retest_at' => $at($s['retest_ms'] ?? null),
            'failed_at' => $at($s['failed_ms'] ?? null),
            'expired_at' => $at($s['expired_ms'] ?? null),
            'state_data' => $s,
        ];
    }
}
