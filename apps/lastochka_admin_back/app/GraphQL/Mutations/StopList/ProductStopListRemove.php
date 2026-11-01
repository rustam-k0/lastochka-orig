<?php

namespace App\GraphQL\Mutations\StopList;

use App\Events\ProductUpdated;
use App\GraphQL\Mutations\Product\ProductPublish;
use App\Models\Product;
use App\Support\StopListType;
use Illuminate\Validation\ValidationException;

/**
 * Убрать товар из стоп-листа магазина — то же действие, что поля карточки:
 *
 * «hidden»       → публикация товара (делегация в ProductPublish: статус,
 *                  publish_after_unlock, ProductHistory, событие в outbox);
 * «pickup_only»  → выключение «только самовывоз» (pickup_only = false).
 */
final class ProductStopListRemove
{
    /**
     * @param null $_
     * @param array{product_id:int,type:string} $args
     * @return array{code:int,message:string}
     */
    public function __invoke($_, array $args): array
    {
        $type = (string) $args['type'];
        StopListType::assertValid($type);

        $product = Product::find($args['product_id']);
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => ['Товар не найден.']]);
        }

        if ($type === StopListType::HIDDEN) {
            return app(ProductPublish::class)(null, ['id' => $product->id]);
        }

        $product->update(['pickup_only' => false]);

        // products.updated НЕ слушается пакетом — публикуем вручную (см. Add).
        event(new ProductUpdated($product));

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
