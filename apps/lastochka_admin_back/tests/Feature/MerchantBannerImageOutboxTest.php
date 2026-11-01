<?php

namespace Tests\Feature;

use App\GraphQL\Mutations\MerchantBanner\MerchantBannerUpdate;
use App\Models\MerchantBanner;
use App\Models\Shop;
use App\Models\Status;
use App\Models\User;
use Comrades\RabbitMQEvents\Models\EventOutbox;
use Comrades\RabbitMQEvents\RabbitMQEventsServiceProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Продолжение LASTOCHKI-JAMI-12236: у баннеров та же грабля, что у историй —
 * замена картинки живёт в img/join_img и не меняет модель MerchantBanner,
 * поэтому image-only правка не публиковала outbox-событие
 * merchant_banner.updated и не синкалась в storefront.
 */
class MerchantBannerImageOutboxTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        // Пакет регистрирует eloquent-observers один раз на PHP-процесс
        // (static-флаг), а PHPUnit пересоздаёт приложение на каждый тест —
        // сбрасываем флаг до boot, чтобы outbox-observer работал в каждом тесте.
        $flag = new \ReflectionProperty(RabbitMQEventsServiceProvider::class, 'observersRegistered');
        $flag->setAccessible(true);
        $flag->setValue(null, false);

        parent::setUp();

        $this->actingAs($this->adminUser());
    }

    private function createBannerWithImage(): MerchantBanner
    {
        $shop = Shop::create([
            'title' => 'Banner Outbox Shop ' . uniqid(),
            'id_1c' => 0,
            'default' => false,
            'slot_max_orders' => 10,
            'slot_time' => 30,
            'payment_type_id' => 1,
            'is_popular' => false,
        ]);

        $banner = MerchantBanner::create([
            'title' => 'Banner Outbox ' . uniqid(),
            'link_type' => MerchantBanner::LINK_TYPE_NONE,
            'product_id' => null,
            'category_id' => null,
            'shop_id' => $shop->id,
            'is_active' => true,
            'position' => 0,
        ]);

        $banner->saveImg(['path' => '/files/test-banner.jpg'], Status::CODE_MERCHANT_BANNER_IMG, $banner->getTable());

        return $banner;
    }

    private function updatedEventsCount(int $bannerId): int
    {
        return EventOutbox::where('entity_type', 'merchant_banners')
            ->where('entity_id', $bannerId)
            ->where('event_type', 'updated')
            ->count();
    }

    /** @test */
    public function image_only_update_creates_outbox_event()
    {
        $banner = $this->createBannerWithImage();
        $baseline = $this->updatedEventsCount($banner->id);

        // Правка картинки происходит позже создания — иначе touch() попадёт
        // в ту же секунду и updated_at не станет dirty
        $this->travelTo(now()->addMinute());

        (new MerchantBannerUpdate())(null, [
            'id' => $banner->id,
            'image' => ['path' => '/files/test-banner-new.jpg'],
        ]);

        $this->assertGreaterThan(
            $baseline,
            $this->updatedEventsCount($banner->id),
            'Замена картинки без изменения полей баннера должна публиковать merchant_banner.updated'
        );
    }

    /** @test */
    public function scalar_update_with_image_does_not_double_publish()
    {
        $banner = $this->createBannerWithImage();
        $baseline = $this->updatedEventsCount($banner->id);

        $this->travelTo(now()->addMinute());

        (new MerchantBannerUpdate())(null, [
            'id' => $banner->id,
            'title' => 'Обновлённый баннер ' . uniqid(),
            'image' => ['path' => '/files/test-banner-new.jpg'],
        ]);

        $this->assertSame(
            $baseline + 1,
            $this->updatedEventsCount($banner->id),
            'Изменение полей баннера уже публикует merchant_banner.updated — touch() не должен дублировать событие'
        );
    }
}
