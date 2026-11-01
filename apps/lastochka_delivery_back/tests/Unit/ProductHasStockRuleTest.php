<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Shop;
use App\Rules\ProductHasStockRule;
use Tests\TestCase;

final class ProductHasStockRuleTest extends TestCase
{
    public function test_passes_when_shop_ignores_stock(): void
    {
        $shop = new Shop();
        $shop->ignore_stock = true;

        $rule = new ProductHasStockRule($shop);

        // Магазин с "Не учитывать остатки": проверка наличия пропускается ещё до
        // обращения к БД, даже для заведомо отсутствующего товара.
        self::assertTrue(
            $rule->passes('replacement_product_id', 999999999),
            'Для магазина с ignore_stock проверка наличия должна проходить всегда'
        );
    }

    public function test_message_is_correct(): void
    {
        self::assertSame('Товар отсутствует на складе', (new ProductHasStockRule())->message());
    }
}
