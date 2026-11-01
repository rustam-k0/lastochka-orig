<template>
  <TableComponent
    class="stores-page__table"
    :ordering="ordering"
    :columns="columns || []"
    :rows="rows || []"
    :loading="loading"
  >
    <template #col="{ col, row }">
      <div v-if="col.field === 'id'" class="rows__article">
        {{ row.id }}
      </div>
      <div v-if="col.field === 'created_at'" class="rows__left stores-page__table-chart">
        <template v-if="row.working_days.length === 7">
          <span>Ежедневно</span>
        </template>
        <template v-else>
          <span v-for="(day, i) in row.working_days" :key="i">
            {{ workDays(day) }}<span v-if="i !== row.working_days.length - 1">,</span>
          </span>
        </template>
        <div class="stores-page__table-time">
          {{ row.work_type_id === 2 ? "Круглосуточно" : "" }}
          {{ row.opening_time ? formatDate(row.opening_time) : "" }} -
          {{ row.closing_time ? formatDate(row.closing_time) : "" }}
        </div>
      </div>
      <div v-if="col.field === 'client_type'" class="rows__left">
        {{ row.address }}
      </div>
      <!--      <div v-if="col.field === 'buyer'" class="rows__left">-->
      <!--        {{ row.buyer }}-->
      <!--      </div>-->
      <div v-if="col.field === 'status_id'" class="rows__left">
        {{ row.latitude }} с.ш {{ row.longitude }} в.д
      </div>
      <!--      <div v-if="col.field === 'pay'" class="rows__center">-->
      <!--        <FlagCheckboxComponent v-model="row.pay" green />-->
      <!--      </div>-->
      <!--      <div v-if="col.field === 'action'" class="rows__action rows__right">-->
      <!--        <IconComponent name="more-2-line" />-->
      <!--      </div>-->
    </template>
  </TableComponent>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import moment from "moment";
export default {
  name: "StoresTable",
  components: {
    IconComponent,
    TableComponent,
    FlagCheckboxComponent,
  },
  props: {
    rows: {
      type: Array,
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
          title: "Код",
          field: "id",
          width: "100px",
        },
        {
          title: "График работы",
          field: "created_at",
        },
        {
          title: "Адрес",
          field: "client_type",
        },
        // {
        //   title: "Зона доставки",
        //   field: "buyer",
        // },
        {
          title: "Координаты",
          field: "status_id",
        },
        // {
        //   title: "Автивировать",
        //   field: "pay",
        //   width: "130px",
        // },
        {
          title: "",
          field: "action",
          width: "50px",
        },
      ],
    };
  },

  methods: {
    formatDate(date) {
      return moment(date, "HH:mm:ss").format("HH:mm");
    },
    handleSelected($event) {
      this.selected = [...$event];
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
    height auto
    .table__row--header .table__col {
      padding 15px 15px
    }
  }
}
</style>
