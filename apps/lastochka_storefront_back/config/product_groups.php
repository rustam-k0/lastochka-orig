<?php

/*
|--------------------------------------------------------------------------
| Автоматические товарные группы
|--------------------------------------------------------------------------
|
| Личные группы (purchase_history, viewed_not_bought) собираются под
| пользователя из токена, см. App\Services\ProductGroup\ProductGroupProductsResolver.
|
*/

return [

    // Личная группа отдаётся, только если в ней не меньше N товаров
    'personal_min_products' => 3,

    // Просмотр карточки короче N мс не фиксируется (product.viewed без
    // duration_ms не фиксируется вообще)
    'personal_view_min_duration_ms' => 3000,

    // Просмотр старше N дней не учитывается в «Смотрел, но не купил»
    // и удаляется командой product-views:prune
    'personal_view_ttl_days' => 14,

    // Веса ранжирования «Смотрел, но не купил»: views_count × views,
    // плюс бонусы за сигналы. При равенстве — last_viewed_at DESC.
    'ranking' => [
        'views' => 1,
        'cart_removed' => 2,
        'favorite' => 2,
        'discount' => 1,
    ],

];
