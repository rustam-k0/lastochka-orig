@php
  $autofocus = isset($data['autofocus']) ? 'autofocus' : '';
  $width = isset($data['width']) ? "width='{$data['width']}'" : '';
  $height = isset($data['height']) ? "height='{$data['height']}'" : '';
  $title = $data['title'];
  $field = $data['field'];
@endphp
<ImageCropComponent
  v-model.trim='{{ $form }}["{{ $field }}"].value'
  :errors='{{ $form }}["{{ $field }}"].errors'
  title='{{ $title }}'
  {!! $width !!}
  {!! $height !!}
  {!! $autofocus !!}
/>
