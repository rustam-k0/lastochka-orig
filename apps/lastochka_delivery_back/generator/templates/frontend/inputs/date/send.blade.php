@if($array)
  @if(isset($data['range']))
    "{{ $data['field'] }}_start_date": o["{{ $data['field'] }}"].value ? moment(o["{{ $data['field'] }}"].value.start).format('YYYY-MM-DD HH:mm:ss') : undefined,
    "{{ $data['field'] }}_end_date": o["{{ $data['field'] }}"].value ? moment(o["{{ $data['field'] }}"].value.end).format('YYYY-MM-DD HH:mm:ss') : undefined,
  @else
    "{{ $data['field'] }}": o["{{ $data['field'] }}"].value ? moment(o["{{ $data['field'] }}"].value).format('YYYY-MM-DD HH:mm:ss') : undefined,
  @endif
@else
  @if(isset($data['range']))
    variables["{{ $data['field'] }}"] = undefined
    if (this.form["{{ $data['field'] }}"].value) {
      variables["{{ $data['field'] }}_start_date"] = moment(this.form["{{ $data['field'] }}"].value.start).format('YYYY-MM-DD HH:mm:ss')
      variables["{{ $data['field'] }}_end_date"] = moment(this.form["{{ $data['field'] }}"].value.end).format('YYYY-MM-DD HH:mm:ss')
    }
  @else
    variables["{{ $data['field'] }}"] = undefined
    if (this.form["{{ $data['field'] }}"].value) {
      variables["{{ $data['field'] }}"] = moment(this.form["{{ $data['field'] }}"].value).format('YYYY-MM-DD HH:mm:ss')
    }
  @endif
@endif
