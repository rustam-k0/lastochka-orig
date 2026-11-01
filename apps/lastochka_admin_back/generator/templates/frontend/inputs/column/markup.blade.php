@php
  $inputs = '';
  foreach ($data['fields'] as $item) {
      $inputs .= view('frontend.inputs.'. $item['type'] .'.markup', [
        'data' => $item,
        'form' => $form
      ])->render();
  }
@endphp
<div class="form__col">{!! $inputs !!}</div>
