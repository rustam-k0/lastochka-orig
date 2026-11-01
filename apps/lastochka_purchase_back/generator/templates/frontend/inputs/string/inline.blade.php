@php
  $title = $data['title'];
  $value  = "{{ object['{$data['field']}'] }}";
@endphp
<div class="form__item">
  <b>{{ $title }}</b>
  <span>{!! $value !!}</span>
</div>
