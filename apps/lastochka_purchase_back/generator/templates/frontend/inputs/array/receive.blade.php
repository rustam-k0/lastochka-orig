@php
  $receiveFixes = \App\Console\Commands\GeneratorStart::collectReceiveFixes($data['fields'], true);
@endphp
if (this.object["{{ $data['field'] }}"]) {
  this.form["{{ $data['field'] }}"].value = this.object["{{ $data['field'] }}"].map((t) => ({
    id: t.id,
    {!! $receiveFixes !!}
  }));
}
