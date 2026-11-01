<?php

declare(strict_types=1);

use App\Models\PriceTier;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * MID-308: таблица price_tiers парсера. Ступени «цены от количества» хранятся
 * по (товар, тип цены, порог), потому что prices держит одну строку на тип цены.
 */
test('таблица price_tiers создана с нужными колонками', function () {
    expect(Schema::hasTable('price_tiers'))->toBeTrue();
    expect(Schema::hasColumns('price_tiers', [
        'id', 'product_id', 'price_type', 'min_quantity', 'value', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('Product::priceTiers отдаёт ступени по возрастанию порога с float-кастами', function () {
    $product = makeProduct();

    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 5, 'value' => 199]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2.5, 'value' => 249.5]);

    $tiers = $product->priceTiers()->get();

    expect($tiers)->toHaveCount(2);
    expect($tiers[0]->min_quantity)->toBe(2.5);
    expect($tiers[0]->value)->toBe(249.5);
    expect($tiers[1]->min_quantity)->toBe(5.0);
    expect($tiers[0]->product->is($product))->toBeTrue();
});

test('порог уникален в пределах товара и типа цены', function () {
    $product = makeProduct();

    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'ОПТ', 'min_quantity' => 2, 'value' => 240]);

    expect(fn () => PriceTier::create([
        'product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 230,
    ]))->toThrow(QueryException::class);
});

test('ступени удаляются каскадом вместе с товаром', function () {
    $product = makeProduct();
    PriceTier::create(['product_id' => $product->id, 'price_type' => 'Розничная', 'min_quantity' => 2, 'value' => 249]);

    $product->forceDelete();

    expect(PriceTier::count())->toBe(0);
});
