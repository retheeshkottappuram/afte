<?php

namespace App\Services\Strategy;

use App\Models\CryptoSignal;
use Carbon\Carbon;

/**
 * Persists strategy signals to crypto_signals (with features for the AI model) so their
 * outcomes can be tracked and measured.
 */
class SignalLedger
{
    public function record(Signal $signal, string $source = 'scanner', string $market = 'Binance USDⓈ-M Futures'): CryptoSignal
    {
        $candleClose = Carbon::createFromTimestampUTC($signal->time);
        $attributes = [
            'symbol' => $signal->symbol,
            'interval' => $signal->interval,
            'candle_close_time' => $candleClose,
            'side' => $signal->orderSide(),
        ];

        $values = [
            'market' => $market,
            'setup_type' => $signal->setup,
            'setup' => $signal->setup,
            'is_shadow' => $signal->isShadow,
            'passed_filters' => $signal->failedFilters() === [],
            'score' => (int) round(($signal->aiProbability ?? 0) * 100),
            'grade' => $signal->grade ?? 'C',
            'ai_probability' => $signal->aiProbability,
            'entry_price' => $signal->entry,
            'stop_loss' => $signal->stopLoss,
            'take_profit_1' => $signal->tp1,
            'take_profit_2' => $signal->tp2,
            'take_profit_3' => $signal->tp3,
            'risk_reward' => '1 : '.round($signal->riskReward(), 1),
            'rsi' => $signal->indicators['rsi'] ?? null,
            'adx' => $signal->indicators['adx'] ?? null,
            'volume_ratio' => $signal->indicators['volume_ratio'] ?? null,
            'atr_pct' => $signal->indicators['atr_pct'] ?? null,
            'features' => array_merge($signal->features, ['atr' => $signal->atr, 'tradable' => $signal->isTradable()]),
            'leverage' => 'Risk-sized',
            'margin_mode' => 'Isolated Margin',
            'source' => $source,
            'telegram_sent' => false,
            'sent_at' => $source === 'backtest' ? $candleClose : now(),
        ];

        $record = CryptoSignal::query()->where($attributes)->first();

        if ($record === null) {
            return CryptoSignal::create(array_merge($attributes, $values, ['outcome' => 'OPEN']));
        }

        // Upgrade rows written by the legacy engine; never overwrite a tracked outcome.
        if ($record->setup === null) {
            $record->update($values);
        }

        return $record;
    }
}
