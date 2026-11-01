@php
  $title = $data['title'];
  if (isset($data['receiveJoin'])) {
    $value  = "{{ object['{$data['receiveJoin']}']['{$data['receiveJoinParam']}'] === {$data['receiveJoinParamValue']} }}";
  } else {
    $value  = "{{ object['{$data['field']}'] ? 'Да' : 'Нет'  }}";
  }
@endphp
<div class="form__item">
  <b>{{ $title }}</b>
  <span>{!! $value !!}</span>
</div>
