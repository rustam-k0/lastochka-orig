<template>
  <BaseWrapper>
    <template #title>История</template>
    <TableComponent
      class="client-history__table"
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
        <div v-if="col.field === 'date'" class="row-date">
          <span class="row-date__date">{{ row.date }}</span>
          <span class="row-date__time">{{ row.time }}</span>
        </div>
        <HistoryStatusComponent v-if="col.field === 'status'" :status="row.status" />
        <div v-if="col.field === 'order' && row.orderNumber" class="row-order">
          <span>Заказ:</span>
          <span class="row-order__number">{{ row.orderNumber }}</span>
          <span class="row-order__payment">{{ row.payment }}</span>
        </div>
        <div v-if="col.field === 'action' && row.action" class="row-action">
          <button class="btn btn--white btn--sm" title="Вывести талон">
            <IconComponent name="arrow-down-s-line" />
          </button>
        </div>
      </template>
    </TableComponent>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import TableComponent from "@/components/table/index.vue";
import HistoryStatusComponent from "@/components/HistoryStatusComponent.vue";
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "ClientHistory",
  components: { IconComponent, HistoryStatusComponent, TableComponent, BaseWrapper },
  data() {
    return {
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
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 2,
              name: "Списание балов: 12",
            },
          },
          {
            id: 2,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 1,
              name: "Начисление баллов: 94",
            },
          },
          {
            id: 3,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 3,
              name: "Доставлено",
            },
            orderNumber: "К 812-863",
            payment: "Онлайн",
          },
          {
            id: 4,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 3,
              name: "Выполнен",
            },
            orderNumber: "К 812-863",
            payment: "Онлайн",
          },
          {
            id: 5,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 4,
              name: "Отменен",
            },
            orderNumber: "К 812-863",
            payment: "Онлайн",
          },
          {
            id: 6,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 5,
              name: "Пуш",
            },
            action: true,
          },
          {
            id: 7,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 6,
              name: "Смс",
            },
            action: true,
          },
          {
            id: 8,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 7,
              name: "Почта",
            },
            action: true,
          },
          {
            id: 10,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 1,
              name: "Начисление баллов: 94",
            },
          },
          {
            id: 9,
            date: "22.03.2023",
            time: "16:25",
            status: {
              code: 3,
              name: "Выполнен",
            },
            orderNumber: "К 812-863",
            payment: "Онлайн",
          },
        ],
        paginatorInfo: {
          total: 0,
        },
      },
      columns: [
        {
          field: "date",
          // sortable: true,
          selectable: true,
          show: true,
          width: "150px",
        },
        {
          field: "status",
          // sortable: true,
          selectable: true,
          show: true,
          width: "220px",
        },
        {
          field: "order",
          // sortable: true,
          selectable: true,
          show: true,
          // width: "272px",
        },
        {
          field: "action",
          show: true,
          system: true,
        },
      ],
    };
  },
  methods: {
    handleFirstCount($event) {
      this.first = $event;
    },
    handlePaginate($event) {
      this.page = $event;
    },
    handleOrdering($event) {
      this.ordering = $event;
    },
  },
};
</script>

<style lang="stylus">
.client-history__table {
  max-height none

  & .table {
    &__container {
      height auto
    }

    &__row--header {
      display none
    }

    &__col {
      padding 16px
    }
  }
}
.row-order {
  display flex
  gap 8px
  align-items center

  & > span {
    font-weight: 400;
    font-size: 0.813rem
    line-height: 20px;
    color: var(--dark)
  }

  &__number {
    border: 1px solid var(--border-color)
    border-radius: 6px;
    padding: 5px 8px
    font-weight: 400;
    font-size: 0.813rem
    line-height: 20px;
    color: var(--dark)
  }

  &__payment {
    background: var(--neutral-50)
    border-radius: 6px;
    padding: 4px 8px
    font-weight: 500;
    font-size: 0.75rem
    line-height: 16px;
  }
}

.row-date {
  display flex
  gap 4px
  align-items center
  font-weight: 400;
  font-size: 0.813rem
  line-height: 20px;

  &__date {
    color var(--dark)
  }

  &__time {
    color var(--body-muted)
  }
}

.row-action {
  margin-left auto
}
</style>
