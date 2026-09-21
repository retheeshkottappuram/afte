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
 * @property int $total_trades
 * @property int $winning_trades
 * @property int $losing_trades
 * @property int $consecutive_losses
 * @property int $consecutive_wins
 * @property Carbon|null $paused_until
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
        'total_trades',
        'winning_trades',
        'losing_trades',
        'consecutive_losses',
        'consecutive_wins',
        'paused_until',
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
                'initial_balance' => (float) config('trading.seed_capital', 5.0),
                'balance' => (float) config('trading.seed_capital', 5.0),
                'equity' => (float) config('trading.seed_capital', 5.0),
                'peak_equity' => (float) config('trading.seed_capital', 5.0),
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
     * Return progress toward $500 target.
     */
    public function getTargetProgressAttribute(): float
    {
        $target = (float) config('trading.target_capital', 500.0);
        $seed = (float) config('trading.seed_capital', 5.0);

        if ($target <= $seed) {
            return 100.0;
        }

        $gain = max(0.0, $this->balance - $seed);
        $totalNeeded = $target - $seed;

        return min(100.0, round(($gain / $totalNeeded) * 100, 2));
    }
}
