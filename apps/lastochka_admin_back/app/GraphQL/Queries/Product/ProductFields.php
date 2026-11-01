<?php

namespace App\GraphQL\Queries\Product;

use App\Models\Product;
use App\Services\ModuleConfigService;
use App\Services\Quantum\CategoryQuantumService;

final class ProductFields
{
    /**
     * Шаг кванта, заданного витринными категориями товара (в наименьшей единице
     * семейства: грамм/мл/штука), read-only. Не null → квант управляется
     * категорией, карточка показывает его информативно. null → менеджер может
     * проставить ручной квант товару (manual_quantum_step).
     */
    public function categoryQuantumStep(Product $product): ?int
    {
        return app(CategoryQuantumService::class)->resolveCategoryQuantum($product)['step'] ?? null;
    }

    /**
     * Единица кванта витринной категории (2=грамм / 5=мл / 1=шт), read-only.
     */
    public function categoryQuantumUnitTypeId(Product $product): ?int
    {
        return app(CategoryQuantumService::class)->resolveCategoryQuantum($product)['unit'] ?? null;
    }

    /**
     * Ступени «цены от количества» товара по всем магазинам (MID-308): фронт
     * группирует их по shop_id. При выключенном модуле — пустой список.
     *
     * ВНИМАНИЕ N+1: связь ленивая, без батч-лоадера. Сегодня поле запрашивает
     * только карточка одного товара — безопасно. Если price_tiers понадобится
     * в списочном запросе (paginate), нужен eager-load связи Product::priceTiers()
     * или отдельный батч-лоадер, иначе на каждый товар списка уйдёт свой запрос.
     *
     * @return iterable<int, \App\Models\PriceTier>
     */
    public function priceTiers(Product $product): iterable
    {
        if (!ModuleConfigService::isPriceTiersEnabled()) {
            return [];
        }

        return $product->priceTiers;
    }
}
