<template>
  <div class="orders-page-reviews">
    <TableComponent
      class="orders-page-reviews__table"
      :ordering="ordering"
      :columns="(columns || []).filter((c) => c.show || c.system)"
      :rows="rows"
    >
      <template #col="{ col, row }">
        <div v-if="col.field === 'id'" class="rows__article">
          {{ row?.id }}
        </div>
        <div v-if="col.field === 'comment'" class="rows__left">
          {{ row?.comment }}
        </div>
        <div v-if="col.field === 'main_mark'" class="rows__center">
          {{ row?.main_mark }}
        </div>
        <div v-if="col.field === 'assembly_mark'" class="rows__center">
          {{ row?.assembly_mark }}
        </div>
        <div v-if="col.field === 'delivery_mark'" class="rows__center">
          {{ row?.delivery_mark }}
        </div>
      </template>
    </TableComponent>
  </div>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
export default {
  name: "OrdersPageReviews",
  props: {
    review: {
      type: Object,
    },
  },

  mounted() {},
  data() {
    return {
      ordering: [
        {
          orderColumn: "created_at",
          orderBy: "DESC",
        },
      ],
      columns: [
        {
          title: "",
          field: "id",
          selectable: true,
          show: true,
          width: "190px",
        },
        {
          title: "Комментарий",
          field: "comment",
          selectable: true,
          show: true,
        },
        {
          title: "Оценка заказа",
          field: "main_mark",
          selectable: true,
          show: true,
          width: "150px",
        },
        {
          title: "Оценка сборки",
          field: "assembly_mark",
          selectable: true,
          show: true,
          width: "150px",
        },
        {
          title: "Оценка доставки",
          field: "delivery_mark",
          show: true,
          system: true,
          width: "170px",
        },
      ],
    };
  },
  computed: {
    rows() {
      return this.review ? [this.review] : [];
    },
  },
  components: {
    TableComponent,
  },
};
</script>

<style lang="stylus">
.orders-page-reviews {

  &__table {
    .table__container {
      border: none
    }
  }

}
</style>
