<?php

namespace App\Rules;

use App\Models\User;
use App\Models\UserShop;
use App\Models\UserWorkingHour;
use Illuminate\Contracts\Validation\Rule;

class UniqueUserWorkingHour implements Rule
{
  protected $user_shop_id;
  protected $opening_time;
  protected $closing_time;
  protected $days;

  /**
   * Create a new rule instance.
   *
   * @return void
   */
  public function __construct($user_shop_id, $opening_time, $closing_time, $days = [])
  {
    $this->user_shop_id = $user_shop_id;
    $this->opening_time = $opening_time;
    $this->closing_time = $closing_time;
    $this->days = $days;
  }

  /**
   * Determine if the validation rule passes.
   *
   * @param string $attribute
   * @param mixed $value
   * @return bool
   */
  public function passes($attribute, $value)
  {
    if (!$this->checkFields()) {
      return true;
    }

    $user = UserShop::find($this->user_shop_id)?->user;

    if (!$user) {
      return true;
    }

    foreach ($this->days as $day) {

      // Ищем другие рабочие часы сотрудника в определенный день($day), которые пересекаются с переданными в `opening_time` и `closing_time`
      // в магазинах, отличных от текущего.
      $exists = UserWorkingHour::whereHas('userShop.user', function ($q) use ($user) {
        $q->where(User::table . '.id', $user->id);
      })
        ->where(function ($query){
          $this->checkWorkingIntervals($query);
        })
        ->whereDayOfWeek($day)
        ->where('user_shop_id', '<>', $this->user_shop_id)
        ->exists();

      if ($exists) {
        return false;
      }

    }

    return true;
  }

  /**
   * Get the validation error message.
   *
   * @return string
   */
  public function message()
  {
    return 'Сотрудник не может работать одновременно в нескольких магазинах';
  }

  private function checkFields(): bool
  {
    return $this->user_shop_id && $this->opening_time && $this->closing_time;
  }

  /**
   * Ищем пересечения в интервалах рабочих часов
   * Сверху отправленный промежуток
   * Снизу искомый промежуток
   */
  public function checkWorkingIntervals($query): void
  {
    $query->where(function ($q){
      //         (opening_time)  ----------------  (closing_time)
      //                          |----------|
      $q->where('opening_time','>=',$this->opening_time)->where('closing_time','<=',$this->closing_time);
    })
    ->orWhere(function ($q){
      //           (opening_time)  ----------------  (closing_time)
      //       |--------------------|
      $q->where('opening_time','<=',$this->opening_time)->where('closing_time','>=',$this->opening_time)->where('closing_time','<=',$this->closing_time);
    })
    ->orWhere(function ($q){
      //           (opening_time)  -------  (closing_time)
      //      |----------------------------------------------|
      $q->where('opening_time','<=',$this->opening_time)->where('closing_time','>=',$this->closing_time);
    })
    ->orWhere(function ($q){
      //     (opening_time)  ----------------  (closing_time)
      //                       |----------------------------------|
      $q->where('opening_time','>=',$this->opening_time)->where('opening_time','<=',$this->closing_time)->where('closing_time','>=',$this->closing_time);
    });

  }
}
