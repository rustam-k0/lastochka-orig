<template>
  <main class="orders-page page--main">
    <template v-if="isAdmin && !hideShop">
      <ShopSelectComponent
        title="Магазин"
        placeholder="Все"
        v-model="shopId"
        clearable
        @change="updateShop"
      />
    </template>
    <div class="page__header">
      <h2>{{ pageTitle }}</h2>
    </div>
    <!--    <ul class="orders-page__statuses">-->
    <!--      <li><span>Новые заказы</span><span class="status status&#45;&#45;blue">711</span></li>-->
    <!--      <li><span>В обработке</span><span class="status status&#45;&#45;gray">795</span></li>-->
    <!--      <li><span>Собранные</span><span class="status status&#45;&#45;gray">274</span></li>-->
    <!--      <li><span>На доставке</span><span class="status status&#45;&#45;purple">274</span></li>-->
    <!--      <li><span>Выполненные</span><span class="status status&#45;&#45;green">961</span></li>-->
    <!--      <li><span>Жалобы</span><span class="status status&#45;&#45;orange">907</span></li>-->
    <!--      <li><span>Отмененные</span><span class="status status&#45;&#45;red">283</span></li>-->
    <!--    </ul>-->
    <OrdersPageFilters
      :filters="filters"
      :couriers="couriers"
      :collectors="collectors"
      @update:filters="handleFiltersChange"
    />
    <template v-if="switchView">
      <div
        v-if="!loading && rows.paginatorInfo.total === 0"
        class="orders-page__empty"
      >
        Заказы не найдены, измените фильтры
      </div>
      <TableComponent
        v-else-if="rows"
        class="orders-page__table"
        :class="{
          'orders-page__table--is-admin': isAdmin,
        }"
        :ordering="ordering"
        :columns="visibleColumns"
        :rows="rows.data || []"
        :loading="loading"
        selectable
        :selected="selected"
        @update:selected="handleSelected"
        @update:page="handlePaginate"
        @update:count="handleFirstCount"
        :total="rows.paginatorInfo.total"
        :first="savedFilters.first"
        :page="page"
        pagination
      >
        <template #col="{ col, row, rowIndex }">
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
            <div class="orders-page__row-date">{{ formatDate(row.created_at) }}</div>
            <div class="orders-page__row-subtext">
              {{ formatTime(row.created_at) }}
            </div>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'client_type'"
            class="rows__left"
          >
            {{ row.client_type }}
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
            v-if="col.field === 'client'"
            class="rows__left"
          >
            <div>{{ formatClientName(row.client) }}</div>
            <div class="orders-page__row-subtext">{{ row.client?.phone }}</div>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'address'"
            class="rows__left"
          >
            <!-- Квартира уже входит в строку адреса («…, д. 41, кв. 102»),
                 отдельным полем flat её не дублируем. -->
            <template v-if="row.client_info?.address">
              {{ row.client_info.address }}
            </template>
            <template v-else>—</template>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'status_id'"
          >
            <template v-if="row.status_id === 1">
              <div class="status status--orange">
                <div class="orders-page__dot"></div>
                <span>Создан</span>
              </div>
            </template>
            <template v-if="row.status_id === 2">
              <div class="status status--orange">
                <div class="orders-page__dot"></div>
                <span>Заказ на сборке</span>
              </div>
            </template>
            <template v-if="row.status_id === 3">
              <div class="status status--orange">
                <div class="orders-page__dot"></div>
                <span v-if="courierAssignmentModuleEnabled && row.couriers?.length">Назначен курьер</span>
                <span v-else>Собрано</span>
              </div>
            </template>
            <template v-if="row.status_id === 4">
              <div class="status status--orange">
                <div class="orders-page__dot"></div>
                <span>В доставке</span>
              </div>
            </template>
            <template v-if="row.status_id === 5">
              <div class="status status--green">
                <IconComponent name="checkbox-circle-fill" />
                <span v-if="row.type_id === 1">Выдан</span>
                <span v-else>Доставлен</span>
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
            v-if="col.field === 'type_id'"
          >
            <span v-if="row.type_id === 1" class="status status--purple"
              >Самовывоз</span
            >
            <span v-else>Доставка</span>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'slot'"
            class="rows__left"
          >
            <template
              v-if="
                row.slot_start_date &&
                row.slot_end_date &&
                (!checkoutWithoutSlotsModuleEnabled || row.type_id === 1)
              "
            >
              <div class="orders-page__row-date">{{ formatDate(row.slot_start_date) }}</div>
              <div class="orders-page__row-subtext">
                {{ formatTime(row.slot_start_date) }} – {{ formatTime(row.slot_end_date) }}
              </div>
            </template>
            <template v-else>—</template>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'payment_type_id'"
          >
            <template v-if="row.payment_type_id === 1">
              <span>Карта</span>
            </template>
            <template v-if="row.payment_type_id === 2">
              <span>Наличные</span>
            </template>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'payment_status'"
          >
            <div
              v-if="row.payment_status === 'paid'"
              class="status status--green"
            >
              <span>Оплачено</span>
            </div>
            <div
              v-else-if="row.payment_status === 'failed'"
              class="status status--red"
            >
              <span>Ошибка оплаты</span>
            </div>
            <div
              v-else-if="row.payment_status === 'refunded'"
              class="status status--pink"
            >
              <span>Возврат</span>
            </div>
            <div
              v-else-if="row.payment_status === 'awaiting_surcharge'"
              class="status status--yellow"
            >
              <span>Ожидает доплату</span>
            </div>
            <div v-else class="status status--orange">
              <span>Ожидает оплаты</span>
            </div>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'amount'"
            class="rows__left"
          >
            {{ row.amount }} ₽
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'collector'"
            class="rows__left"
          >
            {{ formatStaffName(row.collector) || "—" }}
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'couriers'"
            class="rows__left"
          >
            <template v-if="row.couriers && row.couriers.length">
              <div v-for="courier in row.couriers" :key="courier.id">
                {{ formatStaffName(courier) }}
              </div>
            </template>
            <template v-else>—</template>
          </router-link>
          <router-link
            :to="{ name: 'order', params: { id: row.id } }"
            v-if="col.field === 'is_heavy'"
            class="rows__left"
          >
            <span
              v-if="row.is_heavy && heavyOrdersModuleEnabled"
              class="status status--heavy"
              >Тяжелый заказ</span
            >
            <span v-else>—</span>
          </router-link>
          <!--            <div v-if="col.field === 'action'" class="rows__action">-->
          <!--              <IconComponent name="more-2-line" />-->
          <!--              <div v-if="activeRowIndex === rowIndex">-->
          <!--                {{ rowIndex }}-->
          <!--              </div>-->
          <!--            </div>-->
        </template>
      </TableComponent>
    </template>
    <template v-else>
      <div class="orders-page__content">
        <OrdersPageCards />
      </div>
    </template>
    <OrdersPageAlert
      v-if="selected.length && switchView"
      :selected="selected"
      :filters="exportFilters"
    />
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import OrdersPageFilters from "@/views/orders/components/OrdersPageFilters.vue";
import TableComponent from "@/components/table/index.vue";
import OrdersPageCards from "@/views/orders/components/OrdersPageCards.vue";
import OrdersPageAlert from "@/views/orders/components/OrdersPageAlert.vue";
import ORDERS from "@/graphql/pages/Orders/admin_orders_paginate.graphql";
import STAFF_OPTIONS from "@/graphql/pages/Order/options.graphql";
import { useFiltersStore } from "@/stores/pageFilters.js";
import { useAuthStore } from "@/stores/auth.js";
import { useMenuStore } from "@/stores/menu.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { useTableColumnsConfigStore } from "@/stores/tableColumnsConfig.js";
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import ADMIN_SETTING from "@/graphql/queries/adminSetting.graphql";
import dayjs from "dayjs";

export default {
  name: "OrdersPage",

  inject: ["apollo"],

  async mounted() {
    this.filters = this.buildFiltersFromRoute();
    await this.loadHideShopSetting();
    this.shopId = this.savedFilters.partnerId || null;
    await this.getStaffOptions();
    await this.get();
  },

  data() {
    return {
      hideShop: false,
      shopId: null,
      couriers: [],
      collectors: [],
      switchView: true,
      active: false,
      first: 25,
      page: 1,
      activeFilters: [328],
      selected: [],
      activeRowIndex: null,
      filters: this.defaultFilters(),
      filtersDebounce: null,
      loading: false,
      // filters: [
      //   {
      //     id: 1,
      //     title: "Самые дорогие",
      //   },
      //   {
      //     id: 13,
      //     title: "Заказы от Ангелины",
      //   },
      //   {
      //     id: 328,
      //     title: "Лучшие заказы",
      //   },
      //   {
      //     id: 43,
      //     title: "Заказы на доставке",
      //   },
      // ],
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
          // sortable: true,
          selectable: true,
          show: true,
          width: "130px",
        },
        {
          title: "Дата",
          field: "created_at",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Покупатель",
          field: "client",
          // sortable: true,
          selectable: true,
          show: true,
          // Минимум под самое длинное слово колонки: при 100% дефолтные 100px рвали слова посреди («Покупате/ль», «Наличны/е») (MID-343)
          width: "minmax(130px, 1fr)",
        },
        {
          title: "Адрес",
          field: "address",
          selectable: true,
          show: true,
          // Адрес длиннее прочих ячеек: дефолтные minmax(100px, 1fr) режут его
          // на 3–4 строки.
          width: "minmax(200px, 1.8fr)",
        },
        {
          title: "Статус",
          field: "status_id",
          // sortable: true,
          selectable: true,
          show: true,
          width: "minmax(130px, 1fr)",
        },
        {
          title: "Магазин",
          field: "shop",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Тип",
          field: "type_id",
          selectable: true,
          show: true,
          width: "minmax(115px, 1fr)",
        },
        {
          title: "Слот доставки",
          field: "slot",
          selectable: true,
          show: true,
        },
        {
          title: "Способ оплаты",
          field: "payment_type_id",
          // sortable: true,
          selectable: true,
          show: true,
          width: "minmax(115px, 1fr)",
        },
        {
          title: "Статус оплаты",
          field: "payment_status",
          selectable: true,
          show: true,
          width: "minmax(115px, 1fr)",
        },
        {
          title: "Сумма",
          field: "amount",
          show: true,
          system: true,
        },
        {
          title: "Сборщик",
          field: "collector",
          selectable: true,
          show: true,
        },
        {
          title: "Курьер",
          field: "couriers",
          selectable: true,
          show: true,
        },
        {
          title: "Тяжелый",
          field: "is_heavy",
          selectable: true,
          show: true,
        },
        {
          title: "",
          field: "action",
          show: true,
          system: true,
          width: "50px",
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
    async loadHideShopSetting() {
      try {
        const { data } = await this.apollo().admin.query({
          query: ADMIN_SETTING,
          variables: { key: "hide_orders_shop" },
          fetchPolicy: "network-only",
        });
        if (data.adminSetting && data.adminSetting.value === "1") {
          this.hideShop = true;
          const shopCol = this.columns.find((c) => c.field === "shop");
          if (shopCol) shopCol.show = false;
        }
      } catch {
        // Настройка не найдена — показываем магазин
      }
    },
    defaultFilters() {
      return {
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
      };
    },
    buildFiltersFromRoute() {
      const q = this.$route.query || {};
      const parseArr = (v) => {
        if (!v) return [];
        const arr = Array.isArray(v) ? v : String(v).split(",");
        return arr
          .map((x) => parseInt(x, 10))
          .filter((x) => Number.isFinite(x));
      };
      const parseStrArr = (v) => {
        if (!v) return [];
        const arr = Array.isArray(v) ? v : String(v).split(",");
        return arr.map((x) => String(x).trim()).filter(Boolean);
      };
      const parseNum = (v) => {
        if (v === null || v === undefined || v === "") return null;
        const n = Number(v);
        return Number.isFinite(n) ? n : null;
      };
      return {
        title: q.title || null,
        client_search: q.client_search || null,
        statuses: parseArr(q.statuses),
        types: parseArr(q.types),
        payment_types: parseArr(q.payment_types),
        payment_statuses: parseStrArr(q.payment_statuses),
        couriers: parseArr(q.couriers),
        collectors: parseArr(q.collectors),
        amount_from: parseNum(q.amount_from),
        amount_to: parseNum(q.amount_to),
        created_at_start_date: q.date_from || null,
        created_at_end_date: q.date_to || null,
      };
    },
    syncFiltersToUrl() {
      const f = this.filters;
      const query = {};
      if (f.title) query.title = f.title;
      if (f.client_search) query.client_search = f.client_search;
      if (f.statuses && f.statuses.length)
        query.statuses = f.statuses.join(",");
      if (f.types && f.types.length) query.types = f.types.join(",");
      if (f.couriers && f.couriers.length) query.couriers = f.couriers.join(",");
      if (f.collectors && f.collectors.length)
        query.collectors = f.collectors.join(",");
      if (f.payment_types && f.payment_types.length)
        query.payment_types = f.payment_types.join(",");
      if (f.payment_statuses && f.payment_statuses.length)
        query.payment_statuses = f.payment_statuses.join(",");
      if (f.amount_from !== null && f.amount_from !== undefined)
        query.amount_from = String(f.amount_from);
      if (f.amount_to !== null && f.amount_to !== undefined)
        query.amount_to = String(f.amount_to);
      if (f.created_at_start_date) query.date_from = f.created_at_start_date;
      if (f.created_at_end_date) query.date_to = f.created_at_end_date;

      const curr = this.$route.query || {};
      const same = JSON.stringify(curr) === JSON.stringify(query);
      if (!same) {
        this.$router.replace({ name: this.$route.name, query });
      }
    },
    handleSelected($event) {
      this.selected = [...$event];
    },
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.get();
    },
    handlePaginate($event) {
      this.page = $event;
      this.get();
    },
    updateActiveFilters($event) {
      this.activeFilters = $event;
    },
    updateSwitchView($event) {
      this.switchView = $event;
    },
    handleFiltersChange($event) {
      this.filters = { ...this.filters, ...$event };
      this.page = 1;
      this.syncFiltersToUrl();

      if (this.filtersDebounce) {
        clearTimeout(this.filtersDebounce);
      }
      const isTextChange =
        "title" in $event ||
        "client_search" in $event ||
        "amount_from" in $event ||
        "amount_to" in $event;
      const delay = isTextChange ? 300 : 0;
      this.filtersDebounce = setTimeout(() => {
        this.get();
      }, delay);
    },
    updateShop() {
      useFiltersStore().set(this.$route.name, {
        partnerId: this.shopId || undefined,
      });
      this.page = 1;
      this.getStaffOptions();
      this.get();
    },
    formatClientName(client) {
      if (!client) return "";
      const parts = [client.surname, client.name].filter(Boolean);
      return parts.join(" ") || client.phone || "";
    },
    formatStaffName(staff) {
      if (!staff) return "";
      const parts = [staff.surname, staff.name, staff.patronymic].filter(Boolean);
      return parts.join(" ");
    },
    formatDate(date) {
      if (!date) return "";
      return dayjs(date, "YYYY-MM-DD HH:mm:ss").format("DD.MM.YYYY");
    },
    formatTime(date) {
      if (!date) return "";
      return dayjs(date, "YYYY-MM-DD HH:mm:ss").format("HH:mm");
    },
    isAmountRangeValid() {
      const { amount_from: from, amount_to: to } = this.filters;
      if (from === null || from === undefined) return true;
      if (to === null || to === undefined) return true;
      return Number(from) <= Number(to);
    },
    isDateRangeValid() {
      const { created_at_start_date: from, created_at_end_date: to } =
        this.filters;
      if (!from || !to) return true;
      return new Date(from) <= new Date(to);
    },
    async getStaffOptions() {
      // Queries couriers и collectors в admin_back закрыты политикой onlyAdmin —
      // у роли shop вернётся ошибка, тогда оба списка остаются пустыми
      // и селекты не отрисуются
      try {
        const { data } = await this.apollo().admin.query({
          query: STAFF_OPTIONS,
          variables: { shop_id: this.shopId || undefined },
          fetchPolicy: "network-only",
        });
        this.couriers = data?.couriers || [];
        this.collectors = data?.collectors || [];
      } catch {
        this.couriers = [];
        this.collectors = [];
      }
    },
    async get() {
      if (this.loading) return;
      if (!this.isAmountRangeValid() || !this.isDateRangeValid()) {
        return;
      }
      this.rows.data = [];
      this.loading = true;
      const f = this.filters;
      const variables = {
        first: this.savedFilters.first,
        page: this.page || undefined,
        ordering: this.ordering,
        shops: this.shopId ? [this.shopId] : undefined,
        title: f.title || undefined,
        client_search: f.client_search || undefined,
        statuses: f.statuses && f.statuses.length ? f.statuses : undefined,
        types: f.types && f.types.length ? f.types : undefined,
        couriers: f.couriers && f.couriers.length ? f.couriers : undefined,
        collectors: f.collectors && f.collectors.length ? f.collectors : undefined,
        payment_types:
          f.payment_types && f.payment_types.length
            ? f.payment_types
            : undefined,
        payment_statuses:
          f.payment_statuses && f.payment_statuses.length
            ? f.payment_statuses
            : undefined,
        amount_from:
          f.amount_from !== null && f.amount_from !== undefined
            ? Number(f.amount_from)
            : undefined,
        amount_to:
          f.amount_to !== null && f.amount_to !== undefined
            ? Number(f.amount_to)
            : undefined,
        created_at_start_date: this.toGraphqlDateTime(f.created_at_start_date),
        created_at_end_date: this.toGraphqlDateTime(f.created_at_end_date),
      };
      await this.apollo()
        .purchase.query({
          query: ORDERS,
          variables: variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data && data.admin_orders_paginate) {
            this.rows.data = data.admin_orders_paginate.data;
            this.rows.paginatorInfo = data.admin_orders_paginate.paginatorInfo;
          }
        })
        .catch(() => {
          console.warn("error");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    /**
     * Lighthouse DateScalar ожидает `Y-m-d H:i:s`; toISOString() даёт `.000Z` —
     * парсер падает с "Trailing data". Приводим ISO к формату MySQL/Carbon.
     */
    toGraphqlDateTime(value) {
      if (!value) return undefined;
      const d = value instanceof Date ? value : new Date(value);
      if (Number.isNaN(d.getTime())) return undefined;
      return d.toISOString().slice(0, 19).replace("T", " ");
    },
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Заказы");
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...(useFiltersStore().get(this.$route.name) || {
            search: null,
            partnerId: null,
          }),
        },
      };
    },
    isAdmin() {
      return useAuthStore().isAdminRole;
    },
    heavyOrdersModuleEnabled() {
      // Флоу тяжёлых заказов активен при heavy_orders ИЛИ courier_assignment
      // (совместная доставка входит в «Назначение курьера сборщиком»)
      const modules = useModuleConfigStore();
      return modules.isModuleEnabled("heavy_orders") || modules.isModuleEnabled("courier_assignment");
    },
    courierAssignmentModuleEnabled() {
      return useModuleConfigStore().isModuleEnabled("courier_assignment");
    },
    checkoutWithoutSlotsModuleEnabled() {
      // Модуль «Оформление заказа без слотов» (MID-256): у доставки слот заказа
      // технический (момент оформления) — время в колонке не показываем.
      // Самовывоз в модуль не входит: клиент выбирает время выдачи, слот реальный
      return useModuleConfigStore().isModuleEnabled("checkout_without_slots");
    },
    /**
     * Состав и порядок колонок — из «Настройки → Колонки таблиц», поверх них
     * остаются правила самой страницы: hide_orders_shop (флаг show) и гейт
     * колонки «Тяжелый» по модулю.
     */
    visibleColumns() {
      return useTableColumnsConfigStore()
        .applyTo("orders", this.columns || [])
        .filter((c) => c.show || c.system)
        .filter((c) => c.field !== "is_heavy" || this.heavyOrdersModuleEnabled);
    },
    exportFilters() {
      const f = this.filters;
      return {
        title: f.title || undefined,
        shops: this.shopId ? [this.shopId] : undefined,
        statuses: f.statuses && f.statuses.length ? f.statuses : undefined,
        types: f.types && f.types.length ? f.types : undefined,
        couriers: f.couriers && f.couriers.length ? f.couriers : undefined,
        collectors: f.collectors && f.collectors.length ? f.collectors : undefined,
        payment_types:
          f.payment_types && f.payment_types.length
            ? f.payment_types
            : undefined,
        payment_statuses:
          f.payment_statuses && f.payment_statuses.length
            ? f.payment_statuses
            : undefined,
        client_search: f.client_search || undefined,
        amount_from:
          f.amount_from !== null && f.amount_from !== undefined
            ? Number(f.amount_from)
            : undefined,
        amount_to:
          f.amount_to !== null && f.amount_to !== undefined
            ? Number(f.amount_to)
            : undefined,
        created_at_start_date: this.toGraphqlDateTime(f.created_at_start_date),
        created_at_end_date: this.toGraphqlDateTime(f.created_at_end_date),
      };
    },
  },
  // directives: {
  //   "click-outside": {
  //     mounted(el, binding) {
  //       el.clickOutsideEvent = (event) => {
  //         if (!(el === event.target || el.contains(event.target))) {
  //           binding.value(event);
  //         }
  //       };
  //       document.body.addEventListener("click", el.clickOutsideEvent);
  //     },
  //     beforeUnmount(el) {
  //       document.body.removeEventListener("click", el.clickOutsideEvent);
  //     },
  //   },
  // },
  components: {
    ShopSelectComponent,
    OrdersPageAlert,
    OrdersPageCards,
    IconComponent,
    OrdersPageFilters,
    TableComponent,
  },
};
</script>

<style lang="stylus">
//@import "styles"
.orders-page {
  display flex
  flex-direction column
  gap 16px
  padding 16px 32px
  height 100%
  width 100%
  padding-bottom 0
  background var(--gray-3)

  &__table {
    max-height: calc(100vh - 170px);

    .table__container {
      height: calc(100% - 70px) !important;
    }
  }

  &__table--is-admin {
    max-height: calc(100vh - 275px);
  }

  &__empty {
    display flex
    align-items center
    justify-content center
    padding 32px
    background var(--white)
    border-radius 8px
    color var(--gray-2)
    font-size 0.875em
    min-height 160px
  }

  &__row-subtext {
    font-size 0.75em
    color var(--gray-2)
    margin-top 2px
    // Время/телефон — неразрывные: word-break break-word таблицы в узкой
    // колонке рвал «11:48» и «+7967…» посреди строки (MID-193).
    white-space nowrap
  }

  // Глобальный .rows__left — flex row: пары «дата+время», «имя+телефон»,
  // «дата слота+интервал» и несколько курьеров вставали В РЯД и сжимали
  // друг друга до переносов посреди слова (MID-193). В таблице заказов
  // содержимое таких ячеек — вертикальный стек.
  .rows__left {
    flex-direction column
    align-items flex-start
    gap 2px
  }

  &__row-date {
    white-space nowrap
  }

  &__dot {
    width 6px
    height 6px
    background var(--orange-600)
    border-radius 999px
  }

  &__statuses {
    display grid
    grid-template-columns repeat(4, 1fr)
    grid-gap 16px

    li {
      display flex
      justify-content space-between
      align-items center
      padding 8px 16px
      background var(--white)
      border-radius 8px
      font-size 0.875em

      .status {
        padding 4px 8px
      }
    }
  }
}
</style>
