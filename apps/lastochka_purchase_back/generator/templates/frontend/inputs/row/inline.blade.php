@php
  $value = '';
  $onlyInline = isset($onlyInline) ? $onlyInline : false;
  foreach ($data['fields'] as $item) {
    if (isset($item['editable']) && $item['editable'] && !$onlyInline) {
      $value .= view('frontend.inputs.'. $item['type'] .'.markup', [
        'data' => $item,
        'form' => 'form'
      ])->render();
    } else {
      $value .= view('frontend.inputs.'. $item['type'] .'.inline', [
        'data' => $item,
        'onlyInline' => $onlyInline
      ])->render();
    }
  }
@endphp
<div class="form__row">
  {!! $value !!}
</div>
