<?php

namespace App\Rules;

use App\Models\ShopWorkingHour;
use App\Models\Type;
use App\Models\UserShop;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Rule;

class WithinShopWorkingHours implements Rule
{
  protected $user_shop_id;
  protected $opening_time;
  protected $closing_time;
  protected $days;

  public function __construct($user_shop_id, $opening_time, $closing_time, $days = [])
  {
    $this->user_shop_id = $user_shop_id;
    $this->opening_time = \DateTime::createFromFormat('H:i',$opening_time);
    $this->closing_time = \DateTime::createFromFormat('H:i',$closing_time);
    $this->days = $days;
  }

  public function passes($attribute, $value)
  {
    if (!($this->fieldsExists())) {
      return true;
    }

    $shop =  UserShop::find($this->user_shop_id)?->shop;

    if (!$shop) {
      return true;
    }

    foreach ($this->days as $day) {

      $shop_working_hour = ShopWorkingHour::where('shop_id', $shop->id)->whereDayOfWeek($day)->first();

      if (!$shop_working_hour) {
        return false;
      }

      if ($shop_working_hour->type_id == Type::WORK_ALL_DAY) {
        return true;
      }

      $shop_opening_time = new \DateTime($shop_working_hour->opening_time);
      $shop_closing_time = new \DateTime($shop_working_hour->closing_time);

      $correct_time = $this->opening_time >= $shop_opening_time && $this->closing_time <= $shop_closing_time;

      if (!$correct_time) {
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
    return 'График работы сотрудника выбран не в соответствии с графиком работы магазина';
  }

  /**
   * @return bool
   */
  protected function fieldsExists(): bool
  {
    return $this->user_shop_id && $this->opening_time && $this->closing_time;
  }
}
