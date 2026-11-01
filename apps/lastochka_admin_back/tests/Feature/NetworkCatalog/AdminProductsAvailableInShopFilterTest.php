<?php

namespace Tests\Feature\NetworkCatalog;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use App\Models\Shop;
use App\Models\Status;
use App\Models\Type;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

/**
 * Фильтр списка товаров админки по магазину в сетевом каталоге.
 *
 * Карточка товара принадлежит якорному магазину (products.shop_id), поэтому
 * прежний фильтр shop_id @eq отдавал пустой список для любого не-якорного
 * магазина. Новый аргумент available_in_shop_id фильтрует по наличию товара
 * в магазине: есть цена ИЛИ есть остаток по этому shop_id.
 */
class AdminProductsAvailableInShopFilterTest extends TestCase
{
  use DatabaseTransactions, MakesGraphQLRequests;

  protected User $admin;

  protected Shop $anchor;

  protected Shop $withPrice;

  protected Shop $withRemain;

  protected Shop $empty;

  protected Product $product;

  protected function setUp(): void
  {
    parent::setUp();
    $this->admin = User::role('admin')->firstOrFail();

    $suffix = uniqid();
    $this->anchor = Shop::create(['title' => 'Якорь', 'code' => 'ais-anchor-' . $suffix]);
    $this->withPrice = Shop::create(['title' => 'С ценой', 'code' => 'ais-price-' . $suffix]);
    $this->withRemain = Shop::create(['title' => 'С остатком', 'code' => 'ais-remain-' . $suffix]);
    $this->empty = Shop::create(['title' => 'Пустой', 'code' => 'ais-empty-' . $suffix]);

    $this->product = Product::withoutEvents(fn () => Product::create([
      'title' => 'Сетевой товар ' . $suffix,
      'articul' => 'AIS-' . $suffix,
      'shop_id' => $this->anchor->id,
      'status_id' => Status::CODE_ACTIVE,
      'unit_type_id' => Type::UNIT_PIECE,
      'fill_percent' => 80,
    ]));

    Price::withoutEvents(fn () => (new Price())->forceFill([
      'product_id' => $this->product->id,
      'shop_id' => $this->withPrice->id,
      'type_id' => Type::PRICE_DEFAULT,
      'value' => 250,
      'step' => 1,
    ])->save());

    Remain::withoutEvents(fn () => (new Remain())->forceFill([
      'product_id' => $this->product->id,
      'shop_id' => $this->withRemain->id,
      'quantity' => 3,
    ])->save());
  }

  /**
   * @return array<int, int>
   */
  private function paginateIds(array $variables): array
  {
    $response = $this->actingAs($this->admin)->graphQL(/** @lang GraphQL */ '
      query ($shop_id: Int, $available_in_shop_id: Int, $title: String) {
        admin_products_paginate(shop_id: $shop_id, available_in_shop_id: $available_in_shop_id, title: $title, first: 50) {
          data { id }
        }
      }
    ', $variables);

    $response->assertJsonMissingPath('errors');

    return array_map(
      static fn (array $row): int => (int) $row['id'],
      $response->json('data.admin_products_paginate.data') ?? []
    );
  }

  /** @test */
  public function test_available_in_shop_matches_shop_with_price_only(): void
  {
    $ids = $this->paginateIds([
      'available_in_shop_id' => $this->withPrice->id,
      'title' => $this->product->title,
    ]);

    $this->assertContains($this->product->id, $ids);
  }

  /** @test */
  public function test_available_in_shop_matches_shop_with_remain_only(): void
  {
    $ids = $this->paginateIds([
      'available_in_shop_id' => $this->withRemain->id,
      'title' => $this->product->title,
    ]);

    $this->assertContains($this->product->id, $ids);
  }

  /** @test */
  public function test_available_in_shop_excludes_shop_without_price_and_remain(): void
  {
    $ids = $this->paginateIds([
      'available_in_shop_id' => $this->empty->id,
      'title' => $this->product->title,
    ]);

    $this->assertNotContains($this->product->id, $ids);
  }

  /**
   * Якорный магазин — владелец карточки, но без собственных цены и остатка
   * товара в нём нет. Дискриминирует availableInShop от shop_id @eq.
   *
   * @test
   */
  public function test_available_in_shop_excludes_anchor_without_offer(): void
  {
    $ids = $this->paginateIds([
      'available_in_shop_id' => $this->anchor->id,
      'title' => $this->product->title,
    ]);

    $this->assertNotContains($this->product->id, $ids);
  }

  /**
   * Прежний shop_id @eq по-прежнему бьёт по владельцу карточки — легаси-режим
   * (флаг сетевого каталога выключен) не меняется.
   *
   * @test
   */
  public function test_legacy_shop_id_filter_still_matches_card_owner(): void
  {
    $anchorIds = $this->paginateIds([
      'shop_id' => $this->anchor->id,
      'title' => $this->product->title,
    ]);
    $this->assertContains($this->product->id, $anchorIds);

    $otherIds = $this->paginateIds([
      'shop_id' => $this->withPrice->id,
      'title' => $this->product->title,
    ]);
    $this->assertNotContains($this->product->id, $otherIds);
  }

  /** @test */
  public function test_available_in_shop_omitted_returns_product(): void
  {
    $ids = $this->paginateIds(['title' => $this->product->title]);

    $this->assertContains($this->product->id, $ids);
  }

  /** @test */
  public function test_available_in_shop_null_does_not_filter(): void
  {
    $ids = $this->paginateIds([
      'available_in_shop_id' => null,
      'title' => $this->product->title,
    ]);

    $this->assertContains($this->product->id, $ids);
  }

  /**
   * Сводка заполненности мерит ту же выборку, что и список.
   *
   * @test
   */
  public function test_fill_stats_follows_available_in_shop_filter(): void
  {
    $matched = $this->actingAs($this->admin)->graphQL(/** @lang GraphQL */ '
      query ($available_in_shop_id: Int, $title: String) {
        admin_products_fill_stats(available_in_shop_id: $available_in_shop_id, title: $title) {
          total
        }
      }
    ', ['available_in_shop_id' => $this->withPrice->id, 'title' => $this->product->title]);

    $matched->assertJsonMissingPath('errors');
    $this->assertSame(1, (int) $matched->json('data.admin_products_fill_stats.total'));

    $unmatched = $this->actingAs($this->admin)->graphQL(/** @lang GraphQL */ '
      query ($available_in_shop_id: Int, $title: String) {
        admin_products_fill_stats(available_in_shop_id: $available_in_shop_id, title: $title) {
          total
        }
      }
    ', ['available_in_shop_id' => $this->empty->id, 'title' => $this->product->title]);

    $unmatched->assertJsonMissingPath('errors');
    $this->assertSame(0, (int) $unmatched->json('data.admin_products_fill_stats.total'));
  }
}
