@if($array)
  @if(isset($data['multiple']) && $data['multiple'])
    "{{ $data['field'] }}": o["{{ $data['field'] }}"].value ? o["{{ $data['field'] }}"].value.map((v) => v["{{ $data['idName'] }}"]) : [],
  @else
    "{{ $data['field'] }}": o["{{ $data['field'] }}"].value ? o["{{ $data['field'] }}"].value["{{ $data['idName'] }}"] : undefined,
  @endif
@else
  @if(isset($data['multiple']) && $data['multiple'])
    variables["{{ $data['field'] }}"] = [];
    if (this.form["{{ $data['field'] }}"].value.length) {
      variables["{{ $data['field'] }}"] = this.form["{{ $data['field'] }}"].value.map(v => v["{{ $data['idName'] }}"])
    }
  @else
    variables["{{ $data['field'] }}"] = undefined;
    if (this.form["{{ $data['field'] }}"].value) {
      variables["{{ $data['field'] }}"] = this.form["{{ $data['field'] }}"].value["{{ $data['idName'] }}"]
    }
  @endif
@endif
