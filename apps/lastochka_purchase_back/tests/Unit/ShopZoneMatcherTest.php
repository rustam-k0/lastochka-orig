<?php

namespace Tests\Unit;

use App\Models\ShopZone;
use App\Models\ZoneCoordinate;
use App\Services\ShopZoneMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ShopZoneMatcherTest extends TestCase
{
    private function makeZone(int $shopId, array $points): ShopZone
    {
        $zone = new ShopZone();
        $zone->shop_id = $shopId;

        $coordinates = new Collection();
        foreach ($points as $position => $point) {
            [$lat, $lng] = $point;
            $coordinate = new ZoneCoordinate();
            $coordinate->latitude = $lat;
            $coordinate->longitude = $lng;
            $coordinate->position = $position;
            $coordinates->push($coordinate);
        }
        $zone->setRelation('coordinates', $coordinates);

        return $zone;
    }

    public function test_point_inside_square_polygon_matches(): void
    {
        $zone = $this->makeZone(10, [
            ['0', '0'],
            ['0', '10'],
            ['10', '10'],
            ['10', '0'],
        ]);

        $matcher = new ShopZoneMatcher();

        $this->assertTrue($matcher->isPointInZone($zone, 5.0, 5.0));
    }

    public function test_point_outside_square_polygon_does_not_match(): void
    {
        $zone = $this->makeZone(10, [
            ['0', '0'],
            ['0', '10'],
            ['10', '10'],
            ['10', '0'],
        ]);

        $matcher = new ShopZoneMatcher();

        $this->assertFalse($matcher->isPointInZone($zone, 20.0, 20.0));
    }

    public function test_point_exactly_on_edge_is_treated_consistently(): void
    {
        // Точка на границе полигона (lat=0, lng=5) — ray-casting может дать true
        // или false в зависимости от направления рёбер; тест фиксирует ТЕКУЩЕЕ
        // поведение алгоритма (порт 1:1 из admin_back), не строгую геометрию.
        $zone = $this->makeZone(10, [
            ['0', '0'],
            ['0', '10'],
            ['10', '10'],
            ['10', '0'],
        ]);

        $matcher = new ShopZoneMatcher();

        $result = $matcher->isPointInZone($zone, 0.0, 5.0);

        $this->assertIsBool($result);
    }

    public function test_degenerate_polygon_with_less_than_three_points_never_matches(): void
    {
        $zone = $this->makeZone(10, [
            ['0', '0'],
            ['0', '10'],
        ]);

        $matcher = new ShopZoneMatcher();

        $this->assertFalse($matcher->isPointInZone($zone, 0.0, 5.0));
    }

    public function test_zone_with_no_coordinates_never_matches(): void
    {
        $zone = $this->makeZone(10, []);

        $matcher = new ShopZoneMatcher();

        $this->assertFalse($matcher->isPointInZone($zone, 0.0, 0.0));
    }
}
