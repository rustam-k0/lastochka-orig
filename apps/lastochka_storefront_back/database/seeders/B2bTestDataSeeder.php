<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

class B2bTestDataSeeder extends Seeder
{
    public function run(): void
    {
        // B2B-клиент
        $user = User::factory()->b2b()->create([
            'phone' => '+79001111111',
            'first_name' => 'B2B',
            'last_name' => 'Клиент',
            'email' => 'b2b@test.example.com',
        ]);

        // B2B-склад
        $store = Store::factory()->b2b()->withSchedule()->create([
            'code' => 'B2B-WAREHOUSE-01',
            'name' => 'B2B Склад (оптовый)',
            'min_order_amount' => 3000,
            'delivery_price' => 0,
            'free_delivery_from' => null,
        ]);

        // Категории
        $dairy = Category::factory()->forStore($store)->create(['name' => 'Молочная продукция (опт)']);
        $bakery = Category::factory()->forStore($store)->create(['name' => 'Выпечка (опт)']);
        $drinks = Category::factory()->forStore($store)->create(['name' => 'Напитки (опт)']);

        // Товары с оптовыми ценами
        $products = [
            ['title' => 'Молоко 3.2% (упак. 12шт)', 'price' => 960, 'quantity' => 50, 'category' => $dairy],
            ['title' => 'Творог 5% (упак. 10шт)', 'price' => 1500, 'quantity' => 30, 'category' => $dairy],
            ['title' => 'Сметана 20% (упак. 10шт)', 'price' => 1200, 'quantity' => 40, 'category' => $dairy],
            ['title' => 'Хлеб белый (упак. 20шт)', 'price' => 800, 'quantity' => 100, 'category' => $bakery],
            ['title' => 'Батон нарезной (упак. 20шт)', 'price' => 900, 'quantity' => 80, 'category' => $bakery],
            ['title' => 'Вода питьевая 0.5л (упак. 24шт)', 'price' => 480, 'quantity' => 200, 'category' => $drinks],
            ['title' => 'Сок яблочный 1л (упак. 12шт)', 'price' => 720, 'quantity' => 60, 'category' => $drinks],
        ];

        foreach ($products as $data) {
            Product::factory()->for($store)->for($data['category'])->create([
                'title' => $data['title'],
                'price' => $data['price'],
                'quantity' => $data['quantity'],
            ]);
        }

        $this->command->info("B2B тестовые данные созданы:");
        $this->command->info("  Клиент: {$user->phone} (is_b2b=true)");
        $this->command->info("  Склад: {$store->name} (code={$store->code})");
        $this->command->info("  Товаров: ".count($products));
    }
}
