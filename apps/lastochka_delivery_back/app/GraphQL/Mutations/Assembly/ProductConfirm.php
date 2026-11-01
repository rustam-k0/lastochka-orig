<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\AssemblyItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Status;
use App\Models\Type;
use App\Services\Pricing\OrderItemTierPricing;
use App\Services\Pricing\PriceTierQuote;
use App\Support\NetworkCatalogMode;

final class ProductConfirm
{

  //Количество собранного товара
  private ?int $quantity;

  //Фактический вес в граммах (для весовых товаров)
  private ?int $actual_weight;

  //ID причины замены товара (справочник product_replace_reasons)
  private ?int $replace_reason_id;

  // Расчёт по лесенке «цены от количества» (MID-308): один экземпляр на мутацию
  // (флаг модуля, нужный только для свежих снапшотов замены, читается один раз)
  private ?OrderItemTierPricing $tier_pricing = null;

  /**
   * @param null $_
   * @param array{} $args
   */
  public function __invoke($_, array $args)
  {
    $this->quantity = $args['quantity'] ?? null;
    $this->actual_weight = $args['actual_weight'] ?? null;
    $this->replace_reason_id = $args['replace_reason_id'] ?? null;

    \DB::transaction(function () use ($args) {
      $item = AssemblyItem::with(['orderItem.order', 'replacements.product'])->find($args['assembly_item_id']);

      //id товара на замену
      $replacement_product_id = $args['replacement_product_id'] ?? null;

      $order_item = $item->orderItem;

      // Повторное подтверждение уже собранной позиции: двойное нажатие
      // «Собрать» или повторный скан того же штрих-кода, пока в сети висел
      // ответ на первый запрос. Те же параметры — идемпотентный 200 OK без
      // перезаписи позиции (иначе съедет assembled_at, на котором построена
      // методика «скан за сканом», и уйдёт дубль item_assembled в аналитику).
      if ((int) $item->status_id === Status::PRODUCT_ASSEMBLED) {
        if (!$this->isSameConfirmation($item, $order_item, $replacement_product_id)) {
          throw new \GraphQL\Error\Error('Товар уже собран');
        }

        return;
      }

      $this->collectProduct($item, $order_item, $replacement_product_id);
    });

    return [
      'code' => 200,
      'message' => 'OK'
    ];


  }

  /**
   * Повтор подтверждения даёт тот же результат, что уже записан в позиции?
   *
   * Сравниваем то, ЧТО собрано: товар (свой или замена) и сколько его собрано
   * (фактический вес для весового, количество для штучного). Причина замены в
   * сравнение не входит — состав от неё не меняется.
   */
  private function isSameConfirmation(AssemblyItem $item, OrderItem $order_item, ?int $replacement_product_id): bool
  {
    $selected_replacement = $item->replacements->first(fn($replacement) => (bool) $replacement->selected);

    if ($replacement_product_id !== null) {
      // Повтор с заменой засчитываем, только если собран тот же товар-замена.
      if (!$selected_replacement || (int) $selected_replacement->product_id !== $replacement_product_id) {
        return false;
      }

      $product = $selected_replacement->product;
    } else {
      // Позиция собрана заменой, а повтор пришёл без неё — это другое действие.
      if ($selected_replacement) {
        return false;
      }

      $product = $order_item->product;
    }

    if ($this->actual_weight !== null && $product?->isWeightBased()) {
      return (int) $item->actual_weight === $this->actual_weight;
    }

    // Штучный путь: присланный вес игнорируется (MID-246), а пустой quantity
    // означает «собрано заказанное» — ровно как в confirmProduct().
    $quantity = $this->quantity ?? $order_item->quantity;

    return $item->actual_weight === null && (int) $item->quantity === (int) $quantity;
  }

  /**
   * Добавить товар в СОБРАНО
   * @param AssemblyItem $item
   * @param OrderItem $order_item
   * @return void
   */
  private function collectProduct(AssemblyItem $item, OrderItem $order_item, ?int $replacement_product_id): void
  {
    $item->update([
      'status_id' => Status::PRODUCT_ASSEMBLED,
      'assembled_at' => now()
    ]);

    match ($item->type_id) {
      // Подтвердить товар
      Type::PRODUCT_WITHOUT_PROBLEMS => $this->confirmProduct($item, $order_item),
      // Подтвердить товар с заменой
      Type::PRODUCT_NOT_EXISTS => $this->confirmProductChange($item, $order_item, $replacement_product_id),
      // Подтвердить уточняющийся товар, если отправлена замена то заменить, если нет, подтвердить текущий товар
      Type::PRODUCT_CLARIFY => $replacement_product_id
        ? $this->confirmProductChange($item, $order_item, $replacement_product_id)
        : $this->confirmProduct($item, $order_item)
    };

  }

  //Подтвердить, что товар собран
  private function confirmProduct(AssemblyItem $item, OrderItem $order_item): void
  {
    // Весовой товар: расчёт по actual_weight. Для штучного товара присланный вес
    // игнорируется (MID-246): getStepGrams()=1 превращал цену за штуку в цену за
    // грамм, а залипший actual_weight раздувал quantity/total на витрине.
    if ($this->actual_weight !== null && $order_item->product?->isWeightBased()) {
      $product = $order_item->product;
      $price = $this->calculateWeightPrice($order_item->price_during_order, $product);

      // MID-308: ступень по фактическим порциям max(1, round(вес / шаг)), цена
      // строки = цена ступени × вес / шаг. Недовес в пределах половины шага
      // порции не меняет, а значит и ступень не срывает.
      $tier_quote = $this->tierPricing()->quoteFor(
        $order_item,
        OrderItemTierPricing::weightPortions($this->actual_weight, $product)
      );

      if ($tier_quote !== null) {
        $price = $this->calculateWeightPrice($tier_quote->unitPrice, $product);
      }

      $item->update([
        'actual_weight' => $this->actual_weight,
        'quantity' => null,
        'price' => $price,
      ]);

      \App\Services\AnalyticsEvents::itemAssembled($item);
      return;
    }

    // Штучный товар: существующая логика
    // Мобилка могла прислать только actual_weight без quantity — собрано заказанное
    if ($this->quantity === null) {
      $this->quantity = $order_item->quantity;
    }

    // Если количество товара было изменено, то цена товара тоже меняется
    $changed_price = $this->getChangedPrice($order_item,$order_item->product);

    // assembly_item.price всегда хранит итоговую цену за позицию
    $price = $changed_price ?? ($order_item->price_during_order * $this->quantity);

    $item->update([
      'quantity' => $this->quantity,
      'price' => $price,
      // Сброс веса от прежнего весового подтверждения: иначе toEvent подтянет
      // его fallback'ом из assembly_item и испортит line_total события
      'actual_weight' => null,
    ]);

    // Аналитика подтверждения позиции (LASTOCHKI-JAMI-11659): время на товар
    // считается разницей соседних assembled_at (методика «скан за сканом»)
    \App\Services\AnalyticsEvents::itemAssembled($item);
  }

  private function confirmProductChange(AssemblyItem $item, OrderItem $order_item, int $replacement_product_id): void
  {
    $replacement_product = $item->replacements->where('product_id', $replacement_product_id)->first();

    $replacement_product->update([
      'selected' => true
    ]);

    // Весовой товар: расчёт по actual_weight. Весовость — по товару-замене;
    // для штучной замены присланный вес игнорируется (MID-246)
    if ($this->actual_weight !== null && $replacement_product->product?->isWeightBased()) {
      $product = $replacement_product->product;
      $price_per_unit = $this->replacementPriceValue($product, $order_item);
      $price = $this->calculateWeightPrice($price_per_unit, $product);

      // MID-308: свежая лесенка товара-замены в магазине заказа, порции по факту
      $replacement_quote = $this->replacementQuote(
        $product,
        $order_item,
        OrderItemTierPricing::weightPortions($this->actual_weight, $product)
      );

      if ($replacement_quote !== null) {
        $price = $this->calculateWeightPrice($replacement_quote->unitPrice, $product);
      }

      $item->update([
        'actual_weight' => $this->actual_weight,
        'quantity' => null,
        'price' => $price,
        'replace_reason_id' => $this->replace_reason_id,
      ]);

      \App\Services\AnalyticsEvents::itemReplaced($item, $order_item->product, $replacement_product->product);
      return;
    }

    // Штучный товар: существующая логика
    if ($this->quantity === null) {
      $this->quantity = $order_item->quantity;
    }

    $price = $this->getChangedPrice($order_item,$replacement_product->product);

    $item->update([
      'quantity' => $this->quantity,
      'price' => $price,
      'replace_reason_id' => $this->replace_reason_id,
      'actual_weight' => null,
    ]);

    // Аналитика замены позиции (LASTOCHKI-JAMI-11659)
    \App\Services\AnalyticsEvents::itemReplaced($item, $order_item->product, $replacement_product->product);
  }

  // Вернуть цену. Цена меняется в случае если товар был заменен или обновлено его количество
  // Если обновлено только количество, берется та цена которая была во время оформления заказа
  public function getChangedPrice(OrderItem $item,Product $product): ?float
  {

    // Если товар не заменен, и количество товара не изменено возвращаем NULL
    if ($item->quantity == $this->quantity && $item->product_id == $product->id) {
      return null;
    }

    //Попадаем в условие если изменено количество без замены товара
    if ($item->product_id == $product->id) {
      // MID-308: у позиции со снапшотом лесенки цена за единицу зависит от
      // СОБРАННОГО количества (5 → 3: 199 → 249). От флага модуля не зависит.
      // Без снапшота: прежняя формула.
      $tier_quote = $this->tierPricing()->quoteFor($item, (float) $this->quantity);

      if ($tier_quote !== null) {
        return round($tier_quote->unitPrice * $this->quantity, 2);
      }

      // Если товар не поменялся оставляем ту же цену, что была во время оформления заказа
      return $item->price_during_order * $this->quantity;
    }

    // MID-308: у товара-замены своя лесенка в магазине заказа; цена по собранному
    // количеству. Лесенки нет или модуль выключен: прежняя формула ниже.
    $replacement_quote = $this->replacementQuote($product, $item, (float) $this->quantity);

    if ($replacement_quote !== null) {
      return round($replacement_quote->unitPrice * $this->quantity, 2);
    }

    // Попадаем сюда только в случае если товар заменен
    return $this->replacementPriceValue($product, $item) * $this->quantity;
  }

  /**
   * Цена единицы товара-замены в магазине заказа.
   *
   * В сетевом каталоге цены пер-магазин, и связь Product::price() (hasOne без
   * фильтра по магазину) вернула бы строку с минимальным id — цену чужого
   * магазина. При включённом списании оплаты сборщиком это реальные деньги,
   * поэтому отсутствие цены магазина — явная ошибка, а не молчаливый ноль.
   *
   * Легаси: поведение прежнее — цена связи price(), при её отсутствии null
   * (дальше по коду это нулевая цена, как было до сетевого каталога).
   */
  private function replacementPriceValue(Product $product, OrderItem $order_item): ?float
  {
    $price = $product->effectivePriceForShop($order_item->order?->shop_id);

    if (!$price && NetworkCatalogMode::isEnabled()) {
      throw new \GraphQL\Error\Error('У товара для замены нет цены в магазине заказа');
    }

    return $price?->value;
  }

  /**
   * Котировка товара-замены по свежему снапшоту из живой лесенки price_tiers
   * магазина заказа (MID-308). null: модуль выключен, у замены нет цены либо
   * лесенки в этом магазине; вызывающий код считает прежней формулой.
   *
   * base_price_old = null и base_is_eligible = true: курьерка не знает об акциях
   * на товар-замену (та же посылка, что у правила «при замене is_eligible = true»).
   */
  private function replacementQuote(Product $product, OrderItem $order_item, float $portions): ?PriceTierQuote
  {
    if (!$this->tierPricing()->enabled()) {
      return null;
    }

    $base_price = $this->replacementPriceValue($product, $order_item);

    if ($base_price === null) {
      return null;
    }

    $snapshot = $this->tierPricing()->freshSnapshot(
      $product,
      $order_item->order?->shop_id,
      (float) $base_price,
      null,
      true
    );

    return $snapshot !== null ? $snapshot->quote($portions) : null;
  }

  private function tierPricing(): OrderItemTierPricing
  {
    return $this->tier_pricing ??= new OrderItemTierPricing();
  }

  /**
   * Рассчитать цену весового товара по фактическому весу
   */
  private function calculateWeightPrice(float $price_during_order, Product $product): float
  {
    $step_grams = $this->getStepGrams($product);

    // Копейки: цена кванта дробная (LASTOCHKI-JAMI-11200)
    return round($price_during_order * $this->actual_weight / $step_grams, 2);
  }

  /**
   * Получить шаг в граммах для продукта
   */
  public static function getStepGrams(Product $product): int
  {
    $weight = (int) ($product->info_weight ?: 0);

    if ($weight > 0) {
      // Крупные единицы (кг, литр) → ×1000 (наименьшая ед.); грамм, мл, шт — как есть
      // (LASTOCHKI-JAMI-11200, адаптивные единицы).
      $isLargeUnit = in_array((int) $product->info_weight_type_id, [
        Type::INFO_WEIGHT_KILOGRAM,
        Type::INFO_WEIGHT_LITER,
      ], true);

      return $isLargeUnit ? $weight * 1000 : $weight;
    }

    // Без кванта порция = 1 базовая единица 1С (admin_back хранит value за неё):
    // у товара с базой «грамм» цена за грамм, шаг 1 — иначе цена по весу в 1000 раз ниже.
    if ((int) $product->base_unit_type_id > 0) {
      return \App\Support\StockUnits::sizeInSmallest((int) $product->base_unit_type_id);
    }

    // Весовой/объёмный товар без кванта: порция = 1 крупная единица (1000 наименьших;
    // раньше fallback 1 завышал line_total по факт. весу в 1000 раз) — LASTOCHKI-JAMI-11200.
    // Штучный → шаг 1; весовые/объёмные (гр/кг/л/мл) → шаг 1000.
    // unit_type_id здесь использовать нельзя — у весовых 1С-товаров он всегда =2 (шт),
    // а константы Type::UNIT_FOOD не существует (давала Fatal Error / 500). (tg-14178)
    return (int) $product->info_weight_type_id === Type::INFO_WEIGHT_PIECE ? 1 : 1000;
  }
}
