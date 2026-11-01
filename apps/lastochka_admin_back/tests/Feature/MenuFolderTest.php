<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MenuFolderTest extends TestCase
{
    use DatabaseTransactions;
    use \Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

    private function setFlag(string $value): void
    {
        AdminSetting::setValue('role_access_matrix_enabled', $value, 'boolean');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function actingAsAdmin(): User
    {
        $this->setFlag('0');
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate(Role::ADMIN));

        return $admin;
    }

    public function test_menu_item_children_relation_returns_items_ordered_by_position(): void
    {
        $folder = MenuItem::create([
            'name' => 'test-folder-relation',
            'title' => 'Тестовая папка',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        MenuItem::create([
            'name' => 'test-child-2',
            'title' => 'Дочерний 2',
            'position' => 1,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        MenuItem::create([
            'name' => 'test-child-1',
            'title' => 'Дочерний 1',
            'position' => 0,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        $children = $folder->fresh()->children;

        $this->assertCount(2, $children);
        $this->assertSame('test-child-1', $children->first()->name);
        $this->assertSame('test-child-2', $children->last()->name);
    }

    public function test_next_position_scopes_by_parent_id(): void
    {
        $folder = MenuItem::create([
            'name' => 'test-folder-nextpos',
            'title' => 'Папка',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        // В пустой (внутри теста) папке следующая позиция для детей — 0.
        $emptyFolderNext = MenuItem::nextPosition($folder->id);
        $this->assertSame(0, $emptyFolderNext);

        // Создаём два ребёнка с позициями 0 и 1.
        MenuItem::create([
            'name' => 'test-child-a',
            'title' => 'Дочерний А',
            'position' => 0,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        MenuItem::create([
            'name' => 'test-child-b',
            'title' => 'Дочерний Б',
            'position' => 1,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        // Следующая позиция в непустой папке должна быть 2 (max + 1).
        $populatedFolderNext = MenuItem::nextPosition($folder->id);
        $this->assertSame(2, $populatedFolderNext);
    }

    public function test_create_menu_folder_creates_folder_row(): void
    {
        $admin = $this->actingAsAdmin();
        $expectedPosition = MenuItem::nextPosition(null);

        $response = $this->actingAs($admin)->graphQL('
            mutation {
              createMenuFolder(title: "Продажи и акции", icon: "price-tag-3-line") {
                name title icon is_folder parent_id position
              }
            }');

        $response->assertJsonPath('data.createMenuFolder.title', 'Продажи и акции');
        $response->assertJsonPath('data.createMenuFolder.is_folder', true);
        $response->assertJsonPath('data.createMenuFolder.parent_id', null);
        $response->assertJsonPath('data.createMenuFolder.position', $expectedPosition);

        $this->assertDatabaseHas('menu_items', [
            'name' => 'prodazi-i-akcii',
            'is_folder' => true,
            'position' => $expectedPosition,
        ]);
    }

    public function test_create_menu_folder_generates_unique_name_on_title_collision(): void
    {
        $admin = $this->actingAsAdmin();
        MenuItem::create([
            'name' => 'akcii',
            'title' => 'Акции',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        $response = $this->actingAs($admin)->graphQL('
            mutation { createMenuFolder(title: "Акции") { name } }');

        $this->assertNotSame('akcii', $response->json('data.createMenuFolder.name'));
    }

    public function test_create_menu_folder_defaults_icon_to_folder_line(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->graphQL('
            mutation { createMenuFolder(title: "Без иконки") { icon } }');

        $response->assertJsonPath('data.createMenuFolder.icon', 'folder-line');
    }

    public function test_delete_menu_folder_promotes_children_and_removes_folder(): void
    {
        $admin = $this->actingAsAdmin();

        $folder = MenuItem::create([
            'name' => 'test-folder-delete',
            'title' => 'Папка на удаление',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        $child = MenuItem::create([
            'name' => 'test-child-delete',
            'title' => 'Дочерний пункт',
            'position' => 0,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        $response = $this->actingAs($admin)->graphQL('
            mutation { deleteMenuFolder(name: "test-folder-delete") }');

        $response->assertJsonPath('data.deleteMenuFolder', true);
        $this->assertDatabaseMissing('menu_items', ['name' => 'test-folder-delete']);

        $child->refresh();
        $this->assertNull($child->parent_id);
    }

    public function test_delete_menu_folder_recalculates_positions_for_promoted_children(): void
    {
        $admin = $this->actingAsAdmin();

        // Create a pre-existing root-level item to establish a baseline position
        $preexistingItem = MenuItem::create([
            'name' => 'existing-root-item',
            'title' => 'Существующий пункт',
            'position' => MenuItem::nextPosition(null), // Position will be 0
            'is_visible' => true,
            'accesses' => ['admin'],
        ]);

        // Create a folder (will have position 1 at root level)
        $folder = MenuItem::create([
            'name' => 'test-folder-positions',
            'title' => 'Папка для теста позиций',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        // Create two children within the folder at positions 0 and 1
        $child1 = MenuItem::create([
            'name' => 'test-child-pos-0',
            'title' => 'Первый дочерний',
            'position' => 0,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        $child2 = MenuItem::create([
            'name' => 'test-child-pos-1',
            'title' => 'Второй дочерний',
            'position' => 1,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        // Delete the folder
        $response = $this->actingAs($admin)->graphQL('
            mutation { deleteMenuFolder(name: "test-folder-positions") }');

        $response->assertJsonPath('data.deleteMenuFolder', true);

        // Verify folder is deleted
        $this->assertDatabaseMissing('menu_items', ['name' => 'test-folder-positions']);

        // Refresh and verify children are promoted to root level
        $child1->refresh();
        $child2->refresh();

        $this->assertNull($child1->parent_id);
        $this->assertNull($child2->parent_id);

        // Verify positions are recalculated correctly and distinct
        $this->assertNotNull($child1->position);
        $this->assertNotNull($child2->position);
        $this->assertNotEquals($child1->position, $child2->position, 'Promoted children must have distinct positions');

        // Verify positions are appended after the maximum existing position before deletion
        // Both children should have positions greater than all previously existing root items
        $allRootItems = MenuItem::where('parent_id', null)->orderBy('position')->get();
        $maxPositionBefore = $allRootItems->where('name', '!=', 'test-child-pos-0')
            ->where('name', '!=', 'test-child-pos-1')
            ->max('position');

        $this->assertGreaterThan($maxPositionBefore, $child1->position);
        $this->assertGreaterThan($maxPositionBefore, $child2->position);

        // Verify the two children have sequential positions relative to each other
        $childPositions = collect([$child1->position, $child2->position])->sort()->values()->toArray();
        $this->assertEquals(1, $childPositions[1] - $childPositions[0], 'Promoted children should have sequential positions');
    }

    public function test_delete_menu_folder_rejects_non_folder_item(): void
    {
        $admin = $this->actingAsAdmin();
        MenuItem::create([
            'name' => 'test-plain-item-delete',
            'title' => 'Обычный пункт',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => ['admin'],
        ]);

        $response = $this->actingAs($admin)->graphQL('
            mutation { deleteMenuFolder(name: "test-plain-item-delete") }');

        $response->assertGraphQLErrorMessage('Папка с таким названием не найдена.');
    }

    public function test_delete_menu_folder_rejects_unknown_name(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->graphQL('
            mutation { deleteMenuFolder(name: "does-not-exist") }');

        $response->assertGraphQLErrorMessage('Папка с таким названием не найдена.');
    }

    public function test_assign_menu_item_to_folder_sets_parent(): void
    {
        $admin = $this->actingAsAdmin();
        $folder = MenuItem::create([
            'name' => 'test-folder-assign',
            'title' => 'Папка',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);
        $item = MenuItem::create([
            'name' => 'test-item-assign',
            'title' => 'Пункт',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => ['admin'],
        ]);

        $expectedPosition = MenuItem::nextPosition($folder->id);

        $response = $this->actingAs($admin)->graphQL('
            mutation {
              assignMenuItemToFolder(name: "test-item-assign", parentName: "test-folder-assign") {
                parent_id
                position
              }
            }');

        $response->assertJsonPath('data.assignMenuItemToFolder.parent_id', (string) $folder->id);
        $response->assertJsonPath('data.assignMenuItemToFolder.position', $expectedPosition);
        $item->refresh();
        $this->assertSame($folder->id, $item->parent_id);
        $this->assertSame($expectedPosition, $item->position);
    }

    public function test_assign_menu_item_to_folder_with_null_parent_moves_to_root(): void
    {
        $admin = $this->actingAsAdmin();
        $folder = MenuItem::create([
            'name' => 'test-folder-ungroup',
            'title' => 'Папка',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);
        $item = MenuItem::create([
            'name' => 'test-item-ungroup',
            'title' => 'Пункт',
            'position' => 0,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        $response = $this->actingAs($admin)->graphQL('
            mutation { assignMenuItemToFolder(name: "test-item-ungroup", parentName: null) { parent_id } }');

        $response->assertJsonPath('data.assignMenuItemToFolder.parent_id', null);
        $item->refresh();
        $this->assertNull($item->parent_id);
    }

    public function test_assign_menu_item_to_folder_rejects_nesting_folder_in_folder(): void
    {
        $admin = $this->actingAsAdmin();
        $folderA = MenuItem::create([
            'name' => 'test-folder-a',
            'title' => 'Папка A',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);
        MenuItem::create([
            'name' => 'test-folder-b',
            'title' => 'Папка B',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        $response = $this->actingAs($admin)->graphQL('
            mutation { assignMenuItemToFolder(name: "test-folder-b", parentName: "test-folder-a") { name } }');

        $response->assertGraphQLErrorMessage('Папку нельзя вложить в другую папку.');
    }

    public function test_assign_menu_item_to_folder_rejects_unknown_parent(): void
    {
        $admin = $this->actingAsAdmin();
        MenuItem::create([
            'name' => 'test-item-unknown-parent',
            'title' => 'Пункт',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => ['admin'],
        ]);

        $response = $this->actingAs($admin)->graphQL('
            mutation { assignMenuItemToFolder(name: "test-item-unknown-parent", parentName: "does-not-exist") { name } }');

        $response->assertGraphQLErrorMessage('Папка с таким названием не найдена.');
    }

    public function test_assign_menu_item_to_folder_rejects_non_folder_as_parent(): void
    {
        $admin = $this->actingAsAdmin();

        // Create a plain (non-folder) item to use as parent
        $plainItem = MenuItem::create([
            'name' => 'test-plain-item-as-parent',
            'title' => 'Обычный пункт',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => ['admin'],
            'is_folder' => false,
        ]);

        // Create item to be assigned
        MenuItem::create([
            'name' => 'test-item-to-assign',
            'title' => 'Пункт для назначения',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => ['admin'],
        ]);

        // Try to assign to plain item (not a folder)
        $response = $this->actingAs($admin)->graphQL('
            mutation { assignMenuItemToFolder(name: "test-item-to-assign", parentName: "test-plain-item-as-parent") { name } }');

        $response->assertGraphQLErrorMessage('Папка с таким названием не найдена.');
    }

    public function test_assign_menu_item_to_folder_moves_between_folders(): void
    {
        $admin = $this->actingAsAdmin();

        // Create two folders
        $folderA = MenuItem::create([
            'name' => 'test-folder-a-move',
            'title' => 'Папка A',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        $folderB = MenuItem::create([
            'name' => 'test-folder-b-move',
            'title' => 'Папка B',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        // Create item initially assigned to folder A
        $item = MenuItem::create([
            'name' => 'test-item-move-between',
            'title' => 'Пункт для перемещения',
            'position' => 0,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folderA->id,
        ]);

        // A second child stays behind in folder A — it must be left untouched
        // by the move (regression guard: moving one child must not disturb
        // its siblings' parent_id/position).
        $sibling = MenuItem::create([
            'name' => 'test-item-stays-in-a',
            'title' => 'Пункт остаётся в A',
            'position' => 1,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folderA->id,
        ]);
        $siblingPositionBefore = $sibling->position;

        // Move item from folder A to folder B
        $response = $this->actingAs($admin)->graphQL('
            mutation {
              assignMenuItemToFolder(name: "test-item-move-between", parentName: "test-folder-b-move") {
                parent_id
              }
            }');

        $response->assertJsonPath('data.assignMenuItemToFolder.parent_id', (string) $folderB->id);

        // Verify in database that parent_id is now folderB's id
        $item->refresh();
        $this->assertSame($folderB->id, $item->parent_id);
        $this->assertNotSame($folderA->id, $item->parent_id);

        // The sibling left behind in folder A must be completely unaffected.
        $sibling->refresh();
        $this->assertSame($folderA->id, $sibling->parent_id);
        $this->assertSame($siblingPositionBefore, $sibling->position);
    }

    public function test_assign_menu_item_to_folder_rejects_unknown_item_name(): void
    {
        $admin = $this->actingAsAdmin();

        // Create a folder (valid parent)
        MenuItem::create([
            'name' => 'test-folder-valid',
            'title' => 'Валидная папка',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        // Try to assign non-existent item to the folder
        $response = $this->actingAs($admin)->graphQL('
            mutation { assignMenuItemToFolder(name: "does-not-exist-item", parentName: "test-folder-valid") { name } }');

        $response->assertGraphQLErrorMessage('Пункт меню с таким названием не найден.');
    }

    public function test_update_menu_item_and_reorder_menu_items_work_on_items_inside_folder(): void
    {
        $admin = $this->actingAsAdmin();

        $folder = MenuItem::create([
            'name' => 'test-folder-regression',
            'title' => 'Папка для регресса',
            'position' => MenuItem::nextPosition(null),
            'is_visible' => true,
            'accesses' => [],
            'is_folder' => true,
        ]);

        $childA = MenuItem::create([
            'name' => 'test-child-regression-a',
            'title' => 'Дочерний А',
            'position' => 0,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        $childB = MenuItem::create([
            'name' => 'test-child-regression-b',
            'title' => 'Дочерний Б',
            'position' => 1,
            'is_visible' => true,
            'accesses' => ['admin'],
            'parent_id' => $folder->id,
        ]);

        // updateMenuItem must keep working on an item nested inside a folder
        // (non-null parent_id) — spec regression requirement.
        $updateResponse = $this->actingAs($admin)->graphQL('
            mutation {
              updateMenuItem(name: "test-child-regression-a", title: "Дочерний А (переименован)", is_visible: false) {
                name title is_visible parent_id
              }
            }');

        $updateResponse->assertJsonPath('data.updateMenuItem.title', 'Дочерний А (переименован)');
        $updateResponse->assertJsonPath('data.updateMenuItem.is_visible', false);
        $updateResponse->assertJsonPath('data.updateMenuItem.parent_id', (string) $folder->id);

        $childA->refresh();
        $this->assertSame('Дочерний А (переименован)', $childA->title);
        $this->assertFalse($childA->is_visible);
        $this->assertSame($folder->id, $childA->parent_id);

        // reorderMenuItems must keep working on items nested inside a folder,
        // swapping their positions without touching parent_id.
        $reorderResponse = $this->actingAs($admin)->graphQL('
            mutation {
              reorderMenuItems(items: [
                { name: "test-child-regression-a", position: 1 },
                { name: "test-child-regression-b", position: 0 }
              ]) {
                name
              }
            }');

        $reorderJson = $reorderResponse->json();
        $this->assertArrayNotHasKey('errors', $reorderJson, 'GraphQL errors: ' . json_encode($reorderJson['errors'] ?? null, JSON_UNESCAPED_UNICODE));

        $childA->refresh();
        $childB->refresh();

        $this->assertSame(1, $childA->position);
        $this->assertSame(0, $childB->position);
        $this->assertSame($folder->id, $childA->parent_id);
        $this->assertSame($folder->id, $childB->parent_id);
    }

    public function test_create_menu_folder_truncates_long_slug_to_fit_name_column(): void
    {
        $admin = $this->actingAsAdmin();
        $longTitle = str_repeat('a', 60);

        $response = $this->actingAs($admin)->graphQL('
            mutation($title: String!) { createMenuFolder(title: $title) { name } }',
            ['title' => $longTitle]
        );

        $name = $response->json('data.createMenuFolder.name');

        $this->assertNotNull($name);
        $this->assertLessThanOrEqual(50, strlen($name));
        $this->assertDatabaseHas('menu_items', ['name' => $name]);
    }

    public function test_create_menu_folder_treats_blank_icon_as_default(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->graphQL('
            mutation { createMenuFolder(title: "Папка с пустой иконкой", icon: "") { icon } }');

        $response->assertJsonPath('data.createMenuFolder.icon', 'folder-line');
    }

    public function test_create_menu_folder_rejects_blank_title(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->actingAs($admin)->graphQL('
            mutation { createMenuFolder(title: "   ") { name } }');

        $response->assertGraphQLErrorMessage('Название папки не может быть пустым.');
    }
}
