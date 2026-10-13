<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $mode
 * @property float $initial_balance
 * @property float $balance
 * @property float $equity
 * @property float $peak_equity
 * @property float|null $day_start_equity
 * @property Carbon|null $day_start_date
 * @property int $total_trades
 * @property int $winning_trades
 * @property int $losing_trades
 * @property int $consecutive_losses
 * @property int $consecutive_wins
 * @property Carbon|null $paused_until
 * @property string|null $pause_reason
 * @property bool $kill_switch
 * @property bool $is_running
 */
class TradingAccount extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'mode',
        'initial_balance',
        'balance',
        'equity',
        'peak_equity',
        'day_start_equity',
        'day_start_date',
        'total_trades',
        'winning_trades',
        'losing_trades',
        'consecutive_losses',
        'consecutive_wins',
        'paused_until',
        'pause_reason',
        'kill_switch',
        'is_running',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'initial_balance' => 'float',
        'balance' => 'float',
        'equity' => 'float',
        'peak_equity' => 'float',
        'day_start_equity' => 'float',
        'day_start_date' => 'date',
        'total_trades' => 'integer',
        'winning_trades' => 'integer',
        'losing_trades' => 'integer',
        'consecutive_losses' => 'integer',
        'consecutive_wins' => 'integer',
        'paused_until' => 'datetime',
        'kill_switch' => 'boolean',
        'is_running' => 'boolean',
    ];

    /**
     * Get or create account for a specific mode.
     */
    public static function getForMode(string $mode = 'paper'): self
    {
        return static::firstOrCreate(
            ['mode' => $mode],
            [
                'initial_balance' => (float) config('trading.seed_capital', 24.0),
                'balance' => (float) config('trading.seed_capital', 24.0),
                'equity' => (float) config('trading.seed_capital', 24.0),
                'peak_equity' => (float) config('trading.seed_capital', 24.0),
                'total_trades' => 0,
                'winning_trades' => 0,
                'losing_trades' => 0,
                'consecutive_losses' => 0,
                'consecutive_wins' => 0,
                'kill_switch' => false,
                'is_running' => false,
            ]
        );
    }

    /**
     * Check if trading is currently active and allowed.
     */
    public function canTrade(): bool
    {
        if ($this->kill_switch || ! $this->is_running) {
            return false;
        }

        if ($this->paused_until !== null && $this->paused_until->isFuture()) {
            return false;
        }

        return true;
    }

    /**
     * Calculate win rate percentage.
     */
    public function getWinRateAttribute(): float
    {
        if ($this->total_trades === 0) {
            return 0.0;
        }

        return round(($this->winning_trades / $this->total_trades) * 100, 2);
    }

    /**
     * Restart progress tracking from a new starting balance (e.g. after a deposit). The old peak predates the
     * deposit, so drawdown is measured from today's balance. Trade history is untouched.
     */
    public function setBaseline(float $amount): void
    {
        $this->initial_balance = $amount;
        $this->peak_equity = max($amount, (float) $this->balance, (float) $this->equity);
        $this->save();
    }

    /**
     * Progress from this account's starting balance to the final target.
     */
    public function getTargetProgressAttribute(): float
    {
        $target = (float) config('trading.target_capital', 500.0);
        $seed = (float) ($this->initial_balance ?: config('trading.seed_capital', 24.0));

        if ($target <= $seed) {
            return 100.0;
        }

        $gain = max(0.0, $this->balance - $seed);
        $totalNeeded = $target - $seed;

        return min(100.0, round(($gain / $totalNeeded) * 100, 2));
    }

    /**
     * The next balance milestone and progress toward it from the previous one (or the starting balance).
     *
     * @return array{from: float, to: float, pct: float}
     */
    public function milestone(?float $equity = null): array
    {
        $equity ??= (float) $this->equity;
        $target = (float) config('trading.target_capital', 500.0);
        $from = (float) ($this->initial_balance ?: config('trading.seed_capital', 24.0));
        $to = $target;
        foreach ((array) config('trading.milestones', [50.0, 100.0, 250.0]) as $milestone) {
            $milestone = (float) $milestone;
            if ($milestone >= $target) {
                break;
            }
            if ($equity < $milestone) {
                $to = $milestone;
                break;
            }
            $from = max($from, $milestone);
        }
        $pct = $to > $from ? ($equity - $from) / ($to - $from) * 100 : 100.0;

        return ['from' => round($from, 2), 'to' => round($to, 2), 'pct' => round(min(100.0, max(0.0, $pct)), 1)];
    }
}
