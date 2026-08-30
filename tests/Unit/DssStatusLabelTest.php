<?php

namespace Tests\Unit;

use App\Services\DashboardAnalyticsService;
use Tests\TestCase;

/**
 * CLAUDE.md's Principal-facing 4-bucket language (On Track / Needs
 * Monitoring / Needs Attention / At Risk) mapped from the stored
 * risk_level (low/moderate/high) + trend — a pure display mapping,
 * verified in isolation since it touches no database.
 */
class DssStatusLabelTest extends TestCase
{
    private DashboardAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardAnalyticsService();
    }

    public function test_high_risk_is_always_at_risk(): void
    {
        $this->assertSame('At Risk', $this->service->dssStatusLabel('high', 'improving', false));
    }

    public function test_moderate_risk_is_always_needs_attention(): void
    {
        $this->assertSame('Needs Attention', $this->service->dssStatusLabel('moderate', null, false));
    }

    public function test_low_risk_with_stable_trend_is_on_track(): void
    {
        $this->assertSame('On Track', $this->service->dssStatusLabel('low', 'stable', false));
    }

    public function test_low_risk_with_declining_trend_is_needs_monitoring_not_on_track(): void
    {
        $this->assertSame('Needs Monitoring', $this->service->dssStatusLabel('low', 'declining', false));
    }

    public function test_low_risk_with_consecutive_decline_is_needs_monitoring(): void
    {
        $this->assertSame('Needs Monitoring', $this->service->dssStatusLabel('low', 'stable', true));
    }
}
