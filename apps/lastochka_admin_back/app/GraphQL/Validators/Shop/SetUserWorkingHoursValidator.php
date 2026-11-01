<?php

namespace App\GraphQL\Validators\Shop;

use App\Models\Type;
use App\Models\UserShop;
use App\Rules\UniqueUserWorkingHour;
use App\Rules\WithinShopWorkingHours;
use Illuminate\Validation\Rule;
use Nuwave\Lighthouse\Validation\Validator;

final class SetUserWorkingHoursValidator extends Validator
{
  /**
   * Return the validation rules.
   *
   * @return array<string, array<mixed>>
   */
  public function rules(): array
  {
    return [
      'user_shop_id' => ['bail', 'required', Rule::exists((new UserShop())->getTable(), 'id')->whereNull('deleted_at')],
      'opening_time' => ['bail', 'required', 'date_format:H:i', 'before:closing_time',
        new WithinShopWorkingHours($this->arg('user_shop_id'), $this->arg('opening_time'), $this->arg('closing_time'), $this->arg('days') ?? []),
        new UniqueUserWorkingHour($this->arg('user_shop_id'), $this->arg('opening_time'), $this->arg('closing_time'), $this->arg('days') ?? [])
      ],
      'closing_time' => ['bail', 'required', 'date_format:H:i', 'after:opening_time'],
      'days' => ['required', 'min:1'],
      'days.*' => ['required', Rule::in(Type::getWeekDays())]
    ];
  }

  /**
   * Человекочитаемые тексты для админки (ALADDIN-12067) —
   * дефолтные содержат сырые имена полей («opening time», «user shop id»).
   *
   * @return array<string, string>
   */
  public function messages(): array
  {
    return [
      'user_shop_id.required' => 'Сотрудник не указан',
      'user_shop_id.exists' => 'Сотрудник магазина не найден',
      'opening_time.required' => 'Укажите время начала работы',
      'opening_time.date_format' => 'Время начала работы должно быть в формате ЧЧ:ММ',
      'opening_time.before' => 'Время начала работы должно быть раньше времени окончания',
      'closing_time.required' => 'Укажите время окончания работы',
      'closing_time.date_format' => 'Время окончания работы должно быть в формате ЧЧ:ММ',
      'closing_time.after' => 'Время окончания работы должно быть позже времени начала',
      'days.required' => 'Выберите хотя бы один день работы',
      'days.min' => 'Выберите хотя бы один день работы',
      'days.*.required' => 'Некорректный день недели',
      'days.*.in' => 'Некорректный день недели',
    ];
  }
}
