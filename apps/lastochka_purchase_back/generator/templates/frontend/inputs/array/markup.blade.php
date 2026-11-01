@php
  $autofocus = isset($data['autofocus']) ? 'autofocus' : '';
  $title = $data['title'];
  $field = $data['field'];
  $inputs = '';
  foreach ($data['fields'] as $item) {
      $inputs .= view('frontend.inputs.'. $item['type'] .'.markup', [
        'data' => $item,
        'form' => 'item'
      ])->render();
  }
@endphp
<ArrayFieldComponent
  v-model.trim='{{ $form }}["{{ $field }}"].value'
  :template='{{ $field }}TemplateField'
  title='{{ $title }}'
>
  <template v-slot='{ item }'>
    {!! $inputs !!}
  </template>
</ArrayFieldComponent>
