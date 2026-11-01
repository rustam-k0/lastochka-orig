<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Banner;

use App\Services\Settings\ModuleConfigService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class GetStoreBannersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Место показа: ключ кастомного чекбокса баннера из настроек
             * админки (Настройки → Чекбоксы → Баннеры). Отдаются только баннеры,
             * у которых этот тумблер включён. Без параметра — все баннеры
             * главной (слайдер). Список допустимых значений динамический
             * (например main_after_catalog, product_card) и возвращается
             * в тексте ошибки 422.
             *
             * @example main_after_catalog
             */
            'placement' => ['sometimes', 'nullable', 'string', $this->placementRule()],
        ];
    }

    /**
     * Место показа или null, если параметр не передан (пустая строка = не передан).
     */
    public function placement(): ?string
    {
        $placement = $this->validated('placement');

        return is_string($placement) && $placement !== '' ? $placement : null;
    }

    /**
     * Допустимые значения берутся из настроек в момент запроса, поэтому не
     * Rule::in (его список зафиксировался бы в OpenAPI при генерации доки).
     */
    private function placementRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $allowed = array_column(app(ModuleConfigService::class)->bannerPlacements(), 'field');

            if ($allowed === []) {
                $fail('Места показа баннеров не настроены');

                return;
            }

            if (! in_array($value, $allowed, true)) {
                $fail('Допустимые значения: '.implode(', ', $allowed));
            }
        };
    }
}
