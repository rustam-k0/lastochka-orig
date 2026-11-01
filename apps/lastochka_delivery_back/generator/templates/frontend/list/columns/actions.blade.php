<div v-if="column.field === 'actions'" class="table-action table-action--main">
  @include('frontend.list.action', ['global' => $global])
  @include('frontend.list.changeStatus', ['global' => $global])
</div>
