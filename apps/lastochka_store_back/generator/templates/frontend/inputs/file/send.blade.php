@if($array)
  @if(isset($data['multiple']) && $data['multiple'])
    "{{ $data['field'] }}": o["{{ $data['field'] }}"].value ? o["{{ $data['field'] }}"].value : [],
  @else
    "{{ $data['field'] }}": o["{{ $data['field'] }}"].value.length ? o["{{ $data['field'] }}"].value[0] : undefined,
  @endif
@else
  @if(!(isset($data['multiple']) && $data['multiple']))
    variables["{{ $data['field'] }}"] = undefined;
    if (this.form["{{ $data['field'] }}"].value.length) {
      variables["{{ $data['field'] }}"] = this.form["{{ $data['field'] }}"].value[0]
    }
  @endif
@endif
