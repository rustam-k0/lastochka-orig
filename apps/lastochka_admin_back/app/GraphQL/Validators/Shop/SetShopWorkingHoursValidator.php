<?php

namespace App\GraphQL\Validators\Shop;

use App\Models\Shop;
use App\Models\Type;
use App\Rules\WithinUserWorkingHours;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class SetShopWorkingHoursValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'shop_id' => ['bail', 'required', Rule::exists((new Shop())->getTable(), 'id')->whereNull('deleted_at')],
      'type_id' => ['required', Rule::in([Type::WORK_SCHEDULE, Type::WORK_ALL_DAY])],
      'opening_time' => ['bail', 'required_if:type_id,' . Type::WORK_SCHEDULE, 'date_format:H:i', 'before:closing_time',
        new WithinUserWorkingHours($this->arg('shop_id'), $this->arg('type_id'), $this->arg('opening_time'), $this->arg('closing_time'), $this->arg('days') ?? []),
      ],
      'closing_time' => ['bail', 'required_if:type_id,' . Type::WORK_SCHEDULE, 'date_format:H:i', 'after:opening_time'],
      'days' => ['required', 'min:1'],
      'days.*' => ['required', Rule::in(Type::getWeekDays())]
    ];
  }

  /**
   * Человекочитаемые тексты для админки (ALADDIN-12067) —
   * дефолтные содержат сырые имена полей («opening time», «type id»).
   *
   * @return array<string, string>
   */
  public function messages(): array
  {
    return [
      'shop_id.required' => 'Магазин не указан',
      'shop_id.exists' => 'Магазин не найден',
      'type_id.required' => 'Укажите тип графика работы',
      'type_id.in' => 'Некорректный тип графика работы',
      'opening_time.required_if' => 'Укажите время открытия',
      'opening_time.date_format' => 'Время открытия должно быть в формате ЧЧ:ММ',
      'opening_time.before' => 'Время открытия должно быть раньше времени закрытия',
      'closing_time.required_if' => 'Укажите время закрытия',
      'closing_time.date_format' => 'Время закрытия должно быть в формате ЧЧ:ММ',
      'closing_time.after' => 'Время закрытия должно быть позже времени открытия',
      'days.required' => 'Выберите хотя бы один день работы',
      'days.min' => 'Выберите хотя бы один день работы',
      'days.*.required' => 'Некорректный день недели',
      'days.*.in' => 'Некорректный день недели',
    ];
  }
}
