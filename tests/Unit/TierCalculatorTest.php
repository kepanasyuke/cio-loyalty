<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Tier;
use App\Services\Loyalty\TierCalculator;
use Tests\TestCase;

final class TierCalculatorTest extends TestCase
{
    private TierCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new TierCalculator;
    }

    public function test_it_maps_lifetime_points_to_tier(): void
    {
        $this->assertSame(Tier::Bronze, $this->calculator->for(0));
        $this->assertSame(Tier::Bronze, $this->calculator->for(999));
        $this->assertSame(Tier::Silver, $this->calculator->for(1_000));
        $this->assertSame(Tier::Silver, $this->calculator->for(4_999));
        $this->assertSame(Tier::Gold, $this->calculator->for(5_000));
        $this->assertSame(Tier::Gold, $this->calculator->for(19_999));
        $this->assertSame(Tier::Platinum, $this->calculator->for(20_000));
        $this->assertSame(Tier::Platinum, $this->calculator->for(1_000_000));
    }

    public function test_it_returns_next_threshold(): void
    {
        $this->assertSame(1_000, $this->calculator->nextThreshold(Tier::Bronze));
        $this->assertSame(5_000, $this->calculator->nextThreshold(Tier::Silver));
        $this->assertSame(20_000, $this->calculator->nextThreshold(Tier::Gold));
        $this->assertNull($this->calculator->nextThreshold(Tier::Platinum));
    }
}
