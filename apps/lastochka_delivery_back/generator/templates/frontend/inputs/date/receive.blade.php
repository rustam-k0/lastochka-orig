@if($array)
  @if(isset($data['range']))
    "{{ $data['field']}}": { value: {
      start: moment(t["{{$data['field']}}_start_date"]).toDate(),
      end: moment(t["{{$data['field']}}_end_date"]).toDate(),
    }, defaultValue: null, errors: [], },
  @else
    "{{ $data['field']}}": { value: moment(t["{{$data['field']}}"]).toDate(), defaultValue: null, errors: [], },
  @endif
@else
  @if(isset($data['range']))
    if (this.object["{{ $data['field'] }}"]) {
      this.form["{{ $data['field'] }}"].value = {
        start: moment(this.object["{{ $data['field'] }}_start_date"]).toDate(),
        end: moment(this.object["{{ $data['field'] }}_end_date"]).toDate(),
      }
    };
  @else
    if (this.object["{{ $data['field'] }}"]) { this.form["{{ $data['field'] }}"].value = moment(this.object["{{ $data['field'] }}"]).toDate(); };
  @endif
@endif
