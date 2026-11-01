<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Comparison;

use Illuminate\Foundation\Http\FormRequest;

final class AddComparisonItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'productId' => ['required', 'integer', 'exists:products,id'],
        ];
    }

    public function productId(): int
    {
        return (int) $this->validated('productId');
    }
}
