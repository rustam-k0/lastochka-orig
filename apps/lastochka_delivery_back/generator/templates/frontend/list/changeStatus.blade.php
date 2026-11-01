@if(isset($global['changeStatus']))
  <Icon category="system" name="arrow-down-s-line" />
  <div class="table-action__drop">
    @if(in_array('hide', $global['changeStatus']))
      <a @click.prevent="changeStatus(row.id, row.status.code === 1 ? 3 : 1, row.status.table_name)">
        <span v-if="row.status.code === 1">Скрыть</span>
        <span v-else>Опубликовать</span>
      </a>
    @endif
    @if(in_array('delete', $global['changeStatus']))
      <a @click.prevent="changeStatus(row.id, 2, row.status.table_name)">Удалить</a>
    @endif
  </div>
@endif
