<?php

namespace App\Rules;

use App\Models\AssemblyItem;
use App\Models\Status;
use Illuminate\Contracts\Validation\Rule;

class AssemblyItemExistsRule implements Rule
{
    /**
     * Create a new rule instance.
     *
     * @param  bool  $allowAssembled  Пропускать уже собранные позиции (статус
     *   PRODUCT_ASSEMBLED). Нужно только ProductConfirm: повторное нажатие
     *   «Собрать» / повторный скан того же штрих-кода должны разбираться в
     *   мутации (идемпотентный OK либо «Товар уже собран»), а не отбиваться
     *   валидацией с бессмысленным для сборщика «Товара нет в заказе».
     * @return void
     */
    public function __construct(private bool $allowAssembled = false)
    {
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        $statuses = [Status::PRODUCT_WAIT, Status::PRODUCT_CLARIFY];

        if ($this->allowAssembled) {
            $statuses[] = Status::PRODUCT_ASSEMBLED;
        }

        return AssemblyItem::whereId($value)
          ->auth()
          ->whereIn('status_id', $statuses)
          ->exists();
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Товара нет в заказе';
    }
}
