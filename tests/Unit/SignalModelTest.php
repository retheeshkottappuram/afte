<?php

namespace Tests\Unit;

use App\Services\Strategy\SignalModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Samples whose label depends on volume and trend strength (plus 5% label noise).
     *
     * @return array<int, array{features: array<string, float|int|string>, label: int}>
     */
    protected function separableSamples(int $count, bool $random = false): array
    {
        mt_srand(42);
        $samples = [];

        for ($i = 0; $i < $count; $i++) {
            $volume = mt_rand(50, 400) / 100;
            $adx = mt_rand(10, 45);
            $label = ($volume > 1.8 && $adx > 22) ? 1 : 0;
            if ($random) {
                $label = mt_rand(0, 1);
            } elseif (mt_rand(1, 100) <= 5) {
                $label = 1 - $label;
            }

            $samples[] = ['features' => [
                'setup' => $i % 2 ? 'TREND_PULLBACK' : 'SQUEEZE_BREAKOUT',
                'side' => $i % 3 ? 1 : -1,
                'adx_4h' => $adx,
                'adx_1h' => $adx,
                'rsi' => 55,
                'volume_ratio' => $volume,
                'atr_pct' => 1.0,
                'ema21_dist_atr' => 0.5,
                'bbw_pct' => 0.3,
                'rs_vs_btc' => 0.0,
                'btc_aligned' => 0,
                'funding_adverse' => 0.0,
                'hour_sin' => 0.0,
                'hour_cos' => 1.0,
                'sl_pct' => 1.0,
            ], 'label' => $label];
        }

        return $samples;
    }

    public function test_untrained_model_returns_no_prediction(): void
    {
        $this->assertNull((new SignalModel)->predict(['setup' => 'TREND_PULLBACK']));
    }

    public function test_refuses_to_train_on_too_few_samples(): void
    {
        $result = (new SignalModel)->train($this->separableSamples(50));

        $this->assertFalse($result['trained']);
    }

    public function test_learns_a_separable_pattern_and_explains_predictions(): void
    {
        $model = new SignalModel;
        $result = $model->train($this->separableSamples(600));

        $this->assertTrue($result['activated'], $result['message']);
        $this->assertGreaterThan(0.9, $result['metrics']['auc']);

        $strongFeatures = array_merge($this->separableSamples(1)[0]['features'], ['volume_ratio' => 3.5, 'adx_4h' => 40, 'adx_1h' => 40]);
        $weakFeatures = array_merge($strongFeatures, ['volume_ratio' => 0.6, 'adx_4h' => 12, 'adx_1h' => 12]);

        $this->assertGreaterThan($model->predict($weakFeatures)['probability'], $model->predict($strongFeatures)['probability']);
        $this->assertNotEmpty($model->predict($strongFeatures)['reasons']);
        $this->assertTrue((new SignalModel)->isTrained(), 'The activated model is persisted for other processes');
    }

    public function test_a_worse_model_does_not_replace_the_active_one(): void
    {
        (new SignalModel)->train($this->separableSamples(600));
        $activeAuc = (new SignalModel)->metrics()['auc'];

        $result = (new SignalModel)->train($this->separableSamples(600, random: true));

        $this->assertFalse($result['activated']);
        $this->assertSame($activeAuc, (new SignalModel)->metrics()['auc']);
    }
}
