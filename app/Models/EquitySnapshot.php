<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $mode
 * @property float $balance
 * @property float $equity
 * @property int $open_positions
 */
class EquitySnapshot extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'mode',
        'balance',
        'equity',
        'open_positions',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'balance' => 'float',
        'equity' => 'float',
        'open_positions' => 'integer',
    ];
}
