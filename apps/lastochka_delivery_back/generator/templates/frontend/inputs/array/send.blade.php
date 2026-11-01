@php
  $sendFixes = \App\Console\Commands\GeneratorStart::collectSendFixes($data['fields'], true);
@endphp
variables["{{ $data['field'] }}"] = this.form["{{ $data['field'] }}"].value.map((o) => ({
  id: o.id,
  {!! $sendFixes !!}
}))
