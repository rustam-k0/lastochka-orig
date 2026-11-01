@php
  $name = $data['name'];
  $type = $data['type'];

  $input = '';
  if (isset($data['variables'])) {
    $variables = '';
    foreach ($data['variables'] as $variable) {
      $input .= "\${$variable['name']}: {$variable['type']}, ";
      $variables .= "{$variable['name']}: \${$variable['name']}, ";
    }
  } else {
    $variables = false;
  }

  $fields = \App\Console\Commands\GeneratorStart::buildGraphqlBody($data['body']);
@endphp
@if($variables)
{!! $type !!} (
  {!! $input !!}
) {
  {!! $name !!}(
    {!! $variables !!}
  ) {
    {!! $fields !!}
  }
}
@else
{!! $type !!} {
  {!! $name !!} {
    {!! $fields !!}
  }
}
@endif
