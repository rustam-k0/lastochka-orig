<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>Панель управления</title>
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('assets/favicons/apple-touch-icon.png') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/favicons/favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('assets/favicons/favicon-16x16.png') }}">
  <link rel="manifest" href="{{ asset('assets/favicons/site.webmanifest') }}">
  <link rel="mask-icon" href="{{ asset('assets/favicons/safari-pinned-tab.svg') }}" color="#2b5797">
  <link rel="shortcut icon" href="{{ asset('assets/favicons/favicon.ico') }}">
  <meta name="msapplication-TileColor" content="#ffffff">
  <meta name="msapplication-config" content="{{ asset('assets/favicons/browserconfig.xml') }}">
  <meta name="theme-color" content="#ffffff">
  <link rel="stylesheet" href="{{ mix('distro/css/style.min.css') }}" type="text/css">
</head>
<body>
<div id="app"></div>
@if(isset($user))
<script>
window.user = {{ Illuminate\Support\Js::from($user) }};
</script>
@endif
<script src="{{ mix('distro/js/bundle.min.js') }}"></script>
</body>
</html>
