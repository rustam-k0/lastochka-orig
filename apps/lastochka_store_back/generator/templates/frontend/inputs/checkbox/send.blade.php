@if($array)
  "{{ $data['field'] }}": o["{{ $data['field'] }}"] ? o["{{ $data['field'] }}"].value : undefined,
@endif
