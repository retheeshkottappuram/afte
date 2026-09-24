<?php

namespace App\Services\Crypto;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramNotifier
{
    public function __construct(
        protected string $botToken,
        protected string $chatId
    ) {}

    /**
     * Check if the bot token and chat ID are configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->botToken) && ! empty($this->chatId);
    }

    /**
     * Send an HTML formatted message via Telegram Bot API with automatic safe retry and latency tracking.
     */
    public function send(string $message): bool
    {
        $result = $this->sendWithDetails($message);

        return $result['success'];
    }

    /**
     * Send message with detailed execution metrics, millisecond latency, and error reporting.
     *
     * @return array{
     *     success: bool,
     *     latency_ms: int,
     *     message_id: ?int,
     *     attempts: int,
     *     error: ?string
     * }
     */
    public function sendWithDetails(string $message, int $maxRetries = 3): array
    {
        if (empty($this->botToken) || empty($this->chatId)) {
            Log::warning('TelegramNotifier: Bot token or chat ID is not configured.');

            return [
                'success' => false,
                'latency_ms' => 0,
                'message_id' => null,
                'attempts' => 0,
                'error' => 'Bot token or Chat ID not configured',
            ];
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $payload = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ];

        $attempt = 0;
        $startTime = microtime(true);
        $lastError = null;

        while ($attempt < $maxRetries) {
            $attempt++;
            $attemptStart = microtime(true);

            try {
                $response = Http::timeout(8)
                    ->asJson()
                    ->post($url, $payload);

                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $json = $response->json();
                    $messageId = $json['result']['message_id'] ?? null;

                    Log::info("TelegramNotifier: Alert successfully dispatched to Telegram in {$elapsedMs}ms (Attempt {$attempt}, Message ID: {$messageId})");

                    return [
                        'success' => true,
                        'latency_ms' => $elapsedMs,
                        'message_id' => $messageId,
                        'attempts' => $attempt,
                        'error' => null,
                    ];
                }

                $status = $response->status();
                $body = $response->body();
                $lastError = "HTTP {$status} - {$body}";

                // Handle rate limiting specifically
                if ($status === 429) {
                    $retryAfter = (int) ($response->json('parameters.retry_after') ?? 1);
                    Log::warning("TelegramNotifier: Rate limited (429). Waiting {$retryAfter}s before retry #{$attempt}...");
                    sleep($retryAfter);

                    continue;
                }

                Log::warning("TelegramNotifier: Dispatch attempt #{$attempt} failed with status {$status}: {$body}");
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning("TelegramNotifier: Exception on attempt #{$attempt}: {$lastError}");
            }

            // Exponential backoff before next attempt
            if ($attempt < $maxRetries) {
                usleep($attempt * 400000); // 400ms, 800ms
            }
        }

        $totalElapsedMs = (int) round((microtime(true) - $startTime) * 1000);
        Log::error("TelegramNotifier: All {$maxRetries} delivery attempts failed in {$totalElapsedMs}ms. Last error: {$lastError}");

        return [
            'success' => false,
            'latency_ms' => $totalElapsedMs,
            'message_id' => null,
            'attempts' => $attempt,
            'error' => $lastError,
        ];
    }
}
