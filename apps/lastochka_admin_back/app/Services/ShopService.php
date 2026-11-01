<?php

namespace App\Services;

use App\Events\ShopCategoryDetached;
use App\Models\Category;
use App\Models\Shop;
use App\Models\Status;
use Illuminate\Validation\ValidationException;

class ShopService
{


  public function fillInfo(Shop $shop, $args): void
  {
    $args = \App\Services\CustomCheckboxService::sanitizeArgs('Shop', $args);

    $info = [
      'title' => $args['title'] ?? null,
      'description' => $args['description'] ?? null,
      'schedule' => $args['schedule'] ?? null,
      'address' => $args['address'] ?? null,
      'longitude' => $args['longitude'] ?? null,
      'latitude' => $args['latitude'] ?? null,
      'phone' => $args['phone'] ?? null,
      'email' => $args['email'] ?? null,
      'ignore_stock' => $args['ignore_stock'] ?? false,
      // Город: ключ отсутствует — сохраняем текущий (partial update мерчанта),
      // явный null — снимаем привязку (иначе город невозможно отвязать из админки).
      'city_id' => array_key_exists('city_id', $args) ? $args['city_id'] : $shop->city_id,
    ];

    // Динамические чекбоксы: только при наличии ключа (partial update без
    // ключа не затирает значения). В первом update — чтобы сохранялось и
    // в партнёрской ветке (isShopScope выходит ниже).
    if (array_key_exists('custom_checkboxes', $args)) {
      $info['custom_checkboxes'] = $args['custom_checkboxes'];
    }

    $shop->update($info);

    $cityChanged = $shop->wasChanged('city_id');

    $this->fillImages($shop, $args);

    if (\Auth::user()->isShopScope()) {
      return;
    }

    $shop->update([
      'deposit' => $args['deposit'] ?? $shop->deposit,
      'commission' => $args['commission'] ?? $shop->commission,
      'area_sqm' => $args['area_sqm'] ?? $shop->area_sqm,
      'type_id' => $args['type_id'] ?? null,
      // status_id применяется ТОЛЬКО в админской ветке (после return для isShopScope):
      // партнёр не может снять свой магазин с публикации. Ключ отсутствует —
      // сохраняем текущий статус, а не сбрасываем на дефолт.
      'status_id' => $args['status_id'] ?? $shop->status_id,
      'sphere_id' => $args['sphere_id'] ?? null,
      // NOT NULL колонки: ключ отсутствует — сохраняем текущее значение,
      // а не затираем null (pre-existing баг, обнаружен тестами T6:
      // прямой вызов fillInfo() с partial args ронял constraint violation).
      'slot_time' => $args['slot_time'] ?? $shop->slot_time,
      'slot_max_orders' => $args['slot_max_orders'] ?? $shop->slot_max_orders,
      'payment_type_id' => $args['payment_type_id'] ?? $shop->payment_type_id,
      'is_popular' => $args['is_popular'] ?? false,
      'is_b2b' => $args['is_b2b'] ?? false,
      'is_bonus_enabled' => $args['is_bonus_enabled'] ?? false,
      'bonus_earn_percent' => $args['bonus_earn_percent'] ?? 0,
      'bonus_spend_percent' => $args['bonus_spend_percent'] ?? 0,
      'is_pickup_enabled' => $args['is_pickup_enabled'] ?? false,
      'min_pickup_order_amount' => $args['min_pickup_order_amount'] ?? null,
      'heavy_order_threshold' => $args['heavy_order_threshold'] ?? null,
      'assembly_price' => $args['assembly_price'] ?? null,
      'pickup_payment_methods' => isset($args['pickup_payment_methods']) ? json_decode($args['pickup_payment_methods'], true) : null,
      'pickup_auto_cancel_enabled' => $args['pickup_auto_cancel_enabled'] ?? false,
      // NOT NULL колонки (см. комментарий у slot_time выше) — fallback на текущее значение.
      'pickup_wait_perishable_hours' => $args['pickup_wait_perishable_hours'] ?? $shop->pickup_wait_perishable_hours,
      'pickup_wait_regular_hours' => $args['pickup_wait_regular_hours'] ?? $shop->pickup_wait_regular_hours,
      'pickup_grace_period_hours' => $args['pickup_grace_period_hours'] ?? $shop->pickup_grace_period_hours,
    ]);

    // Город сняли — «дефолт» без города бессмыслен, снимаем молча.
    if (empty($shop->city_id) && $shop->is_default) {
      $shop->update(['is_default' => false]);
    }

    // Магазин по умолчанию — conditional set: без ключа флаг не трогаем.
    // Отдельная ветка на смену города: магазин-«дефолт» переехал в другой город,
    // где уже есть свой дефолт — сбрасываем прежний, иначе в городе их станет два.
    if (array_key_exists('is_default', $args)) {
      $this->syncDefaultShop($shop, (bool)$args['is_default']);
    } elseif ($cityChanged && $shop->is_default) {
      $this->syncDefaultShop($shop, true);
    }

    $this->syncCategoriesWithEvents($shop, $args['categories'] ?? []);
  }

  /**
   * Проставить/снять флаг «магазин по умолчанию» с гарантией единственности в городе.
   *
   * Инвариант: в одном городе не более одного магазина с is_default = true.
   * Прежний дефолт сбрасывается поштучно через Eloquent, а не массовым update,
   * чтобы observer положил его изменение в outbox и флаг снялся и в витрине.
   */
  public function syncDefaultShop(Shop $shop, bool $isDefault): void
  {
    if (!$isDefault) {
      $shop->update(['is_default' => false]);

      return;
    }

    if (empty($shop->city_id)) {
      throw ValidationException::withMessages([
        'is_default' => ['Магазин по умолчанию можно назначить только магазину с указанным городом.'],
      ]);
    }

    \DB::transaction(function () use ($shop) {
      Shop::where('city_id', $shop->city_id)
        ->where('id', '!=', $shop->id)
        ->where('is_default', true)
        ->get()
        ->each(fn(Shop $other) => $other->update(['is_default' => false]));

      $shop->update(['is_default' => true]);
    });
  }

  /**
   * Синхронизировать категории магазина и опубликовать RabbitMQ события.
   *
   * Инвариант: pivot `shops_categories` всегда содержит полную ветку от
   * корня до каждого выбранного узла. GetShopCategories и RabbitMQ-консьюмеры
   * строят дерево `whereNull('parent_id')` + `with(childCategories)` от pivot —
   * листья-сироты (у которых parent_id не в pivot) выпадают из результата.
   * Поэтому перед sync() расширяем id всеми предками.
   */
  public function syncCategoriesWithEvents(Shop $shop, array $categoryIds): void
  {
    $oldIds = $shop->categories()->pluck('categories.id')->toArray();

    $fullIds = $this->expandWithAncestors($categoryIds);

    $detachedIds = array_values(array_diff($oldIds, $fullIds));

    // Диспатчим detach-события ДО sync() — пока pivot shops_categories ещё жив,
    // чтобы resolveShopCode мог достать code через relatedModel/связь.
    // После sync() связи уже нет, Observer при touch() не опубликует событие
    // с shop_code отвязанного магазина, и сторонние сервисы не узнают об отвязке.
    if (!empty($detachedIds)) {
      Category::whereIn('id', $detachedIds)
        ->get()
        ->each(fn(Category $cat) => event(new ShopCategoryDetached($cat, $shop)));
    }

    $shop->categories()->sync($fullIds);

    // Только привязанные: изменение касается этого магазина, поэтому и событие
    // уходит одному ему (иначе publishMultiEntity разошлёт по всем связям
    // категории — на сети в сотни магазинов это тысячи лишних событий).
    //
    // Отвязанные сюда не входят: связи с $shop уже нет, скоуп не нашёл бы её и
    // Observer сделал бы fallback publishSingleEntity — событие без shop_code,
    // которого потребители не ждут. Об отвязке сообщает ShopCategoryDetached выше.
    if (!empty($fullIds)) {
      Category::whereIn('id', $fullIds)
          ->each(fn(Category $cat) => Category::publishingOnlyForShops(
            [$shop->id],
            fn() => $cat->touch()
          ));
    }
  }

  /**
   * Расширить список id категорий всеми предками по parent_id.
   * Возвращает уникальный массив id (листья + все их предки до корня).
   */
  public function expandWithAncestors(array $categoryIds): array
  {
    return $this->expandWithAncestorsFromMap($categoryIds, $this->categoryParentMap());
  }

  /**
   * Карта `id => parent_id` по всему справочнику категорий.
   *
   * Дерево витринных категорий небольшое (десятки узлов), поэтому его дешевле
   * один раз поднять в память, чем ходить в БД на каждом уровне и на каждом
   * магазине. Нужно для массовых обходов (раскатка витрин по сети магазинов).
   *
   * @return array<int, int|null>
   */
  public function categoryParentMap(): array
  {
    return Category::query()
      ->pluck('parent_id', 'id')
      ->map(fn($parentId) => $parentId === null ? null : (int) $parentId)
      ->all();
  }

  /**
   * То же, что expandWithAncestors(), но по заранее загруженной карте предков —
   * без единого запроса к БД.
   *
   * @param array<int, int|null> $parentMap
   */
  public function expandWithAncestorsFromMap(array $categoryIds, array $parentMap): array
  {
    $categoryIds = array_values(array_unique(array_filter(
      array_map('intval', $categoryIds),
      fn(int $id) => $id > 0
    )));

    if (empty($categoryIds)) {
      return [];
    }

    $all = array_flip($categoryIds);
    $frontier = $categoryIds;

    while (!empty($frontier)) {
      $new = [];

      foreach ($frontier as $id) {
        $parentId = $parentMap[$id] ?? null;

        if ($parentId === null || $parentId <= 0 || isset($all[$parentId])) {
          continue;
        }

        $all[$parentId] = true;
        $new[] = $parentId;
      }

      $frontier = $new;
    }

    return array_keys($all);
  }

  /**
   * Аддитивно привязать витринные категории к магазину с публикацией событий.
   *
   * В отличие от syncCategoriesWithEvents (полная замена через sync()) — НИЧЕГО
   * не отвязывает: добавляет только недостающие категории и их предков, сохраняя
   * инвариант shops_categories (полная ветка от корня). touch() новых записей
   * триггерит outbox `category.updated` per-shop (config relation `shopCategories`),
   * благодаря чему витрина появляется в дереве категорий магазина на storefront.
   *
   * Используется при привязке 1С→витрина (LinkToShowcase), когда товаровед
   * выбирает витрину, ещё не добавленную в магазин.
   */
  public function attachCategoriesWithEvents(Shop $shop, array $categoryIds): void
  {
    $currentIds = $shop->categories()
      ->pluck('categories.id')
      ->map(fn($v) => (int) $v)
      ->all();

    $fullIds = $this->expandWithAncestors(array_merge($currentIds, $categoryIds));
    $newIds = array_values(array_diff($fullIds, $currentIds));

    if (empty($newIds)) {
      return;
    }

    // syncWithoutDetaching: добавляет только новые, существующие не трогает.
    $shop->categories()->syncWithoutDetaching($newIds);

    // touch новых → Observer публикует category.updated с relatedModel=Shop
    // (через relation shopCategories.shop), storefront привяжет витрину к store.
    // Скоуп по $shop: привязка касается только его, а без скоупа событие ушло бы
    // по каждой связи категории, то есть всем магазинам сети.
    Category::whereIn('id', $newIds)
      ->each(fn(Category $cat) => Category::publishingOnlyForShops(
        [$shop->id],
        fn() => $cat->touch()
      ));
  }

  /**
   * Отвязать одну категорию (секцию карты магазина) от магазина с публикацией
   * detach-события. Идемпотентно: если привязки нет — no-op (возвращает false).
   *
   * Событие ShopCategoryDetached диспатчится ДО detach() — пока строка pivot
   * shops_categories ещё жива, чтобы listener/resolveShopCode достал shop_code
   * через связь и storefront узнал об отвязке категории (зеркалит паттерн
   * syncCategoriesWithEvents). После detach() связи уже нет и событие с кодом
   * отвязанного магазина построить нельзя.
   *
   * @return bool true — строка pivot удалена; false — привязки не было.
   */
  public function detachCategorySection(Shop $shop, int $categoryId): bool
  {
    if (!$shop->categories()->where('categories.id', $categoryId)->exists()) {
      return false;
    }

    $category = Category::find($categoryId);

    // Событие (outbox) и detach — в одной транзакции: outbox в той же БД,
    // атомарность бесплатна. Иначе при падении detach() остался бы висеть
    // detach-event без реальной отвязки (storefront рассинхронизировался бы).
    \DB::transaction(function () use ($shop, $category, $categoryId) {
      if ($category !== null) {
        event(new ShopCategoryDetached($category, $shop));
      }

      $shop->categories()->detach($categoryId);
    });

    return true;
  }

  public function fillImages(Shop $shop, $args): void
  {
    if (isset($args['img']) && !$shop->joinImg) {
      if ($args['img'] != []) {
        $shop->saveImg($args['img']);
      }
    } else {
      $shop->updateImg($shop->joinImg, $args['img'] ?? null);
    }

    if (isset($args['logo_img']) && !$shop->joinLogoImg) {
      if ($args['logo_img'] != []) {
        $shop->saveImg($args['logo_img'],Status::CODE_LOGO_IMG);
      }
    } else {
      $shop->updateImg($shop->joinLogoImg, $args['logo_img'] ?? null);
    }

    if (isset($args['background_img']) && !$shop->joinBackgroundImg) {
      if ($args['background_img'] != []) {
        $shop->saveImg($args['background_img'],Status::CODE_BACKGROUND_IMG);
      }
    } else {
      $shop->updateImg($shop->joinBackgroundImg, $args['background_img'] ?? null);
    }
  }


}
