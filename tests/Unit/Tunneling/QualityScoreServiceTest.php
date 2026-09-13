<?php

namespace Tests\Unit\Tunneling;

use App\Services\Tunneling\QualityScoreService;
use Tests\TestCase;

class QualityScoreServiceTest extends TestCase
{
    protected QualityScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QualityScoreService;
    }

    public function test_perfect_metrics_score_near_100(): void
    {
        $score = $this->service->score(latencyMs: 5, jitterMs: 1, lossPct: 0, throughputRatio: 1.0);

        $this->assertGreaterThan(95, $score);
    }

    public function test_total_loss_tanks_the_score(): void
    {
        $bad = $this->service->score(latencyMs: 50, jitterMs: 5, lossPct: 100, throughputRatio: 1.0);
        $good = $this->service->score(latencyMs: 50, jitterMs: 5, lossPct: 0, throughputRatio: 1.0);

        $this->assertLessThan($good - 30, $bad);
    }

    public function test_throttled_throughput_lowers_score(): void
    {
        $throttled = $this->service->score(latencyMs: 50, jitterMs: 5, lossPct: 0, throughputRatio: 0.1);
        $healthy = $this->service->score(latencyMs: 50, jitterMs: 5, lossPct: 0, throughputRatio: 1.0);

        $this->assertLessThan($healthy, $throttled);
    }

    public function test_missing_components_are_excluded_from_weighting(): void
    {
        $score = $this->service->score(latencyMs: null, jitterMs: null, lossPct: 0, throughputRatio: null);

        $this->assertSame(100.0, $score);
    }

    public function test_no_data_scores_zero(): void
    {
        $this->assertSame(0.0, $this->service->score(null, null, null, null));
    }

    public function test_throttle_detection_uses_configured_ratio(): void
    {
        config(['tunneling.scoring.throttle_ratio' => 0.35]);

        $this->assertTrue($this->service->isThrottled(0.2));
        $this->assertFalse($this->service->isThrottled(0.5));
        $this->assertFalse($this->service->isThrottled(null));
    }
}
