@if($array)
  "{{ $data['field'] }}": this.getEditorJSValueForSubmit(o["{{ $data['field'] }}"].value),
@else
  variables["{{ $data['field'] }}"] = this.getEditorJSValueForSubmit(this.form["{{ $data['field'] }}"].value);
@endif
