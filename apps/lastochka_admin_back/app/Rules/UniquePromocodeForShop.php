<?php

namespace App\Rules;

use App\Models\MerchantPromocode;
use Illuminate\Contracts\Validation\InvokableRule;

class UniquePromocodeForShop implements InvokableRule
{
    protected ?int $shopId;
    protected ?int $excludeId;

    public function __construct(?int $shopId, ?int $excludeId = null)
    {
        $this->shopId = $shopId;
        $this->excludeId = $excludeId;
    }

    /**
     * Run the validation rule.
     *
     * @param string $attribute
     * @param mixed $value
     * @param \Closure $fail
     * @return void
     */
    public function __invoke($attribute, $value, $fail)
    {
        if ($this->shopId === null) {
            return;
        }

        $code = mb_strtoupper(trim($value));

        $query = MerchantPromocode::where('shop_id', $this->shopId)
            ->where('code', $code);

        if ($this->excludeId !== null) {
            $query->where('id', '!=', $this->excludeId);
        }

        if ($query->exists()) {
            $fail('Промокод с таким кодом уже существует в этом магазине.');
        }
    }
}
