<?php

namespace Tests\Unit;

use App\Models\ShopZone;
use App\Models\ZoneCoordinate;
use App\Services\ShopZoneMatcher;
use PHPUnit\Framework\TestCase;

class ShopZoneMatcherTest extends TestCase
{
    private function makeSquareZone(): ShopZone
    {
        // Квадрат (55.0..55.1, 37.0..37.1)
        $zone = new ShopZone();
        $zone->setRelation('coordinates', collect([
            new ZoneCoordinate(['latitude' => 55.0, 'longitude' => 37.0]),
            new ZoneCoordinate(['latitude' => 55.0, 'longitude' => 37.1]),
            new ZoneCoordinate(['latitude' => 55.1, 'longitude' => 37.1]),
            new ZoneCoordinate(['latitude' => 55.1, 'longitude' => 37.0]),
        ]));

        return $zone;
    }

    public function test_point_inside_polygon_matches(): void
    {
        $matcher = new ShopZoneMatcher();
        $this->assertTrue($matcher->isPointInZone($this->makeSquareZone(), 55.05, 37.05));
    }

    public function test_point_outside_polygon_does_not_match(): void
    {
        $matcher = new ShopZoneMatcher();
        $this->assertFalse($matcher->isPointInZone($this->makeSquareZone(), 55.2, 37.05));
    }

    public function test_degenerate_polygon_is_never_matched(): void
    {
        $zone = new ShopZone();
        $zone->setRelation('coordinates', collect([
            new ZoneCoordinate(['latitude' => 55.0, 'longitude' => 37.0]),
            new ZoneCoordinate(['latitude' => 55.1, 'longitude' => 37.1]),
        ]));

        $matcher = new ShopZoneMatcher();
        $this->assertFalse($matcher->isPointInZone($zone, 55.05, 37.05));
    }
}
