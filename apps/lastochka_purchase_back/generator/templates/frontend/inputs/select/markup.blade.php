@php
  $autofocus = isset($data['autofocus']) ? 'autofocus' : '';
  $clearable = isset($data['clearable']) ? "clearable='{$data['clearable']}'" : '';
  $labelName = isset($data['labelName']) ? "label-name='{$data['labelName']}'" : '';
  $idName = isset($data['idName']) ? "id-name='{$data['idName']}'" : '';
  $multiple = isset($data['multiple']) ? ":multiple='true'" : '';
  $title = $data['title'];
  $field = $data['field'];
  $options = '[]';
  if ($data['options']['type'] == 'query') {
   $options = "options='{$data['options']['name']}'";
  }
  if ($data['options']['type'] == 'array') {
      $options = ":options='{$field}OptionsArray'";
  }
@endphp
<SelectComponent
  v-model.trim='{{ $form }}["{{ $field }}"].value'
  :errors='{{ $form }}["{{ $field }}"].errors'
  title='{{ $title }}'
  {!! $options !!}
  {!! $clearable !!}
  {!! $labelName !!}
  {!! $idName !!}
  {!! $multiple !!}
/>
