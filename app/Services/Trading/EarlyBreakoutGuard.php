<?php

namespace App\Services\Trading;

use App\Models\Setting;
use App\Models\Trade;

/**
 * Hard limits for auto-traded early breakouts (entries before the candle closes): a daily cap,
 * one open position at a time, and a pause after a losing streak until the user resumes it.
 */
class EarlyBreakoutGuard
{
    public const SETUP = 'EARLY_BREAKOUT';

    public const RESUMED_KEY = 'early_breakout_resumed_at';

    /**
     * Why a new early-breakout entry is not allowed right now (null when it is).
     */
    public static function blockReason(string $mode): ?string
    {
        if ($paused = self::pauseReason($mode)) {
            return $paused;
        }

        $config = (array) config('trading.strategy.early_breakout', []);
        $maxPerDay = (int) ($config['max_per_day'] ?? 3);
        $maxOpen = (int) ($config['max_open'] ?? 1);
        $base = Trade::where('mode', $mode)->where('setup_tag', self::SETUP);

        if ((clone $base)->where('status', 'OPEN')->count() >= $maxOpen) {
            return "Early Breakout: already {$maxOpen} open position(s).";
        }

        if ((clone $base)->where('opened_at', '>=', now()->startOfDay())->count() >= $maxPerDay) {
            return "Early Breakout: daily limit of {$maxPerDay} entries reached.";
        }

        return null;
    }

    /**
     * Paused after N losses in a row (counted since the user last resumed it).
     */
    public static function pauseReason(string $mode): ?string
    {
        $streak = (int) config('trading.strategy.early_breakout.pause_after_losses', 5);
        if ($streak <= 0) {
            return null;
        }

        $resumedAt = Setting::getValue(self::RESUMED_KEY);
        $recent = Trade::where('mode', $mode)->where('setup_tag', self::SETUP)->where('status', 'CLOSED')
            ->when($resumedAt, fn ($q) => $q->where('closed_at', '>', $resumedAt))
            ->orderByDesc('closed_at')->limit($streak)->pluck('net_pnl');

        if ($recent->count() >= $streak && $recent->every(fn ($pnl): bool => (float) $pnl < 0)) {
            return "Early Breakout paused: {$streak} losses in a row. Resume it from the dashboard when you want it back.";
        }

        return null;
    }

    public static function resume(): void
    {
        Setting::putValue(self::RESUMED_KEY, now()->toDateTimeString());
    }
}
