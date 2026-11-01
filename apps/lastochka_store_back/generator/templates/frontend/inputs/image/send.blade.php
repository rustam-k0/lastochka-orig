@if($array)
  "{{ $data['field'] }}": o["{{ $data['field'] }}"].value ? o["{{ $data['field'] }}"].value : undefined,
@endif
