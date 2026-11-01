@php
  $autofocus = isset($data['autofocus']) ? 'autofocus' : '';
  $title = $data['title'];
  $field = $data['field'];
  $rows = isset($data['rows']) ? "rows='{$data['rows']}'" : ''
@endphp
<TextareaComponent
  v-model.trim='{{ $form }}["{{ $field }}"].value'
  :errors='{{ $form }}["{{ $field }}"].errors'
  title='{{ $title }}'
  {!! $rows !!}
  {!! $autofocus !!}
/>
