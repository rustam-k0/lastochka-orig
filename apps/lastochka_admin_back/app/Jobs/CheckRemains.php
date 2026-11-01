<?php

namespace App\Jobs;

use App\Models\Price;
use App\Models\Product;
use App\Models\Remain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckRemains implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  public $failOnTimeout = true;
  public $timeout = 0;

  /**
   * Create a new job instance.
   *
   * @return void
   */
  public function __construct()
  {

  }

  /**
   * Execute the job.
   *
   * @return void
   */
  public function handle()
  {
    $products = Product::with(['remains', 'categories', 'prices'])->get();

    foreach ($products as $product) {

      if (!count($product->prices)) {
        continue;
      }

      $min_quantity = $this->getMinimumQuantity($product->categories);

      foreach ($product->prices as $price) {
        // Остатки по конкретному магазину
        $remain = $product->remains->where('shop_id', $price->shop_id)->first();

        $this->updateRemain($remain, $price, $min_quantity);
      }
    }
  }

  public function getMinimumQuantity($categories)
  {
    $min_quantity = 0;

    foreach ($categories as $category) {
      if ($category->min_quantity) {
        if ($min_quantity == 0) {
          $min_quantity = $category->min_quantity;
        } elseif ($min_quantity > $category->min_quantity) {
          $min_quantity = $category->min_quantity;
        }
      }
    }

    return $min_quantity;
  }

  public function updateRemain(?Remain $remain, Price $price, int $min_quantity): void
  {


    // Если у товара нет остатков, скрываем его с витрины
    if (!$remain) {
      $price->update([
        'blocked' => true
      ]);
      return;
    }

    // Если остатков меньше чем минимум, скрываем его с витрины
    $price->update([
      'blocked' => $remain->quantity < $min_quantity
    ]);
  }
}
