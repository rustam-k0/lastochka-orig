<?php

namespace App\GraphQL\Mutations\Assembly;

use App\Models\AssemblyItem;
use App\Models\AssemblyItemMarkingCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\Status;
use App\Services\HonestSign\HonestSignClient;
use App\Services\HonestSign\MarkingCodeGtin;
use App\Services\HonestSign\MarkingRequirement;
use Illuminate\Support\Facades\Log;

/**
 * Скан кода маркировки при сборке (Честный знак, MID-266).
 *
 * Сборщик сканирует DataMatrix/КИЗ с единицы маркированного товара; код
 * проверяется в 1С (CheckCIS) и сохраняется за позицией сборки. Один вызов —
 * одна единица. Коды ответа: 200 принят (или уже был), 422 отклонён (текст
 * для сборщика в message), 503 сервис проверки недоступен — повторить.
 */
final class MarkingCodeScan
{
  public const MODULE_DISABLED = 'Модуль «Честный знак» выключен';

  public const ALREADY_ASSEMBLED = 'Товар уже собран, верните его в сборку';

  public const NOT_MARKED = 'Товар не подлежит маркировке';

  public const OTHER_PRODUCT_SAME_ITEM = 'Код уже отсканирован для другого товара этой позиции';

  public const FOREIGN_GTIN = 'Код маркировки принадлежит другому товару';

  public const NO_BARCODE = 'У товара не задан штрихкод, проверка маркировки невозможна';

  public const SERVICE_UNAVAILABLE = 'Сервис проверки маркировки недоступен, повторите';

  public function __construct(private HonestSignClient $client)
  {
  }

  /**
   * @param null $_
   * @param array{assembly_item_id: int, code: string, product_id?: int|null} $args
   */
  public function __invoke($_, array $args): array
  {
    if (!Order::isHonestSignModuleEnabled()) {
      return self::respond(422, self::MODULE_DISABLED);
    }

    $item = AssemblyItem::with(['orderItem.product', 'replacements.product', 'assembly.order'])
      ->findOrFail($args['assembly_item_id']);
    $code = trim($args['code']);

    // Валидатор гарантирует: product_id — товар позиции либо из replacements.
    $product = isset($args['product_id']) && $args['product_id'] !== null
      ? Product::find($args['product_id'])
      : $item->orderItem?->product;

    if (!$product) {
      return self::respond(422, 'Товар позиции не найден', $item);
    }

    if ((int) $item->status_id === Status::PRODUCT_ASSEMBLED) {
      return self::respond(422, self::ALREADY_ASSEMBLED, $item, $product);
    }

    if (!$product->is_marked) {
      return self::respond(422, self::NOT_MARKED, $item, $product);
    }

    // Повторный скан того же кода той же единицы (двойное нажатие, ретрай
    // офлайн-буфера) — идемпотентный OK без нового ряда.
    $existing = $item->markingCodes()->where('code', $code)->first();
    if ($existing) {
      if ((int) $existing->product_id === (int) $product->id) {
        return self::respond(200, 'OK', $item, $product);
      }

      return self::respond(422, self::OTHER_PRODUCT_SAME_ITEM, $item, $product);
    }

    // Код занят живым заказом (не отменённым): продать единицу дважды нельзя.
    $occupied = AssemblyItemMarkingCode::query()
      ->occupied()
      ->where('assembly_item_marking_codes.code', $code)
      ->where('assembly_item_marking_codes.assembly_item_id', '!=', $item->id)
      ->first();
    if ($occupied) {
      $orderLabel = $occupied->occupied_order_uuid ?: $occupied->occupied_order_id;

      return self::respond(422, "Код уже отсканирован в заказе №{$orderLabel}", $item, $product);
    }

    $barcodes = MarkingCodeGtin::productBarcodes($product);
    if (!$barcodes) {
      return self::respond(422, self::NO_BARCODE, $item, $product);
    }

    // Дешёвая локальная сверка: GTIN из кода GS1 должен совпасть со штрихкодом
    // товара — чужой код отбиваем без похода в 1С. Марки без GTIN (ЕГАИС)
    // сверку пропускают.
    $barcode = $barcodes[0];
    $gtin = MarkingCodeGtin::extract($code);
    if ($gtin !== null) {
      $matched = MarkingCodeGtin::matchingBarcode($gtin, $barcodes);
      if ($matched === null) {
        return self::respond(422, self::FOREIGN_GTIN, $item, $product);
      }
      $barcode = $matched;
    }

    $result = $this->client->check($barcode, $code);

    Log::info('HonestSign: скан кода маркировки', [
      'assembly_item_id' => $item->id,
      'order_id' => $item->assembly?->order_id,
      'product_id' => $product->id,
      'barcode' => $barcode,
      'cis' => $code,
      'available' => $result->available,
      'allowed' => $result->allowed,
      'message' => $result->message,
    ]);

    if (!$result->available) {
      return self::respond(503, self::SERVICE_UNAVAILABLE, $item, $product);
    }

    if (!$result->allowed) {
      return self::respond(422, $result->message ?: HonestSignClient::DEFAULT_REJECT_MESSAGE, $item, $product);
    }

    $item->markingCodes()->create([
      'order_item_id' => $item->order_item_id,
      'product_id' => $product->id,
      'code' => $code,
      'barcode' => $barcode,
      'verified_at' => now(),
      'verify_message' => $result->message !== null ? mb_substr($result->message, 0, 255) : null,
    ]);

    return self::respond(200, 'OK', $item, $product);
  }

  /**
   * Ответ с актуальным состоянием позиции: список кодов и сколько требуется.
   */
  public static function respond(int $code, string $message, ?AssemblyItem $item = null, ?Product $product = null): array
  {
    $codes = $item
      ? $item->markingCodes()->orderBy('id')->get()
      : collect();

    $required = 0;
    if ($item && $product) {
      $required = MarkingRequirement::enabled()
        ? MarkingRequirement::required($product, $item->quantity, $item->actual_weight, $item->orderItem)
        : 0;
    }

    return [
      'code' => $code,
      'message' => $message,
      'marking_codes' => $codes,
      'marking_codes_required' => $required,
    ];
  }
}
