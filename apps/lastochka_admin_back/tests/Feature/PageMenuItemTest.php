<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PageMenuItemTest extends TestCase
{
    use DatabaseTransactions;

    public function test_pages_menu_item_exists(): void
    {
        $item = DB::table('menu_items')->where('name', 'pages')->first();

        $this->assertNotNull($item, 'Пункт меню pages не найден — миграция add_pages_menu_item не применена');
        $this->assertSame('Страницы', $item->title);
        $this->assertSame('file-list-2-line', $item->icon);
        $this->assertEquals(1, $item->is_visible);
        $this->assertSame(['admin'], json_decode($item->accesses, true));
    }

    public function test_system_pages_are_seeded(): void
    {
        foreach (['privacy-policy' => 'Политика конфиденциальности', 'public-offer' => 'Публичная оферта'] as $slug => $title) {
            $page = DB::table('pages')->where('slug', $slug)->first();

            $this->assertNotNull($page, "Системная страница {$slug} не найдена — миграция seed_system_pages не применена");
            $this->assertSame($title, $page->title);
            $this->assertEquals(1, $page->is_system);
            $this->assertEquals(0, $page->is_published);
            $this->assertSame(['blocks' => []], json_decode($page->content, true));
        }
    }

    public function test_pages_section_permission_granted_to_admin_and_superadmin(): void
    {
        $permission = DB::table('permissions')->where('name', 'section.pages')->first();

        $this->assertNotNull($permission, 'Permission section.pages не найдена — миграция add_pages_menu_item не создала permission раздела');

        $grantedRoles = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('role_has_permissions.permission_id', $permission->id)
            ->pluck('roles.name')
            ->all();

        $this->assertContains('admin', $grantedRoles, 'Роль admin не получила permission section.pages');
        $this->assertContains('superadmin', $grantedRoles, 'Роль superadmin не получила permission section.pages');
    }
}
