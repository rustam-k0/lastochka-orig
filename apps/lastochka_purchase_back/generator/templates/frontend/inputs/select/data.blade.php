@if($array)
  @if(isset($data['multiple']) && $data['multiple'])
    "{{ $data['field'] }}": { value: [], defaultValue: [], errors: [], },
  @else
    "{{ $data['field'] }}": { value: null, defaultValue: null, errors: [], },
  @endif
@else
  @if ($data['options']['type'] == 'array')
    "{{ $data['field'] }}OptionsArray": {!! json_encode($data['options']['array'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !!},
  @endif
@endif
