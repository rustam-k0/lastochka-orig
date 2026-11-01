@php
  $dataFields =  \App\Console\Commands\GeneratorStart::collectDataFields($data['fields'], true);
@endphp
{{ $data['field'] }}TemplateField: {
  {!! $dataFields !!}
},
