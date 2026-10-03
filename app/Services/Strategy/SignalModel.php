<?php

namespace App\Services\Strategy;

use App\Models\Setting;

/**
 * "AI Confidence": a logistic-regression model, in pure PHP, estimating the probability
 * that a signal ends profitable after fees. Trained nightly on resolved signals
 * (backtest seeds + live outcomes); a new model is only activated if it beats the
 * current one on a time-ordered holdout set.
 */
class SignalModel
{
    public const SETTING_KEY = 'signal_model';

    public const MIN_TRAINING_SAMPLES = 100;

    /** A model must clearly beat chance (AUC 0.5) on unseen signals before it may influence trades. */
    public const MIN_ACTIVATION_AUC = 0.55;

    /** Human-readable names for the explanation of a prediction. */
    protected const FEATURE_LABELS = [
        'setup_SQUEEZE_BREAKOUT' => 'squeeze breakout setup',
        'setup_TREND_PULLBACK' => 'trend pullback setup',
        'setup_EMA_CROSS' => 'EMA cross setup',
        'setup_SWING_REVERSAL' => 'reversal setup',
        'side' => 'trade direction',
        'adx_4h' => '4h trend strength',
        'adx_1h' => '1h trend strength',
        'rsi_centered' => 'RSI momentum',
        'log_volume_ratio' => 'volume surge',
        'atr_pct' => 'volatility',
        'ema21_dist' => 'distance from EMA21',
        'bbw_pct' => 'volatility squeeze',
        'rs_vs_btc' => 'strength vs BTC',
        'btc_aligned' => 'BTC alignment',
        'funding_adverse' => 'crowded funding',
        'hour_sin' => 'time of day',
        'hour_cos' => 'time of day',
        'sl_pct' => 'stop distance',
    ];

    /** @var array{weights: array<string, float>, bias: float, means: array<string, float>, stds: array<string, float>, metrics: array<string, mixed>, trained_at: string, n: int}|null */
    protected ?array $model = null;

    protected bool $loaded = false;

    /**
     * Turn raw signal features into the model's numeric inputs.
     *
     * @param  array<string, float|int|string>  $features
     * @return array<string, float>
     */
    public static function vectorize(array $features): array
    {
        $vector = [];
        foreach (array_keys(StrategyEngine::SETUP_LABELS) as $setup) {
            $vector["setup_{$setup}"] = ($features['setup'] ?? '') === $setup ? 1.0 : 0.0;
        }

        $vector['side'] = (float) ($features['side'] ?? 0);
        $vector['adx_4h'] = (float) ($features['adx_4h'] ?? 0);
        $vector['adx_1h'] = (float) ($features['adx_1h'] ?? 0);
        $vector['rsi_centered'] = ((float) ($features['rsi'] ?? 50) - 50) * (float) ($features['side'] ?? 1);
        $vector['log_volume_ratio'] = log(max(0.05, (float) ($features['volume_ratio'] ?? 1)));
        $vector['atr_pct'] = (float) ($features['atr_pct'] ?? 0);
        $vector['ema21_dist'] = (float) ($features['ema21_dist_atr'] ?? 0);
        $vector['bbw_pct'] = (float) ($features['bbw_pct'] ?? 0.5);
        $vector['rs_vs_btc'] = (float) ($features['rs_vs_btc'] ?? 0);
        $vector['btc_aligned'] = (float) ($features['btc_aligned'] ?? 0);
        $vector['funding_adverse'] = (float) ($features['funding_adverse'] ?? 0);
        $vector['hour_sin'] = (float) ($features['hour_sin'] ?? 0);
        $vector['hour_cos'] = (float) ($features['hour_cos'] ?? 0);
        $vector['sl_pct'] = (float) ($features['sl_pct'] ?? 1);

        return $vector;
    }

    public function isTrained(): bool
    {
        return $this->active() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metrics(): ?array
    {
        $model = $this->active();

        return $model === null ? null : array_merge($model['metrics'], ['trained_at' => $model['trained_at'], 'n' => $model['n']]);
    }

    /**
     * Probability the trade ends profitable after fees, with the top contributing factors.
     *
     * @param  array<string, float|int|string>  $features
     * @return array{probability: float, reasons: array<int, string>}|null
     */
    public function predict(array $features): ?array
    {
        $model = $this->active();
        if ($model === null) {
            return null;
        }

        $vector = self::vectorize($features);
        $z = $model['bias'];
        $contributions = [];

        foreach ($model['weights'] as $name => $weight) {
            $std = $model['stds'][$name] ?: 1.0;
            $x = (($vector[$name] ?? 0.0) - $model['means'][$name]) / $std;
            $contributions[$name] = $weight * $x;
            $z += $contributions[$name];
        }

        uasort($contributions, fn (float $a, float $b): int => abs($b) <=> abs($a));
        $reasons = [];
        foreach (array_slice($contributions, 0, 3, true) as $name => $value) {
            if (abs($value) < 0.02) {
                continue;
            }
            $reasons[] = (self::FEATURE_LABELS[$name] ?? $name).' '.($value > 0 ? '+' : '−');
        }

        return ['probability' => round(self::sigmoid($z), 4), 'reasons' => $reasons];
    }

    /**
     * Train on samples ordered oldest-first; the newest 20% are held out for validation.
     *
     * @param  array<int, array{features: array<string, float|int|string>, label: int}>  $samples
     * @return array{trained: bool, activated: bool, message: string, metrics: array<string, mixed>}
     */
    public function train(array $samples, int $epochs = 400, float $learningRate = 0.1, float $l2 = 0.01): array
    {
        $n = count($samples);
        if ($n < self::MIN_TRAINING_SAMPLES) {
            return ['trained' => false, 'activated' => false, 'message' => 'Need at least '.self::MIN_TRAINING_SAMPLES." resolved signals, have {$n}.", 'metrics' => []];
        }

        $vectors = array_map(fn (array $s): array => self::vectorize($s['features']), $samples);
        $labels = array_map(fn (array $s): int => (int) $s['label'], $samples);
        $split = (int) floor($n * 0.8);
        $names = array_keys($vectors[0]);

        // Standardize with training-set statistics only
        $means = [];
        $stds = [];
        foreach ($names as $name) {
            $column = array_map(fn (array $v): float => $v[$name], array_slice($vectors, 0, $split));
            $mean = array_sum($column) / count($column);
            $variance = array_sum(array_map(fn (float $x): float => ($x - $mean) ** 2, $column)) / count($column);
            $means[$name] = $mean;
            $stds[$name] = sqrt($variance) > 1e-9 ? sqrt($variance) : 1.0;
        }

        $standardize = function (array $v) use ($names, $means, $stds): array {
            $out = [];
            foreach ($names as $name) {
                $out[$name] = ($v[$name] - $means[$name]) / $stds[$name];
            }

            return $out;
        };

        $train = array_map($standardize, array_slice($vectors, 0, $split));
        $trainLabels = array_slice($labels, 0, $split);
        $holdout = array_map($standardize, array_slice($vectors, $split));
        $holdoutLabels = array_slice($labels, $split);

        $weights = array_fill_keys($names, 0.0);
        $positiveRate = array_sum($trainLabels) / max(1, count($trainLabels));
        $bias = log(max(0.01, $positiveRate) / max(0.01, 1 - $positiveRate));
        $m = count($train);

        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            $gradW = array_fill_keys($names, 0.0);
            $gradB = 0.0;

            foreach ($train as $idx => $x) {
                $error = self::sigmoid($bias + self::dot($weights, $x)) - $trainLabels[$idx];
                foreach ($names as $name) {
                    $gradW[$name] += $error * $x[$name];
                }
                $gradB += $error;
            }

            foreach ($names as $name) {
                $weights[$name] -= $learningRate * ($gradW[$name] / $m + $l2 * $weights[$name]);
            }
            $bias -= $learningRate * $gradB / $m;
        }

        $predictions = array_map(fn (array $x): float => self::sigmoid($bias + self::dot($weights, $x)), $holdout);
        $metrics = self::evaluate($predictions, $holdoutLabels);
        $metrics['holdout_n'] = count($holdoutLabels);
        $metrics['train_n'] = $m;

        $candidate = [
            'weights' => $weights,
            'bias' => $bias,
            'means' => $means,
            'stds' => $stds,
            'metrics' => $metrics,
            'trained_at' => now()->toIso8601String(),
            'n' => $n,
        ];

        // Compare against the current model on the same holdout.
        $current = $this->active();
        if ($current !== null) {
            $currentPredictions = [];
            foreach (array_slice($samples, $split) as $sample) {
                $currentPredictions[] = $this->predictWith($current, $sample['features']);
            }
            $currentMetrics = self::evaluate($currentPredictions, $holdoutLabels);

            if (($metrics['auc'] ?? 0) <= ($currentMetrics['auc'] ?? 0)) {
                return ['trained' => true, 'activated' => false, 'message' => sprintf('New model AUC %.3f did not beat current %.3f. Kept current model.', $metrics['auc'], $currentMetrics['auc']), 'metrics' => $metrics];
            }
        }

        if (($metrics['auc'] ?? 0) < self::MIN_ACTIVATION_AUC) {
            return ['trained' => true, 'activated' => false, 'message' => sprintf('Model AUC %.3f is too close to chance (needs %.2f). Not activated.', $metrics['auc'], self::MIN_ACTIVATION_AUC), 'metrics' => $metrics];
        }

        Setting::putValue(self::SETTING_KEY, $candidate);
        $this->model = $candidate;
        $this->loaded = true;

        return ['trained' => true, 'activated' => true, 'message' => sprintf('Model activated: holdout AUC %.3f, accuracy %.1f%%, Brier %.3f.', $metrics['auc'], $metrics['accuracy'] * 100, $metrics['brier']), 'metrics' => $metrics];
    }

    /**
     * @param  array<int, float>  $predictions
     * @param  array<int, int>  $labels
     * @return array{auc: float, accuracy: float, brier: float, base_rate: float, calibration: array<int, array{bucket: string, predicted: float, actual: float, n: int}>}
     */
    public static function evaluate(array $predictions, array $labels): array
    {
        $n = count($labels);
        if ($n === 0) {
            return ['auc' => 0.5, 'accuracy' => 0.0, 'brier' => 0.25, 'base_rate' => 0.0, 'calibration' => []];
        }

        $correct = 0;
        $brier = 0.0;
        foreach ($predictions as $k => $p) {
            $correct += (int) (($p >= 0.5 ? 1 : 0) === $labels[$k]);
            $brier += ($p - $labels[$k]) ** 2;
        }

        $calibration = [];
        foreach ([[0, 0.4], [0.4, 0.5], [0.5, 0.6], [0.6, 1.01]] as [$lo, $hi]) {
            $inBucket = array_keys(array_filter($predictions, fn (float $p): bool => $p >= $lo && $p < $hi));
            if ($inBucket === []) {
                continue;
            }
            $calibration[] = [
                'bucket' => sprintf('%d-%d%%', $lo * 100, min(100, $hi * 100)),
                'predicted' => round(array_sum(array_map(fn (int $k): float => $predictions[$k], $inBucket)) / count($inBucket), 3),
                'actual' => round(array_sum(array_map(fn (int $k): int => $labels[$k], $inBucket)) / count($inBucket), 3),
                'n' => count($inBucket),
            ];
        }

        return [
            'auc' => round(self::auc($predictions, $labels), 4),
            'accuracy' => round($correct / $n, 4),
            'brier' => round($brier / $n, 4),
            'base_rate' => round(array_sum($labels) / $n, 4),
            'calibration' => $calibration,
        ];
    }

    /**
     * @param  array<int, float>  $scores
     * @param  array<int, int>  $labels
     */
    protected static function auc(array $scores, array $labels): float
    {
        $positives = array_keys(array_filter($labels, fn (int $l): bool => $l === 1));
        $negatives = array_keys(array_filter($labels, fn (int $l): bool => $l === 0));
        if ($positives === [] || $negatives === []) {
            return 0.5;
        }

        $wins = 0.0;
        foreach ($positives as $p) {
            foreach ($negatives as $q) {
                $wins += $scores[$p] > $scores[$q] ? 1.0 : ($scores[$p] == $scores[$q] ? 0.5 : 0.0);
            }
        }

        return $wins / (count($positives) * count($negatives));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function active(): ?array
    {
        if (! $this->loaded) {
            $stored = Setting::getValue(self::SETTING_KEY);
            $this->model = is_array($stored) && isset($stored['weights']) ? $stored : null;
            $this->loaded = true;
        }

        return $this->model;
    }

    /**
     * @param  array<string, mixed>  $model
     * @param  array<string, float|int|string>  $features
     */
    protected function predictWith(array $model, array $features): float
    {
        $vector = self::vectorize($features);
        $z = (float) $model['bias'];
        foreach ($model['weights'] as $name => $weight) {
            $std = $model['stds'][$name] ?: 1.0;
            $z += $weight * ((($vector[$name] ?? 0.0) - $model['means'][$name]) / $std);
        }

        return self::sigmoid($z);
    }

    /**
     * @param  array<string, float>  $weights
     * @param  array<string, float>  $x
     */
    protected static function dot(array $weights, array $x): float
    {
        $sum = 0.0;
        foreach ($weights as $name => $w) {
            $sum += $w * $x[$name];
        }

        return $sum;
    }

    protected static function sigmoid(float $z): float
    {
        return 1.0 / (1.0 + exp(-max(-35.0, min(35.0, $z))));
    }
}
