<?php

namespace Tests\Feature;

use App\Models\Brand;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrandBackfillCountryCodesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Пакет rabbitmq-events регистрирует observer'ы один раз на процесс
     * (static $observersRegistered), а приложение между тестами пересоздаётся,
     * поэтому во втором и последующих тестах outbox молчит. Сбрасываем флаг
     * и поднимаем провайдер заново — иначе проверка событий зависит от порядка тестов.
     */
    private function rebindOutboxObservers(): void
    {
        $flag = new \ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered');
        $flag->setAccessible(true);
        $flag->setValue(null, false);

        (new RabbitMQEventsServiceProvider($this->app))->boot();
    }

    /** @test */
    public function command_fills_codes_from_existing_titles(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
        ]);

        $this->artisan('brands:backfill-country-codes')->assertExitCode(0);

        $brand->refresh();
        $this->assertSame('RU', $brand->country_origin_code);
        $this->assertSame('CN', $brand->country_production_code);
        // Название, введённое админом, не переписывается.
        $this->assertSame('Россия', $brand->country_origin);
    }

    /** @test */
    public function command_resolves_aliases(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'РФ',
            'country_production' => 'Южная Корея',
        ]);

        $this->artisan('brands:backfill-country-codes')->assertExitCode(0);

        $brand->refresh();
        $this->assertSame('RU', $brand->country_origin_code);
        $this->assertSame('KR', $brand->country_production_code);
    }

    /** @test */
    public function command_leaves_unresolved_titles_untouched(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Нарния',
        ]);

        $this->artisan('brands:backfill-country-codes')->assertExitCode(0);

        $brand->refresh();
        $this->assertNull($brand->country_origin_code);
        $this->assertSame('Нарния', $brand->country_origin);
    }

    /** @test */
    public function command_does_not_overwrite_existing_codes(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
            'country_origin_code' => 'DE',
        ]);

        $this->artisan('brands:backfill-country-codes')->assertExitCode(0);

        $brand->refresh();
        $this->assertSame('DE', $brand->country_origin_code);
    }

    /** @test */
    public function dry_run_changes_nothing(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
        ]);

        $this->artisan('brands:backfill-country-codes', ['--dry-run' => true])->assertExitCode(0);

        $brand->refresh();
        $this->assertNull($brand->country_origin_code);
    }

    /** @test */
    public function command_publishes_outbox_event_for_updated_brand(): void
    {
        $this->rebindOutboxObservers();

        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
        ]);

        DB::table('event_outbox')->where('entity_id', (string) $brand->id)->delete();

        $this->artisan('brands:backfill-country-codes')->assertExitCode(0);

        $events = DB::table('event_outbox')
            ->where('entity_id', (string) $brand->id)
            ->get();

        $this->assertNotEmpty($events, 'Изменение должно уехать в storefront через outbox');

        $payloads = $events->pluck('payload')->implode(' ');
        $this->assertStringContainsString('country_origin_code', $payloads);
        $this->assertStringContainsString('RU', $payloads);
    }
}
