<template>
  <TableComponent
    class="stores-page__table"
    :ordering="ordering"
    :columns="columns || []"
    :rows="rows || []"
    :loading="loading"
  >
    <template #col="{ col, row }">
      <div v-if="col.field === 'id'" class="rows__center">
        {{ row.user.id }}
      </div>
      <div v-if="col.field === 'employee'" class="rows__left">
        {{ row.user.name }} {{ row.user.surname }} {{ row.user.patronymic }}
      </div>
      <div v-if="col.field === 'chart'" class="rows__left stores-page__table-chart">
        <template v-if="row.working_days.length === 7">
          <span>Ежедневно</span>
        </template>
        <template v-else>
          <span v-for="(day, i) in row.working_days" :key="i">
            {{ workDays(day) }}<span v-if="i !== row.working_days.length - 1">,</span>
          </span>
        </template>
        <span class="stores-page__table-time">
          {{ row.opening_time ? formatDate(row.opening_time) : "" }} -
          {{ row.closing_time ? formatDate(row.closing_time) : "" }}
        </span>
      </div>
      <!--      <div v-if="col.field === 'status_id'" class="rows__left">-->
      <!--        <div v-if="row.status_id" class="status status&#45;&#45;green">-->
      <!--          <IconComponent name="checkbox-circle-fill" />-->
      <!--          <span>Активный</span>-->
      <!--        </div>-->
      <!--        <div v-else class="status status&#45;&#45;gray">-->
      <!--          <IconComponent name="indeterminate-circle-line" />-->
      <!--          <span>Не активный</span>-->
      <!--        </div>-->
      <!--      </div>-->
      <!--      <div v-if="col.field === 'action'" class="rows__action rows__right">-->
      <!--        <IconComponent name="more-2-line" />-->
      <!--      </div>-->
    </template>
  </TableComponent>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import moment from "moment";
export default {
  name: "StoresPersonsTable",
  components: {
    TableComponent,
  },
  props: {
    rows: {
      type: Array,
      default: () => [],
    },
    loading: Boolean,
  },

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
          title: "ID",
          field: "id",
          width: "70px",
        },
        {
          title: "Сотрудники",
          field: "employee",
        },
        {
          title: "График сотрудников",
          field: "chart",
        },
        // {
        //   title: "Статус",
        //   field: "status_id",
        // },
      ],
    };
  },
  methods: {
    formatDate(date) {
      return moment(date, "HH:mm:ss").format("HH:mm");
    },
    workDays(day) {
      switch (day) {
        case 1:
          return "Пн";
        case 2:
          return "Вт";
        case 3:
          return "Ср";
        case 4:
          return "Чт";
        case 5:
          return "Пт";
        case 6:
          return "Сб";
        case 7:
          return "Вс";
        default:
          return "";
      }
    },
  },
};
</script>

<style lang="stylus">
.stores-page {
  &__table {
    .table__row--header .table__col {
      padding 15px 15px
    }

    .table__row .table__col:first-child {
      white-space nowrap
      word-break normal
    }
  }

  &__table-time {
    color: #8C8C8C;
  }

  &__table-chart {
    display flex
    gap 4px
  }
}
</style>
