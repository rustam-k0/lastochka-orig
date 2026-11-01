@if(isset($global['action']))
  @if($global['action']['type'] === 'route')
    @php
      $route = $global['action']['to'];
    @endphp
    <router-link :to="{!! $route !!}">
      {!! $global['action']['title'] !!}
    </router-link>
  @endif
  @if($global['action']['type'] === 'modal')
    <button type="button" @click="showModal('{{ $global['action']['modalName'] }}', row)">
      {!! $global['action']['title'] !!}
    </button>
  @endif
@endif
