<?php

namespace App\Services\Trading;

use App\Models\Setting;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls the real wallet balance from Binance and lists positions the bot did not open.
 * It never adopts, edits or closes trades: DynamicTradeManager is the only owner of bot trades.
 */
class ExchangePositionSync
{
    public const EXTERNAL_POSITIONS_KEY = 'external_positions';

    public function __construct(
        protected BinanceFuturesClient $client
    ) {}

    public function syncLiveAccountAndPositions(TradingAccount $account, string $mode = 'live'): bool
    {
        if ($mode !== 'live') {
            return false;
        }

        $client = $this->client->forMode('live');
        if (! $client->hasCredentials()) {
            return false;
        }

        try {
            foreach ($client->getBalance() as $row) {
                if (($row['asset'] ?? '') === 'USDT') {
                    $wallet = (float) ($row['balance'] ?? $row['crossWalletBalance'] ?? 0);
                    $account->balance = round($wallet, 4);
                    $account->equity = round($wallet + (float) ($row['crossUnPnl'] ?? 0), 4);
                    $account->peak_equity = max((float) $account->peak_equity, $account->balance);
                    $account->save();
                    break;
                }
            }

            $botSymbols = Trade::where('mode', 'live')->where('status', 'OPEN')->pluck('symbol')->all();
            $external = [];

            foreach ($client->getPositions() as $position) {
                $amount = (float) ($position['positionAmt'] ?? 0);
                $symbol = (string) ($position['symbol'] ?? '');

                if ($amount == 0.0 || $symbol === '' || in_array($symbol, $botSymbols, true)) {
                    continue;
                }

                $external[] = [
                    'symbol' => $symbol,
                    'side' => $amount > 0 ? 'LONG' : 'SHORT',
                    'quantity' => abs($amount),
                    'entry_price' => (float) ($position['entryPrice'] ?? 0),
                    'unrealized_pnl' => (float) ($position['unRealizedProfit'] ?? 0),
                ];
            }

            Setting::putValue(self::EXTERNAL_POSITIONS_KEY, $external);

            return true;
        } catch (Throwable $e) {
            Log::warning('[ExchangePositionSync] '.$e->getMessage());

            return false;
        }
    }

    /**
     * Every open position on the real Binance account (bot and manual), with live PnL, liquidation
     * and the stop / take-profit orders protecting it. Read-only; works in any view mode.
     *
     * Also returns the wallet balance and equity (wallet + unrealized PnL of every position).
     *
     * @return array{available: bool, positions: array<int, array<string, mixed>>, error: ?string, wallet_balance: ?float, unrealized_total: float, equity: ?float}
     */
    public function exchangePositions(): array
    {
        $client = $this->client->forMode('live');
        if (! $client->hasCredentials()) {
            return ['available' => false, 'positions' => [], 'error' => null, 'wallet_balance' => null, 'unrealized_total' => 0.0, 'equity' => null];
        }

        try {
            $wallet = $this->walletBalance($client);
            $rows = array_values(array_filter($client->getPositions(), fn (array $p): bool => (float) ($p['positionAmt'] ?? 0) != 0.0));
            if ($rows === []) {
                return ['available' => true, 'positions' => [], 'error' => null, 'wallet_balance' => $wallet, 'unrealized_total' => 0.0, 'equity' => $wallet];
            }

            // Open-order lists are heavy on the API weight limit; SL/TP changes also arrive via the websocket.
            $protection = Cache::remember('exchange:protective-orders', 10, fn (): array => $this->protectiveOrders($client));
            $botTrades = Trade::where('mode', 'live')->where('status', 'OPEN')->get()->keyBy('symbol');
            $positions = [];

            foreach ($rows as $row) {
                $symbol = (string) $row['symbol'];
                $amount = (float) $row['positionAmt'];
                $side = $amount > 0 ? 'LONG' : 'SHORT';
                $entry = (float) ($row['entryPrice'] ?? 0);
                $mark = (float) ($row['markPrice'] ?? 0);
                $leverage = max(1, (int) ($row['leverage'] ?? 1));
                $notional = abs((float) ($row['notional'] ?? $amount * $mark));
                $pnl = (float) ($row['unRealizedProfit'] ?? 0);
                $margin = (string) ($row['marginType'] ?? 'cross') === 'isolated' ? (float) ($row['isolatedWallet'] ?? $row['isolatedMargin'] ?? 0) : $notional / $leverage;
                $liquidation = (float) ($row['liquidationPrice'] ?? 0);
                $orders = $protection[$symbol] ?? [];
                $bot = $botTrades->get($symbol);

                $positions[] = [
                    'symbol' => $symbol,
                    'side' => $side,
                    'position_side' => (string) ($row['positionSide'] ?? 'BOTH'),
                    'quantity' => abs($amount),
                    'entry_price' => $entry,
                    'mark_price' => $mark,
                    'unrealized_pnl' => round($pnl, 4),
                    'roe_pct' => $entry > 0 ? round($pnl / (abs($amount) * $entry / $leverage) * 100, 2) : 0.0, // return on initial margin, as Binance shows it
                    'leverage' => $leverage,
                    'margin_type' => strtoupper((string) ($row['marginType'] ?? 'cross')),
                    'margin' => round($margin, 4),
                    'notional' => round($notional, 2),
                    'liquidation_price' => $liquidation > 0 ? $liquidation : null,
                    'liquidation_distance_pct' => $liquidation > 0 && $mark > 0 ? round(abs($mark - $liquidation) / $mark * 100, 2) : null,
                    'sl' => $orders['sl'] ?? null,
                    'tp' => $orders['tp'] ?? null,
                    'managed_by' => $bot !== null && $bot->side === $side ? 'bot' : 'manual',
                    'trade_id' => $bot !== null && $bot->side === $side ? $bot->id : null,
                ];
            }

            $unrealized = round(array_sum(array_column($positions, 'unrealized_pnl')), 4);

            return [
                'available' => true,
                'positions' => $positions,
                'error' => null,
                'wallet_balance' => $wallet,
                'unrealized_total' => $unrealized,
                'equity' => $wallet !== null ? round($wallet + $unrealized, 4) : null,
            ];
        } catch (Throwable $e) {
            Log::warning('[ExchangePositionSync] positions: '.$e->getMessage());

            return ['available' => true, 'positions' => [], 'error' => 'Could not load Binance positions: '.mb_substr($e->getMessage(), 0, 160), 'wallet_balance' => null, 'unrealized_total' => 0.0, 'equity' => null];
        }
    }

    /**
     * USDT wallet balance (realized money, before open-position PnL).
     */
    protected function walletBalance(BinanceFuturesClient $client): ?float
    {
        foreach ($client->getBalance() as $row) {
            if (($row['asset'] ?? '') === 'USDT') {
                return round((float) ($row['balance'] ?? $row['crossWalletBalance'] ?? 0), 4);
            }
        }

        return null;
    }

    /**
     * Nearest stop-loss and take-profit trigger per symbol from open algo and classic orders.
     *
     * @return array<string, array{sl?: float, tp?: float}>
     */
    protected function protectiveOrders(BinanceFuturesClient $client): array
    {
        $out = [];
        $add = function (string $symbol, string $type, float $price) use (&$out): void {
            if ($price <= 0) {
                return;
            }
            $key = str_starts_with($type, 'STOP') ? 'sl' : (str_starts_with($type, 'TAKE_PROFIT') ? 'tp' : null);
            if ($key !== null && ! isset($out[$symbol][$key])) {
                $out[$symbol][$key] = $price;
            }
        };

        try {
            foreach ($client->getOpenAlgoOrders() as $order) {
                $add((string) ($order['symbol'] ?? ''), strtoupper((string) ($order['orderType'] ?? $order['type'] ?? '')), (float) ($order['triggerPrice'] ?? 0));
            }
        } catch (Throwable) {
            // Algo endpoint unavailable: fall back to classic orders only
        }

        try {
            foreach ($client->getOpenOrders() as $order) {
                $add((string) ($order['symbol'] ?? ''), strtoupper((string) ($order['type'] ?? '')), (float) ($order['stopPrice'] ?? 0));
            }
        } catch (Throwable) {
            // No classic orders
        }

        return $out;
    }
}
