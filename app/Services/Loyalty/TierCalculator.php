<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\Tier;

final class TierCalculator
{
    /**
     * @return array<string, int>
     */
    private function thresholds(): array
    {
        /** @var array<string, int|string> $configured */
        $configured = (array) config('loyalty.tiers', []);

        $thresholds = [];
        foreach ($configured as $name => $threshold) {
            $thresholds[$name] = (int) $threshold;
        }

        asort($thresholds);

        return $thresholds;
    }

    public function for(int $lifetimePoints): Tier
    {
        $computed = Tier::Bronze;

        foreach ($this->thresholds() as $name => $threshold) {
            if ($lifetimePoints >= $threshold) {
                $computed = Tier::from($name);
            }
        }

        return $computed;
    }

    public function nextThreshold(Tier $tier): ?int
    {
        $thresholds = $this->thresholds();
        $current = $thresholds[$tier->value] ?? 0;

        foreach ($thresholds as $threshold) {
            if ($threshold > $current) {
                return $threshold;
            }
        }

        return null;
    }
}
