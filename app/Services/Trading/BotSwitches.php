<?php

namespace App\Services\Trading;

use App\Models\Setting;
use InvalidArgumentException;

/**
 * Dashboard on/off switches for each auto-trading bot (new entries only; open trades keep being managed).
 * Stored in the settings table so the cron engine and the web dashboard agree. Until a switch is used,
 * each bot follows its config default.
 */
class BotSwitches
{
    public const KEY = 'bot_switches';

    public const BOTS = ['swing', 'breakout', 'lab', 'dayrange'];

    public static function on(string $bot): bool
    {
        $stored = (array) Setting::getValue(self::KEY, []);

        return array_key_exists($bot, $stored) ? (bool) $stored[$bot] : self::default($bot);
    }

    public static function set(string $bot, bool $enabled): void
    {
        if (! in_array($bot, self::BOTS, true)) {
            throw new InvalidArgumentException("Unknown bot {$bot}.");
        }
        $stored = (array) Setting::getValue(self::KEY, []);
        $stored[$bot] = $enabled;
        Setting::putValue(self::KEY, $stored);
    }

    /**
     * @return array<string, bool>
     */
    public static function all(): array
    {
        $stored = (array) Setting::getValue(self::KEY, []);

        return collect(self::BOTS)->mapWithKeys(fn (string $bot): array => [$bot => array_key_exists($bot, $stored) ? (bool) $stored[$bot] : self::default($bot)])->all();
    }

    protected static function default(string $bot): bool
    {
        return match ($bot) {
            'swing' => (bool) config('trading.swing_sar.enabled', false),
            'lab' => (bool) config('trading.lab.enabled', false),
            'dayrange' => (bool) config('trading.day_range.enabled', false),
            'breakout' => true,
            default => false,
        };
    }
}
