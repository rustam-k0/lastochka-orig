@php
  $autofocus = isset($data['autofocus']) ? 'autofocus' : '';
  $title = $data['title'];
  $field = $data['field'];
  $inputs = '';
  $editable = isset($data['editable']) && $data['editable'];
  foreach ($data['fields'] as $item) {
    $inputs .= view('frontend.inputs.'. $item['type'] .'.inline', [
      'data' => $item,
      'onlyInline' => true,
    ])->render();
  }
@endphp
@if($editable)
  <div class="form__item">
    <b>{{ $title }}</b>
    <div class="form__inputs">
      {!! $inputs !!}
    </div>
  </div>
@endif
