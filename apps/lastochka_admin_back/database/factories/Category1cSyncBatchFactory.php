<?php

namespace Database\Factories;

use App\Models\Category1cSyncBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category1cSyncBatch>
 */
class Category1cSyncBatchFactory extends Factory
{
  protected $model = Category1cSyncBatch::class;

  public function definition(): array
  {
    return [
      'shop_id' => ShopFactory::new(),
      'status' => 'PENDING',
      'progress_total' => 0,
      'progress_done' => 0,
      'started_at' => now(),
      'completed_at' => null,
      'error' => null,
      'metadata' => null,
    ];
  }
}
