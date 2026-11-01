@php
  // Компоненты полей ввода
  $components = '';
  $inputs = \App\Console\Commands\GeneratorStart::getInputsUnique($data);

  if ($data['type'] === 'modal') {// Фикс импорта для статьи в модалке
    if ($inputs->contains(function ($value) {
      return $value['type'] == 'article' && $value['editable'] == false;
    })) {
      $components .= "EditorJSRenderer,\n";
    }
    foreach ($inputs as $item) {
      // Если поле редактируемое
      if (isset($item['editable']) && $item['editable'] && $item['type'] !== 'array') {
        $components .= view('frontend.inputs.'. $item['type'] .'.component')->render();
      }
    }
  } else {
    foreach ($inputs as $item) {
        $components .= view('frontend.inputs.'. $item['type'] .'.component')->render();
    }
  }
@endphp
{!! $components !!}
