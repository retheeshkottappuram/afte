<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lifecycle event of an EBD setup. Unique per (setup, event), which is what makes alerts idempotent.
 *
 * @property int $id
 * @property int $breakout_setup_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property bool $notify
 */
class BreakoutSetupEvent extends Model
{
    /** @var array<int, string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'notify' => 'boolean',
            'notified_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function setup(): BelongsTo
    {
        return $this->belongsTo(BreakoutSetup::class, 'breakout_setup_id');
    }
}
