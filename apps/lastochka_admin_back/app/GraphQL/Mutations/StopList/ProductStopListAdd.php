<?php

namespace App\GraphQL\Mutations\StopList;

use App\Events\ProductUpdated;
use App\GraphQL\Mutations\Product\ProductHide;
use App\Models\Product;
use App\Support\StopListType;
use Illuminate\Validation\ValidationException;

/**
 * Добавить товар в стоп-лист магазина — то же действие, что поля карточки:
 *
 * «hidden»       → деактивация товара (делегация в ProductHide: статус,
 *                  publish_after_unlock, ProductHistory, событие в outbox);
 * «pickup_only»  → включение «только самовывоз» (pickup_only = true).
 *
 * Дату попадания в стоп-лист (hidden_at / pickup_only_at) ведут мутаторы
 * модели Product.
 */
final class ProductStopListAdd
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
            return app(ProductHide::class)(null, ['id' => $product->id]);
        }

        $product->update(['pickup_only' => true]);

        // products.updated НЕ слушается пакетом (config/rabbitmq-events.php: только
        // created/deleted) — публикуем ProductUpdated вручную, как ProductHide/
        // ProductPublish, иначе storefront не пересчитает самовывоз.
        event(new ProductUpdated($product));

        return [
            'code' => 200,
            'message' => 'OK',
        ];
    }
}
