<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SharedEstimatePageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create();
        $this->category = Category::factory()->forStore($this->store)->create();
    }

    public function test_shared_page_shows_estimate_contents(): void
    {
        $estimate = Estimate::factory()
            ->for(User::factory()->create())
            ->create([
                'name' => 'Смета для Никольской',
                'share_token' => 'test-share-token',
            ]);

        $product = Product::factory()->for($this->store)->inCategory($this->category)->create([
            'title' => 'Кабель ВВГнг 3x2.5',
            'price' => 1500,
            'quantity' => 100,
            'quantity_step' => 1,
            'is_active' => true,
        ]);

        EstimateItem::factory()->for($estimate)->for($product)->create(['quantity' => 4]);

        $response = $this->get('/estimates/shared/test-share-token');

        $response->assertOk()
            ->assertViewIs('estimates.shared')
            ->assertSee('Смета для Никольской')
            ->assertSee('Кабель ВВГнг 3x2.5')
            ->assertSee('6 000'); // итого: 4 × 1500
    }

    public function test_shared_page_not_found_renders_stub_with_404(): void
    {
        $response = $this->get('/estimates/shared/nonexistent-token');

        $response->assertNotFound()
            ->assertViewIs('estimates.shared')
            ->assertSee('Смета не найдена');
    }

    public function test_shared_page_shows_app_links_when_configured(): void
    {
        config()->set('deeplinks.apps.appstore_url', 'https://apps.apple.com/ru/app/electrohub/id1234567890');
        config()->set('deeplinks.apps.google_play_url', 'https://play.google.com/store/apps/details?id=com.app.electrohub');
        config()->set('deeplinks.apps.scheme', 'electrohub');

        $estimate = Estimate::factory()
            ->for(User::factory()->create())
            ->create(['share_token' => 'test-share-token']);

        $response = $this->get('/estimates/shared/test-share-token');

        $response->assertOk()
            ->assertSee('Открыть в приложении')
            ->assertSee('electrohub://estimates/shared/test-share-token')
            ->assertSee('https://apps.apple.com/ru/app/electrohub/id1234567890', escape: false)
            ->assertSee('https://play.google.com/store/apps/details?id=com.app.electrohub', escape: false)
            // Smart App Banner: app-id извлечён из URL App Store
            ->assertSee('app-id=1234567890', escape: false);
    }

    public function test_shared_page_hides_app_blocks_when_not_configured(): void
    {
        config()->set('deeplinks.apps.appstore_url', '');
        config()->set('deeplinks.apps.google_play_url', '');
        config()->set('deeplinks.apps.scheme', '');

        $estimate = Estimate::factory()
            ->for(User::factory()->create())
            ->create(['share_token' => 'test-share-token']);

        $response = $this->get('/estimates/shared/test-share-token');

        $response->assertOk()
            ->assertDontSee('Открыть в приложении')
            ->assertDontSee('App Store')
            ->assertDontSee('apple-itunes-app');
    }
}
