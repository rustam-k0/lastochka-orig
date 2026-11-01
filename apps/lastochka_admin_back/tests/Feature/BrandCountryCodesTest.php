<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Transformers\BrandEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class BrandCountryCodesTest extends TestCase
{
    use DatabaseTransactions, MakesGraphQLRequests;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->adminUser();
    }

    private function logoImageInput(): array
    {
        return [
            'path' => '/files/test/logo.png',
            'origin_name' => 'logo.png',
            'alt' => 'logo',
            'size' => '10',
            'type' => 'image/png',
        ];
    }

    /** @test */
    public function countries_query_returns_directory(): void
    {
        $response = $this->actingAs($this->user)->graphQL('
            query { admin_countries { code title } }
        ');

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $countries = $response->json('data.admin_countries');
        $this->assertGreaterThanOrEqual(249, count($countries));

        $codes = array_column($countries, 'code');
        $this->assertContains('RU', $codes);
        $this->assertContains('CN', $codes);

        $russia = collect($countries)->firstWhere('code', 'RU');
        $this->assertSame('Россия', $russia['title']);
    }

    /** @test */
    public function brand_create_stores_country_codes_and_fills_titles(): void
    {
        $response = $this->actingAs($this->user)->graphQL('
            mutation(
                $title: String!
                $country_origin_code: String
                $country_production_code: String
                $logo_image: ImgInput!
            ) {
                BrandCreate(
                    title: $title
                    country_origin_code: $country_origin_code
                    country_production_code: $country_production_code
                    logo_image: $logo_image
                ) {
                    id
                    country_origin
                    country_origin_code
                    country_production
                    country_production_code
                }
            }
        ', [
            'title' => 'Бренд ' . uniqid(),
            'country_origin_code' => 'RU',
            'country_production_code' => 'CN',
            'logo_image' => $this->logoImageInput(),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand = Brand::findOrFail($response->json('data.BrandCreate.id'));
        $this->assertSame('RU', $brand->country_origin_code);
        $this->assertSame('CN', $brand->country_production_code);
        // Название страны — из справочника, чтобы код и текст не разъезжались.
        $this->assertSame('Россия', $brand->country_origin);
        $this->assertSame('Китай', $brand->country_production);

        $this->assertSame('RU', $response->json('data.BrandCreate.country_origin_code'));
        $this->assertSame('Россия', $response->json('data.BrandCreate.country_origin'));
    }

    /** @test */
    public function brand_create_normalizes_lowercase_code(): void
    {
        $response = $this->actingAs($this->user)->graphQL('
            mutation($title: String!, $country_origin_code: String, $logo_image: ImgInput!) {
                BrandCreate(title: $title, country_origin_code: $country_origin_code, logo_image: $logo_image) {
                    id
                }
            }
        ', [
            'title' => 'Бренд ' . uniqid(),
            'country_origin_code' => 'ru',
            'logo_image' => $this->logoImageInput(),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand = Brand::findOrFail($response->json('data.BrandCreate.id'));
        $this->assertSame('RU', $brand->country_origin_code);
        $this->assertSame('Россия', $brand->country_origin);
    }

    /** @test */
    public function brand_create_rejects_unknown_country_code(): void
    {
        $response = $this->actingAs($this->user)->graphQL('
            mutation($title: String!, $country_origin_code: String, $logo_image: ImgInput!) {
                BrandCreate(title: $title, country_origin_code: $country_origin_code, logo_image: $logo_image) {
                    id
                }
            }
        ', [
            'title' => 'Бренд ' . uniqid(),
            'country_origin_code' => 'XX',
            'logo_image' => $this->logoImageInput(),
        ]);

        $this->assertNotEmpty($response->json('errors'));
        $this->assertStringContainsString(
            'country_origin_code',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE),
        );
    }

    /** @test */
    public function brand_create_keeps_plain_title_without_code(): void
    {
        // Обратная совместимость: клиент, который шлёт только название.
        $response = $this->actingAs($this->user)->graphQL('
            mutation($title: String!, $country_origin: String, $logo_image: ImgInput!) {
                BrandCreate(title: $title, country_origin: $country_origin, logo_image: $logo_image) { id }
            }
        ', [
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Нарния',
            'logo_image' => $this->logoImageInput(),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand = Brand::findOrFail($response->json('data.BrandCreate.id'));
        $this->assertSame('Нарния', $brand->country_origin);
        $this->assertNull($brand->country_origin_code);
    }

    /** @test */
    public function brand_update_sets_country_code_and_title(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $country_origin_code: String) {
                BrandUpdate(id: $id, country_origin_code: $country_origin_code) {
                    id country_origin country_origin_code
                }
            }
        ', ['id' => $brand->id, 'country_origin_code' => 'DE']);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand->refresh();
        $this->assertSame('DE', $brand->country_origin_code);
        $this->assertSame('Германия', $brand->country_origin);
    }

    /** @test */
    public function brand_update_with_null_code_clears_country(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
            'country_production' => 'Китай',
            'country_production_code' => 'CN',
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $country_origin_code: String) {
                BrandUpdate(id: $id, country_origin_code: $country_origin_code) {
                    id country_origin country_origin_code
                }
            }
        ', ['id' => $brand->id, 'country_origin_code' => null]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand->refresh();
        $this->assertNull($brand->country_origin_code);
        $this->assertNull($brand->country_origin);
        // Вторая страна не тронута.
        $this->assertSame('CN', $brand->country_production_code);
        $this->assertSame('Китай', $brand->country_production);
    }

    /** @test */
    public function brand_update_without_country_keys_keeps_codes(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
            'country_production' => 'Китай',
            'country_production_code' => 'CN',
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $title: String) {
                BrandUpdate(id: $id, title: $title) { id title }
            }
        ', ['id' => $brand->id, 'title' => 'Ребрендинг ' . uniqid()]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand->refresh();
        $this->assertSame('RU', $brand->country_origin_code);
        $this->assertSame('CN', $brand->country_production_code);
    }

    /** @test */
    public function brand_update_rejects_unknown_country_code(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $country_production_code: String) {
                BrandUpdate(id: $id, country_production_code: $country_production_code) { id }
            }
        ', ['id' => $brand->id, 'country_production_code' => 'ZZ']);

        $this->assertNotEmpty($response->json('errors'));

        $brand->refresh();
        $this->assertSame('RU', $brand->country_origin_code);
    }

    /** @test */
    public function transformer_payload_contains_country_codes(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'country_origin' => 'Россия',
            'country_origin_code' => 'RU',
            'country_production' => 'Китай',
            'country_production_code' => 'CN',
        ]);

        $payload = (new BrandEventTransformer())->toEvent($brand);

        $this->assertSame('RU', $payload['country_origin_code']);
        $this->assertSame('CN', $payload['country_production_code']);
        $this->assertSame('Россия', $payload['country_origin']);
        $this->assertSame('Китай', $payload['country_production']);
    }

    /** @test */
    public function transformer_payload_has_null_codes_when_not_set(): void
    {
        $brand = Brand::create(['title' => 'Бренд ' . uniqid()]);

        $payload = (new BrandEventTransformer())->toEvent($brand);

        $this->assertArrayHasKey('country_origin_code', $payload);
        $this->assertArrayHasKey('country_production_code', $payload);
        $this->assertNull($payload['country_origin_code']);
        $this->assertNull($payload['country_production_code']);
    }
}
