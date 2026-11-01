<?php

namespace App\GraphQL\Mutations\Product;

use App\Events\ProductUpdated;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetProductsCategory
{
    /**
     * Массово переносит товары в категорию: привязки категорий каждого товара
     * заменяются на одну целевую. Возвращает число обработанных товаров.
     *
     * RabbitMQ: после переноса публикует ProductUpdated для каждого товара
     * (НЕ через touch — Product::touch не триггерит outbox в admin_back).
     */
    public function __invoke($_, array $args): int
    {
        $category = Category::find($args['category_id']);

        if (!$category) {
            throw ValidationException::withMessages([
                'category_id' => ['Категория не найдена.'],
            ]);
        }

        if ($category->childCategories()->exists()) {
            throw ValidationException::withMessages([
                'category_id' => ["Категория «{$category->title}» содержит вложенные категории. Выберите конечную категорию (без подкатегорий)."],
            ]);
        }

        $count = 0;

        DB::transaction(function () use ($args, $category, &$count) {
            foreach ((array) $args['ids'] as $id) {
                $product = Product::find($id);
                if (!$product) {
                    continue;
                }

                $product->categories()->sync([$category->id => ['position' => 0]]);

                event(new ProductUpdated($product->fresh()));
                $count++;
            }
        });

        return $count;
    }
}
