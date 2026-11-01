<template>
  <BaseWrapper>
    <template #title>Заказы</template>
    <div class="client-orders__filters">
      <SelectComponent
        class="client-orders__filter-status"
        placeholder="Все статусы"
        label-name="title"
        v-model="statusFilter"
        :options="statuses"
        @update:model-value="handleFilterChange"
        clearable
      />
      <DateInput
        class="client-orders__filter-date"
        v-model="dateFrom"
        placeholder="От"
        @update:model-value="handleFilterChange"
      />
      <DateInput
        class="client-orders__filter-date"
        v-model="dateTo"
        placeholder="До"
        @update:model-value="handleFilterChange"
      />
    </div>
    <TableComponent
      v-if="rows"
      class="client-orders__table"
      :ordering="ordering"
      :columns="columns"
      :rows="rows.data || []"
      :loading="loading"
      :total="rows.paginatorInfo.total"
      :first="savedFilters.first"
      :page="page"
      :selectable="false"
      pagination
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
    >
      <template #col="{ col, row }">
        <router-link
          :to="{ name: 'order', params: { id: row.id } }"
          v-if="col.field === 'id'"
          class="rows__article"
        >
          {{ row.number }}
        </router-link>
        <router-link
          :to="{ name: 'order', params: { id: row.id } }"
          v-if="col.field === 'created_at'"
          class="rows__left"
        >
          {{ formatDate(row.created_at) }}
        </router-link>
        <router-link
          :to="{ name: 'order', params: { id: row.id } }"
          v-if="col.field === 'status_id'"
        >
          <template v-if="row.status_id === 1">
            <div class="status status--orange">
              <div class="client-orders__dot"></div>
              <span>Создан</span>
            </div>
          </template>
          <template v-if="row.status_id === 2">
            <div class="status status--orange">
              <div class="client-orders__dot"></div>
              <span>На сборке</span>
            </div>
          </template>
          <template v-if="row.status_id === 3">
            <div class="status status--orange">
              <div class="client-orders__dot"></div>
              <span>Собрано</span>
            </div>
          </template>
          <template v-if="row.status_id === 4">
            <div class="status status--orange">
              <div class="client-orders__dot"></div>
              <span>В доставке</span>
            </div>
          </template>
          <template v-if="row.status_id === 5">
            <div class="status status--green">
              <IconComponent name="checkbox-circle-fill" />
              <span>Доставлен</span>
            </div>
          </template>
          <template v-if="row.status_id === 6">
            <div class="status status--red">
              <span>Отменён</span>
            </div>
          </template>
        </router-link>
        <router-link
          :to="{ name: 'order', params: { id: row.id } }"
          v-if="col.field === 'shop'"
          class="rows__left"
        >
          {{ row.shop?.title }}
        </router-link>
        <router-link
          :to="{ name: 'order', params: { id: row.id } }"
          v-if="col.field === 'payment_type_id'"
          class="rows__left"
        >
          <template v-if="row.payment_type_id === 1">Карта</template>
          <template v-if="row.payment_type_id === 2">Наличные</template>
        </router-link>
        <router-link
          :to="{ name: 'order', params: { id: row.id } }"
          v-if="col.field === 'amount'"
          class="rows__left"
        >
          {{ formatPrice(row.amount) }}
        </router-link>
      </template>
    </TableComponent>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import TableComponent from "@/components/table/index.vue";
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import { useFiltersStore } from "@/stores/pageFilters.js";
import DateInput from "@/components/inputs/DateInput.vue";
import ORDERS from "@/graphql/pages/Client/admin_client_orders_paginate.graphql";
import { formatPrice } from "@/utils.js";
import dayjs from "dayjs";

export default {
  name: "ClientOrdersComponent",
  inject: ["apollo"],
  components: {
    DateInput,
    SelectComponent,
    IconComponent,
    TableComponent,
    BaseWrapper,
  },
  props: {
    clientId: {
      type: Number,
      required: true,
    },
  },
  mounted() {
    this.get();
  },
  data() {
    return {
      loading: false,
      first: 25,
      page: 1,
      statusFilter: null,
      dateFrom: null,
      dateTo: null,
      statuses: [
        { id: 1, title: "Создан" },
        { id: 2, title: "На сборке" },
        { id: 3, title: "Собрано" },
        { id: 4, title: "В доставке" },
        { id: 5, title: "Доставлен" },
        { id: 6, title: "Отменён" },
      ],
      ordering: [
        {
          orderColumn: "created_at",
          orderBy: "DESC",
        },
      ],
      columns: [
        {
          title: "№ заказа",
          field: "id",
          selectable: true,
          show: true,
          width: "100px",
        },
        {
          title: "Дата",
          field: "created_at",
          selectable: true,
          show: true,
        },
        {
          title: "Статус",
          field: "status_id",
          selectable: true,
          show: true,
        },
        {
          title: "Магазин",
          field: "shop",
          selectable: true,
          show: true,
        },
        {
          title: "Оплата",
          field: "payment_type_id",
          selectable: true,
          show: true,
        },
        {
          title: "Сумма",
          field: "amount",
          show: true,
          system: true,
        },
      ],
      rows: {
        data: [],
        paginatorInfo: {
          total: 0,
        },
      },
    };
  },
  methods: {
    formatPrice,
    formatDate(date) {
      return date ? dayjs(date).format("DD.MM.YYYY HH:mm") : "";
    },
    handlePaginate($event) {
      this.page = $event;
      this.get();
    },
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.get();
    },
    handleFilterChange() {
      this.page = 1;
      this.get();
    },
    get() {
      if (!this.loading) {
        this.loading = true;
        let variables = {
          client_id: this.clientId,
          first: this.savedFilters.first,
          page: this.page,
          ordering: this.ordering,
          statuses: this.statusFilter ? [this.statusFilter.id] : undefined,
          created_at_start_date: this.dateFrom
            ? dayjs(this.dateFrom).format("YYYY-MM-DD 00:00:00")
            : undefined,
          created_at_end_date: this.dateTo
            ? dayjs(this.dateTo).format("YYYY-MM-DD 23:59:59")
            : undefined,
        };
        this.apollo()
          .purchase.query({
            query: ORDERS,
            variables: variables,
          })
          .then(({ data }) => {
            if (data && data.admin_orders_paginate) {
              this.rows.data = data.admin_orders_paginate.data;
              this.rows.paginatorInfo =
                data.admin_orders_paginate.paginatorInfo;
            }
          })
          .catch(() => {
            console.warn("error loading client orders");
          })
          .finally(() => {
            this.loading = false;
          });
      }
    },
  },
  computed: {
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...useFiltersStore().get(this.$route.name),
        },
      };
    },
  },
};
</script>

<style lang="stylus">
.client-orders {
  &__filters {
    display flex
    gap 12px
    margin-bottom 16px
  }

  &__filter-status {
    max-width 200px
  }

  &__filter-date {
    max-width 160px
  }

  &__dot {
    width 8px
    height 8px
    border-radius 50%
    background var(--orange)
  }

  &__table {
    max-height none

    & .table {
      &__container {
        height auto
      }
    }
  }
}
</style>
