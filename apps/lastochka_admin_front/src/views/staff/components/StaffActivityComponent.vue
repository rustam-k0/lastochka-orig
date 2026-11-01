<template>
  <div class="staff-activity">
    <div class="staff-activity__header">
      <span>Последняя активность</span>
      <div class="staff-statistics__tabs">
        <div
          v-for="(item, i) in tabs"
          :key="i"
          class="staff-statistics__tabs-item"
          :class="{ 'staff-statistics__tabs-item--active': item.active }"
        >
          {{ item.title }}
        </div>
      </div>
    </div>
    <TableComponent
      v-if="rows"
      :ordering="ordering"
      :columns="(columns || []).filter((c) => c.show || c.system)"
      :rows="rows.data || []"
      :selectable="false"
      :total="rows.paginatorInfo.total"
      @update:ordering="handleOrdering"
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
    >
      <!--      <template #header>-->
      <!--        <StaffTableHeader />-->
      <!--      </template>-->
      <template #col="{ col, row }">
        <IndicatorComponent v-if="col.field === 'standing'" :code="row.standing" />
        <IndicatorComponent v-if="col.field === 'pay'" :code="row.pay" />
      </template>
    </TableComponent>
  </div>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import IndicatorComponent from "@/components/IndicatorComponent.vue";
export default {
  name: "StaffActivityComponent",
  data() {
    return {
      tabs: [
        {
          title: "Заказы",
          active: true,
        },
        {
          title: "Клиенты",
          active: false,
        },
      ],

      ordering: [
        {
          orderColumn: "created_at",
          orderBy: "DESC",
        },
      ],
      rows: {
        data: [
          {
            id: 1,
            date: "22.03.2023 16:25",
            standing: 1,
            buyer: "Элеонора Морозова",
            pay: 5,
            cost: "Оплачен",
          },
          {
            id: 1,
            date: "22.03.2023 16:25",
            standing: 2,
            buyer: "Элеонора Морозова",
            pay: 6,
            cost: "Оплачен",
          },
          {
            id: 1,
            date: "22.03.2023 16:25",
            standing: 3,
            buyer: "Элеонора Морозова",
            pay: 7,
            cost: "Оплачен",
          },
          {
            id: 1,
            date: "22.03.2023 16:25",
            standing: 4,
            buyer: "Элеонора Морозова",
            pay: 5,
            cost: "Оплачен",
          },
          {
            id: 1,
            date: "22.03.2023 16:25",
            standing: 5,
            buyer: "Элеонора Морозова",
            pay: 6,
            cost: "Оплачен",
          },
          {
            id: 1,
            date: "22.03.2023 16:25",
            standing: 6,
            buyer: "Элеонора Морозова",
            pay: 7,
            cost: "Оплачен",
          },
        ],
        paginatorInfo: {
          total: 0,
        },
      },
      columns: [
        {
          title: "ID",
          field: "id",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Дата",
          field: "date",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "272px",
        },
        {
          title: "Покупатель",
          field: "buyer",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "272px",
        },

        {
          title: "Статус",
          field: "standing",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "160px",
        },
        {
          title: "Оплата",
          field: "pay",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "272px",
        },
        {
          title: "Стоимость",
          field: "cost",
          show: true,
          system: true,
          // width: "160px",
        },
      ],
    };
  },
  components: { IndicatorComponent, TableComponent },
};
</script>

<style lang="stylus">
.staff-activity{
  display flex
  flex-direction column
  background-color var(--white)
  border-radius: 16px;

  &__header {
    display flex
    justify-content space-between
    align-items center
    padding 20px 16px
    //border-bottom: 1px solid #E3E8EC;
  }
}
</style>
