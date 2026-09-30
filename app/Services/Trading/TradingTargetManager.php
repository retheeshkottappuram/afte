<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TradingTargetManager
{
    public const CACHE_KEY = 'trading:active_coin';

    public const DEFAULT_COIN = 'NEARUSDT';

    /**
     * Recommended liquid perpetual assets available for single-coin trading.
     *
     * @var array<int, array{symbol: string, base: string, name: string}>
     */
    public const POPULAR_COINS = [
        ['symbol' => 'NEARUSDT', 'base' => 'NEAR', 'name' => 'NEAR Protocol'],
        ['symbol' => 'BTCUSDT', 'base' => 'BTC', 'name' => 'Bitcoin'],
        ['symbol' => 'ETHUSDT', 'base' => 'ETH', 'name' => 'Ethereum'],
        ['symbol' => 'SOLUSDT', 'base' => 'SOL', 'name' => 'Solana'],
        ['symbol' => 'DOGEUSDT', 'base' => 'DOGE', 'name' => 'Dogecoin'],
        ['symbol' => 'XRPUSDT', 'base' => 'XRP', 'name' => 'Ripple (XRP)'],
        ['symbol' => 'BNBUSDT', 'base' => 'BNB', 'name' => 'Binance Coin'],
        ['symbol' => 'SUIUSDT', 'base' => 'SUI', 'name' => 'Sui'],
        ['symbol' => 'AVAXUSDT', 'base' => 'AVAX', 'name' => 'Avalanche'],
        ['symbol' => '1000PEPEUSDT', 'base' => '1000PEPE', 'name' => 'Pepe 1000'],
        ['symbol' => 'LINKUSDT', 'base' => 'LINK', 'name' => 'Chainlink'],
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

        Log::info("Trading target asset switched to {$normalized}");

        return $normalized;
    }

    /**
     * Check if a symbol matches the strictly permitted active trading coin.
     */
    public static function isCoinAllowed(string $symbol): bool
    {
        return self::normalizeSymbol($symbol) === self::normalizeSymbol(self::getActiveCoin());
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
     * Get list of popular coins plus current active coin.
     *
     * @return array<int, array{symbol: string, base: string, name: string, is_active: bool}>
     */
    public static function getAvailableCoins(): array
    {
        $active = self::getActiveCoin();
        $list = self::POPULAR_COINS;

        $hasActive = false;
        foreach ($list as &$item) {
            $item['is_active'] = ($item['symbol'] === $active);
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
