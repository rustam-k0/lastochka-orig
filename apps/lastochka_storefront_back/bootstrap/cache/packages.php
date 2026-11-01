<?php return array (
  'barryvdh/laravel-dompdf' => 
  array (
    'aliases' => 
    array (
      'PDF' => 'Barryvdh\\DomPDF\\Facade\\Pdf',
      'Pdf' => 'Barryvdh\\DomPDF\\Facade\\Pdf',
    ),
    'providers' => 
    array (
      0 => 'Barryvdh\\DomPDF\\ServiceProvider',
    ),
  ),
  'comrades/laravel-rabbitmq-events' => 
  array (
    'providers' => 
    array (
      0 => 'Comrades\\RabbitMQEvents\\RabbitMQEventsServiceProvider',
    ),
  ),
  'dedoc/scramble' => 
  array (
    'providers' => 
    array (
      0 => 'Dedoc\\Scramble\\ScrambleServiceProvider',
    ),
  ),
  'kreait/laravel-firebase' => 
  array (
    'aliases' => 
    array (
      'Firebase' => 'Kreait\\Laravel\\Firebase\\Facades\\Firebase',
    ),
    'providers' => 
    array (
      0 => 'Kreait\\Laravel\\Firebase\\ServiceProvider',
    ),
  ),
  'laravel/boost' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Boost\\BoostServiceProvider',
    ),
  ),
  'laravel/mcp' => 
  array (
    'aliases' => 
    array (
      'Mcp' => 'Laravel\\Mcp\\Server\\Facades\\Mcp',
    ),
    'providers' => 
    array (
      0 => 'Laravel\\Mcp\\Server\\McpServiceProvider',
    ),
  ),
  'laravel/pail' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Pail\\PailServiceProvider',
    ),
  ),
  'laravel/roster' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Roster\\RosterServiceProvider',
    ),
  ),
  'laravel/sanctum' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Sanctum\\SanctumServiceProvider',
    ),
  ),
  'laravel/tinker' => 
  array (
    'providers' => 
    array (
      0 => 'Laravel\\Tinker\\TinkerServiceProvider',
    ),
  ),
  'nesbot/carbon' => 
  array (
    'providers' => 
    array (
      0 => 'Carbon\\Laravel\\ServiceProvider',
    ),
  ),
  'nunomaduro/collision' => 
  array (
    'providers' => 
    array (
      0 => 'NunoMaduro\\Collision\\Adapters\\Laravel\\CollisionServiceProvider',
    ),
  ),
  'nunomaduro/termwind' => 
  array (
    'providers' => 
    array (
      0 => 'Termwind\\Laravel\\TermwindServiceProvider',
    ),
  ),
  'sentry/sentry-laravel' => 
  array (
    'aliases' => 
    array (
      'Sentry' => 'Sentry\\Laravel\\Facade',
    ),
    'providers' => 
    array (
      0 => 'Sentry\\Laravel\\ServiceProvider',
      1 => 'Sentry\\Laravel\\Tracing\\ServiceProvider',
    ),
  ),
);