<template>
  <TableComponent
    class="store-create-table"
    :ordering="ordering"
    :columns="columns || []"
    :rows="localRows || []"
    :loading="loading"
  >
    <template #col="{ col, row }">
      <div v-if="col.field === 'id'" class="rows__center">
        {{ row.user.id }}
      </div>
      <div v-if="col.field === 'employee'" class="rows__left">
        <div class="store-create-table__wrapper">
          {{ row.user.name }} {{ row.user.surname }} {{ row.user.patronymic }}
          <span v-if="errorsIds.includes(row.id)" class="store-create-table__errors"
            >{{ form.opening_time.errors[0] }} <br />
            {{ form.closing_time.errors[0] }} <br />
            {{ form.days.errors[0] }}</span
          >
        </div>
      </div>
      <div v-if="col.field === 'position'" class="rows__left">
        {{ row.user.roles?.[0]?.name_ru || '—' }}
      </div>
      <div v-if="col.field === 'chart'" class="rows__left store-create-table__schedule">
        <span>c</span>
        <InputComponent
          :model-value="row.opening_time"
          mask="##:##"
          @blur="updateWorker($event, row.id, 'open')"
        />
        <span>до</span>
        <InputComponent
          :model-value="row.closing_time"
          mask="##:##"
          @blur="updateWorker($event, row.id, 'close')"
        />
      </div>
      <div v-if="col.field === 'work_days'">
        <WorkDaysComponent
          :model-value="row.working_days"
          @update:model-value="updateWorker($event, row.id)"
        />
      </div>
    </template>
  </TableComponent>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import WorkDaysComponent from "@/views/stores/components/WorkDaysComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import UPDATE_WORKERS from "@/graphql/pages/Stores/SetUserWorkingHours.graphql";
import moment from "moment";
import { parseGqlErrors, resetErrors } from "@/utils.js";
export default {
  name: "StoreCreateTable",
  // emits: ["update:worker"],
  inject: ["apollo"],
  components: {
    InputComponent,
    TableComponent,
    WorkDaysComponent,
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
      form: {
        opening_time: {
          value: null,
          errors: [],
        },
        closing_time: {
          value: null,
          errors: [],
        },
        days: {
          value: null,
          errors: [],
        },
      },
      errorsIds: [],
      debounce: null,
      localRows: [],
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
          title: "Должность",
          field: "position",
          width: "160px",
        },
        {
          title: "График работы",
          field: "chart",
          width: "230px",
        },
        {
          title: "Дни работы",
          field: "work_days",
          width: "450px",
        },
      ],
    };
  },
  watch: {
    // immediate: при переключении на вкладку «Сотрудники» данные магазина
    // уже загружены — prop rows больше не меняется, и без immediate watcher
    // не срабатывал: localRows оставался [] («Данных нет» до F5).
    rows: {
      immediate: true,
      handler() {
        this.localRows = JSON.parse(JSON.stringify(this.rows));
      },
    },
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    formatDate(date) {
      return moment(date, "HH:mm:ss").format("HH:mm");
    },
    updateWorker(event, id, field) {
      if (field) {
        if (field === "open") {
          this.localRows.find((item) => item.id === id).opening_time = event;
        }
        if (field === "close") {
          this.localRows.find((item) => item.id === id).closing_time = event;
        }
      } else {
        this.localRows.find((item) => item.id === id).working_days = event;
      }
      let opening_time = this.localRows.find((item) => item.id === id).opening_time;
      let closing_time = this.localRows.find((item) => item.id === id).closing_time;
      let work_days = this.localRows.find((item) => item.id === id).working_days;
      if (this.debounce) {
        clearTimeout(this.debounce);
      }
      this.debounce = setTimeout(() => {
        // this.errorsIds = this.errorsIds.filter((item) => item !== id);
        if (opening_time && closing_time && work_days.length) {
          // this.resetErrors();
          this.apollo()
            .admin.mutate({
              mutation: UPDATE_WORKERS,
              variables: {
                user_shop_id: id,
                opening_time: this.formatDate(opening_time),
                closing_time: this.formatDate(closing_time),
                days: work_days,
              },
            })
            .then(() => {
              this.errorsIds = this.errorsIds.filter((item) => item !== id);
            })
            .catch(({ graphQLErrors }) => {
              if (!this.errorsIds.includes(id)) {
                this.errorsIds = [id, ...this.errorsIds];
              }
              this.parseGqlErrors(graphQLErrors);
            });
        }
      }, 500);
    },
  },
};
</script>

<style lang="stylus">
.store-create-table {
  // Глобальные утилиты .rows__left/.rows__center несут cursor pointer
  // (рассчитаны на таблицы с кликабельными строками) — здесь строки
  // никуда не ведут, pointer читался как ложная кликабельность ФИО.
  .rows__left,
  .rows__center {
    cursor default
  }

  .input {
    width 74.5px
  }

  &__schedule {
    display flex
    gap 6px
  }

  .table__row--header .table__col {
    padding 15px 15px
  }

  .table__row .table__col:first-child {
    white-space nowrap
    word-break normal
  }

  &__wrapper {
    display flex
    flex-direction column
    gap 8px
  }

  &__errors {
    font-weight: 400;
    font-size: 0.75rem;
    line-height: 15px;
    display grid
    grid-gap 3px
    color var(--red)
  }
}
</style>
