<?php

declare(strict_types=1);

namespace Tests\Feature\RabbitMQ;

use App\Models\Store;
use App\RabbitMQ\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * unit_type события — единица остатка: крупная (кг, литр) хранится ×1000 в
 * г/мл, наименьшая (г, мл) и штуки — как есть.
 *
 * 1c_parser шлёт unit_type kg/l/g/ml, а распознавался только kg: литровый
 * остаток 12.5 л ложился как 13 «штук» и не дотягивал до шага 500 мл (товар
 * пропадал из каталога до следующего эха admin_back, который присылал 'kg').
 */
final class ProductStockUnitTypeTest extends TestCase
{
    use RefreshDatabase;

    private function stockFromEvent(string $unitType, float $quantity, string $measurementUnit): array
    {
        $store = Store::factory()->create();

        $data = app(ProductEventTransformer::class)->fromEvent([
            'id' => 'guid-' . uniqid(),
            'shop_code' => $store->code,
            'source' => '1c',
            'sku' => 'SKU-' . uniqid(),
            'title' => 'Товар',
            'price' => 100,
            'quantity' => $quantity,
            'unit_type' => $unitType,
            'measurement_unit' => $measurementUnit,
        ]);

        return [$data['unit_type'], (int) $data['quantity']];
    }

    public function test_kilograms_are_stored_in_grams(): void
    {
        $this->assertSame(['kg', 12806], $this->stockFromEvent('kg', 12.806, 'kilogram'));
    }

    public function test_liters_are_stored_in_milliliters(): void
    {
        $this->assertSame(['kg', 12500], $this->stockFromEvent('l', 12.5, 'liter'));
    }

    public function test_grams_are_stored_as_is(): void
    {
        $this->assertSame(['piece', 500], $this->stockFromEvent('g', 500, 'gram'));
    }

    public function test_pieces_are_stored_as_is(): void
    {
        $this->assertSame(['piece', 3], $this->stockFromEvent('piece', 3, 'piece'));
    }
}
