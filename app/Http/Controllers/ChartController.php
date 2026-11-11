<?php

namespace App\Http\Controllers;

use App\Services\Trading\NearBreakoutTrader;
use App\Services\Trading\NearChart;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Throwable;

/**
 * SignalAlgo PRO chart of the NEAR 1H Volatility-Gated Breakout. Read-only: public candles, the replayed script
 * signals and the bot's trades. It never places orders.
 */
class ChartController extends Controller
{
    public function index(): View
    {
        return view('chart.index', ['symbol' => NearBreakoutTrader::symbol()]);
    }

    public function data(NearChart $chart): JsonResponse
    {
        try {
            return response()->json($chart->payload());
        } catch (Throwable $e) {
            return response()->json(['error' => 'Chart data unavailable: '.$e->getMessage()], 503);
        }
    }
}
