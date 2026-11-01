<?php

namespace Database\Seeders;

use App\Models\ExplainReason;
use Illuminate\Database\Seeder;

class ExplainReasonSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    ExplainReason::create([
      'title' => 'Упаковка товара повреждена'
    ]);
  }
}
