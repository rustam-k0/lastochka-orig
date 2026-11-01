<?php

namespace App\Services;

class PhoneFormatter
{

  /**
   * Убирает в строке всё кроме цифр и символа +
   * @param string $phone
   * @return string
   */
  public static function format(string $phone): string
  {
    return preg_replace("/([^\d+])/", "", $phone);
  }
}
