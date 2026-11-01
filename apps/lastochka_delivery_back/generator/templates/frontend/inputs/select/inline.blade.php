@php
  $title = $data['title'];
  $field = "object['{$data['field']}']";
  $value  = "{{ object['{$data['field']}'].map(v => v[{$data['labelName']}]).join(', ') }}";
@endphp
<div class="form__item">
  <b>{{ $title }}</b>
  <span v-if="{!! $field !!}">{!! $value !!}</span>
</div>
