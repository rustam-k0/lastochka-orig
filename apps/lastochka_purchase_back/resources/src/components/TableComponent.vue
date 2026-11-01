<template>
  <div class="table">
    <VueGoodTable
      :columns="columns"
      :pagination-options="{ enabled: false }"
      :rows="rows"
      :sort-options="{ enabled: true, initialSortBy: initialSortBy }"
      compactMode
      striped
      styleClass="vgt-table striped"
      @on-sort-change="$emit('sort', $event)"
    >
      <template slot="table-row" slot-scope="props">
        <slot :column="props.column" :index="props.index" :row="props.row" name="table-row">
          <span>{{ props.row[props.column.field] || "" }}</span>
        </slot>
      </template>
      <span slot="emptystate" class="vgt-empty">
        <slot name="empty">Данных нет</slot>
      </span>
    </VueGoodTable>
    <PaginationComponent
      :first="first"
      :page="page"
      :pagination="pagination"
      :total="Math.ceil(total / first)"
      :totalItems="total"
      @change="$emit('change', $event)"
      @paginate="$emit('paginate', $event)"
    />
  </div>
</template>

<script>
import { VueGoodTable } from "vue-good-table";
import PaginationComponent from "@/components/Pagination";

export default {
  name: "TableComponent",
  props: {
    total: {
      type: Number,
      default: 0,
    },
    first: {
      type: Number,
      default: 0,
    },
    initialSortBy: Array,
    page: {
      type: Number,
      default: 1,
    },
    columns: Array,
    rows: Array,
    pagination: Boolean,
    listClass: {
      type: String,
      default: "",
    },
  },
  components: {
    PaginationComponent,
    VueGoodTable,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/elements/table"
</style>
