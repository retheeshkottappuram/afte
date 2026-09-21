<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $level
 * @property string $category
 * @property string $message
 * @property array|null $context
 */
class SystemLog extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'level',
        'category',
        'message',
        'context',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'context' => 'array',
    ];

    /**
     * Quick static logger helper.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function write(string $category, string $message, string $level = 'info', ?array $context = null): self
    {
        return static::create([
            'category' => $category,
            'message' => $message,
            'level' => $level,
            'context' => $context,
        ]);
    }
}
