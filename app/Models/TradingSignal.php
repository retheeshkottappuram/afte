<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $symbol
 * @property string $direction
 * @property int $score
 * @property string $grade
 * @property float $price
 * @property string $timeframe
 * @property array|null $indicators
 * @property string $ai_status
 * @property int $ai_confidence
 * @property string|null $ai_regime
 * @property string|null $ai_reason
 * @property bool $executed
 * @property int|null $trade_id
 */
class TradingSignal extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'symbol',
        'direction',
        'score',
        'grade',
        'price',
        'timeframe',
        'indicators',
        'ai_status',
        'ai_confidence',
        'ai_regime',
        'ai_reason',
        'executed',
        'trade_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'score' => 'integer',
        'price' => 'float',
        'indicators' => 'array',
        'ai_confidence' => 'integer',
        'executed' => 'boolean',
        'trade_id' => 'integer',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    public function isApproved(): bool
    {
        return $this->ai_status === 'APPROVED';
    }

    public function isLong(): bool
    {
        return strtoupper($this->direction) === 'LONG';
    }

    public function isShort(): bool
    {
        return strtoupper($this->direction) === 'SHORT';
    }
}
