@php
  // Импорты компонентов полей ввода
  $imports = '';
  $inputs = \App\Console\Commands\GeneratorStart::getInputsUnique($data);
  if ($data['type'] === 'modal') {
    // Фикс импорта для статьи в модалке
    if ($inputs->contains(function ($value) {
      return $value['type'] == 'article' && $value['editable'] == false;
    })) {
      $imports .= "import EditorJSRenderer from '@/components/EditorJSComponent.vue'\n";
    }
    foreach ($inputs as $item) {
      // Если поле редактируемое
      if (isset($item['editable']) && $item['editable'] && $item['type'] !== 'array') {
        $imports .= view('frontend.inputs.'. $item['type'] .'.import')->render();
      }
    }
  } else {
    foreach ($inputs as $item) {
      $imports .= view('frontend.inputs.'. $item['type'] .'.import')->render();
    }
  }
  // Фикс импорта даты (если участвует дата то обязательно используется moment)
  if ($inputs->contains('type', 'date')) {
    $imports .= "import moment from 'moment'\n";
  }
  // Импорты graphql запросов
  if (isset($data['graphql'])) {
    foreach ($data['graphql'] as $key => $graphql) {;
      if (!isset($graphql['independent'])) {
        $name = strtoupper($key);
        $folderName = strtolower($data['name']);
        $path = "@/graphql/generated/{$folderName}/{$graphql['name']}.graphql";
        $imports .= "import $name from '$path';\n";
      }
    }
  }
@endphp
{!! $imports !!}
