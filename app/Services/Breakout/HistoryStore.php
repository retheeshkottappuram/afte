<?php

namespace App\Services\Breakout;

use App\Services\Crypto\BinanceClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Historical Binance futures klines and funding rates for the EBD backtest, downloaded ONE request at a
 * time (never in parallel), throttled below Binance's weight limit, and cached on disk per month
 * (storage/app/ebd-history) so a re-run does not download again.
 */
class HistoryStore
{
    protected const BASE = 'https://fapi.binance.com';

    protected float $lastRequestAt = 0;

    /** @var callable|null */
    protected $log = null;

    public function __construct(protected ?string $dir = null, protected int $maxWeightPerMinute = 1000)
    {
        $this->dir ??= storage_path('app/ebd-history');
    }

    public function onLog(callable $log): self
    {
        $this->log = $log;

        return $this;
    }

    /** Closed candles from $startMs to $endMs (UTC ms), oldest first. */
    public function candles(string $symbol, string $interval, int $startMs, int $endMs): Candles
    {
        $rows = [];
        foreach ($this->months($startMs, $endMs) as [$from, $monthEnd, $key]) {
            // A month cut by $endMs is cached under its own name, so it never shadows the full month.
            $to = min($monthEnd, $endMs);
            $file = "{$this->dir}/{$symbol}/{$interval}-{$key}".($to < $monthEnd ? '-to'.$to : '').'.json.gz';
            $complete = $to < (int) (microtime(true) * 1000) - 600_000;
            if (is_file($file)) {
                $chunk = json_decode((string) gzdecode((string) file_get_contents($file)), true);
            } else {
                $chunk = $this->download($symbol, $interval, $from, $to);
                if ($complete) {
                    @mkdir(dirname($file), 0775, true);
                    file_put_contents($file, gzencode((string) json_encode($chunk), 6));
                }
            }
            foreach ($chunk as $row) {
                if ($row[0] >= $startMs && $row[6] <= $endMs) {
                    $rows[$row[0]] = $row;
                }
            }
        }
        ksort($rows);

        return Candles::fromKlines(app(BinanceClient::class)->parseRawKlines(array_values($rows)), $interval);
    }

    /**
     * Funding rate history: [fundingTime ms => rate], ascending.
     *
     * @return array<int, float>
     */
    public function funding(string $symbol, int $startMs, int $endMs): array
    {
        $file = "{$this->dir}/{$symbol}/funding-".gmdate('Ymd', intdiv($startMs, 1000)).'-'.gmdate('Ymd', intdiv($endMs, 1000)).'.json.gz';
        if (is_file($file)) {
            return array_map('floatval', json_decode((string) gzdecode((string) file_get_contents($file)), true));
        }

        $out = [];
        $cursor = $startMs;
        while ($cursor < $endMs) {
            $page = $this->get('/fapi/v1/fundingRate', ['symbol' => $symbol, 'startTime' => $cursor, 'endTime' => $endMs, 'limit' => 1000], 1);
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $out[(int) $row['fundingTime']] = (float) $row['fundingRate'];
            }
            $last = (int) end($page)['fundingTime'];
            if (count($page) < 1000 || $last <= $cursor) {
                break;
            }
            $cursor = $last + 1;
        }
        ksort($out);
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, gzencode((string) json_encode($out), 6));

        return $out;
    }

    /** @return array<int, array<int, mixed>> raw kline rows */
    protected function download(string $symbol, string $interval, int $from, int $to): array
    {
        $rows = [];
        $cursor = $from;
        while ($cursor < $to) {
            $page = $this->get('/fapi/v1/klines', ['symbol' => $symbol, 'interval' => $interval, 'startTime' => $cursor, 'endTime' => $to, 'limit' => 1500], 10);
            if ($page === []) {
                break;
            }
            foreach ($page as $row) {
                $rows[] = $row;
            }
            $last = (int) end($page)[0];
            if (count($page) < 1500 || $last <= $cursor) {
                break;
            }
            $cursor = $last + 1;
        }

        return $rows;
    }

    /** One throttled, sequential GET. Stops on 418/429 instead of retrying into a ban. */
    protected function get(string $path, array $query, int $weight): array
    {
        $gap = 60 / max(1, $this->maxWeightPerMinute / $weight);
        $wait = $this->lastRequestAt + $gap - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        for ($attempt = 1; ; $attempt++) {
            $this->lastRequestAt = microtime(true);
            $response = Http::timeout(30)->acceptJson()->get(self::BASE.$path, $query);
            if (in_array($response->status(), [418, 429], true)) {
                throw new RuntimeException("Binance HTTP {$response->status()} (rate limit / ban). Stopped; retry after ".($response->header('Retry-After') ?: '?').'s.');
            }
            $used = (int) $response->header('X-MBX-USED-WEIGHT-1M');
            if ($used > 1800) {
                $this->say("  weight {$used}/2400: pausing 30s");
                sleep(30);
            }
            if ($response->successful()) {
                return (array) $response->json();
            }
            if ($attempt >= 3 || $response->status() === 400) {
                throw new RuntimeException("Binance {$path} HTTP {$response->status()}: ".mb_substr($response->body(), 0, 200));
            }
            sleep(2 * $attempt);
        }
    }

    /** @return array<int, array{0: int, 1: int, 2: string}> [from, to, YYYYMM] per calendar month */
    protected function months(int $startMs, int $endMs): array
    {
        $out = [];
        $t = gmmktime(0, 0, 0, (int) gmdate('n', intdiv($startMs, 1000)), 1, (int) gmdate('Y', intdiv($startMs, 1000))) * 1000;
        while ($t < $endMs) {
            $next = gmmktime(0, 0, 0, (int) gmdate('n', intdiv($t, 1000)) + 1, 1, (int) gmdate('Y', intdiv($t, 1000))) * 1000;
            $out[] = [$t, $next - 1, gmdate('Ym', intdiv($t, 1000))];
            $t = $next;
        }

        return $out;
    }

    protected function say(string $line): void
    {
        if ($this->log !== null) {
            ($this->log)($line);
        }
    }
}
