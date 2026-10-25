<?php

namespace App\Services\Breakout;

use App\Models\CryptoSignal;

/**
 * Live track record of EBD entries per coin (and direction): resolved confirmed / retest signals from crypto_signals,
 * R after fees as resolved by crypto:resolve-signals. Gates which coins may send Telegram entry alerts.
 */
class BreakoutCoinRecord
{
    public const SETUPS = ['EBD_CONFIRMED', 'EBD_RETEST'];

    public const RESOLVED = ['TP1', 'TP2', 'SL', 'BE', 'EXPIRED'];

    public function __construct(protected EbdConfig $config) {}

    /**
     * @return array{signals: int, wins: int, net_r: float, profit_factor: ?float, qualifies: bool}
     */
    public function of(string $symbol, ?string $direction = null): array
    {
        $rs = CryptoSignal::query()
            ->where('source', 'ebd')
            ->where('symbol', $symbol)
            ->whereIn('setup', self::SETUPS)
            ->whereIn('outcome', self::RESOLVED)
            ->where('created_at', '>=', now()->subDays($this->config->int('notifications.record_days')))
            ->when($direction !== null && $this->config->bool('notifications.record_by_direction'), fn ($q) => $q->where('side', $direction))
            ->pluck('r_multiple')
            ->map(fn ($r): float => (float) $r)
            ->all();

        $won = array_sum(array_filter($rs, fn (float $r): bool => $r > 0));
        $lost = -array_sum(array_filter($rs, fn (float $r): bool => $r < 0));
        $pf = $lost > 0 ? round($won / $lost, 2) : ($won > 0 ? null : 0.0); // null = no losses yet

        return [
            'signals' => count($rs),
            'wins' => count(array_filter($rs, fn (float $r): bool => $r > 0)),
            'net_r' => round(array_sum($rs), 2),
            'profit_factor' => $pf,
            'qualifies' => count($rs) >= $this->config->int('notifications.record_min_signals')
                && ($pf === null || $pf >= $this->config->float('notifications.record_min_pf')),
        ];
    }

    /** One line for the alert, e.g. "ZEC SHORT live 30d: 7 signals, 4 won, PF 1.60, +3.2R". */
    public function line(string $symbol, ?string $direction = null): string
    {
        $r = $this->of($symbol, $direction);
        $who = trim(str_replace('USDT', '', $symbol).' '.($this->config->bool('notifications.record_by_direction') ? (string) $direction : ''));

        return sprintf('%s live %dd: %d signals, %d won, PF %s, %+.1fR', $who, $this->config->int('notifications.record_days'),
            $r['signals'], $r['wins'], $r['profit_factor'] === null ? 'n/a (no losses)' : number_format($r['profit_factor'], 2), $r['net_r']);
    }
}
