<template>
  <main class="page store-page">
    <BreadcrumbsComponent v-if="isAdmin" :breadcrumbs="breadcrumbs" />
    <div class="page__header store-page__header">
      <div class="store-page__title-block">
        <router-link
          v-if="isAdmin"
          :to="{ name: 'stores' }"
          class="btn btn--white btn--sm"
        >
          <IconComponent name="arrow-left-line" />
        </router-link>
        <h2>{{ headerTitle }}</h2>
        <span
          v-if="showStatusBadge"
          class="store-page__badge"
          :class="isActive ? 'store-page__badge--active' : 'store-page__badge--inactive'"
        >
          {{ isActive ? 'Активен' : 'Неактивен' }}
        </span>
      </div>
      <button @click.prevent="submit" :disabled="loading" class="btn btn--md store-page__btn">
        <IconComponent name="checkbox-circle-line" />
        <span> Сохранить изменения </span>
      </button>
    </div>

    <div v-if="tabs.length > 1" class="store-page__tabs">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="store-page__tab"
        :class="{ 'store-page__tab--active': activeTab === tab.key }"
        @click="activeTab = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>

    <div class="store-page__content">
      <MainInfoTab
        v-if="activeTab === 'main'"
        :form="form"
        :is-admin="isAdmin"
        :is-edit="!!id"
        :type-options="type_options"
        :parent-categories="parent_categories"
        :cities="cities"
      />
      <DeliveryPickupTab
        v-else-if="activeTab === 'delivery'"
        :form="form"
        :is-admin="isAdmin"
        :is-edit="!!id"
        @open-zone="changeDeliveryTab"
      />
      <AssemblyMapTab
        v-else-if="activeTab === 'map'"
        :shop-categories="shopCategories"
        :parent-categories="parent_categories"
        @reload="refreshShopCollections"
      />
      <BonusTab
        v-else-if="activeTab === 'bonus'"
        :form="form"
      />
      <StopListsTab v-else-if="activeTab === 'stoplists'" />
      <EmployeesTab
        v-else-if="activeTab === 'employees'"
        :staffs="staffs"
        :loading="loading"
      />
      <StatisticsTab v-else-if="activeTab === 'statistics'" />
    </div>
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import { parseGqlErrors, resetErrors, parseCustomCheckboxes } from "@/utils.js";
import GET from "@/graphql/pages/Stores/shop.graphql";
import GET_MY_SHOP from "@/graphql/pages/Stores/my_shop.graphql";
import CREATE from "@/graphql/mutations/ShopCreate.graphql";
import UPDATE from "@/graphql/mutations/ShopUpdate.graphql";
import OPTIONS from "@/graphql/pages/Stores/options.graphql";
import SET_WORK_HOUS from "@/graphql/pages/Stores/SetShopWorkingHours.graphql";
import moment from "moment";
import AddDeliveryArea from "@/views/stores/modals/AddDeliveryArea.vue";
import { shallowRef } from "vue";
import { useAuthStore } from "@/stores/auth.js";
import { useMenuStore } from "@/stores/menu.js";
import { useDefaultStore } from "@/stores/default.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { useFieldVisibilityConfigStore } from "@/stores/fieldVisibilityConfig.js";
import MainInfoTab from "@/views/stores/tabs/MainInfoTab.vue";
import DeliveryPickupTab from "@/views/stores/tabs/DeliveryPickupTab.vue";
import AssemblyMapTab from "@/views/stores/tabs/AssemblyMapTab.vue";
import BonusTab from "@/views/stores/tabs/BonusTab.vue";
import StopListsTab from "@/views/stores/tabs/StopListsTab.vue";
import EmployeesTab from "@/views/stores/tabs/EmployeesTab.vue";
import StatisticsTab from "@/views/stores/tabs/StatisticsTab.vue";
// import UPDATE_DELIVERY from "@/graphql/pages/Stores/ShopZoneCreteOrUpdate.graphql";

export default {
  name: "StorePage",
  inject: ["apollo"],
  components: {
    IconComponent,
    BreadcrumbsComponent,
    MainInfoTab,
    DeliveryPickupTab,
    AssemblyMapTab,
    BonusTab,
    StopListsTab,
    EmployeesTab,
    StatisticsTab,
  },
  data() {
    return {
      activeTab: this.$route.query.tab || "main",
      // Был ли у магазина график при загрузке: у магазинов из 1С его обычно нет
      hadWorkingHours: false,
      parent_categories: [],
      cities: [],
      payment_options: [
        {
          id: 1,
          title: "Списывание оплаты после сборки заказа",
        },
        {
          id: 2,
          title: "Списывание оплаты после оформления заказа",
        },
        {
          id: 3,
          title: "Списывание оплаты после начала сборки",
        },
        {
          id: 4,
          title: "Списывание оплаты после доставки",
        },
      ],
      type_options: [
        {
          id: 1,
          title: "Магазин",
        },
        {
          id: 2,
          title: "Кафе",
        },
      ],

      activeZone: 0,
      staffs: [],
      shopId: null,
      shopCategories: null,
      debounce: null,
      loading: false,
      deliveryAreas: {
        small: {
          value: null,
          errors: [],
        },
        middle: {
          value: null,
          errors: [],
        },
        big: {
          value: null,
          errors: [],
        },
      },
      form: {
        code: {
          value: null,
          errors: [],
        },
        status_id: {
          value: 1,
          errors: [],
        },
        img: {
          value: null,
          errors: [],
        },
        logo_img: {
          value: null,
          errors: [],
        },
        background_img: {
          value: null,
          errors: [],
        },
        title: {
          value: null,
          errors: [],
        },
        city_id: {
          value: null,
          errors: [],
        },
        is_default: {
          value: false,
          errors: [],
        },
        phone: {
          value: null,
          errors: [],
        },
        email: {
          value: null,
          errors: [],
        },
        payment_type_id: {
          value: null,
          errors: [],
        },
        slot_time: {
          value: null,
          errors: [],
        },
        twentyFourSeven: {
          value: true,
          errors: [],
        },
        schedule: {
          value: null,
          errors: [],
        },
        commission: {
          value: null,
          errors: [],
        },
        area_sqm: {
          value: null,
          errors: [],
        },
        categories: {
          value: [],
          errors: [],
        },
        slot_max_orders: {
          value: null,
          errors: [],
        },
        working_days: {
          value: [],
          errors: [],
        },
        address: {
          value: null,
          errors: [],
        },
        deposit: {
          value: null,
          errors: [],
        },
        opening_time: {
          value: null,
          errors: [],
        },
        closing_time: {
          value: null,
          errors: [],
        },
        longitude: {
          value: null,
          errors: [],
        },
        latitude: {
          value: null,
          errors: [],
        },
        description: {
          value: null,
          errors: [],
        },
        zones: {
          value: [],
          errors: [],
        },
        type_id: {
          value: null,
          errors: [],
        },
        is_popular: {
          value: false,
          errors: [],
        },
        is_b2b: {
          value: false,
          errors: [],
        },
        ignore_stock: {
          value: false,
          errors: [],
        },
        is_bonus_enabled: {
          value: null,
          errors: [],
        },
        bonus_earn_percent: {
          value: null,
          errors: [],
        },
        bonus_spend_percent: {
          value: null,
          errors: [],
        },
        is_pickup_enabled: {
          value: false,
          errors: [],
        },
        min_pickup_order_amount: {
          value: null,
          errors: [],
        },
        heavy_order_threshold: {
          value: null,
          errors: [],
        },
        assembly_price: {
          value: null,
          errors: [],
        },
        pickup_payment_methods: {
          value: ['cash'],
          errors: [],
        },
        pickup_auto_cancel_enabled: {
          value: true,
          errors: [],
        },
        pickup_wait_perishable_hours: {
          value: 1,
          errors: [],
        },
        pickup_wait_regular_hours: {
          value: 3,
          errors: [],
        },
        pickup_grace_period_hours: {
          value: 1,
          errors: [],
        },
        custom_checkboxes: {
          value: {},
          errors: [],
        },
      },
    };
  },
  created() {
    // Если ?tab= указывает на вкладку, недоступную в текущем режиме (мерчант / создание / модуль
    // выключен) — откатываемся на «Основную информацию».
    if (!this.tabs.some((t) => t.key === this.activeTab)) {
      this.activeTab = "main";
    }
  },
  mounted() {
    this.get();
  },
  watch: {
    activeTab(val) {
      if (val !== this.$route.query.tab) {
        this.$router.replace({ query: { tab: val } });
      }
    },
    // Конфиг модулей грузится асинхронно: если после его прихода активная
    // вкладка оказалась скрыта (модуль выключен) — откатываемся на «Основную».
    tabs(list) {
      if (!list.some((t) => t.key === this.activeTab)) {
        this.activeTab = "main";
      }
    },
  },
  methods: {
    parseGqlErrors,
    resetErrors,
    formatDate(date) {
      return moment(date, "HH:mm:ss").format("HH:mm");
    },
    get() {
      if (this.isAdmin) {
        this.apollo()
          .admin.query({
            query: OPTIONS,
          })
          .then(({ data }) => {
            this.parent_categories = data.parent_categories;
            this.cities = data.cities || [];
          });
      }

      if (this.id || !this.isAdmin) {
        this.loading = true;
        // network-only: apollo-кэш денормализован (addTypename:false) и не
        // инвалидируется мутациями других разделов — назначение сотрудника
        // магазину (UserCreate/UserUpdate в «Сотрудниках») не обновляет
        // закэшированный blob shop → cache-first отдавал бы устаревших
        // workers до жёсткого обновления страницы.
        this.apollo()
          .admin.query({
            query: this.isAdmin ? GET : GET_MY_SHOP,
            fetchPolicy: "network-only",
            variables: {
              id: this.id,
            },
          })
          .then(({ data }) => {
            if (data.shop) {
              this.staffs = data.shop.workers;
              // Время сборки задаётся только конечным (листовым) категориям —
              // родительские (у которых есть подкатегории) не показываем.
              this.shopCategories = (data.shop.categories || []).filter(
                (item) => item.shop_category && !item.child_categories?.length
              );
              Object.keys(this.form).forEach((key) => {
                this.form[key].value = data.shop[key];
                if (key === "opening_time" || key === "closing_time") {
                  this.form[key].value = data.shop[key]
                    ? moment(data.shop[key], "HH:mm:ss").format("HH:mm")
                    : "";
                }
                if (key === "pickup_payment_methods") {
                  // Пустой fallback: чекбокс «Оплата при получении» отражает
                  // фактическое наличие 'cash' в сохранённых способах оплаты.
                  try {
                    this.form[key].value = typeof data.shop[key] === 'string'
                      ? JSON.parse(data.shop[key]) || []
                      : (data.shop[key] || []);
                  } catch { this.form[key].value = []; }
                }
                if (key === "heavy_order_threshold") {
                  // В БД граммы — в форме килограммы
                  this.form[key].value = data.shop[key] != null ? data.shop[key] / 1000 : null;
                }
                if (key === "assembly_price") {
                  // Рубли, без конвертации.
                  this.form[key].value = data.shop[key] != null ? data.shop[key] : null;
                }
                if (key === "custom_checkboxes") {
                  this.form[key].value = parseCustomCheckboxes(data.shop[key]);
                }
              });
              this.form.twentyFourSeven.value = data.shop.work_type_id === 2;
              this.hadWorkingHours = this.isScheduleFilled();
              this.form.payment_type_id.value = this.payment_options.find(
                (item) => item.id === data.shop.payment_type_id
              );
              this.form.type_id.value = this.type_options.find((item) => item.id === data.shop.type_id);
            }
            if (data.my_shop) {
              this.shopId = data.my_shop.id;
              this.staffs = data.my_shop.workers;
              Object.keys(this.form).forEach((key) => {
                this.form[key].value = data.my_shop[key];
                if (key === "custom_checkboxes") {
                  this.form[key].value = parseCustomCheckboxes(data.my_shop[key]);
                }
              });
              this.form.payment_type_id.value = this.payment_options.find(
                (item) => item.id === data.my_shop.payment_type_id
              );
            }
            this.loading = false;
          })
          .catch(() => {
            console.log("error");
            this.loading = false;
          })
          .finally(() => {
            this.loading = false;
          });
      }
    },
    // Точечный refresh серверных коллекций магазина после действий с картой
    // (добавление/удаление секции) и зонами. В ОТЛИЧИЕ от get() НЕ раскладывает
    // все form-поля по data.shop — иначе несохранённый ввод пользователя на
    // других вкладках (email, описание, проценты, бонусы и т.д.) молча
    // затирался бы. Обновляются только те данные, что реально изменились на
    // сервере: сотрудники, секции карты, привязки категорий и зоны.
    // network-only обязателен: apollo-кэш денормализован (addTypename:false),
    // мутации секций/зон не обновляют закэшированный blob shop → cache-first
    // вернул бы устаревшие данные и сделал refresh бессмысленным.
    refreshShopCollections() {
      // Метод дёргается только из админских вкладок (карта/зоны) при
      // редактировании существующего магазина. Guard страхует мерчантский путь.
      if (!this.isAdmin || !this.id) {
        return;
      }
      this.apollo()
        .admin.query({
          query: GET,
          fetchPolicy: "network-only",
          variables: {
            id: this.id,
          },
        })
        .then(({ data }) => {
          if (!data.shop) {
            return;
          }
          this.staffs = data.shop.workers;
          // Время сборки задаётся только конечным (листовым) категориям —
          // родительские (у которых есть подкатегории) не показываем.
          // Фильтр идентичен get().
          this.shopCategories = (data.shop.categories || []).filter(
            (item) => item.shop_category && !item.child_categories?.length
          );
          // ShopCategorySectionDelete / добавление секции физически меняют
          // привязки категорий магазина. Без обновления form.categories сейв
          // главной вкладки отправил бы СТАРЫЙ список в ShopUpdate и вернул бы
          // удалённую категорию обратно (re-attach).
          this.form.categories.value = data.shop.categories;
          this.form.zones.value = data.shop.zones;
        })
        .catch(() => {
          console.log("error");
        });
    },
    collectData() {
      let variables = {
        id: this.id || this.shopId || undefined,
      };

      Object.keys(this.form).forEach((key) => {
        variables[key] = this.form[key].value ?? undefined;
        if (key === "slot_max_orders" || key === "slot_time" || key === "bonus_earn_percent" || key === "bonus_spend_percent"
          || key === "pickup_wait_perishable_hours" || key === "pickup_wait_regular_hours" || key === "pickup_grace_period_hours"
          || key === "min_pickup_order_amount") {
          variables[key] = this.form[key].value != null ? parseInt(this.form[key].value) : undefined;
        }
        variables.categories = this.form.categories.value.map((item) => item.id);
        variables.payment_type_id = this.form.payment_type_id.value
          ? this.form.payment_type_id.value.id
          : undefined;

        if (key === "pickup_payment_methods") {
          // При выключенном модуле pickup_card_payment чекбокс «Картой онлайн»
          // скрыт — вычищаем 'card' при сохранении, иначе значение из синка 1С
          // (["card"]) невозможно убрать и витрина продолжала бы предлагать
          // онлайн-оплату самовывоза.
          const methods = this.moduleStore.isModuleEnabled("pickup_card_payment")
            ? this.form[key].value || []
            : (this.form[key].value || []).filter((m) => m !== "card");
          variables[key] = JSON.stringify(methods);
        }

        if (key === "custom_checkboxes") {
          variables[key] = JSON.stringify(this.form[key].value || {});
        }

        if (key === "heavy_order_threshold") {
          // Килограммы из формы → граммы в БД. Поле видно только админу — мерчант его не отправляет.
          variables[key] = this.isAdmin && this.form[key].value != null && this.form[key].value !== ""
            ? Math.round(parseFloat(this.form[key].value) * 1000)
            : (this.isAdmin ? null : undefined);
        }

        if (key === "assembly_price") {
          // Только админ отправляет поле; рубли без конвертации.
          variables[key] = this.isAdmin && this.form[key].value != null && this.form[key].value !== ""
            ? Math.round(parseFloat(this.form[key].value))
            : (this.isAdmin ? null : undefined);
        }

        let floatValues = ["longitude", "latitude", "commission", "area_sqm", "deposit"];

        if (floatValues.includes(key)) {
          variables[key] = this.form[key].value ? parseFloat(this.form[key].value) : undefined;
        }
      });

      // Код магазина синхронизируется из 1С и в мутациях не участвует (readonly).
      variables.code = undefined;
      // Город и «магазин по умолчанию» правит только админ. null у города — осознанная
      // отвязка (backend отличает её от отсутствия ключа), у мерчанта ключей нет вовсе.
      variables.city_id = this.isAdmin
        ? (this.form.city_id.value ? parseInt(this.form.city_id.value) : null)
        : undefined;
      variables.is_default = this.isAdmin ? !!this.form.is_default.value : undefined;
      // Статус магазина отправляется только при редактировании админом (ShopUpdate). Контракт: 1|3.
      variables.status_id = this.isAdmin && this.id && this.form.status_id.value != null
        ? parseInt(this.form.status_id.value)
        : undefined;

      if (this.isAdmin) {
        // Поле «Тип магазина» может быть скрыто настройкой полей (MID-306). Сервер требует
        // type_id при создании, поэтому скрытое пустое поле отправляет «Магазин» (1).
        const typeHidden = !useFieldVisibilityConfigStore().isEnabled("Shop", "type_id");
        variables.type_id = this.form.type_id.value
          ? this.form.type_id.value.id
          : (typeHidden ? 1 : undefined);
      } else {
        variables.type_id = this.form.type_id.value;
      }

      return variables;
    },
    isScheduleFilled() {
      return Boolean(
        this.form.twentyFourSeven.value ||
          this.form.working_days.value?.length ||
          this.form.opening_time.value ||
          this.form.closing_time.value
      );
    },
    // Сообщения ошибок GraphQL для alert: ошибки полей выводятся под полями,
    // но кнопка «Сохранить» наверху длинной формы, и без alert их не видно (MID-310)
    gqlErrorText(graphQLErrors) {
      const messages = (graphQLErrors || []).flatMap((err) =>
        err.extensions?.validation ? Object.values(err.extensions.validation).flat() : [err.message]
      );
      return messages.length ? messages.join("\n") : "Не удалось связаться с сервером";
    },
    saveWorkingHours() {
      return this.apollo()
        .admin.mutate({
          mutation: SET_WORK_HOUS,
          variables: {
            shop_id: this.id,
            type_id: this.form.twentyFourSeven.value ? 2 : 1,
            days: this.form.working_days.value,
            opening_time: this.form.twentyFourSeven.value ? undefined : this.form.opening_time.value,
            closing_time: this.form.twentyFourSeven.value ? undefined : this.form.closing_time.value,
          },
        })
        .then(() => {
          this.hadWorkingHours = this.isScheduleFilled();
          alert("Изменения сохранены");
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors || []);
          // В мутации поле называется days, в форме working_days
          const days = (graphQLErrors || []).flatMap((err) => err.extensions?.validation?.days || []);
          this.form.working_days.errors = days;
          alert(
            "Основные данные сохранены, но график работы не сохранён:\n" + this.gqlErrorText(graphQLErrors)
          );
        });
    },
    submit() {
      this.loading = true;
      Object.values(this.form).forEach((field) => {
        field.errors = [];
      });

      this.apollo()
        .admin.mutate({
          mutation: this.id || !this.isAdmin ? UPDATE : CREATE,
          variables: this.collectData(),
        })
        .then(() => {
          // Пустой график не отправляем: у магазинов из 1С его нет, и мутация
          // графика отклоняла сохранение карточки без единого сообщения (MID-310)
          if (this.isAdmin && this.id && (this.hadWorkingHours || this.isScheduleFilled())) {
            return this.saveWorkingHours();
          }
          alert("Изменения сохранены");
          if (!this.id) {
            this.$router.push({ name: "stores" });
          }
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors || []);
          alert("Изменения не сохранены:\n" + this.gqlErrorText(graphQLErrors));
        })
        .finally(() => {
          this.loading = false;
        });
    },
    changeDeliveryTab(item, i) {
      this.activeZone = i;
      useDefaultStore().modals.push({
        component: shallowRef(AddDeliveryArea),
        options: {
          id: item?.id || undefined,
          shop_id: this.id,
          zoneIndex: (i ?? this.form.zones.value?.length ?? 0) + 1,
          zoneName: item?.name || null,
          callback: this.refreshShopCollections,
        },
      });
    },
  },
  computed: {
    my_shop_id() {
      return useAuthStore().user?.shops?.[0]?.shop?.id;
    },
    isAdmin() {
      return useAuthStore().isGlobalUser;
    },
    moduleStore() {
      return useModuleConfigStore();
    },
    id() {
      return parseInt(this.$route.params.id);
    },
    isActive() {
      return this.form.status_id.value === 1;
    },
    showStatusBadge() {
      return this.isAdmin && !!this.id;
    },
    headerTitle() {
      if (!this.isAdmin) {
        return this.form.title.value || "Мой магазин";
      }
      return this.id ? this.form.title.value || "Магазин" : "Новый магазин";
    },
    tabs() {
      const list = [{ key: "main", label: "Основная информация" }];
      // Мерчант видит только «Основную информацию».
      if (!this.isAdmin) {
        return list;
      }
      // Остальные вкладки — только у админа при редактировании существующего
      // магазина. Каждая (кроме «Сотрудников» — они за fieldVisibility
      // Shop.employees_table внутри вкладки) дополнительно гейтится своим
      // модулем из «Настройки → Модули».
      if (this.id) {
        if (this.moduleStore.isModuleEnabled("shop_delivery_pickup")) {
          list.push({ key: "delivery", label: "Доставка и самовывоз" });
        }
        if (this.moduleStore.isModuleEnabled("shop_assembly_map")) {
          list.push({ key: "map", label: "Карта магазина для сборщика" });
        }
        if (this.moduleStore.isModuleEnabled("bonuses")) {
          list.push({ key: "bonus", label: "Бонусная программа" });
        }
        if (this.moduleStore.isModuleEnabled("shop_stop_lists")) {
          list.push({ key: "stoplists", label: "Стоп-листы" });
        }
        list.push({ key: "employees", label: "Сотрудники" });
        if (this.moduleStore.isModuleEnabled("shop_statistics")) {
          list.push({ key: "statistics", label: "Статистика" });
        }
      }
      return list;
    },
    breadcrumbs() {
      // Название раздела берём из пункта меню (на mendev — «Магазины»),
      // как это делает заголовок списка; хардкод «Партнеры» расходился с меню.
      return [
        {
          to: { name: "stores" },
          title: useMenuStore().titleFor("stores", "Магазины"),
        },
        {
          title: this.id ? "Редактирование" : "Новый магазин",
        },
      ];
    },
  },
};
</script>

<style lang="stylus">
.store-page {

  &__header {
    display flex
    align-items center
    justify-content space-between
    gap 16px
  }

  &__title-block {
    display flex
    align-items center
    gap 16px

    h2 {
      margin 0
    }
  }

  &__badge {
    padding 4px 12px
    border-radius 999px
    font-size 0.75rem
    font-weight 500
    line-height 18px
  }

  &__badge--active {
    background var(--green)
    color var(--white)
  }

  &__badge--inactive {
    background var(--gray-dark)
    color var(--dark-light)
  }

  &__btn {
    margin-left auto
  }

  &__tabs {
    display flex
    flex-wrap wrap
    gap 0
    border-bottom 2px solid var(--gray-dark)
    margin 24px 0
  }

  &__tab {
    padding 12px 20px
    font-size 0.875rem
    font-weight 500
    color var(--dark-light)
    background none
    border none
    border-bottom 2px solid transparent
    margin-bottom -2px
    cursor pointer
    transition color 0.2s, border-color 0.2s
    white-space nowrap

    &:hover {
      color var(--dark)
    }
  }

  &__tab--active {
    color var(--green)
    border-bottom-color var(--green)
  }

  &__content {
    display flex
    flex-direction column
    gap 16px
    margin-top 16px
  }
}
</style>
