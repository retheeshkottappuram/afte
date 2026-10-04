<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Services\Binance\BinanceFuturesClient;
use App\Services\Trading\ExchangePositionCloser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Real-time account stream support for the dashboard (the browser opens the Binance websocket
 * itself; shared hosting cannot keep one open) and the position close / book-profit actions.
 */
class ExchangeStreamController extends Controller
{
    protected const LISTEN_KEY_CACHE = 'binance:live:listen-key';

    public function __construct(
        protected BinanceFuturesClient $client
    ) {}

    /**
     * Listen key for wss://fstream.binance.com/ws/<key>: account and order events, no trading rights.
     */
    public function listenKey(): JsonResponse
    {
        $client = $this->client->forMode('live');
        if (! $client->hasCredentials()) {
            return response()->json(['success' => false, 'message' => 'Binance API keys are not configured.'], 422);
        }

        try {
            $key = Cache::remember(self::LISTEN_KEY_CACHE, 50 * 60, fn (): string => $client->createListenKey());

            return response()->json(['success' => true, 'listen_key' => $key, 'ws_base' => 'wss://fstream.binance.com', 'keepalive_seconds' => 30 * 60]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Could not open the Binance account stream: '.$e->getMessage()], 502);
        }
    }

    public function keepAlive(): JsonResponse
    {
        try {
            $this->client->forMode('live')->keepAliveListenKey();
            if (Cache::has(self::LISTEN_KEY_CACHE)) {
                Cache::put(self::LISTEN_KEY_CACHE, Cache::get(self::LISTEN_KEY_CACHE), 50 * 60);
            }

            return response()->json(['success' => true]);
        } catch (Throwable $e) {
            Cache::forget(self::LISTEN_KEY_CACHE);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }

    /**
     * Set, move or remove SL / TP on a manual Binance position: {symbol, side?, sl?, tp?}; a null value removes that order.
     */
    public function protect(Request $request, ExchangePositionCloser $closer): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:30'],
            'side' => ['nullable', 'in:LONG,SHORT'],
            'sl' => ['nullable', 'numeric', 'gt:0'],
            'tp' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $changes = [];
        foreach (['sl', 'tp'] as $leg) {
            if ($request->exists($leg)) {
                $changes[$leg] = $data[$leg] !== null ? (float) $data[$leg] : null;
            }
        }
        if ($changes === []) {
            return response()->json(['success' => false, 'message' => 'Nothing to change: send sl and/or tp.'], 422);
        }

        $result = $closer->protect((string) $data['symbol'], $data['side'] ?? null, $changes);

        return response()->json(array_diff_key($result, ['http' => true]), $result['http']);
    }

    /**
     * Close or book part of a position: {symbol, side?, percent} for Binance positions, {trade_id, percent} for bot trades.
     */
    public function close(Request $request, ExchangePositionCloser $closer): JsonResponse
    {
        $data = $request->validate([
            'trade_id' => ['nullable', 'integer'],
            'symbol' => ['required_without:trade_id', 'nullable', 'string', 'max:30'],
            'side' => ['nullable', 'in:LONG,SHORT'],
            'percent' => ['required', 'integer', 'in:25,50,75,100'],
        ]);
        $ratio = $data['percent'] / 100;

        if (! empty($data['trade_id'])) {
            $trade = Trade::where('status', 'OPEN')->find($data['trade_id']);
            if ($trade === null) {
                return response()->json(['success' => false, 'message' => 'Trade not found or already closed.'], 404);
            }
            $result = $closer->closeTrade($trade, $ratio);
        } else {
            $result = $closer->close((string) $data['symbol'], $data['side'] ?? null, $ratio);
        }

        return response()->json($result, $result['success'] ? 200 : 502);
    }
}
