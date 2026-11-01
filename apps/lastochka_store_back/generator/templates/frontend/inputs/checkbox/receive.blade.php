@if($array)
  @if (isset($data['receiveJoin']))
    "{{ $data['field'] }}": { value: t["{{ $data['receiveJoin'] }}"]["{{ $data['receiveJoinParam'] }}"] === {{ $data['receiveJoinParamValue'] }}, defaultValue: null, errors: [], },
  @else
    "{{ $data['field'] }}": { value: t["{{ $data['field'] }}"], defaultValue: null, errors: [], },
  @endif
@else
  @if (isset($data['receiveJoin']))
    this.form["{{ $data['field'] }}"].value = this.object["{{ $data['receiveJoin'] }}"]["{{ $data['receiveJoinParam'] }}"] === {{ $data['receiveJoinParamValue'] }};
  @else
    this.form["{{ $data['field'] }}"].value = this.object["{{ $data['field'] }}"];
  @endif
@endif
