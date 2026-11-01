<?php

namespace Tests\Feature\Transformers;

use App\Models\Category1c;
use App\Models\Shop;
use App\Transformers\Category1cEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты Category1cEventTransformer (consumer-side).
 *
 * Покрывают:
 *  - fromEvent: array_key_exists-парадигма (только присутствующие поля)
 *  - findExistingEntity: лукап по (shop_id, external_id), withTrashed
 *  - afterUpdate: резолв parent_id через external_id parent'а
 *  - afterUpdate: restore() для soft-deleted записи (1С прислал .updated после .deleted)
 *  - afterCreate: резолв parent_id
 *
 * Контекст: docs/superpowers/specs/2026-05-05-1c-categories-mapping-design.md §4.2.2.
 */
class Category1cEventTransformerTest extends TestCase
{
    use DatabaseTransactions;

    private Category1cEventTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new Category1cEventTransformer();
        // Сброс кэша shop_code → shop_id между тестами
        Category1cEventTransformer::clearShopCodeCache();
    }

    private function makeShop(string $code): Shop
    {
        return Shop::query()->create([
            'title' => 'fixture-shop-' . $code,
            'code' => $code,
            'address' => 'fixture',
            'phone' => '+70000000000',
        ]);
    }

    /** @test */
    public function from_event_maps_present_fields_only(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());

        $attrs = $this->transformer->fromEvent([
            'event_id' => 'evt-1',
            'id' => 'ext-100',
            'shop_code' => $shop->code,
            'name' => 'Хлеб',
            'position' => 5,
            'active' => true,
        ]);

        $this->assertSame($shop->id, $attrs['shop_id']);
        $this->assertSame('ext-100', $attrs['external_id']);
        $this->assertSame('Хлеб', $attrs['name']);
        $this->assertSame(5, $attrs['position']);
        $this->assertTrue($attrs['is_active']);
        $this->assertArrayHasKey('synced_from_1c_at', $attrs);
    }

    /** @test */
    public function from_event_does_not_include_absent_optional_fields(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());

        $attrs = $this->transformer->fromEvent([
            'id' => 'ext-200',
            'shop_code' => $shop->code,
            // name/position/active отсутствуют — partial event
        ]);

        $this->assertArrayHasKey('shop_id', $attrs);
        $this->assertArrayHasKey('external_id', $attrs);
        $this->assertArrayNotHasKey('name', $attrs);
        $this->assertArrayNotHasKey('position', $attrs);
        $this->assertArrayNotHasKey('is_active', $attrs);
    }

    /** @test */
    public function find_existing_entity_returns_record_by_shop_and_external_id(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $existing = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'ext-300',
            'name' => 'Существующая',
        ]);

        $found = $this->transformer->findExistingEntity([
            'id' => 'ext-300',
            'shop_code' => $shop->code,
        ], []);

        $this->assertNotNull($found);
        $this->assertSame($existing->id, $found->id);
    }

    /** @test */
    public function find_existing_entity_returns_soft_deleted_via_with_trashed(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $existing = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'ext-310',
        ]);
        $existing->delete();
        $this->assertNotNull($existing->fresh() === null ? null : $existing); // sanity

        $found = $this->transformer->findExistingEntity([
            'id' => 'ext-310',
            'shop_code' => $shop->code,
        ], []);

        $this->assertNotNull($found, 'withTrashed должен находить soft-deleted запись');
        $this->assertSame($existing->id, $found->id);
        $this->assertNotNull($found->deleted_at);
    }

    /** @test */
    public function find_existing_entity_returns_null_when_no_match(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());

        $found = $this->transformer->findExistingEntity([
            'id' => 'never-existed',
            'shop_code' => $shop->code,
        ], []);

        $this->assertNull($found);
    }

    /** @test */
    public function after_create_resolves_parent_id_via_external_id_lookup(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'parent-ext-1',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-ext-1',
            'parent_id' => null,
        ]);

        $this->transformer->afterCreate($child, [
            'id' => 'child-ext-1',
            'shop_code' => $shop->code,
            'parent_id' => 'parent-ext-1',
        ]);

        $child->refresh();
        $this->assertSame($parent->id, $child->parent_id);
    }

    /** @test */
    public function after_update_resolves_parent_id_via_external_id_lookup(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'parent-ext-2',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-ext-2',
            'parent_id' => null,
        ]);

        $this->transformer->afterUpdate($child, [
            'id' => 'child-ext-2',
            'shop_code' => $shop->code,
            'parent_id' => 'parent-ext-2',
        ]);

        $child->refresh();
        $this->assertSame($parent->id, $child->parent_id);
    }

    /** @test */
    public function after_update_clears_parent_when_payload_parent_id_is_null(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'parent-ext-3',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-ext-3',
            'parent_id' => $parent->id,
        ]);

        $this->transformer->afterUpdate($child, [
            'id' => 'child-ext-3',
            'shop_code' => $shop->code,
            'parent_id' => null,
        ]);

        $child->refresh();
        $this->assertNull($child->parent_id);
    }

    /** @test */
    public function after_update_does_not_touch_parent_when_payload_lacks_parent_id(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'parent-ext-4',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-ext-4',
            'parent_id' => $parent->id,
        ]);

        // partial event без parent_id — текущая привязка не должна пострадать
        $this->transformer->afterUpdate($child, [
            'id' => 'child-ext-4',
            'shop_code' => $shop->code,
        ]);

        $child->refresh();
        $this->assertSame($parent->id, $child->parent_id);
    }

    /** @test */
    public function after_update_leaves_parent_null_when_parent_external_not_found(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-ext-5',
            'parent_id' => null,
        ]);

        // parent ещё не приехал — оставляем NULL без ошибок
        $this->transformer->afterUpdate($child, [
            'id' => 'child-ext-5',
            'shop_code' => $shop->code,
            'parent_id' => 'unknown-parent-ext',
        ]);

        $child->refresh();
        $this->assertNull($child->parent_id);
    }

    /** @test */
    public function after_update_restores_soft_deleted_record(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $entity = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'restored-ext-1',
        ]);
        $entity->delete();
        $this->assertTrue($entity->trashed());

        $this->transformer->afterUpdate($entity, [
            'id' => 'restored-ext-1',
            'shop_code' => $shop->code,
        ]);

        $entity->refresh();
        $this->assertFalse($entity->trashed(), 'afterUpdate должен восстанавливать soft-deleted запись');
        $this->assertNull($entity->deleted_at);
    }

    /** @test */
    public function after_create_stores_parent_external_id_when_parent_not_yet_arrived(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-pending-1',
            'parent_id' => null,
        ]);

        $this->transformer->afterCreate($child, [
            'id' => 'child-pending-1',
            'shop_code' => $shop->code,
            'parent_id' => 'parent-not-arrived-yet',
        ]);

        $child->refresh();
        $this->assertNull($child->parent_id);
        $this->assertSame('parent-not-arrived-yet', $child->parent_external_id);
    }

    /** @test */
    public function after_create_stores_parent_external_id_when_parent_resolved(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'parent-ext-guid-1',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-resolved-1',
            'parent_id' => null,
        ]);

        $this->transformer->afterCreate($child, [
            'id' => 'child-resolved-1',
            'shop_code' => $shop->code,
            'parent_id' => 'parent-ext-guid-1',
        ]);

        $child->refresh();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('parent-ext-guid-1', $child->parent_external_id);
    }

    /** @test */
    public function after_update_clears_parent_external_id_when_payload_parent_null(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'parent-ext-clear',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-clear-1',
            'parent_id' => $parent->id,
            'parent_external_id' => 'parent-ext-clear',
        ]);

        $this->transformer->afterUpdate($child, [
            'id' => 'child-clear-1',
            'shop_code' => $shop->code,
            'parent_id' => null,
        ]);

        $child->refresh();
        $this->assertNull($child->parent_id);
        $this->assertNull($child->parent_external_id);
    }

    /** @test */
    public function after_update_treats_empty_string_parent_as_root(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'parent-ext-empty',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-empty-1',
            'parent_id' => $parent->id,
            'parent_external_id' => 'parent-ext-empty',
        ]);

        $this->transformer->afterUpdate($child, [
            'id' => 'child-empty-1',
            'shop_code' => $shop->code,
            'parent_id' => '',
        ]);

        $child->refresh();
        $this->assertNull($child->parent_id, 'Пустая строка = sentinel «корень», как null');
        $this->assertNull($child->parent_external_id);
    }

    /** @test */
    public function after_create_adopts_waiting_children_when_parent_arrives(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        // Дети приехали РАНЬШЕ родителя (порядок дампа 1С не гарантирован) —
        // parent_id NULL, parent GUID запомнен в parent_external_id.
        $childA = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'orphan-a',
            'parent_id' => null,
            'parent_external_id' => 'late-parent-guid',
        ]);
        $childB = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'orphan-b',
            'parent_id' => null,
            'parent_external_id' => 'late-parent-guid',
        ]);
        $unrelated = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'orphan-other',
            'parent_id' => null,
            'parent_external_id' => 'another-parent-guid',
        ]);

        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'late-parent-guid',
        ]);

        $this->transformer->afterCreate($parent, [
            'id' => 'late-parent-guid',
            'shop_code' => $shop->code,
        ]);

        $this->assertSame($parent->id, $childA->fresh()->parent_id);
        $this->assertSame($parent->id, $childB->fresh()->parent_id);
        $this->assertNull($unrelated->fresh()->parent_id, 'Чужие сироты не должны цепляться');
    }

    /** @test */
    public function adoption_is_shop_scoped(): void
    {
        $shopA = $this->makeShop('cat1c-shop-a-' . uniqid());
        $shopB = $this->makeShop('cat1c-shop-b-' . uniqid());
        $foreignChild = Category1c::factory()->create([
            'shop_id' => $shopB->id,
            'external_id' => 'foreign-child',
            'parent_id' => null,
            'parent_external_id' => 'shared-parent-guid',
        ]);

        $parent = Category1c::factory()->create([
            'shop_id' => $shopA->id,
            'external_id' => 'shared-parent-guid',
        ]);

        $this->transformer->afterCreate($parent, [
            'id' => 'shared-parent-guid',
            'shop_code' => $shopA->code,
        ]);

        $this->assertNull(
            $foreignChild->fresh()->parent_id,
            'Ребёнок другого магазина не должен усыновляться'
        );
    }

    /** @test */
    public function after_update_restore_adopts_waiting_children(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $parent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'revived-parent-guid',
        ]);
        $parent->delete();

        $orphan = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'orphan-of-revived',
            'parent_id' => null,
            'parent_external_id' => 'revived-parent-guid',
        ]);

        $this->transformer->afterUpdate($parent, [
            'id' => 'revived-parent-guid',
            'shop_code' => $shop->code,
        ]);

        $this->assertFalse($parent->fresh()->trashed());
        $this->assertSame($parent->id, $orphan->fresh()->parent_id);
    }

    /** @test */
    public function adoption_relinks_child_with_stale_parent_link(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $oldParent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'old-parent-guid',
        ]);
        // 1С перенесла ребёнка под нового родителя, который ещё не приехал:
        // parent_external_id обновился, parent_id остался старым (осознанно —
        // лучше устаревшая ветка, чем мигание в корень).
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'moved-child',
            'parent_id' => $oldParent->id,
            'parent_external_id' => 'new-parent-guid',
        ]);

        $newParent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'new-parent-guid',
        ]);

        $this->transformer->afterCreate($newParent, [
            'id' => 'new-parent-guid',
            'shop_code' => $shop->code,
        ]);

        $this->assertSame($newParent->id, $child->fresh()->parent_id);
    }

    /** @test */
    public function after_update_keeps_stale_parent_but_stores_new_guid_when_unresolved(): void
    {
        $shop = $this->makeShop('cat1c-shop-' . uniqid());
        $oldParent = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'old-parent-keep',
        ]);
        $child = Category1c::factory()->create([
            'shop_id' => $shop->id,
            'external_id' => 'child-keep',
            'parent_id' => $oldParent->id,
            'parent_external_id' => 'old-parent-keep',
        ]);

        $this->transformer->afterUpdate($child, [
            'id' => 'child-keep',
            'shop_code' => $shop->code,
            'parent_id' => 'unarrived-new-parent',
        ]);

        $child->refresh();
        $this->assertSame($oldParent->id, $child->parent_id, 'Старая связь не рвётся до приезда нового родителя');
        $this->assertSame('unarrived-new-parent', $child->parent_external_id);
    }

    /** @test */
    public function validate_requires_id_and_shop_code(): void
    {
        $this->assertTrue($this->transformer->validate([
            'id' => 'ext-1',
            'shop_code' => 'shop-1',
        ]));
        $this->assertFalse($this->transformer->validate(['id' => 'ext-1']));
        $this->assertFalse($this->transformer->validate(['shop_code' => 'shop-1']));
        $this->assertFalse($this->transformer->validate([]));
    }
}
