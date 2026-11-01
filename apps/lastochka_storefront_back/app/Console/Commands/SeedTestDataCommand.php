<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Address;
use App\Models\Banner;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductGroupItem;
use App\Models\ProductReview;
use App\Models\Promocode;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\StoreReview;
use App\Models\Story;
use App\Models\StoryImage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedTestDataCommand extends Command
{
    protected $signature = 'db:seed-test
                            {--fresh : Очистить БД перед заполнением}
                            {--stores=3 : Количество магазинов}
                            {--users=10 : Количество пользователей}
                            {--products=50 : Количество товаров на магазин}';

    protected $description = 'Заполнить БД тестовыми данными для разработки';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            if (! $this->option('no-interaction') && ! $this->confirm('Все данные будут удалены. Продолжить?')) {
                $this->info('Отменено.');

                return self::SUCCESS;
            }

            $this->call('migrate:fresh');
        }

        $storesCount = (int) $this->option('stores');
        $usersCount = (int) $this->option('users');
        $productsPerStore = (int) $this->option('products');

        $this->info('Создание тестовых данных...');
        $this->newLine();

        DB::transaction(function () use ($storesCount, $usersCount, $productsPerStore) {
            $this->seedStores($storesCount);
            $this->seedUsers($usersCount);
            $this->seedCategories();
            $this->seedProducts($productsPerStore);
            $this->seedProductGroups();
            $this->seedBanners();
            $this->seedStories();
            $this->seedPromocodes();
            $this->seedPromotions();
            $this->seedCarts();
            $this->seedOrders();
            $this->seedReviews();
            $this->seedFavorites();
        });

        $this->newLine();
        $this->info('Тестовые данные успешно созданы!');
        $this->newLine();

        $this->showStats();

        return self::SUCCESS;
    }

    private function seedStores(int $count): void
    {
        $this->components->task('Создание магазинов', function () use ($count) {
            Store::factory()
                ->count($count)
                ->withSchedule()
                ->create();
        });
    }

    private function seedUsers(int $count): void
    {
        $this->components->task('Создание пользователей', function () use ($count) {
            // Тестовый пользователь с известными данными
            User::factory()->create([
                'phone' => '+79991234567',
                'first_name' => 'Тест',
                'last_name' => 'Пользователь',
                'email' => 'test@example.com',
            ]);

            // Остальные пользователи
            User::factory()
                ->count($count - 1)
                ->create();

            // Добавляем адреса пользователям
            User::all()->each(function (User $user) {
                Address::factory()
                    ->count(fake()->numberBetween(1, 3))
                    ->for($user)
                    ->create();

                // Один активный адрес
                $user->addresses()->first()?->update(['is_active' => true]);
            });
        });
    }

    private function seedCategories(): void
    {
        $this->components->task('Создание категорий', function () {
            $stores = Store::all();

            $categoryNames = [
                'Молочные продукты',
                'Хлеб и выпечка',
                'Овощи и фрукты',
                'Мясо и птица',
                'Рыба и морепродукты',
                'Напитки',
                'Сладости',
                'Замороженные продукты',
                'Крупы и макароны',
                'Консервы',
            ];

            foreach ($categoryNames as $index => $name) {
                $parent = Category::factory()->create([
                    'name' => $name,
                    'slug' => \Illuminate\Support\Str::slug($name),
                    'position' => $index,
                ]);

                // Привязать ко всем магазинам
                foreach ($stores as $store) {
                    $parent->stores()->attach($store->id, [
                        'position' => $index,
                        'is_active' => true,
                    ]);
                }

                // Подкатегории
                $children = Category::factory()
                    ->count(fake()->numberBetween(2, 4))
                    ->childOf($parent)
                    ->create();

                foreach ($children as $child) {
                    foreach ($stores as $store) {
                        $child->stores()->attach($store->id, [
                            'position' => $child->position ?? 0,
                            'is_active' => true,
                        ]);
                    }
                }
            }
        });
    }

    private function seedProducts(int $perStore): void
    {
        $this->components->task('Создание товаров', function () use ($perStore) {
            $stores = Store::all();

            foreach ($stores as $store) {
                $categories = Category::whereHas('stores', fn ($q) => $q->where('stores.id', $store->id))
                    ->whereNotNull('parent_id')
                    ->get();

                if ($categories->isEmpty()) {
                    continue;
                }

                // Обычные товары
                $normalCount = (int) ($perStore * 0.7);
                Product::factory()
                    ->count($normalCount)
                    ->create([
                        'store_id' => $store->id,
                        'category_id' => fn () => $categories->random()->id,
                    ]);

                // Товары со скидкой
                $discountCount = (int) ($perStore * 0.2);
                Product::factory()
                    ->count($discountCount)
                    ->withDiscount()
                    ->create([
                        'store_id' => $store->id,
                        'category_id' => fn () => $categories->random()->id,
                    ]);

                // Товары не в наличии
                $outOfStockCount = $perStore - $normalCount - $discountCount;
                Product::factory()
                    ->count($outOfStockCount)
                    ->outOfStock()
                    ->create([
                        'store_id' => $store->id,
                        'category_id' => fn () => $categories->random()->id,
                    ]);
            }
        });
    }

    private function seedProductGroups(): void
    {
        $this->components->task('Создание групп товаров', function () {
            $stores = Store::all();

            foreach ($stores as $store) {
                $products = Product::where('store_id', $store->id)->get();

                if ($products->isEmpty()) {
                    continue;
                }

                // Хиты продаж
                $hitsGroup = ProductGroup::factory()->onHome()->create([
                    'store_id' => $store->id,
                    'title' => 'Хиты продаж',
                ]);

                $products->random(min(10, $products->count()))->each(function ($product, $index) use ($hitsGroup) {
                    ProductGroupItem::factory()->create([
                        'product_group_id' => $hitsGroup->id,
                        'product_id' => $product->id,
                        'position' => $index,
                    ]);
                });

                // Новинки
                $newGroup = ProductGroup::factory()->inCatalog()->create([
                    'store_id' => $store->id,
                    'title' => 'Новинки',
                ]);

                $products->random(min(8, $products->count()))->each(function ($product, $index) use ($newGroup) {
                    ProductGroupItem::factory()->create([
                        'product_group_id' => $newGroup->id,
                        'product_id' => $product->id,
                        'position' => $index,
                    ]);
                });

                // Скидки
                $discountedProducts = $products->filter(fn ($p) => $p->price_old !== null);
                if ($discountedProducts->isNotEmpty()) {
                    $discountGroup = ProductGroup::factory()->onStorePage()->onHome()->create([
                        'store_id' => $store->id,
                        'title' => 'Скидки',
                    ]);

                    $discountedProducts->each(function ($product, $index) use ($discountGroup) {
                        ProductGroupItem::factory()->create([
                            'product_group_id' => $discountGroup->id,
                            'product_id' => $product->id,
                            'position' => $index,
                        ]);
                    });
                }
            }
        });
    }

    private function seedBanners(): void
    {
        $this->components->task('Создание баннеров', function () {
            $stores = Store::all();

            foreach ($stores as $store) {
                Banner::factory()
                    ->count(5)
                    ->create(['store_id' => $store->id]);

                // Один истёкший
                Banner::factory()
                    ->expired()
                    ->create(['store_id' => $store->id]);

                // Один будущий
                Banner::factory()
                    ->future()
                    ->create(['store_id' => $store->id]);
            }
        });
    }

    private function seedStories(): void
    {
        $this->components->task('Создание историй', function () {
            $stories = Story::factory()->count(10)->create();

            foreach ($stories as $story) {
                StoryImage::factory()
                    ->count(fake()->numberBetween(2, 5))
                    ->create(['story_id' => $story->id]);
            }

            // Несколько историй с внешними ссылками
            Story::factory()
                ->count(3)
                ->withExternalLink()
                ->create()
                ->each(function ($story) {
                    StoryImage::factory()
                        ->count(fake()->numberBetween(2, 4))
                        ->create(['story_id' => $story->id]);
                });
        });
    }

    private function seedPromocodes(): void
    {
        $this->components->task('Создание промокодов', function () {
            // Активные процентные промокоды
            Promocode::factory()
                ->count(5)
                ->percent()
                ->create();

            // Активные фиксированные промокоды
            Promocode::factory()
                ->count(3)
                ->fixed(500)
                ->create();

            // Промокод с минимальной суммой
            Promocode::factory()
                ->percent(15)
                ->withMinAmount(2000)
                ->create(['code' => 'SALE15']);

            // Тестовый промокод
            Promocode::factory()->create([
                'code' => 'TEST10',
                'type' => \App\Enums\PromocodeType::Percent,
                'value' => 10,
            ]);

            // Истёкший
            Promocode::factory()->expired()->create();

            // Исчерпанный
            Promocode::factory()->exhausted()->create();
        });
    }

    private function seedPromotions(): void
    {
        $this->components->task('Создание акций', function () {
            $stores = Store::all();

            foreach ($stores as $store) {
                Promotion::factory()
                    ->count(3)
                    ->create(['store_id' => $store->id]);

                Promotion::factory()
                    ->expired()
                    ->create(['store_id' => $store->id]);

                Promotion::factory()
                    ->upcoming()
                    ->create(['store_id' => $store->id]);
            }
        });
    }

    private function seedCarts(): void
    {
        $this->components->task('Создание корзин', function () {
            $users = User::all();
            $stores = Store::all();

            // У половины пользователей будут корзины
            $usersWithCarts = $users->random((int) ceil($users->count() / 2));

            foreach ($usersWithCarts as $user) {
                $cart = Cart::factory()->create(['user_id' => $user->id]);

                // Выбираем случайный магазин и его товары
                $store = $stores->random();
                $products = Product::where('store_id', $store->id)
                    ->where('quantity', '>', 0)
                    ->inRandomOrder()
                    ->take(fake()->numberBetween(1, 5))
                    ->get();

                foreach ($products as $product) {
                    CartItem::factory()->create([
                        'cart_id' => $cart->id,
                        'product_id' => $product->id,
                        'store_id' => $store->id,
                        'quantity' => fake()->numberBetween(1, 3),
                        'price' => $product->price,
                    ]);
                }
            }
        });
    }

    private function seedOrders(): void
    {
        $this->components->task('Создание заказов', function () {
            $users = User::all();
            $stores = Store::all();

            foreach ($users->random((int) ceil($users->count() * 0.7)) as $user) {
                $ordersCount = fake()->numberBetween(1, 5);

                for ($i = 0; $i < $ordersCount; $i++) {
                    $store = $stores->random();
                    $products = Product::where('store_id', $store->id)
                        ->inRandomOrder()
                        ->take(fake()->numberBetween(1, 5))
                        ->get();

                    if ($products->isEmpty()) {
                        continue;
                    }

                    $subtotal = 0;
                    $itemsCount = 0;

                    $orderItems = $products->map(function ($product) use (&$subtotal, &$itemsCount) {
                        $quantity = fake()->numberBetween(1, 3);
                        $total = $product->price * $quantity;
                        $subtotal += $total;
                        $itemsCount += $quantity;

                        return [
                            'product_id' => $product->id,
                            'title' => $product->title,
                            'sku' => $product->sku,
                            'price' => $product->price,
                            'quantity' => $quantity,
                            'total' => $total,
                        ];
                    });

                    $deliveryCost = fake()->randomElement([0, 200, 300]);
                    $total = $subtotal + $deliveryCost;

                    // Разные статусы заказов
                    $orderFactory = Order::factory();
                    $statusMethod = fake()->randomElement([
                        'pending',
                        'assembling',
                        'assembled',
                        'inDelivery',
                        'delivered',
                        'cancelled',
                    ]);

                    $order = $orderFactory->$statusMethod()->create([
                        'user_id' => $user->id,
                        'store_id' => $store->id,
                        'subtotal' => $subtotal,
                        'delivery_cost' => $deliveryCost,
                        'total' => $total,
                        'items_count' => $itemsCount,
                    ]);

                    // Адрес заказа
                    OrderAddress::factory()->create(['order_id' => $order->id]);

                    // Позиции заказа
                    foreach ($orderItems as $item) {
                        OrderItem::factory()->create([
                            'order_id' => $order->id,
                            ...$item,
                        ]);
                    }
                }
            }
        });
    }

    private function seedReviews(): void
    {
        $this->components->task('Создание отзывов', function () {
            $stores = Store::all();
            $users = User::all();

            foreach ($stores as $store) {
                // Отзывы на магазин (уникальные user_id для store_id)
                $reviewersCount = min($users->count(), fake()->numberBetween(5, 10));
                $reviewers = $users->random($reviewersCount);

                foreach ($reviewers as $index => $user) {
                    StoreReview::factory()
                        ->when($index < $reviewersCount - 2, fn ($f) => $f->published())
                        ->create([
                            'store_id' => $store->id,
                            'user_id' => $user->id,
                        ]);
                }

                // Отзывы на товары (уникальные user_id для product_id)
                $products = Product::where('store_id', $store->id)
                    ->inRandomOrder()
                    ->take(15)
                    ->get();

                foreach ($products as $product) {
                    $productReviewersCount = min($users->count(), fake()->numberBetween(1, 4));
                    $productReviewers = $users->random($productReviewersCount);

                    foreach ($productReviewers as $user) {
                        ProductReview::factory()
                            ->published()
                            ->create([
                                'product_id' => $product->id,
                                'store_id' => $store->id,
                                'user_id' => $user->id,
                            ]);
                    }
                }
            }
        });
    }

    private function seedFavorites(): void
    {
        $this->components->task('Создание избранного', function () {
            $users = User::all();
            $products = Product::all();

            foreach ($users as $user) {
                $favoritesCount = fake()->numberBetween(0, 10);

                if ($favoritesCount === 0) {
                    continue;
                }

                $favoriteProducts = $products->random(min($favoritesCount, $products->count()));

                foreach ($favoriteProducts as $product) {
                    Favorite::factory()->create([
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                    ]);
                }
            }
        });
    }

    private function showStats(): void
    {
        $this->table(
            ['Сущность', 'Количество'],
            [
                ['Магазины', Store::count()],
                ['Пользователи', User::count()],
                ['Адреса', Address::count()],
                ['Категории', Category::count()],
                ['Товары', Product::count()],
                ['Группы товаров', ProductGroup::count()],
                ['Баннеры', Banner::count()],
                ['Истории', Story::count()],
                ['Промокоды', Promocode::count()],
                ['Акции', Promotion::count()],
                ['Корзины', Cart::count()],
                ['Заказы', Order::count()],
                ['Отзывы на магазины', StoreReview::count()],
                ['Отзывы на товары', ProductReview::count()],
                ['Избранное', Favorite::count()],
            ]
        );

        $this->newLine();
        $this->info('Тестовый пользователь: +79991234567 (test@example.com)');
        $this->info('Тестовый промокод: TEST10 (10% скидка)');
    }
}
