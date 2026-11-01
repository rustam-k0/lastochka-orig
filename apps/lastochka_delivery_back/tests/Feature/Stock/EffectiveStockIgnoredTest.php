<?php

namespace Tests\Feature\Stock;

use App\GraphQL\Validators\Assembly\ProductAddToOrderValidator;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ProductHasStockRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Execution\Arguments\Argument;
use Nuwave\Lighthouse\Execution\Arguments\ArgumentSet;
use Tests\TestCase;

/**
 * Per-product effective_stock_mode в курьерке (LASTOCHKI-JAMI-11207).
 *
 * Колонка products.effective_stock_mode материализуется admin_back'ом (Tasks 1-10)
 * в общей БД. Курьерка читает её через accessor Product::effectiveStockIgnored():
 * режимы 'ignore' и 'fixed' = «не блокировать сборку/выдачу остатком». Дневным
 * лимитом 'fixed' рулит витрина, курьерке достаточно не блокировать.
 */
class EffectiveStockIgnoredTest extends TestCase
{
  use DatabaseTransactions;

  /** Магазин-фикстура с явным id и ignore_stock=0 (per-shop НЕ влияет). */
  private function makeShop(int $id): Shop
  {
    return Shop::forceCreate([
      'id' => $id,
      'title' => 'Stock test shop ' . $id,
      'id_1c' => $id,
      'ignore_stock' => 0,
    ]);
  }

  /** Товар-фикстура с заданным effective_stock_mode. */
  private function makeProduct(int $shopId, string $mode): Product
  {
    return Product::forceCreate([
      'title' => 'Stock product ' . uniqid(),
      'articul' => 'S-' . uniqid(),
      'unit_type_id' => 1,
      'status_id' => 1,
      'shop_id' => $shopId,
      'effective_stock_mode' => $mode,
    ]);
  }

  public function test_accessor_true_for_ignore_and_fixed(): void
  {
    $shop = $this->makeShop(990307);

    $ignore = $this->makeProduct($shop->id, 'ignore');
    $fixed = $this->makeProduct($shop->id, 'fixed');
    $normal = $this->makeProduct($shop->id, 'normal');

    $this->assertTrue($ignore->effectiveStockIgnored());
    $this->assertTrue($fixed->effectiveStockIgnored());
    $this->assertFalse($normal->effectiveStockIgnored());
  }

  public function test_stock_rule_passes_for_effective_ignore(): void
  {
    $shop = $this->makeShop(990308);
    // Товар с effective ignore и БЕЗ записи в remains.
    $product = $this->makeProduct($shop->id, 'ignore');

    // Магазин остатки учитывает (ignore_stock=0), но товар в режиме ignore →
    // правило обязано пропустить даже при отсутствии остатка.
    $rule = new ProductHasStockRule($shop->fresh());

    $this->assertTrue($rule->passes('product_id', $product->id));
  }

  public function test_stock_rule_blocks_normal_product_without_remain(): void
  {
    $shop = $this->makeShop(990309);
    // Обычный товар без остатка — правило должно ЗАблокировать (контроль регрессии).
    $product = $this->makeProduct($shop->id, 'normal');

    $rule = new ProductHasStockRule($shop->fresh());

    $this->assertFalse($rule->passes('product_id', $product->id));
  }

  /**
   * Извлекает stock-замыкание правила product_id из ProductAddToOrderValidator
   * и прогоняет его, собирая вызовы $fail. Возвращает список ошибок (пустой = прошёл).
   *
   * @return array<int, string>
   */
  private function runValidatorProductIdClosure(int $assemblyId, int $productId): array
  {
    $validator = new ProductAddToOrderValidator();

    $argSet = new ArgumentSet();
    $assemblyArg = new Argument();
    $assemblyArg->value = $assemblyId;
    $argSet->arguments['assembly_id'] = $assemblyArg;
    $productArg = new Argument();
    $productArg->value = $productId;
    $argSet->arguments['product_id'] = $productArg;
    $validator->setArgs($argSet);

    // Последнее замыкание в правилах product_id — проверка принадлежности+наличия.
    $rules = $validator->rules()['product_id'];
    $closure = null;
    foreach ($rules as $rule) {
      if ($rule instanceof \Closure) {
        $closure = $rule;
      }
    }
    $this->assertNotNull($closure, 'closure-правило product_id не найдено в валидаторе');

    $errors = [];
    $fail = function ($message) use (&$errors) {
      $errors[] = $message;
    };
    $closure('product_id', $productId, $fail);

    return $errors;
  }

  /**
   * Точка 7: ProductAddToOrderValidator — второй stock-existence гейт supplement-мутации.
   *
   * Реально прогоняем closure-правило валидатора (с auth + Assembly + Order + Shop)
   * для effective-ignore товара БЕЗ положительного remain: гейт обязан пропустить
   * (не выдать «Товар отсутствует на складе»). Контрольный обычный товар — валится.
   */
  public function test_validator_passes_effective_ignore_product_without_remain(): void
  {
    $userId = \DB::table('users')->insertGetId([
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $this->actingAs(User::find($userId));

    $shop = $this->makeShop(990310);

    $clientId = \DB::table('clients')->insertGetId([
      'is_authorized' => 1,
      'newsletter_subscribed' => 0,
      'password' => 'test',
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $orderId = \DB::table('orders')->insertGetId([
      'shop_id' => $shop->id,
      'uuid' => 'stock-' . uniqid(),
      'payment_type_id' => 1,
      'type_id' => 2,
      'client_id' => $clientId,
      'status_id' => 2,
      'created_at' => now(),
      'updated_at' => now(),
    ]);
    $assemblyId = \DB::table('assemblies')->insertGetId([
      'order_id' => $orderId,
      'user_id' => $userId,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    // effective-ignore товар магазина заказа, БЕЗ remain.
    $ignoreProduct = $this->makeProduct($shop->id, 'ignore');
    $errors = $this->runValidatorProductIdClosure($assemblyId, $ignoreProduct->id);
    $this->assertSame([], $errors, 'effective-ignore товар не должен валиться по остатку: ' . implode('; ', $errors));

    // Контроль: обычный товар того же магазина без remain — валидатор обязан зарубить.
    $normalProduct = $this->makeProduct($shop->id, 'normal');
    $normalErrors = $this->runValidatorProductIdClosure($assemblyId, $normalProduct->id);
    $this->assertContains('Товар отсутствует на складе', $normalErrors);
  }
}
