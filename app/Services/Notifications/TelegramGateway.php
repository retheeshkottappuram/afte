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
        $this->recordResult($result);

        return $result['success'] ? (int) $result['message_id'] : null;
    }

    /**
     * Why messages are not arriving: not configured, switched off, or the last Telegram error.
     *
     * @return array{enabled: bool, problem: ?string, last_error: ?string, last_error_at: ?string, last_ok_at: ?string}
     */
    public function health(): array
    {
        $problem = match (true) {
            ! (bool) config('trading.telegram.enabled', false) => 'Telegram is OFF: set TELEGRAM_NOTIFICATIONS_ENABLED=true in .env, then run php artisan config:cache.',
            ! filled(config('trading.telegram.bot_token')) || ! filled(config('trading.telegram.chat_id')) => 'Telegram bot token or chat id is missing in .env.',
            default => null,
        };
        $error = (array) Cache::get('telegram:last_error', []);
        $lastOk = Cache::get('telegram:last_ok_at');
        $errorAt = $error['at'] ?? null;

        // An error older than the last successful message is history, not a current problem.
        $current = $errorAt !== null && ($lastOk === null || $errorAt > $lastOk);

        return [
            'enabled' => $problem === null,
            'problem' => $problem ?? ($current ? 'Telegram rejected the last message: '.($error['error'] ?? 'unknown error') : null),
            'last_error' => $error['error'] ?? null,
            'last_error_at' => $errorAt,
            'last_ok_at' => $lastOk,
        ];
    }

    /**
     * @param  array<string, mixed>  $result  From TelegramNotifier::sendWithDetails()
     */
    protected function recordResult(array $result): void
    {
        if ($result['success'] ?? false) {
            Cache::put('telegram:last_ok_at', now()->toIso8601String(), now()->addDays(30));

            return;
        }

        Cache::put('telegram:last_error', ['error' => mb_substr((string) ($result['error'] ?? 'unknown error'), 0, 300), 'at' => now()->toIso8601String()], now()->addDays(30));
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
