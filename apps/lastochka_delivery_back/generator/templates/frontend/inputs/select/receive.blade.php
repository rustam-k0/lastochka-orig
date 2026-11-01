@if($array)
  @if(isset($data['multiple']) && $data['multiple'])
    "{{ $data['field'] }}": {
      value: t["{{ $data['field'] }}"].map((k) => ({
          "{{ $data['idName'] }}": k["{{ $data['idName'] }}"],
          "{{ $data['labelName'] }}": k["{{ $data['labelName'] }}"],
        })),
      defaultValue: null,
      errors: [],
    },
  @else
    "{{ $data['field'] }}": {
      value: {
        "{{ $data['idName'] }}": t["{{ $data['field'] }}"]["{{ $data['idName'] }}"],
        "{{ $data['labelName'] }}": t["{{ $data['field'] }}"]["{{ $data['labelName'] }}"],
      },
      defaultValue: null,
      errors: [],
    },
  @endif
@else
  @if(isset($data['multiple']) && $data['multiple'])
    if (this.object["{{ $data['field'] }}"]) {
        this.form["{{ $data['field'] }}"].value = this.object["{{ $data['field'] }}"].map((t) => ({
          "{{ $data['idName'] }}": t["{{ $data['idName'] }}"],
          "{{ $data['labelName'] }}": t["{{ $data['labelName'] }}"],
        }));
    }
  @else
    if (this.object["{{ $data['field'] }}"]) {
      this.form["{{ $data['field'] }}"].value = {
        "{{ $data['idName'] }}": this.object["{{ $data['field'] }}"]["{{ $data['idName'] }}"],
        "{{ $data['labelName'] }}": this.object["{{ $data['field'] }}"]["{{ $data['labelName'] }}"],
      }
    }
  @endif
@endif
