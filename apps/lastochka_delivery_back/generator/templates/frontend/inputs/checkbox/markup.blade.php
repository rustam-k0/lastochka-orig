@php
  $title = $data['title'];
  $field = $data['field'];
@endphp
<CheckboxComponent
  :checked='{{ $form }}["{{ $field }}"].value === true'
  :errors='{{ $form }}["{{ $field }}"].errors'
  @change='{{ $form }}["{{ $field }}"].value = !{{ $form }}["{{ $field }}"].value'
>
  <span>{!! $title !!}</span>
</CheckboxComponent>
