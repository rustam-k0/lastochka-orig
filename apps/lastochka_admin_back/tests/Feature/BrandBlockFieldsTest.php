<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Transformers\BrandEventTransformer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class BrandBlockFieldsTest extends TestCase
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
    public function brand_create_stores_block_fields(): void
    {
        $title = 'Бренд ' . uniqid();

        $response = $this->actingAs($this->user)->graphQL('
            mutation(
                $title: String!
                $link: String
                $country_origin: String
                $country_production: String
                $certificate_path: String
                $certificate_name: String
                $logo_image: ImgInput!
            ) {
                BrandCreate(
                    title: $title
                    link: $link
                    country_origin: $country_origin
                    country_production: $country_production
                    certificate_path: $certificate_path
                    certificate_name: $certificate_name
                    logo_image: $logo_image
                ) {
                    id
                    title
                    link
                    country_origin
                    country_production
                    certificate_path
                    certificate_name
                }
            }
        ', [
            'title' => $title,
            'link' => 'https://iek.ru',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_path' => '/files/brands/certificate.pdf',
            'certificate_name' => 'Сертификат официального дилера.pdf',
            'logo_image' => $this->logoImageInput(),
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand = Brand::where('title', $title)->firstOrFail();
        $this->assertSame('https://iek.ru', $brand->link);
        $this->assertSame('Россия', $brand->country_origin);
        $this->assertSame('Китай', $brand->country_production);
        $this->assertSame('/files/brands/certificate.pdf', $brand->certificate_path);
        $this->assertSame('Сертификат официального дилера.pdf', $brand->certificate_name);

        $this->assertSame('https://iek.ru', $response->json('data.BrandCreate.link'));
        $this->assertSame('Россия', $response->json('data.BrandCreate.country_origin'));
    }

    /** @test */
    public function brand_update_sets_and_clears_block_fields(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'link' => 'https://old.example',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_path' => '/files/brands/old.pdf',
            'certificate_name' => 'old.pdf',
        ]);

        // Явный null очищает сертификат, новые значения перезаписывают поля.
        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $link: String, $certificate_path: String, $certificate_name: String) {
                BrandUpdate(
                    id: $id
                    link: $link
                    certificate_path: $certificate_path
                    certificate_name: $certificate_name
                ) { id link certificate_path certificate_name }
            }
        ', [
            'id' => $brand->id,
            'link' => 'https://new.example',
            'certificate_path' => null,
            'certificate_name' => null,
        ]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand->refresh();
        $this->assertSame('https://new.example', $brand->link);
        $this->assertNull($brand->certificate_path);
        $this->assertNull($brand->certificate_name);
        // Не переданные ключи не тронуты.
        $this->assertSame('Россия', $brand->country_origin);
        $this->assertSame('Китай', $brand->country_production);
    }

    /** @test */
    public function brand_update_without_keys_keeps_block_fields(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'link' => 'https://keep.example',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_path' => '/files/brands/keep.pdf',
            'certificate_name' => 'keep.pdf',
        ]);

        $response = $this->actingAs($this->user)->graphQL('
            mutation($id: Int!, $title: String) {
                BrandUpdate(id: $id, title: $title) { id title }
            }
        ', ['id' => $brand->id, 'title' => 'Ребрендинг ' . uniqid()]);

        $this->assertEmpty($response->json('errors'), json_encode($response->json('errors')));

        $brand->refresh();
        $this->assertSame('https://keep.example', $brand->link);
        $this->assertSame('Россия', $brand->country_origin);
        $this->assertSame('Китай', $brand->country_production);
        $this->assertSame('/files/brands/keep.pdf', $brand->certificate_path);
        $this->assertSame('keep.pdf', $brand->certificate_name);
    }

    /** @test */
    public function transformer_payload_contains_block_fields(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
            'link' => 'https://iek.ru',
            'country_origin' => 'Россия',
            'country_production' => 'Китай',
            'certificate_path' => 'brands/certificate.pdf',
            'certificate_name' => 'Сертификат.pdf',
        ]);

        $payload = (new BrandEventTransformer())->toEvent($brand);

        $this->assertSame('https://iek.ru', $payload['link']);
        $this->assertSame('Россия', $payload['country_origin']);
        $this->assertSame('Китай', $payload['country_production']);
        $this->assertSame('Сертификат.pdf', $payload['certificate_name']);
        $this->assertNotNull($payload['certificate_url']);
        $this->assertStringContainsString('brands/certificate.pdf', $payload['certificate_url']);
    }

    /** @test */
    public function transformer_payload_certificate_url_null_when_no_certificate(): void
    {
        $brand = Brand::create([
            'title' => 'Бренд ' . uniqid(),
        ]);

        $payload = (new BrandEventTransformer())->toEvent($brand);

        $this->assertArrayHasKey('certificate_url', $payload);
        $this->assertNull($payload['certificate_url']);
        $this->assertArrayHasKey('link', $payload);
        $this->assertNull($payload['link']);
    }
}
