<?php

namespace App\Events;

use App\Models\Category;
use App\Models\Shop;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShopCategoryDetached
{
  use Dispatchable, SerializesModels;

  public Category $category;
  public Shop $shop;

  public function __construct(Category $category, Shop $shop)
  {
    $this->category = $category;
    $this->shop = $shop;
  }
}
