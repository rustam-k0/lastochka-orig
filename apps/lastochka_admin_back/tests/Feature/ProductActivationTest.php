<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\Product\ProductHide;
use App\GraphQL\Mutations\Product\ProductPublish;
use App\Models\Product;
use App\Models\ProductHistory;
use App\Models\Status;
use App\Models\User;
use App\Transformers\ProductEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * LASTOCHKI-JAMI-11983: товар, деактивированный вручную в админке, на следующий
 * день снова становился активным. Root cause — ProductEventTransformer::fromEvent()
 * ставил status_id из ключа `active`, который 1c_parser кладёт БЕЗУСЛОВНО в каждое
 * product.updated (его собственный is_active никогда не false), а полное
 * product.updated публикуется 1С при любом рутинном изменении каталожного поля
 * (название/штрихкод/категория/атрибуты) — то есть регулярно, не только на create.
 *
 * Фикс: beforeUpdate() убирает status_id из transformedData — автоматика больше
 * не может менять активность существующего товара, только ProductHide/ProductPublish.
 * Начальный статус нового товара из 1С по-прежнему берётся из fromEvent() (create-only).
 */
class ProductActivationTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected ProductEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ProductEventTransformer();
    }

    protected function makeProduct(array $attrs = []): Product
    {
        return Product::query()->create(array_merge([
            'title' => 'Activation Product ' . uniqid(),
            'shop_id' => null,
            'status_id' => Status::CODE_ACTIVE,
        ], $attrs));
    }

    protected function baseUpdateEvent(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'test-event-id',
            'id' => 'ext-1',
            'shop_code' => 'djami_store',
            'source' => '1c',
            'sku' => 'SKU-ACT-1',
            'title' => 'Товар из 1С',
            'price' => 100,
            'unit_type' => 'piece',
            // 1c_parser кладёт active БЕЗУСЛОВНО (см. 1c_parser
            // ProductEventTransformer::toEvent:132) и его собственный
            // is_active практически никогда не false — поэтому в тесте
            // это ровно то значение, которое реально приходит в 99% событий.
            'active' => true,
        ], $overrides);
    }

    /** @test */
    public function product_hide_deactivates_and_logs_history()
    {
        $product = $this->makeProduct(['status_id' => Status::CODE_ACTIVE]);

        $response = (new ProductHide())(null, ['id' => $product->id]);

        $this->assertSame(200, $response['code']);
        $this->assertSame(Status::CODE_NOT_PUBLISHED, $product->fresh()->status_id);

        $history = ProductHistory::where('product_id', $product->id)
            ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
            ->latest('id')
            ->first();

        $this->assertNotNull($history, 'ProductHide должен фиксировать изменение статуса в истории');
        $this->assertSame(ProductHistory::ACTOR_STAFF, $history->actor_type);
        $this->assertSame((string) Status::CODE_ACTIVE, $history->old_value);
        $this->assertSame((string) Status::CODE_NOT_PUBLISHED, $history->new_value);
    }

    /** @test */
    public function product_publish_activates_and_logs_history()
    {
        $product = $this->makeProduct(['status_id' => Status::CODE_NOT_PUBLISHED]);

        $response = (new ProductPublish())(null, ['id' => $product->id]);

        $this->assertSame(200, $response['code']);
        $this->assertSame(Status::CODE_ACTIVE, $product->fresh()->status_id);

        $history = ProductHistory::where('product_id', $product->id)
            ->where('action', ProductHistory::ACTION_STATUS_CHANGED)
            ->latest('id')
            ->first();

        $this->assertNotNull($history, 'ProductPublish должен фиксировать изменение статуса в истории');
        $this->assertSame(ProductHistory::ACTOR_STAFF, $history->actor_type);
        $this->assertSame((string) Status::CODE_NOT_PUBLISHED, $history->old_value);
        $this->assertSame((string) Status::CODE_ACTIVE, $history->new_value);
    }

    /** @test */
    public function product_hide_twice_does_not_duplicate_history_entry()
    {
        $product = $this->makeProduct(['status_id' => Status::CODE_ACTIVE]);

        (new ProductHide())(null, ['id' => $product->id]);
        $countAfterFirst = ProductHistory::where('product_id', $product->id)->count();

        (new ProductHide())(null, ['id' => $product->id]);
        $countAfterSecond = ProductHistory::where('product_id', $product->id)->count();

        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'Повторный ProductHide на уже скрытый товар не должен плодить записи истории без реального изменения статуса'
        );
    }

    /** @test */
    public function before_update_strips_status_id_even_when_active_true_in_event()
    {
        $product = $this->makeProduct(['status_id' => Status::CODE_ACTIVE]);
        (new ProductHide())(null, ['id' => $product->id]);
        $this->assertSame(Status::CODE_NOT_PUBLISHED, $product->fresh()->status_id);

        $event = $this->baseUpdateEvent(['active' => true]);
        $transformed = $this->transformer->fromEvent($event);

        // fromEvent() сам по себе ещё ставит status_id из active — это ожидаемо,
        // именно это и было бы дырой без beforeUpdate.
        $this->assertArrayHasKey('status_id', $transformed);

        $result = $this->transformer->beforeUpdate($product, $event, $transformed);

        $this->assertArrayNotHasKey(
            'status_id',
            $result,
            'beforeUpdate должен убирать status_id — входящее событие с active:true не имеет права реактивировать вручную скрытый товар'
        );
    }

    /** @test */
    public function manual_deactivation_survives_simulated_1c_updated_event()
    {
        $product = $this->makeProduct(['status_id' => Status::CODE_ACTIVE]);
        (new ProductHide())(null, ['id' => $product->id]);
        $this->assertSame(Status::CODE_NOT_PUBLISHED, $product->fresh()->status_id);

        // Симулируем то, что реально делает EventConsumer при product.updated:
        // fromEvent -> beforeUpdate -> forceFill()->save().
        $event = $this->baseUpdateEvent(['active' => true, 'title' => 'Переименовано в 1С']);
        $transformed = $this->transformer->fromEvent($event);
        $transformed = $this->transformer->beforeUpdate($product, $event, $transformed);
        $product->forceFill($transformed)->save();

        $this->assertSame(
            Status::CODE_NOT_PUBLISHED,
            $product->fresh()->status_id,
            'Товар должен оставаться выключенным после входящего события синхронизации с active:true'
        );
        // Остальные поля из события всё равно применяются (partial-update продолжает работать).
        $this->assertSame('Переименовано в 1С', $product->fresh()->title);
    }

    /** @test */
    public function before_create_does_not_strip_status_id_for_new_products()
    {
        $event = $this->baseUpdateEvent(['active' => false]);
        $transformed = $this->transformer->fromEvent($event);

        $result = $this->transformer->beforeCreate($event, $transformed);

        $this->assertArrayHasKey(
            'status_id',
            $result,
            'Начальный статус НОВОГО товара из 1С должен по-прежнему браться из active — только update защищён'
        );
        $this->assertSame(Status::CODE_NOT_PUBLISHED, $result['status_id']);
    }

    /** @test */
    public function product_hide_and_publish_work_through_graphql_endpoint()
    {
        $this->actingAs($this->adminUser());
        $product = $this->makeProduct(['status_id' => Status::CODE_ACTIVE]);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int) {
                ProductHide(id: $id) { code }
            }
        ', ['id' => $product->id])->assertJsonPath('data.ProductHide.code', 200);

        $this->assertSame(Status::CODE_NOT_PUBLISHED, $product->fresh()->status_id);

        $this->graphQL(/** @lang GraphQL */ '
            mutation ($id: Int) {
                ProductPublish(id: $id) { code }
            }
        ', ['id' => $product->id])->assertJsonPath('data.ProductPublish.code', 200);

        $this->assertSame(Status::CODE_ACTIVE, $product->fresh()->status_id);
    }
}
