@php
  $field = $data['field'];
  $title = "{{ row['{$data['joinName']}'] ? row['{$data['joinName']}']['{$data['joinField']}'] : '' }}";
@endphp
@if(isset($global['action']))
  @if($global['action']['type'] === 'route')
    @php
      $route = $global['action']['to'];
    @endphp
    <router-link
      v-if="column.field === '{!! $field !!}'"
      :to="{!! $route !!}"
      class="td-container"
    >
      <span class="table-span">{!! $title !!}</span>
    </router-link>
  @else
    <span v-if="column.field === '{!! $field !!}'" class="td-container">
      <span class="table-span">{!! $title !!}</span>
    </span>
  @endif
@endif
