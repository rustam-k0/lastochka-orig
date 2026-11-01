<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\MenuItem;

class MenuItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'orders', 'title' => 'Заказы', 'icon' => 'list-check', 'position' => 0, 'accesses' => ['admin', 'shop']],
            ['name' => 'products', 'title' => 'Товары', 'icon' => 'archive-line', 'position' => 1, 'accesses' => ['admin', 'shop']],
            ['name' => 'categories', 'title' => 'Категории', 'icon' => 'grid-fill', 'position' => 2, 'accesses' => ['admin']],
            ['name' => 'clients', 'title' => 'Клиенты', 'icon' => 'team-line', 'position' => 3, 'accesses' => ['admin']],
            ['name' => 'staffs', 'title' => 'Сотрудники', 'icon' => 'customer-service-2-line', 'position' => 4, 'accesses' => ['admin', 'shop']],
            ['name' => 'stores', 'title' => 'Партнеры', 'icon' => 'store-2-line', 'position' => 5, 'accesses' => ['admin']],
            ['name' => 'my_store', 'title' => 'Магазин', 'icon' => 'list-check', 'position' => 6, 'accesses' => ['shop']],
            ['name' => 'stories', 'title' => 'Истории', 'icon' => 'play-circle-line', 'position' => 7, 'accesses' => ['admin']],
            ['name' => 'merchant-sales', 'title' => 'Акции магазинов', 'icon' => 'price-tag-3-line', 'position' => 8, 'accesses' => ['admin']],
            ['name' => 'merchant-product-groups', 'title' => 'Товарные группы', 'icon' => 'grid-fill', 'position' => 9, 'accesses' => ['admin', 'shop']],
            ['name' => 'merchant-banners', 'title' => 'Баннеры', 'icon' => 'image-line', 'position' => 10, 'accesses' => ['admin', 'shop']],
            ['name' => 'merchant-promocodes', 'title' => 'Промокоды', 'icon' => 'coupon-3-line', 'position' => 11, 'accesses' => ['admin', 'shop']],
            ['name' => 'announcements', 'title' => 'Уведомления об акциях', 'icon' => 'notification-3-line', 'position' => 12, 'accesses' => ['admin']],
            ['name' => 'merchant-reviews', 'title' => 'Отзывы на магазины', 'icon' => 'star-line', 'position' => 13, 'accesses' => ['admin', 'shop']],
            ['name' => 'product-reviews', 'title' => 'Отзывы на товары', 'icon' => 'star-half-line', 'position' => 14, 'accesses' => ['admin', 'shop']],
            ['name' => 'settings', 'title' => 'Настройки', 'icon' => 'settings-3-line', 'position' => 15, 'accesses' => ['admin', 'shop']],
            ['name' => 'roles', 'title' => 'Роли и доступы', 'icon' => 'lock-line', 'position' => 16, 'accesses' => ['admin']],
            ['name' => 'app-settings', 'title' => 'Настройки приложения', 'icon' => 'smartphone-line', 'position' => 17, 'accesses' => ['admin', 'shop']],
            ['name' => 'printers', 'title' => 'Принтеры печати', 'icon' => 'printer-line', 'position' => 18, 'accesses' => ['admin']],
        ];

        foreach ($items as $item) {
            MenuItem::updateOrCreate(
                ['name' => $item['name']],
                $item
            );
        }
    }
}
