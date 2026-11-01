@if($array)
  @if(isset($data['inputType']) && $data['inputType'] === 'number')
    "{{ $data['field'] }}": o["{{ $data['field'] }}"].value !== undefined ? parseInt(o["{{ $data['field'] }}"].value) : undefined,
  @else
    "{{ $data['field'] }}": o["{{ $data['field'] }}"].value ? o["{{ $data['field'] }}"].value : undefined,
  @endif
@else
  @if(isset($data['inputType']) && $data['inputType'] === 'number')
    variables["{{ $data['field'] }}"] = undefined;
    if (this.form["{{ $data['field'] }}"].value) {
    variables["{{ $data['field'] }}"] = parseInt(this.form["{{ $data['field'] }}"].value)
    }
  @endif
@endif
