<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProductStockChanged
{
    use Dispatchable, SerializesModels;

    public Product $product;
    public float $oldQuantity;
    public float $newQuantity;
    public ?string $shopCode;

    /**
     * Create a new event instance.
     *
     * @param Product $product
     * @param float $oldQuantity Остаток до изменения (кг с дробной частью для весовых)
     * @param float $newQuantity Остаток после изменения
     * @param string|null $shopCode
     * @return void
     */
    public function __construct(Product $product, float $oldQuantity, float $newQuantity, ?string $shopCode = null)
    {
        $this->product = $product;
        $this->oldQuantity = $oldQuantity;
        $this->newQuantity = $newQuantity;
        $this->shopCode = $shopCode;
    }
}
