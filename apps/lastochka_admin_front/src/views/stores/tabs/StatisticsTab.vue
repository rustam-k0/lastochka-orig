<template>
  <div class="store-stat">
    <!-- Плашка ошибки бэка: не роняем вкладку, показываем баннер и обнулённые карточки -->
    <div v-if="loadError" class="store-stat__error">
      <IconComponent name="error-warning-line" />
      <span>Не удалось загрузить статистику</span>
    </div>

    <!-- Блок 1. Выручка магазина -->
    <BaseWrapper>
      <template #title>Выручка магазина</template>
      <template #actions>
        <InfoHintComponent :text="revenueHint" aria-label="Как считается выручка" />
        <IconComponent v-if="loading" name="loader-4-line" class="store-stat__spinner" />
      </template>

      <div class="store-stat__cards store-stat__cards--3" :class="{ 'store-stat__cards--loading': loading }">
        <!-- Сегодня -->
        <div class="store-stat__card">
          <div class="store-stat__card-label">Сегодня</div>
          <div class="store-stat__card-value">{{ initialLoading ? '…' : formatPrice(revenueToday.sum) }}</div>
          <div class="store-stat__card-sub">{{ ordersLabel(revenueToday.orders_count) }}</div>
        </div>
        <!-- За месяц -->
        <div class="store-stat__card">
          <div class="store-stat__card-label">За месяц</div>
          <div class="store-stat__card-value">{{ initialLoading ? '…' : formatPrice(revenueMonth.sum) }}</div>
          <div class="store-stat__card-sub">{{ ordersLabel(revenueMonth.orders_count) }}</div>
          <div class="store-stat__control">
            <SelectComponent
              small
              placeholder="Месяц"
              label-name="title"
              :options="monthOptions"
              :model-value="selectedMonth"
              @update:model-value="onMonthChange"
            />
          </div>
        </div>
        <!-- За период -->
        <div class="store-stat__card">
          <div class="store-stat__card-label">За период</div>
          <div v-if="periodLabel" class="store-stat__card-period">{{ periodLabel }}</div>
          <div class="store-stat__card-value">
            {{ initialLoading ? '…' : (revenuePeriod ? formatPrice(revenuePeriod.sum) : '—') }}
          </div>
          <div class="store-stat__card-sub">
            {{ revenuePeriod ? ordersLabel(revenuePeriod.orders_count) : 'Выберите период' }}
          </div>
          <div class="store-stat__control store-stat__control--period">
            <DateInput
              small
              clearable
              placeholder="От"
              :model-value="periodFrom"
              :errors="periodErrors"
              @update:model-value="(v) => onPeriodChange('from', v)"
            />
            <DateInput
              small
              clearable
              placeholder="До"
              :model-value="periodTo"
              @update:model-value="(v) => onPeriodChange('to', v)"
            />
          </div>
        </div>
      </div>

      <p class="store-stat__note">
        Выручка = сумма успешно завершённых заказов. Учитываются стоимость товаров,
        доставки и сборки с применением скидок, промокодов и бонусов. Не учитываются
        отменённые заказы.
      </p>
    </BaseWrapper>

    <!-- Блок 2. Количество заказов -->
    <BaseWrapper>
      <template #title>Количество заказов</template>
      <template #actions>
        <InfoHintComponent :text="ordersHint" aria-label="Как считается количество заказов" />
        <IconComponent v-if="loading" name="loader-4-line" class="store-stat__spinner" />
      </template>

      <div class="store-stat__cards store-stat__cards--3" :class="{ 'store-stat__cards--loading': loading }">
        <!-- Сегодня -->
        <div class="store-stat__card">
          <div class="store-stat__card-label">Сегодня</div>
          <div class="store-stat__card-value">{{ initialLoading ? '…' : formatNumber(ordersToday) }}</div>
          <div class="store-stat__card-sub">{{ pluralOrders(ordersToday) }}</div>
        </div>
        <!-- За месяц (значение синхронизировано с выбором месяца в блоке выручки) -->
        <div class="store-stat__card">
          <div class="store-stat__card-label">За месяц</div>
          <div class="store-stat__card-value">{{ initialLoading ? '…' : formatNumber(ordersMonth) }}</div>
          <div class="store-stat__card-sub">{{ selectedMonth ? selectedMonth.title : pluralOrders(ordersMonth) }}</div>
        </div>
        <!-- За период. Календарь тот же, что в блоке выручки: период на вкладке
             один, поэтому выбор здесь пересчитывает и выручку (MID-211). -->
        <div class="store-stat__card">
          <div class="store-stat__card-label">За период</div>
          <div v-if="periodLabel" class="store-stat__card-period">{{ periodLabel }}</div>
          <div class="store-stat__card-value">
            {{ initialLoading ? '…' : (ordersPeriod !== null ? formatNumber(ordersPeriod) : '—') }}
          </div>
          <div class="store-stat__card-sub">
            {{ ordersPeriod !== null ? pluralOrders(ordersPeriod) : 'Выберите период' }}
          </div>
          <div class="store-stat__control store-stat__control--period">
            <DateInput
              small
              clearable
              placeholder="От"
              :model-value="periodFrom"
              :errors="periodErrors"
              @update:model-value="(v) => onPeriodChange('from', v)"
            />
            <DateInput
              small
              clearable
              placeholder="До"
              :model-value="periodTo"
              @update:model-value="(v) => onPeriodChange('to', v)"
            />
          </div>
        </div>
      </div>
    </BaseWrapper>

    <!-- Блок 3. Активные заказы -->
    <BaseWrapper>
      <template #title>Активные заказы</template>
      <template #actions>
        <InfoHintComponent :text="activeHint" aria-label="Что показывают активные заказы" />
      </template>

      <div class="store-stat__cards store-stat__cards--active" :class="{ 'store-stat__cards--loading': loading }">
        <div
          v-for="item in activeCards"
          :key="item.key"
          class="store-stat__active-card"
        >
          <div class="store-stat__active-icon">
            <IconComponent :name="item.icon" />
          </div>
          <div class="store-stat__active-count">{{ initialLoading ? '…' : formatNumber(item.value) }}</div>
          <div class="store-stat__active-title">{{ item.title }}</div>
        </div>
      </div>

      <p class="store-stat__note">
        Данные обновляются автоматически при изменении статусов заказов.
      </p>
    </BaseWrapper>
  </div>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import InfoHintComponent from "@/components/UI/InfoHintComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import DateInput from "@/components/inputs/DateInput.vue";
import SHOP_STATISTICS from "@/graphql/pages/Stores/shop_statistics.graphql";
import { formatPrice } from "@/utils.js";
import dayjs from "dayjs";

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];
// Максимальная длина периода по контракту бэка.
const MAX_PERIOD_DAYS = 92;

export default {
  name: "StatisticsTab",
  inject: ["apollo"],
  components: { BaseWrapper, InfoHintComponent, IconComponent, SelectComponent, DateInput },
  data() {
    return {
      stats: null,
      loading: false,
      loadError: false,
      selectedMonth: null,
      periodFrom: null,
      periodTo: null,
      periodErrors: [],
      // Тексты подсказок описывают реальную механику подсчёта в
      // purchase_back ShopStatistics: выручка идёт по дате завершения заказа,
      // количество — по дате оформления. Без этого одни и те же сутки дают
      // разные цифры в соседних блоках, и это выглядит как ошибка.
      revenueHint:
        "Сумма завершённых заказов: стоимость товаров, доставки и сборки с учётом скидок, промокодов и бонусов. Отменённые заказы не считаются. Заказ попадает в интервал по дате завершения.",
      ordersHint:
        "Сколько заказов оформлено за интервал, кроме отменённых. Считается по дате оформления, поэтому цифра может отличаться от количества заказов в блоке выручки: там заказы учтены по дате завершения.",
      activeHint:
        "Заказы, которые сейчас в работе, по этапам обработки. Это снимок на текущий момент, а не за период: цифры меняются вместе со статусами заказов.",
    };
  },
  mounted() {
    // Предвыбираем текущий месяц (совпадает с дефолтом бэка) и грузим статистику.
    this.selectedMonth = this.monthOptions[0];
    this.fetchStats();
  },
  computed: {
    shopId() {
      return parseInt(this.$route.params.id);
    },
    initialLoading() {
      // Плейсхолдер «…» только на первой загрузке, при перезапросе показываем прежние данные.
      return this.loading && !this.stats;
    },
    monthOptions() {
      const list = [];
      const base = dayjs().startOf("month");
      for (let i = 0; i < 12; i++) {
        const m = base.subtract(i, "month");
        list.push({ id: m.format("YYYY-MM"), title: `${MONTHS_RU[m.month()]} ${m.year()}` });
      }
      return list;
    },
    revenueToday() {
      return this.stats?.revenue_today || { sum: 0, orders_count: 0 };
    },
    revenueMonth() {
      return this.stats?.revenue_month || { sum: 0, orders_count: 0 };
    },
    revenuePeriod() {
      return this.stats?.revenue_period || null;
    },
    ordersToday() {
      return this.stats?.orders_today ?? 0;
    },
    ordersMonth() {
      return this.stats?.orders_month ?? 0;
    },
    ordersPeriod() {
      return this.stats?.orders_period ?? null;
    },
    activeOrders() {
      return this.stats?.active_orders || {};
    },
    activeCards() {
      const a = this.activeOrders;
      return [
        { key: "new", title: "Создан", icon: "add-circle-line", value: a.new ?? 0 },
        { key: "waiting_collector", title: "Ожидает сборщика", icon: "time-line", value: a.waiting_collector ?? 0 },
        { key: "assembling", title: "В сборке", icon: "boxing-line", value: a.assembling ?? 0 },
        { key: "ready_for_delivery", title: "Готов к доставке", icon: "checkbox-circle-line", value: a.ready_for_delivery ?? 0 },
        { key: "in_delivery", title: "В доставке", icon: "truck-line", value: a.in_delivery ?? 0 },
      ];
    },
    periodLabel() {
      if (this.periodFrom && this.periodTo) {
        return `${dayjs(this.periodFrom).format("DD.MM.YYYY")} – ${dayjs(this.periodTo).format("DD.MM.YYYY")}`;
      }
      return null;
    },
  },
  methods: {
    formatPrice,
    formatNumber(n) {
      return Number(n ?? 0).toLocaleString("ru-RU");
    },
    pluralOrders(n) {
      const num = Number(n ?? 0);
      const mod10 = num % 10;
      const mod100 = num % 100;
      let word = "заказов";
      if (mod10 === 1 && mod100 !== 11) word = "заказ";
      else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) word = "заказа";
      return `${this.formatNumber(num)} ${word}`;
    },
    ordersLabel(n) {
      return this.pluralOrders(n);
    },
    onMonthChange(value) {
      this.selectedMonth = value;
      this.fetchStats();
    },
    onPeriodChange(side, value) {
      if (side === "from") this.periodFrom = value;
      else this.periodTo = value;
      this.periodErrors = [];

      if (this.periodFrom && this.periodTo) {
        const from = dayjs(this.periodFrom);
        const to = dayjs(this.periodTo);
        if (to.isBefore(from, "day")) {
          this.periodErrors = ["Дата «до» раньше даты «от»"];
          return;
        }
        if (to.diff(from, "day") > MAX_PERIOD_DAYS) {
          this.periodErrors = [`Период не может превышать ${MAX_PERIOD_DAYS} дня`];
          return;
        }
      }
      this.fetchStats();
    },
    fetchStats() {
      if (!this.shopId) return;
      this.loading = true;
      this.loadError = false;

      const variables = { shop_id: this.shopId };
      if (this.selectedMonth?.id) variables.month = this.selectedMonth.id;
      if (this.periodFrom && this.periodTo) {
        variables.period_from = dayjs(this.periodFrom).format("YYYY-MM-DD");
        variables.period_to = dayjs(this.periodTo).format("YYYY-MM-DD");
      }

      this.apollo()
        .purchase.query({
          query: SHOP_STATISTICS,
          variables,
          fetchPolicy: "no-cache",
        })
        .then(({ data }) => {
          this.stats = data.shop_statistics;
        })
        .catch(({ graphQLErrors }) => {
          const validationMsgs = this.extractValidationErrors(graphQLErrors);
          if (validationMsgs.length) {
            // Серверная валидация периода — под полями периода, вкладку не роняем.
            this.periodErrors = validationMsgs;
          } else {
            this.loadError = true;
          }
        })
        .finally(() => {
          this.loading = false;
        });
    },
    extractValidationErrors(graphQLErrors) {
      const msgs = [];
      (graphQLErrors || []).forEach((err) => {
        const validation = err.extensions?.validation;
        if (validation) {
          Object.keys(validation).forEach((key) => {
            (validation[key] || []).forEach((m) => msgs.push(m));
          });
        } else if (err.extensions?.category === "validation" && err.message) {
          msgs.push(err.message);
        }
      });
      return msgs;
    },
  },
};
</script>

<style lang="stylus">
.store-stat {
  display flex
  flex-direction column
  gap 16px

  &__error {
    display flex
    align-items center
    gap 8px
    padding 12px 16px
    background var(--red-o15, rgba(255, 77, 79, 0.12))
    border 1px solid var(--red)
    border-radius 8px
    color var(--red)
    font-size 0.875rem
    font-weight 500

    .icon svg {
      width 18px
      height 18px
    }
  }

  &__spinner {
    display inline-flex
    align-items center
    animation store-stat-spin 1s linear infinite

    .icon svg {
      width 18px
      height 18px
    }
  }

  &__cards {
    display grid
    gap 16px

    &--3 {
      grid-template-columns repeat(3, minmax(0, 1fr))
    }

    &--active {
      grid-template-columns repeat(5, minmax(0, 1fr))
    }

    &--loading {
      opacity 0.6
      transition opacity 0.2s
    }
  }

  &__card {
    display flex
    flex-direction column
    gap 6px
    padding 20px
    background var(--gray, #F7F8FA)
    border 1px solid var(--gray-light)
    border-radius 12px
    min-height 120px
  }

  &__card-label {
    font-size 0.8125rem
    font-weight 500
    color var(--dark-light)
  }

  &__card-period {
    font-size 0.75rem
    font-weight 500
    color var(--green)
  }

  &__card-value {
    font-family var(--font-bold)
    font-weight 600
    font-size 1.75rem
    line-height 1.2
    color var(--dark)
  }

  &__card-sub {
    font-size 0.8125rem
    color var(--dark-light)
  }

  &__control {
    margin-top auto
    padding-top 8px

    &--period {
      display grid
      grid-template-columns 1fr 1fr
      gap 8px
    }
  }

  &__active-card {
    display flex
    flex-direction column
    align-items flex-start
    gap 8px
    padding 20px
    background var(--gray, #F7F8FA)
    border 1px solid var(--gray-light)
    border-radius 12px
  }

  &__active-icon {
    display inline-flex
    align-items center
    justify-content center
    width 40px
    height 40px
    border-radius 10px
    background var(--white)
    color var(--green)

    .icon svg {
      width 20px
      height 20px
    }
  }

  &__active-count {
    font-family var(--font-bold)
    font-weight 600
    font-size 1.5rem
    line-height 1.2
    color var(--dark)
  }

  &__active-title {
    font-size 0.8125rem
    color var(--dark-light)
  }

  &__note {
    margin 16px 0 0
    font-size 0.8125rem
    line-height 140%
    color var(--black-2, #8C8C8C)
  }
}

@keyframes store-stat-spin {
  from {
    transform rotate(0deg)
  }
  to {
    transform rotate(360deg)
  }
}

@media (max-width 1100px) {
  .store-stat__cards--active {
    grid-template-columns repeat(3, minmax(0, 1fr))
  }
}

@media (max-width 768px) {
  .store-stat__cards--3,
  .store-stat__cards--active {
    grid-template-columns 1fr
  }
}
</style>
