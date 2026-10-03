<?php

namespace App\Console\Commands;

use App\Models\CryptoSignal;
use App\Services\Strategy\SignalModel;
use Illuminate\Console\Command;

/**
 * Nightly retraining of the AI confidence model on resolved signals.
 * A new model only replaces the current one if it scores better on the holdout set.
 */
class TrainSignalModelCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ai:train-signal-model';

    /**
     * @var string
     */
    protected $description = 'Train the AI confidence model (probability a signal ends profitable after fees) on resolved signals';

    public function handle(SignalModel $model): int
    {
        $samples = CryptoSignal::query()
            ->whereNotNull('setup')
            ->whereNotNull('features')
            ->where('passed_filters', true)
            ->whereIn('outcome', ['TP1', 'TP2', 'SL', 'BE', 'EXPIRED'])
            ->orderBy('candle_close_time')
            ->get(['features', 'outcome', 'r_multiple'])
            ->map(fn (CryptoSignal $s): array => [
                'features' => (array) $s->features,
                'label' => (float) $s->r_multiple > 0 ? 1 : 0,
            ])
            ->all();

        $this->line('Training on '.count($samples).' resolved signals...');
        $result = $model->train($samples);

        $this->{$result['activated'] ? 'info' : 'warn'}($result['message']);

        if ($result['metrics'] !== []) {
            $m = $result['metrics'];
            $this->table(['Metric', 'Value'], [
                ['Holdout AUC', $m['auc']],
                ['Holdout accuracy', round($m['accuracy'] * 100, 1).'%'],
                ['Brier score', $m['brier']],
                ['Base rate (profitable)', round($m['base_rate'] * 100, 1).'%'],
                ['Train / holdout', $m['train_n'].' / '.$m['holdout_n']],
            ]);

            if ($m['calibration'] !== []) {
                $this->table(['Predicted bucket', 'Avg predicted', 'Actual', 'n'], array_map(fn (array $b): array => [$b['bucket'], $b['predicted'], $b['actual'], $b['n']], $m['calibration']));
            }
        }

        return self::SUCCESS;
    }
}
