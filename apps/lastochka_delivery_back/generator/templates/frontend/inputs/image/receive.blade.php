@if($array)
  "{{ $data['field'] }}": { value: t["{{ $data['field'] }}"], defaultValue: null, errors: [], },
@else
  if (this.object["{{ $data['field'] }}"]) {
    let img = this.object["{{ $data['field'] }}"];
    this.form["{{ $data['field'] }}"].value = {
      id: img.id,
      path: img.path,
      origin_name: img.origin_name,
      alt: img.alt,
      size:
      img.size,
      type: img.type,
    };
  }
@endif
