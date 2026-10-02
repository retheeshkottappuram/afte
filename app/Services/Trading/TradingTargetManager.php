<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TradingTargetManager
{
    public const CACHE_KEY = 'trading:active_coin';

    public const CACHE_MONITORED_KEY = 'trading:monitored_coins';

    public const DEFAULT_COIN = 'BTCUSDT';

    /**
     * Default list of top profitable, high-momentum coins to continuously monitor.
     *
     * @var array<int, string>
     */
    public const DEFAULT_MONITORED_COINS = [
        'BTCUSDT',
        'ETHUSDT',
        'SOLUSDT',
        'SUIUSDT',
        'NEARUSDT',
        '1000PEPEUSDT',
        'DOGEUSDT',
        'AVAXUSDT',
        'BNBUSDT',
        'XRPUSDT',
        'LINKUSDT',
        'FETUSDT',
    ];

    /**
     * Recommended liquid perpetual assets available for trading.
     *
     * @var array<int, array{symbol: string, base: string, name: string}>
     */
    public const POPULAR_COINS = [
        ['symbol' => 'BTCUSDT', 'base' => 'BTC', 'name' => 'Bitcoin'],
        ['symbol' => 'ETHUSDT', 'base' => 'ETH', 'name' => 'Ethereum'],
        ['symbol' => 'SOLUSDT', 'base' => 'SOL', 'name' => 'Solana'],
        ['symbol' => 'SUIUSDT', 'base' => 'SUI', 'name' => 'Sui'],
        ['symbol' => 'NEARUSDT', 'base' => 'NEAR', 'name' => 'NEAR Protocol'],
        ['symbol' => '1000PEPEUSDT', 'base' => '1000PEPE', 'name' => 'Pepe 1000'],
        ['symbol' => 'DOGEUSDT', 'base' => 'DOGE', 'name' => 'Dogecoin'],
        ['symbol' => 'AVAXUSDT', 'base' => 'AVAX', 'name' => 'Avalanche'],
        ['symbol' => 'BNBUSDT', 'base' => 'BNB', 'name' => 'Binance Coin'],
        ['symbol' => 'XRPUSDT', 'base' => 'XRP', 'name' => 'Ripple (XRP)'],
        ['symbol' => 'LINKUSDT', 'base' => 'LINK', 'name' => 'Chainlink'],
        ['symbol' => 'FETUSDT', 'base' => 'FET', 'name' => 'Artificial Superintelligence (FET)'],
        ['symbol' => 'RENDERUSDT', 'base' => 'RENDER', 'name' => 'Render'],
        ['symbol' => 'INJUSDT', 'base' => 'INJ', 'name' => 'Injective'],
        ['symbol' => 'APTUSDT', 'base' => 'APT', 'name' => 'Aptos'],
    ];

    /**
     * Get the active trading coin (uppercase symbol, e.g. NEARUSDT).
     */
    public static function getActiveCoin(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached && is_string($cached)) {
            return self::normalizeSymbol($cached);
        }

        $configCoin = config('trading.active_coin', config('trading.symbol', self::DEFAULT_COIN));

        return self::normalizeSymbol((string) $configCoin);
    }

    /**
     * Set the active trading coin and persist in cache.
     */
    public static function setActiveCoin(string $rawCoin): string
    {
        $normalized = self::normalizeSymbol($rawCoin);
        Cache::forever(self::CACHE_KEY, $normalized);

        // Ensure active coin is also in the monitored coins list
        self::addMonitoredCoin($normalized);

        Log::info("Trading target asset switched to {$normalized}");

        return $normalized;
    }

    /**
     * Get list of all coins to continuously monitor (guaranteed at least 5 coins).
     *
     * @return array<int, string>
     */
    public static function getMonitoredCoins(): array
    {
        $cached = Cache::get(self::CACHE_MONITORED_KEY);
        if (is_array($cached) && count($cached) >= 3) {
            $coins = array_values(array_unique(array_map([self::class, 'normalizeSymbol'], $cached)));
        } else {
            $configCoins = config('trading.monitored_coins');
            if (is_array($configCoins) && count($configCoins) >= 3) {
                $coins = array_values(array_unique(array_map([self::class, 'normalizeSymbol'], $configCoins)));
            } else {
                $coins = self::DEFAULT_MONITORED_COINS;
            }
        }

        // Always make sure the active coin is included
        $active = self::getActiveCoin();
        if (! in_array($active, $coins, true)) {
            array_unshift($coins, $active);
        }

        // Ensure at least 5 coins are monitored
        if (count($coins) < 5) {
            foreach (self::DEFAULT_MONITORED_COINS as $fallback) {
                if (! in_array($fallback, $coins, true)) {
                    $coins[] = $fallback;
                }
                if (count($coins) >= 5) {
                    break;
                }
            }
        }

        return array_values($coins);
    }

    /**
     * Set the list of monitored coins.
     *
     * @param  array<int, string>  $rawCoins
     * @return array<int, string>
     */
    public static function setMonitoredCoins(array $rawCoins): array
    {
        $normalized = array_values(array_unique(array_map([self::class, 'normalizeSymbol'], $rawCoins)));

        if (count($normalized) < 5) {
            foreach (self::DEFAULT_MONITORED_COINS as $fallback) {
                if (! in_array($fallback, $normalized, true)) {
                    $normalized[] = $fallback;
                }
                if (count($normalized) >= 5) {
                    break;
                }
            }
        }

        Cache::forever(self::CACHE_MONITORED_KEY, $normalized);

        return $normalized;
    }

    /**
     * Add a coin to the monitored list.
     *
     * @return array<int, string>
     */
    public static function addMonitoredCoin(string $rawCoin): array
    {
        $coins = self::getMonitoredCoins();
        $normalized = self::normalizeSymbol($rawCoin);

        if (! in_array($normalized, $coins, true)) {
            $coins[] = $normalized;
            Cache::forever(self::CACHE_MONITORED_KEY, $coins);
        }

        return $coins;
    }

    /**
     * Remove a coin from the monitored list (maintains at least 5 coins).
     *
     * @return array<int, string>
     */
    public static function removeMonitoredCoin(string $rawCoin): array
    {
        $coins = self::getMonitoredCoins();
        $normalized = self::normalizeSymbol($rawCoin);

        $filtered = array_values(array_filter($coins, fn (string $c): bool => $c !== $normalized));

        if (count($filtered) < 5) {
            foreach (self::DEFAULT_MONITORED_COINS as $fallback) {
                if (! in_array($fallback, $filtered, true) && $fallback !== $normalized) {
                    $filtered[] = $fallback;
                }
                if (count($filtered) >= 5) {
                    break;
                }
            }
        }

        Cache::forever(self::CACHE_MONITORED_KEY, $filtered);

        return $filtered;
    }

    /**
     * Check if a symbol is allowed to be traded (permitted if in monitored list).
     */
    public static function isCoinAllowed(string $symbol): bool
    {
        $normalized = self::normalizeSymbol($symbol);

        // If strict single coin is forced in config and explicitly active
        if (config('trading.single_coin_strict', false)) {
            return $normalized === self::normalizeSymbol(self::getActiveCoin());
        }

        // Otherwise allow any coin in the monitored list
        return in_array($normalized, self::getMonitoredCoins(), true);
    }

    /**
     * Normalize coin representation (e.g., 'near' -> 'NEARUSDT', 'BTC' -> 'BTCUSDT').
     */
    public static function normalizeSymbol(string $raw): string
    {
        $symbol = strtoupper(trim(str_replace('.P', '', $raw)));
        if (! str_ends_with($symbol, 'USDT') && ! str_contains($symbol, ':')) {
            $symbol .= 'USDT';
        }

        return $symbol;
    }

    /**
     * Get base coin ticker (e.g., 'NEARUSDT' -> 'NEAR').
     */
    public static function getBaseCoin(?string $symbol = null): string
    {
        $sym = $symbol ?: self::getActiveCoin();
        if (str_ends_with($sym, 'USDT')) {
            return substr($sym, 0, -4);
        }

        return $sym;
    }

    /**
     * Get list of popular coins with active & monitored flags.
     *
     * @return array<int, array{symbol: string, base: string, name: string, is_active: bool, is_monitored: bool}>
     */
    public static function getAvailableCoins(): array
    {
        $active = self::getActiveCoin();
        $monitored = self::getMonitoredCoins();
        $list = self::POPULAR_COINS;

        $hasActive = false;
        foreach ($list as &$item) {
            $item['is_active'] = ($item['symbol'] === $active);
            $item['is_monitored'] = in_array($item['symbol'], $monitored, true);
            if ($item['is_active']) {
                $hasActive = true;
            }
        }
        unset($item);

        if (! $hasActive) {
            $base = self::getBaseCoin($active);
            array_unshift($list, [
                'symbol' => $active,
                'base' => $base,
                'name' => $base,
                'is_active' => true,
                'is_monitored' => true,
            ]);
        }

        return $list;
    }

    /**
     * Active monitored chart intervals for the SignalAlgo PRO strategy.
     *
     * @return array<int, string>
     */
    public static function getMonitoredTimeframes(): array
    {
        return ['15m', '1h'];
    }
}
