<template>
  <div class="or-p">
    <div class="or-p__row">
      <InputComponent
        class="or-p__field or-p__field--id"
        placeholder="ID заказа"
        :model-value="localFilters.title"
        @update:model-value="updateField('title', $event)"
      >
        <template #before>
          <IconComponent name="search-line" />
        </template>
      </InputComponent>
      <InputComponent
        class="or-p__field or-p__field--client"
        placeholder="Покупатель (ФИО / телефон / email)"
        :model-value="localFilters.client_search"
        @update:model-value="updateField('client_search', $event)"
      >
        <template #before>
          <IconComponent name="user-line" />
        </template>
      </InputComponent>
      <DateInput
        class="or-p__field or-p__field--date"
        range
        clearable
        placeholder="Дата"
        :model-value="dateRangeValue"
        :errors="dateErrors"
        @update:model-value="updateDateRange"
      />
      <SelectComponent
        class="or-p__field or-p__field--status"
        placeholder="Статус"
        label-name="title"
        multiple
        clearable
        :model-value="statusesSelection"
        :options="statusOptions"
        @update:model-value="updateMulti('statuses', $event)"
      />
      <SelectComponent
        class="or-p__field or-p__field--type"
        placeholder="Тип"
        label-name="title"
        multiple
        clearable
        :model-value="typesSelection"
        :options="deliveryTypeOptions"
        @update:model-value="updateMulti('types', $event)"
      />
      <SelectComponent
        v-if="collectorOptions.length > 1"
        class="or-p__field or-p__field--collector"
        placeholder="Сборщик"
        label-name="title"
        multiple
        clearable
        :model-value="collectorsSelection"
        :options="collectorOptions"
        @update:model-value="updateMulti('collectors', $event)"
      />
      <SelectComponent
        v-if="courierOptions.length > 1"
        class="or-p__field or-p__field--courier"
        placeholder="Курьер"
        label-name="title"
        multiple
        clearable
        :model-value="couriersSelection"
        :options="courierOptions"
        @update:model-value="updateMulti('couriers', $event)"
      />
      <SelectComponent
        class="or-p__field or-p__field--payment"
        placeholder="Способ оплаты"
        label-name="title"
        multiple
        clearable
        :model-value="paymentTypesSelection"
        :options="paymentTypeOptions"
        @update:model-value="updateMulti('payment_types', $event)"
      />
      <SelectComponent
        class="or-p__field or-p__field--payment-status"
        placeholder="Статус оплаты"
        label-name="title"
        multiple
        clearable
        :model-value="paymentStatusesSelection"
        :options="paymentStatusOptions"
        @update:model-value="updateMulti('payment_statuses', $event)"
      />
      <div class="or-p__amount-group">
        <InputComponent
          class="or-p__field or-p__field--amount"
          type="number"
          placeholder="Сумма от"
          :model-value="localFilters.amount_from"
          :errors="amountErrors"
          @update:model-value="updateField('amount_from', $event)"
        />
        <InputComponent
          class="or-p__field or-p__field--amount"
          type="number"
          placeholder="Сумма до"
          :model-value="localFilters.amount_to"
          :errors="amountErrors"
          @update:model-value="updateField('amount_to', $event)"
        />
      </div>
      <button class="btn btn--white or-p__reset" type="button" :disabled="!hasFilters" @click="reset">
        Сбросить
      </button>
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import DateInput from "@/components/inputs/DateInput.vue";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

// id 7 — виртуальный код «Выдан» (завершённый самовывоз), разворачивается
// на бэкенде в status_id=5 AND type_id=1; «Доставлен» (5) — только доставка
const STATUS_OPTIONS = [
  { id: 1, title: "Создан" },
  { id: 2, title: "На сборке" },
  { id: 3, title: "Собрано" },
  { id: 4, title: "В доставке" },
  { id: 5, title: "Доставлен" },
  { id: 7, title: "Выдан" },
  { id: 6, title: "Отменён" },
];

const DELIVERY_TYPE_OPTIONS = [
  { id: 1, title: "Самовывоз" },
  { id: 2, title: "Доставка" },
];

const PAYMENT_TYPE_OPTIONS = [
  { id: 1, title: "Карта" },
  { id: 2, title: "Наличные" },
];

const PAYMENT_STATUS_OPTIONS = [
  { id: "pending", title: "Ожидает оплаты" },
  { id: "paid", title: "Оплачено" },
  { id: "failed", title: "Ошибка оплаты" },
  { id: "refunded", title: "Возврат" },
];

const defaultFilters = () => ({
  title: null,
  client_search: null,
  statuses: [],
  types: [],
  payment_types: [],
  payment_statuses: [],
  couriers: [],
  collectors: [],
  amount_from: null,
  amount_to: null,
  created_at_start_date: null,
  created_at_end_date: null,
});

export default {
  name: "OrdersPageFilters",
  emits: ["update:filters"],
  props: {
    filters: {
      type: Object,
      default: () => defaultFilters(),
    },
    couriers: {
      type: Array,
      default: () => [],
    },
    collectors: {
      type: Array,
      default: () => [],
    },
  },

  data() {
    return {
      deliveryTypeOptions: DELIVERY_TYPE_OPTIONS,
      paymentTypeOptions: PAYMENT_TYPE_OPTIONS,
    };
  },

  computed: {
    localFilters() {
      return { ...defaultFilters(), ...(this.filters || {}) };
    },
    statusOptions() {
      // id 8 — виртуальный код «Назначен курьер» (собран + курьер назначен),
      // виден только при включённом модуле courier_assignment
      if (!useModuleConfigStore().isModuleEnabled("courier_assignment")) {
        return STATUS_OPTIONS;
      }
      const opts = [...STATUS_OPTIONS];
      const idx = opts.findIndex((o) => o.id === 3);
      opts.splice(idx + 1, 0, { id: 8, title: "Назначен курьер" });
      return opts;
    },
    paymentStatusOptions() {
      // «Ожидает доплату» — только при включённом модуле collector_payment
      if (!useModuleConfigStore().isModuleEnabled("collector_payment")) {
        return PAYMENT_STATUS_OPTIONS;
      }
      const opts = [...PAYMENT_STATUS_OPTIONS];
      const idx = opts.findIndex((o) => o.id === "paid");
      opts.splice(idx, 0, { id: "awaiting_surcharge", title: "Ожидает доплату" });
      return opts;
    },
    statusesSelection() {
      return this.mapIdsToOptions(this.localFilters.statuses, this.statusOptions);
    },
    typesSelection() {
      return this.mapIdsToOptions(this.localFilters.types, DELIVERY_TYPE_OPTIONS);
    },
    courierOptions() {
      // 0 — виртуальный код «Без курьера» (см. OrderFilter::FILTER_NO_COURIER).
      // Если курьеров нет или запрос недоступен (роль shop) — селект скрыт,
      // поэтому пункт «Без курьера» в одиночку не показывается
      return [
        { id: 0, title: "Без курьера" },
        ...(this.couriers || []).map((courier) => ({
          id: courier.id,
          title: [courier.surname, courier.name, courier.patronymic].filter(Boolean).join(" "),
        })),
      ];
    },
    couriersSelection() {
      return this.mapIdsToOptions(this.localFilters.couriers, this.courierOptions);
    },
    collectorOptions() {
      // 0 — виртуальный код «Без сборщика» (см. OrderFilter::FILTER_NO_COLLECTOR).
      // Если сборщиков нет или запрос недоступен (роль shop) — селект скрыт,
      // поэтому пункт «Без сборщика» в одиночку не показывается
      return [
        { id: 0, title: "Без сборщика" },
        ...(this.collectors || []).map((collector) => ({
          id: collector.id,
          title: [collector.surname, collector.name, collector.patronymic].filter(Boolean).join(" "),
        })),
      ];
    },
    collectorsSelection() {
      return this.mapIdsToOptions(this.localFilters.collectors, this.collectorOptions);
    },
    paymentTypesSelection() {
      return this.mapIdsToOptions(this.localFilters.payment_types, PAYMENT_TYPE_OPTIONS);
    },
    paymentStatusesSelection() {
      return this.mapIdsToOptions(this.localFilters.payment_statuses, this.paymentStatusOptions);
    },
    dateRangeValue() {
      const { created_at_start_date: from, created_at_end_date: to } = this.localFilters;
      if (!from || !to) return null;
      return { start: from, end: to };
    },
    amountErrors() {
      const from = this.toNumber(this.localFilters.amount_from);
      const to = this.toNumber(this.localFilters.amount_to);
      if (from !== null && to !== null && from > to) {
        return ["«Сумма от» больше «Сумма до»"];
      }
      return [];
    },
    dateErrors() {
      const { created_at_start_date: from, created_at_end_date: to } = this.localFilters;
      if (!from || !to) return [];
      if (new Date(from) > new Date(to)) {
        return ["Дата «от» больше даты «до»"];
      }
      return [];
    },
    hasFilters() {
      const f = this.localFilters;
      return Boolean(
        f.title ||
          f.client_search ||
          (f.statuses && f.statuses.length) ||
          (f.types && f.types.length) ||
          (f.couriers && f.couriers.length) ||
          (f.collectors && f.collectors.length) ||
          (f.payment_types && f.payment_types.length) ||
          (f.payment_statuses && f.payment_statuses.length) ||
          f.amount_from !== null ||
          f.amount_to !== null ||
          f.created_at_start_date ||
          f.created_at_end_date
      );
    },
  },

  methods: {
    toNumber(value) {
      if (value === null || value === undefined || value === "") return null;
      const n = Number(value);
      return Number.isFinite(n) ? n : null;
    },
    mapIdsToOptions(ids, options) {
      if (!Array.isArray(ids) || !ids.length) return [];
      return options.filter((opt) => ids.includes(opt.id));
    },
    emitFilters(next) {
      this.$emit("update:filters", { ...this.localFilters, ...next });
    },
    updateField(key, value) {
      let normalized = value;
      if (value === "" || value === undefined) {
        normalized = null;
      }
      if (key === "amount_from" || key === "amount_to") {
        normalized = this.toNumber(value);
      }
      this.emitFilters({ [key]: normalized });
    },
    updateMulti(key, value) {
      const ids = Array.isArray(value) ? value.map((item) => item.id) : [];
      this.emitFilters({ [key]: ids });
    },
    updateDateRange(value) {
      if (!value || !value.start || !value.end) {
        this.emitFilters({
          created_at_start_date: null,
          created_at_end_date: null,
        });
        return;
      }
      const start = new Date(value.start);
      const end = new Date(value.end);
      start.setHours(0, 0, 0, 0);
      end.setHours(23, 59, 59, 999);
      this.emitFilters({
        created_at_start_date: start.toISOString(),
        created_at_end_date: end.toISOString(),
      });
    },
    reset() {
      this.$emit("update:filters", defaultFilters());
    },
  },

  components: {
    IconComponent,
    InputComponent,
    SelectComponent,
    DateInput,
  },
};
</script>

<style lang="stylus">
.or-p {
  display flex
  flex-direction column
  gap 16px

  &__row {
    display flex
    flex-wrap wrap
    align-items flex-start
    gap 8px
  }

  &__field {
    min-width 160px
    flex 1 1 180px

    &--id {
      max-width 160px
      flex 0 0 160px
    }

    &--client {
      min-width 240px
      flex 1 1 260px
    }

    &--date {
      min-width 220px
      max-width 260px
      flex 0 0 240px
    }

    &--status {
      min-width 180px
      max-width 240px
      flex 0 0 220px
    }

    &--type,
    &--collector,
    &--courier,
    &--payment,
    &--payment-status {
      min-width 160px
      max-width 200px
      flex 0 0 180px
    }

    &--amount {
      min-width 110px
      max-width 130px
      flex 0 0 120px
    }
  }

  &__amount-group {
    display flex
    gap 4px
    flex 0 0 auto
  }

  &__reset {
    // Высота = базовая высота инпутов/селектов (48px), чтобы весь ряд
    // фильтров был на одной линии (MID-235)
    height 48px
    padding 0 16px
    align-self flex-start
    white-space nowrap

    &:disabled {
      opacity 0.5
      cursor not-allowed
    }
  }

  @media (max-width: 1440px) {
    &__row {
      gap 8px 6px
    }
  }
}
</style>
