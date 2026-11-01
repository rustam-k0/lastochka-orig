<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Хелперы создания моделей без фабрик
|--------------------------------------------------------------------------
|
| Faker-фабрики в PHPUnit-контексте репо отдают «голый» Generator (numerify
| недоступен), поэтому модели в тестах создаём напрямую через ::create.
|
*/

if (! function_exists('makeOrder')) {
    function makeOrder(array $attrs = []): \App\Models\Order
    {
        $key = $attrs['shop_order_id'] ?? $attrs['number'] ?? uniqid();

        return \App\Models\Order::create(array_merge([
            'number' => 'TEST-'.$key,
            'status' => \App\Models\Order::STATUS_NEW,
            'source' => \App\Models\Order::SOURCE_SHOP,
            'shop_id' => 1,
            'customer_name' => 'Тестовый Клиент',
            'customer_phone' => '+79990000000',
            'total' => 0,
            'delivery_cost' => 0,
        ], $attrs));
    }
}

if (! function_exists('makeIntegrationConfig')) {
    function makeIntegrationConfig(array $attrs = []): \App\Models\IntegrationConfig
    {
        return \App\Models\IntegrationConfig::create(array_merge([
            'integration_type' => '1c',
            'driver' => 'commerceml',
            'is_active' => true,
            'shop_id' => 1,
            'shop_code' => 'aladdin',
        ], $attrs));
    }
}

if (! function_exists('makeProduct')) {
    function makeProduct(array $attrs = []): \App\Models\Product
    {
        return \App\Models\Product::create(array_merge([
            'external_id' => 'guid-'.uniqid(),
            'name' => 'Тестовый товар',
            'is_active' => true,
            'shop_id' => 1,
        ], $attrs));
    }
}
