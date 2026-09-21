<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $symbol
 * @property string $side
 * @property string $mode
 * @property string $status
 * @property string $stage
 * @property float $entry_price
 * @property float $quantity
 * @property float $remaining_quantity
 * @property float $margin_used
 * @property int $leverage
 * @property float $initial_sl
 * @property float $current_sl
 * @property float $tp1_price
 * @property float $tp2_price
 * @property bool $be_locked
 * @property bool $tp1_hit
 * @property bool $tp2_hit
 * @property float|null $exit_price
 * @property string|null $exit_reason
 * @property float $realized_pnl
 * @property float $pnl_percent
 * @property float $fee_paid
 * @property float|null $highest_price
 * @property float|null $lowest_price
 * @property string|null $binance_order_id
 * @property array|null $meta
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 */
class Trade extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'symbol',
        'side',
        'mode',
        'status',
        'stage',
        'entry_price',
        'quantity',
        'remaining_quantity',
        'margin_used',
        'leverage',
        'initial_sl',
        'current_sl',
        'tp1_price',
        'tp2_price',
        'be_locked',
        'tp1_hit',
        'tp2_hit',
        'exit_price',
        'exit_reason',
        'realized_pnl',
        'pnl_percent',
        'fee_paid',
        'highest_price',
        'lowest_price',
        'binance_order_id',
        'meta',
        'opened_at',
        'closed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'entry_price' => 'float',
        'quantity' => 'float',
        'remaining_quantity' => 'float',
        'margin_used' => 'float',
        'leverage' => 'integer',
        'initial_sl' => 'float',
        'current_sl' => 'float',
        'tp1_price' => 'float',
        'tp2_price' => 'float',
        'be_locked' => 'boolean',
        'tp1_hit' => 'boolean',
        'tp2_hit' => 'boolean',
        'exit_price' => 'float',
        'realized_pnl' => 'float',
        'pnl_percent' => 'float',
        'fee_paid' => 'float',
        'highest_price' => 'float',
        'lowest_price' => 'float',
        'meta' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function isLong(): bool
    {
        return strtoupper($this->side) === 'LONG';
    }

    public function isShort(): bool
    {
        return strtoupper($this->side) === 'SHORT';
    }

    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }

    public function isClosed(): bool
    {
        return $this->status === 'CLOSED';
    }

    /**
     * Calculate unrealized PnL in USD based on current market price.
     */
    public function calculateUnrealizedPnl(float $currentPrice): float
    {
        if ($this->entry_price <= 0 || $this->remaining_quantity <= 0) {
            return 0.0;
        }

        if ($this->isLong()) {
            return ($currentPrice - $this->entry_price) * $this->remaining_quantity;
        }

        return ($this->entry_price - $currentPrice) * $this->remaining_quantity;
    }

    /**
     * Calculate Return on Equity (ROE %) based on margin used.
     */
    public function calculateRoe(float $currentPrice): float
    {
        if ($this->margin_used <= 0) {
            return 0.0;
        }

        $pnl = $this->calculateUnrealizedPnl($currentPrice);

        return round(($pnl / $this->margin_used) * 100, 2);
    }

    /**
     * Associated trading signal.
     */
    public function signal(): HasOne
    {
        return $this->hasOne(TradingSignal::class, 'trade_id');
    }
}
