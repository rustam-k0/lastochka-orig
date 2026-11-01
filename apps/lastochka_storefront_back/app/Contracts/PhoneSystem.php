<?php

namespace App\Contracts;


interface PhoneSystem
{
  public function sendPhoneConfirmation(string $phone, string $code): mixed;
}
