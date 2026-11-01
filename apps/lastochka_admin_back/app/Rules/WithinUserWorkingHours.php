<?php

namespace App\Rules;

use App\Models\Shop;
use App\Models\ShopWorkingHour;
use App\Models\Type;
use App\Models\UserShop;
use Carbon\Carbon;
use App\Models\UserWorkingHour;
use Illuminate\Contracts\Validation\Rule;

class WithinUserWorkingHours implements Rule
{
  protected $shop_id;
  protected $type_id;
  protected $opening_time;
  protected $closing_time;
  protected $days;

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct($shop_id, $type_id, $opening_time, $closing_time, $days = [])
    {
      $this->shop_id = $shop_id;
      $this->type_id = $type_id;
      $this->opening_time = \DateTime::createFromFormat('H:i',$opening_time);
      $this->closing_time = \DateTime::createFromFormat('H:i',$closing_time);
      $this->days = $days;
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
//    Если магазин работает круглосуточно - нелзья добавить график работы
      if ($this->type_id == Type::WORK_ALL_DAY) {
        return false;
      }

//    Получаем всех сотрудников работащих в определенном магазине
      $user_shops = UserShop::where('shop_id', $this->shop_id)->get();

//    Если у магазина нет сотрудника можно выставлять любой график работы магазина
      if (!$user_shops) {
        return true;
      }

      foreach ($this->days as $day)
      {
        foreach ($user_shops as $user_shop)
        {
//        Находим время работы сотрудника в определенный день
          $user_working_hours = UserWorkingHour::where('user_shop_id', $user_shop->id)->whereDayOfWeek($day)->first();

//        Находим дни работы сотрудника
          $user_working_days = UserWorkingHour::where('user_shop_id', $user_shop->id)->get('day_of_week');

//        Проверяем учитываются ли рабочие дни сотрудников магазина при изменении рабочих дней магазина
          foreach ($user_working_days as $user_working_day) {
            if (!in_array($user_working_day['day_of_week'], $this->days)) {
              return false;
            }
          }

//        Если сотрудник не работает в этот день - пропускаем итерацию
          if (!$user_working_hours) {
            continue;
          }

//        Находим время начала и конца работы сотрудника
          $user_opening_time = new \DateTime($user_working_hours->opening_time);
          $user_closing_time = new \DateTime($user_working_hours->closing_time);

//        Новое время открытия магазина должно быть меньше либо равно времени начала работы каждого сотрудника
//        Новое время закрытия магазина должно быть больше либо равно времени конца работы каждого сотрудника
          $correct_time = $this->opening_time <= $user_opening_time && $this->closing_time >= $user_closing_time;

          if (!$correct_time) {
            return false;
          }
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
        return 'График работы магазина выбран не в соответствии с графиком работы сотрудников. Или магазин работает круглосуточно';
    }

}
