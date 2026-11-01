@php
  $autofocus = isset($data['autofocus']) ? 'autofocus' : '';
  $inputType = isset($data['inputType']) ? "type='{$data['inputType']}'" : '';
  $max = isset($data['max']) ? ":max='{$data['max']}'" : '';
  $min = isset($data['min']) ? ":min='{$data['min']}'" : '';
  $title = $data['title'];
  $field = $data['field'];
@endphp
<InputComponent
  v-model.trim='{{ $form }}["{{ $field }}"].value'
  :errors='{{ $form }}["{{ $field }}"].errors'
  title='{{ $title }}'
  {!! $inputType !!}
  {!! $autofocus !!}
  {!! $max !!}
  {!! $min !!}
/>
