<?php

namespace App\Services\Strategy;

use App\Models\Setting;

/**
 * "Alert me" coins: always scanned (even outside the liquid universe) and every non-shadow
 * signal on them is sent to Telegram regardless of grade.
 */
class Watchlist
{
    public const KEY = 'alert_watchlist';

    public const ENABLED_KEY = 'telegram_signal_alerts';

    public const DEFAULT = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'DOGEUSDT'];

    /**
     * @return array<int, string>
     */
    public static function symbols(): array
    {
        return array_values((array) Setting::getValue(self::KEY, self::DEFAULT));
    }

    public static function contains(string $symbol): bool
    {
        return in_array(strtoupper($symbol), self::symbols(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function add(string $symbol): array
    {
        $symbols = array_values(array_unique(array_merge(self::symbols(), [strtoupper($symbol)])));
        Setting::putValue(self::KEY, $symbols);

        return $symbols;
    }

    /**
     * @return array<int, string>
     */
    public static function remove(string $symbol): array
    {
        $symbols = array_values(array_diff(self::symbols(), [strtoupper($symbol)]));
        Setting::putValue(self::KEY, $symbols);

        return $symbols;
    }

    /**
     * @return array<int, string>
     */
    public static function reset(): array
    {
        Setting::putValue(self::KEY, self::DEFAULT);

        return self::DEFAULT;
    }

    /**
     * Whether Telegram signal alerts are switched on (risk and health alerts are always on).
     */
    public static function alertsEnabled(): bool
    {
        return (bool) Setting::getValue(self::ENABLED_KEY, true);
    }

    public static function setAlertsEnabled(bool $enabled): void
    {
        Setting::putValue(self::ENABLED_KEY, $enabled);
    }
}
