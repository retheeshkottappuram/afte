<?php

namespace App\Services\Breakout;

use App\Models\BreakoutSetup;
use App\Models\BreakoutSetupEvent;
use App\Services\Notifications\TelegramGateway;
use App\Services\Strategy\Watchlist;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Telegram messages for EBD lifecycle events, through the existing TelegramGateway (hourly limit,
 * digest, retries). Each event row is sent at most once; later events reply to the first alert.
 */
class BreakoutAlerts
{
    public function __construct(protected TelegramGateway $gateway, protected EbdConfig $config) {}

    /**
     * Why an event is not sent, or null when it should be.
     */
    public function skipReason(BreakoutSetupEvent $event, int $nowMs): ?string
    {
        $payload = $event->payload;

        return match (true) {
            ! $event->notify => 'event is not announced',
            $this->config->get('mode') !== 'alerts' => 'EBD in shadow mode',
            ! Watchlist::alertsEnabled() => 'Telegram alerts switched off',
            $nowMs - (int) $payload['at_ms'] > $this->config->int('notifications.max_event_age_minutes') * 60_000 => 'event too old (missed cron runs)',
            default => null,
        };
    }

    /** Sends the event; returns the Telegram message id, or null when not sent. */
    public function dispatch(BreakoutSetup $setup, BreakoutSetupEvent $event, int $nowMs): ?int
    {
        if ($this->skipReason($event, $nowMs) !== null) {
            return null;
        }

        $payload = $event->payload;
        $direction = $payload['direction'] ?? $setup->direction ?? '';
        if (in_array($event->event, ['EARLY', 'CONFIRMED'], true) && $setup->telegram_message_id === null) {
            $minutes = $this->config->int('notifications.cooldown_minutes');
            if ($minutes > 0 && ! Cache::add("ebd:cooldown:{$setup->symbol}:{$direction}:{$event->event}", true, now()->addMinutes($minutes))) {
                return null;
            }
        }

        $html = $this->format($setup->toEngine(), $payload);
        $replyTo = $setup->telegram_message_id !== null ? (int) $setup->telegram_message_id : null;

        return $this->gateway->send($html, $replyTo, $event->event === 'EARLY');
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $e
     */
    public function format(array $setup, array $e): string
    {
        $symbol = $setup['symbol'];
        $dir = $e['direction'] ?? $setup['direction'] ?? '';
        $long = $dir === 'LONG';
        $p = fn (?float $v): string => self::price($v);
        $when = $this->when((int) $e['at_ms']);
        $boundary = $long ? $setup['resistance'] : $setup['support'];

        $lines = match ($e['type']) {
            'EARLY' => [
                "⚡ <b>EARLY BREAKOUT WATCH — {$dir}</b>",
                "<b>{$symbol}</b> · {$this->config->get('exchange')} {$this->config->get('market_type')}",
                "Detected: {$when}".($e['detection_mode'] === 'candle_close' ? ' (seen at the 5m close)' : ''),
                "Price: {$p($e['price'])}",
                "Range: {$p($setup['support'])} – {$p($setup['resistance'])} · trigger {$p($long ? $setup['long_trigger'] : $setup['short_trigger'])}",
                sprintf('Extension: %.2f ATR · volume pace %s', $e['extension_atr'], $e['pace'] === null ? 'n/a' : number_format($e['pace'], 2).'x'),
                sprintf('Early score: %d/100 (%s, provisional)', $e['score']['score'], $e['score']['bucket']),
                'Confirmation: pending the 5m close',
                $this->levelsLine($e['levels']),
                '⚠️ <i>Provisional: breakout NOT confirmed yet. Not a trade instruction.</i>',
            ],
            'CONFIRMED' => [
                ($e['entry_valid'] ? '✅' : 'ℹ️')." <b>CONFIRMED BREAKOUT — {$dir}</b>",
                "<b>{$symbol}</b> · 5m candle closed {$when}",
                "Close: {$p($e['price'])} · broke {$p($boundary)}",
                sprintf('RVOL %.2fx · close location %.2f · body %.2f ATR · RSI %.1f',
                    $e['metrics']['rvol'] ?? 0, $e['metrics']['close_location'] ?? 0, $e['metrics']['body_atr'] ?? 0, $e['metrics']['rsi'] ?? 0),
                sprintf('Score: %d/100 (%s)%s', $e['score']['score'], $e['score']['bucket'], isset($e['early_score']) ? ' · early '.$e['early_score'] : ''),
                $e['levels']['ok'] ? $this->levelsLine($e['levels']) : null,
                $e['why'] !== null ? "⚠️ {$e['why']}" : null,
                sprintf('Costs assumed: fees + slippage + %.1f bps spread. Alert only, no order placed.', $e['levels']['costs']['spread_bps'] ?? 0),
            ],
            'RETEST' => [
                "🔁 <b>RETEST CONFIRMED — {$dir}</b>",
                "<b>{$symbol}</b> · {$when}",
                "Held {$p($boundary)} and closed {$p($e['price'])}",
                $e['levels']['ok'] ? $this->levelsLine($e['levels']) : null,
                $e['why'] !== null ? "⚠️ {$e['why']}" : null,
            ],
            'FAILED' => [
                "❌ <b>FAILED BREAKOUT — {$dir}</b>",
                "<b>{$symbol}</b> · {$when}",
                "{$e['reason']} (price {$p($e['price'])}). Setup cancelled.",
            ],
            'EXPIRED' => [
                "⏳ <b>BREAKOUT EXPIRED — {$dir}</b>",
                "<b>{$symbol}</b> · {$when}",
                ucfirst((string) $e['reason']).'. Setup cancelled.',
            ],
            'EXTENDED' => [
                "🏃 <b>BREAKOUT IN PROGRESS — {$dir}</b>",
                "<b>{$symbol}</b> · {$when}",
                sprintf('Price %s is %.2f ATR past %s. Too extended: no new entry, do not chase.', $p($e['price']), $e['extension_atr'], $p($boundary)),
            ],
            default => ["{$symbol}: {$e['type']}"],
        };

        $note = in_array($e['type'], ['EARLY', 'CONFIRMED', 'RETEST'], true) ? (string) $this->config->get('notifications.backtest_note', '') : '';

        return implode("\n", array_filter($lines, fn (?string $l): bool => $l !== null && $l !== ''))
            .($note !== '' ? "\n📉 <i>{$note}</i>" : '')
            ."\n<i>EBD v1 · setup {$setup['setup_key']}</i>";
    }

    protected function levelsLine(array $levels): string
    {
        if (! $levels['ok']) {
            return 'Levels: no valid stop ('.$levels['reason'].')';
        }
        $p = fn (?float $v): string => self::price($v);

        return sprintf("Entry %s · SL %s (%.2f%%)\nTP1 %s · TP2 %s · net R:R %.2f (TP1, after costs)",
            $p($levels['entry']), $p($levels['sl']), $levels['sl_pct'], $p($levels['tp1']), $p($levels['tp2']), $levels['net_rr_tp1']);
    }

    protected function when(int $ms): string
    {
        $utc = Carbon::createFromTimestampMsUTC($ms);
        $tz = (string) $this->config->get('notifications.display_timezone', 'UTC');

        return $utc->format('H:i:s').' UTC'.($tz === 'UTC' ? '' : ' / '.$utc->copy()->setTimezone($tz)->format('H:i').' '.$utc->copy()->setTimezone($tz)->format('T'));
    }

    /** Enough decimals for sub-cent coins: about 6 significant digits. */
    public static function price(?float $v): string
    {
        if ($v === null) {
            return 'n/a';
        }
        $decimals = $v > 0 ? (int) max(2, min(12, 5 - floor(log10($v)))) : 2;

        return number_format($v, $decimals, '.', '');
    }
}
