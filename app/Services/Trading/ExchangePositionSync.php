<?php

namespace App\Services\Trading;

use App\Models\Setting;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Services\Binance\BinanceFuturesClient;
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
}
