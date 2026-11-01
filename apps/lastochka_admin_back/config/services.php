<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Third Party Services
  |--------------------------------------------------------------------------
  |
  | This file is for storing the credentials for third party services such
  | as Mailgun, Postmark, AWS and more. This file provides the de facto
  | location for this type of information, allowing packages to have
  | a conventional file to locate the various service credentials.
  |
  */

  'mailgun' => [
    'domain' => env('MAILGUN_DOMAIN'),
    'secret' => env('MAILGUN_SECRET'),
    'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
  ],

  'postmark' => [
    'token' => env('POSTMARK_TOKEN'),
  ],

  'ses' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
  ],
  'smsaero'=>[
    'api_key'=>env('SMSAERO_API_KEY'),
    'email'=>env('SMSAERO_EMAIL'),
    'sign'=>env('SMSAERO_SIGN', 'SMS Aero'),
  ],

  /*
  | Название сервиса, которое подставляется в текст SMS с кодом и паролем.
  | Приоритет: настройка `sms_service_name` в админке → `admin_title` →
  | это значение → APP_NAME. Операторы блокируют SMS без названия сервиса.
  */
  'sms' => [
    'service_name' => env('SMS_SERVICE_NAME'),
  ],
  'system' => [
    'token' => env('APP_TOKEN'),
  ],

  '1c_parser' => [
    'url' => env('ONEC_PARSER_URL'),
  ],

  /*
  | Очередь фоновой загрузки фото товаров из 1С (SyncProductImagesFromOneCJob,
  | MID-305). По умолчанию общая очередь default; отдельная очередь имеет смысл
  | только вместе с отдельным воркером, который её слушает.
  */
  'onec_photos' => [
    'queue' => env('ONEC_PHOTOS_QUEUE', 'default'),
  ],

];
