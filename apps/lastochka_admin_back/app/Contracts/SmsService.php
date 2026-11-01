<?php

namespace App\Contracts;

interface SmsService
{
  public function send(string $message, string $phone);
}
