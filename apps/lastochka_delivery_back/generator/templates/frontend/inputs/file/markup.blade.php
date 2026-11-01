@php
  $title = $data['title'];
  $multiple = isset($data['multiple']) ? ":multiple='true'" : '';
  $field = $data['field'];
@endphp
<FileUploadComponent
  :uploaded='{{ $form }}["{{ $field }}"].value'
  @change='{{ $form }}["{{ $field }}"].value = $event'
  title='{{ $title }}'
  {!! $multiple !!}
/>
