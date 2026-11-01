<?php

namespace Tests\Feature;

use App\Models\Attribute;
use Database\Seeders\AttributeSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Свежая установка = migrate, затем db:seed. Миграция 2026_07_14_120000 уже вставляет
 * характеристики карточки (product_type, country, release_form, manufacturer), и сидер
 * падал на UNIQUE attributes.code — новый форк не поднимался через migrate --seed.
 */
class AttributeSeederIdempotencyTest extends TestCase
{
  use DatabaseTransactions;

  /** @test */
  public function seeder_skips_attributes_already_created_by_migration(): void
  {
    $this->assertTrue(Attribute::withTrashed()->where('code', 'product_type')->exists());

    (new AttributeSeeder())->run();

    foreach (['proteins', 'product_type', 'country', 'release_form', 'manufacturer'] as $code) {
      $this->assertSame(1, Attribute::withTrashed()->where('code', $code)->count(), $code);
    }
  }
}
