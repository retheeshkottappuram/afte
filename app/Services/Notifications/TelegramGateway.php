<?php

namespace App\Services\Notifications;

use App\Services\Crypto\TelegramNotifier as TelegramSender;
use Illuminate\Support\Facades\Cache;

/**
 * Single outbound path for every Telegram message (HTML, retries, threading).
 * Normal messages are rate-limited per hour; overflow is batched into a digest.
 * Priority messages (risk and health alerts) are never rate-limited.
 */
class TelegramGateway
{
    protected const DIGEST_KEY = 'telegram:digest';

    public function isEnabled(): bool
    {
        return (bool) config('trading.telegram.enabled', false)
            && filled(config('trading.telegram.bot_token'))
            && filled(config('trading.telegram.chat_id'));
    }

    /**
     * Send an HTML message. Returns the Telegram message id, or null when not sent.
     */
    public function send(string $html, ?int $replyTo = null, bool $priority = false): ?int
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! $priority && ! $this->withinRateLimit()) {
            $digest = (array) Cache::get(self::DIGEST_KEY, []);
            $digest[] = strip_tags(strtok($html, "\n") ?: $html);
            Cache::put(self::DIGEST_KEY, array_slice($digest, -50), now()->addDay());

            return null;
        }

        $this->flushDigest();

        $result = $this->sender()->sendWithDetails($html, 3, $replyTo);

        return $result['success'] ? (int) $result['message_id'] : null;
    }

    /**
     * Send queued overflow lines as one digest message when the hourly budget allows.
     */
    public function flushDigest(): void
    {
        $digest = (array) Cache::get(self::DIGEST_KEY, []);
        if ($digest === [] || ! $this->withinRateLimit()) {
            return;
        }

        Cache::forget(self::DIGEST_KEY);
        $lines = array_map(fn (string $line): string => '• '.e($line), $digest);
        $this->sender()->sendWithDetails("🗂 <b>Digest</b> (rate limit reached earlier)\n\n".implode("\n", $lines));
    }

    protected function withinRateLimit(): bool
    {
        $key = 'telegram:sent:'.now()->format('YmdH');
        $max = (int) config('trading.telegram.max_messages_per_hour', 20);
        Cache::add($key, 0, now()->addHours(2));

        return Cache::increment($key) <= $max;
    }

    protected function sender(): TelegramSender
    {
        return new TelegramSender((string) config('trading.telegram.bot_token'), (string) config('trading.telegram.chat_id'));
    }
}
