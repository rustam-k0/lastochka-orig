@php
  $mode = isset($data['mode']) ? "mode='{$data['mode']}'" : '';
  $range = isset($data['range']) ? "range" : '';
  $title = $data['title'];
  $placeholder = isset($data['placeholder']) ? "placeholder='{$data['placeholder']}'" : '';
  $field = $data['field'];
@endphp
<DateComponent
  v-model.trim='{{ $form }}["{{ $field }}"].value'
  :errors='{{ $form }}["{{ $field }}"].errors'
  title='{{ $title }}'
  {!! $placeholder !!}
  {!! $range !!}
  {!! $mode !!}
/>
