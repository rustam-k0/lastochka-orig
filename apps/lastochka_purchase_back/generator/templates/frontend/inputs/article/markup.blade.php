@php
  $autofocus = isset($data['autofocus']) ? 'autofocus' : '';
  $title = $data['title'];
  $placeholder = $data['placeholder'];
  $field = $data['field'];
@endphp
<EditorJSComponent
  v-if="!loading"
  v-model.trim='{{ $form }}["{{ $field }}"].value'
  :errors='{{ $form }}["{{ $field }}"].errors'
  placeholder='{{ $placeholder }}'
  title='{{ $title }}'
  {!! $autofocus !!}
/>
