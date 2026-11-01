@if(isset($data['multiple']) && $data['multiple'])
  "{{ $data['field'] }}": { value: [], defaultValue: [], errors: [], },
@else
  "{{ $data['field'] }}": { value: null, defaultValue: null, errors: [], },
@endif
