<?php return array (
  'App\\Providers\\EventServiceProvider' => 
  array (
    'Illuminate\\Auth\\Events\\Registered' => 
    array (
      0 => 'Illuminate\\Auth\\Listeners\\SendEmailVerificationNotification',
    ),
    'App\\Events\\ProductUpdated' => 
    array (
      0 => 'App\\Listeners\\RecalculateProductFillPercent',
    ),
    'App\\Events\\ShopCategoryDetached' => 
    array (
      0 => 'App\\Listeners\\PublishShopCategoryDetachedEvent',
    ),
  ),
);