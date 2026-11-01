@if($array)
  "{{ $data['field']}}": { value: [t["{{$data['field']}}"]].flat(), defaultValue: null, errors: [], },
@else
  this.form["{{ $data['field'] }}"].value = [this.object["{{ $data['field'] }}"]].filter(v => !!v).flat();
@endif
