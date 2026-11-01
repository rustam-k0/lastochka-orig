<template>
  <main class="products-page">
    <!-- В сетевом каталоге карточка товара глобальна, а страница всегда
         показывает всю сеть — верхний селектор магазина скрыт (MID-234).
         Пер-магазинные цены/остатки доступны в карточке товара. -->
    <div v-if="isAdmin && !networkCatalogEnabled">
      <SelectComponent
        title="Магазин"
        placeholder="Все"
        label-name="title"
        v-model="shop"
        @update:search="handleShopTitle"
        @update:model-value="updateShop"
        @blur="blurShopTitle"
        :options="shops"
        clearable
        :loading="optionsLoading"
      />
    </div>
    <template v-if="shop || networkCatalogEnabled">
      <div class="products-page__header">
        <h2>{{ pageTitle }}</h2>
        <div class="products-page__header-actions">
          <button
            v-if="supplementsEnabled && selectedProducts.length >= 1"
            class="btn btn--md btn--outline"
            @click="openBulkAssignSupplementsModal"
          >
            <span>Назначить добавку ({{ selectedProducts.length }})</span>
          </button>
          <button
            v-if="selectedProducts.length >= 1"
            class="btn btn--md btn--outline"
            @click="openSetStockModeModal"
          >
            <span>Задать режим остатков ({{ selectedProducts.length }})</span>
          </button>
          <button
            v-if="selectedProducts.length >= 1"
            class="btn btn--md btn--outline"
            @click="openSetProductsCategoryModal"
          >
            <span>Перенести в категорию ({{ selectedProducts.length }})</span>
          </button>
          <button
            v-if="configurableEnabled && selectedProducts.length >= 2"
            class="btn btn--md btn--outline"
            @click="goToMerge"
          >
            <span
              >Объединить в вариативный ({{ selectedProducts.length }})</span
            >
          </button>
          <router-link
            v-if="configurableEnabled"
            :to="{
              name: 'configurable-product',
              params: { shop_id: this.shop?.id ?? NO_SHOP_ROUTE_PARAM },
            }"
            class="btn btn--md btn--outline"
          >
            <span>Создать вариативный</span>
          </router-link>
          <router-link
            :to="{ name: 'product', params: { shop_id: this.shop?.id ?? NO_SHOP_ROUTE_PARAM } }"
            class="btn btn--md"
          >
            <IconComponent name="add-circle-line" />
            <span> Добавить товар </span>
          </router-link>
        </div>
      </div>
      <div v-if="configurableEnabled" class="products-page__type-filter">
        <SelectComponent
          title="Тип товара"
          placeholder="Все"
          v-model="productTypeFilter"
          :options="productTypeOptions"
          clearable
          @update:model-value="handleTypeFilterChange"
        />
      </div>
      <TableComponent
        class="products-page__table"
        :class="{
          'products-page__table--filters-none': !showFilters,
          'products-page__table--is-admin': isAdmin,
        }"
        v-if="rows"
        :ordering="ordering"
        :columns="visibleColumns"
        :rows="rows.data || []"
        :loading="loading"
        @update:selected="handleSelected"
        @update:ordering="handleOrdering"
        @update:page="handlePaginate"
        @update:count="handleFirstCount"
        pagination
        :page="page"
        :first="savedFilters.first"
        :total="rows.paginatorInfo.total"
      >
        <template #header>
          <TableHeader
            :show-filters="showFilters"
            @update:showFilters="toggleShowFilters"
            :model-value="savedFilters"
            @update:model-value="handleFilters"
            :categories-tree="categoriesTree"
            placeholder="Поиск по товарам"
          />
          <div v-if="fillStats" class="products-page__fill-stats">
            Средняя заполненность: <b>{{ fillStats.avg_percent }}%</b>
          </div>
        </template>
        <template #col="{ col, row }">
          <div
            v-if="col.field === 'isActive'"
            :class="{
              'rows__indicator--green': row.status_id === 1,
              'rows__indicator--gray': row.status_id === 3,
            }"
          >
            <span></span>
          </div>
          <div
            v-if="col.field === 'select' && bulkSelectEnabled"
            class="products-page__checkbox-col"
          >
            <CheckboxComponent
              :model-value="selectedProducts.includes(row.id)"
              @update:model-value="toggleProductSelect(row.id)"
            />
          </div>
          <div v-if="col.field === 'photo'" class="products-page__photo-col">
            <img
              v-if="row.img?.path"
              :src="mediaEndpoint + row.img.path"
              class="products-page__photo-thumb"
              alt="фото"
              @click.prevent="openPhotoPreview(row)"
            />
            <span v-else class="products-page__photo-empty">—</span>
          </div>
          <!--        <router-link v-if="col.field === 'articul'" :to="{ name: 'product', params: { id: row.id } }">-->
          <!--          {{ row.articul }}-->
          <!--        </router-link>-->
          <router-link v-if="col.field === 'title'" :to="getProductLink(row)">
            {{ row.title }}
            <span
              v-if="row.is_configurable"
              class="products-page__badge products-page__badge--configurable"
              >Вариативный</span
            >
            <span
              v-if="row.parent_id"
              class="products-page__badge products-page__badge--variant"
              >Вариант</span
            >
          </router-link>
          <router-link
            v-if="col.field === 'categories'"
            :to="getProductLink(row)"
          >
            <span v-for="(item, i) in row.categories" :key="i">
              {{ item?.title }}
            </span>
          </router-link>
          <router-link
            v-if="col.field === 'default_price'"
            :to="getProductLink(row)"
            :class="{ rows__empty: !row.default_price_value }"
          >
            {{ row.default_price_value || "-" }}
          </router-link>
          <router-link
            v-if="col.field === 'remains'"
            :to="getProductLink(row)"
            :class="{ rows__empty: getRemain(row) === null }"
          >
            {{ getRemain(row) ?? "-" }}
          </router-link>
          <router-link
            v-if="col.field === 'fixed_stock'"
            :to="getProductLink(row)"
            :class="{ rows__empty: getFixedStock(row) === null }"
          >
            {{ getFixedStock(row) ?? "-" }}
          </router-link>
          <router-link
            v-if="col.field === 'fill_percent'"
            :to="getProductLink(row)"
            class="products-page__fill"
            :class="fillPercentClass(row.fill_percent)"
          >
            {{ row.fill_percent ?? 0 }}%
          </router-link>
          <div v-if="col.field === 'active'" style="padding-left: 30px">
            <FlagCheckboxComponent
              :model-value="row.status_id === 1"
              @update:model-value="togglePublish($event, row.status_id, row.id)"
            />
          </div>
        </template>
      </TableComponent>
      <ProductsPageAlert
        v-if="selectedProducts.length"
        :selected="selectedProducts"
        :filters="exportFilters"
      />
    </template>
    <template v-else>
      <h2>Выберите магазин</h2>
    </template>
  </main>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import ProductsPageAlert from "@/views/products/components/ProductsPageAlert.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
// import IconComponent from "@/components/IconComponent.vue";
import TableHeader from "@/views/products/components/TableHeader.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import PRODUCTS from "@/graphql/queries/admin_product_paginate.graphql";
import FILL_STATS from "@/graphql/queries/admin_products_fill_stats.graphql";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import PRODUCT_PUBLISH from "@/graphql/mutations/ProductPubish.graphql";
import PRODUCT_HIDE from "@/graphql/mutations/ProductHide.graphql";
import { useFiltersStore } from "@/stores/pageFilters.js";
import IconComponent from "@/components/IconComponent.vue";
import { useAuthStore } from "@/stores/auth.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { useTableColumnsConfigStore } from "@/stores/tableColumnsConfig.js";
import SHOPS from "@/graphql/pages/Products/shops_paginate.graphql";
import SHOP_CATEGORIES from "@/graphql/pages/Product/options.graphql";
import { NO_SHOP_ROUTE_PARAM } from "@/constants/productRoutes.js";
import { useDefaultStore } from "@/stores/default.js";
import { useMenuStore } from "@/stores/menu.js";
import PhotoPreviewModal from "@/components/modals/components/PhotoPreviewModal.vue";
import SetStockModeModal from "@/views/products/modals/SetStockModeModal.vue";
import BulkAssignSupplementsModal from "@/views/products/modals/BulkAssignSupplementsModal.vue";
import SetProductsCategoryModal from "@/views/products/modals/SetProductsCategoryModal.vue";

export default {
  name: "ProductsPage",
  inject: ["apollo"],

  async mounted() {
    if (!this.isAdmin && !this.networkCatalogEnabled) {
      // Для селлера автоматически устанавливаем его магазин.
      // При включённом сетевом каталоге магазин не подставляем — по
      // умолчанию показываем каталог по всей сети («Все магазины»).
      const userShop = useAuthStore().user?.shops?.[0]?.shop;
      if (userShop) {
        this.shop = userShop;
      }
    }
    await this.getFilters();
    this.loadCategories();
    await this.get();
  },

  data() {
    return {
      // Плейсхолдер позиционного сегмента shop_id в роуте карточки —
      // см. constants/productRoutes.js.
      NO_SHOP_ROUTE_PARAM,
      partnerId: null,
      shop: null,
      shops: [],
      categoriesTree: [],
      showFilters: false,
      shopTitle: null,
      optionsLoading: false,
      loading: false,
      activeRowIndex: null,
      shopTitleDebounce: null,
      searchDebounce: null,
      selectedProducts: [],
      productTypeFilter: null,
      fillStats: null,
      page: 1,
      first: 25,
      filters: {
        search: null,
      },
      productTypeOptions: [
        { id: "simple", title: "Простые" },
        { id: "configurable", title: "Вариативные" },
        { id: "children", title: "Варианты (дочерние)" },
      ],
      ordering: [
        {
          orderColumn: "created_at",
          orderBy: "DESC",
        },
      ],
      rows: {
        data: [],
        paginatorInfo: {
          total: 0,
        },
      },
      columns: [
        {
          title: "",
          field: "isActive",
          show: true,
          system: true,
          width: "50px",
        },
        {
          title: "",
          field: "select",
          show: true,
          system: true,
          width: "40px",
        },
        {
          title: "Фото",
          field: "photo",
          show: true,
          width: "70px",
        },
        {
          title: "Название",
          field: "title",
          selectable: true,
          show: true,
        },
        {
          title: "Категория",
          field: "categories",
          selectable: true,
          show: true,
        },
        {
          title: "Стоимость",
          field: "default_price",
          // sortable: true,
          selectable: true,
          show: true,
        },
        {
          title: "Остатки",
          field: "remains",
          selectable: true,
          show: true,
        },
        {
          title: "Фиксированные остатки",
          field: "fixed_stock",
          selectable: true,
          show: true,
        },
        {
          title: "Заполненность",
          field: "fill_percent",
          selectable: true,
          show: true,
        },
        {
          title: "Активировать",
          field: "active",
          selectable: true,
          show: true,
          // show: useAuthStore().user?.roles[0]?.name !== "admin", // тут нельзя вставлять computed свойства
        },
      ],
    };
  },
  methods: {
    /**
     * Значение колонки «Фиксированные остатки».
     *
     * Показываем только когда эффективный режим остатков реально fixed:
     * effective_* материализованы бэкендом с учётом наследования от категории,
     * собственный fixed_stock_quantity товара при этом может быть пустым.
     */
    getFixedStock(row) {
      if (row.effective_stock_mode !== "fixed") return null;
      const value = row.effective_fixed_stock;
      // Фиксированный лимит хранится в наименьшей единице семейства: г/мл/шт.
      return value == null ? null : `${value} ${this.smallestUnitLabel(row)}`;
    },
    getRemain(row) {
      // Сумму считает бэкенд (remains_sum с учётом выбранного магазина):
      // раньше сюда приезжали остатки по всем 1.5k магазинам сети на каждый
      // товар, и страница из 25 товаров весила сотни KB.
      const value = row.remains_sum;
      // Остаток — в базовой единице 1С (кг/л/шт): «12.806» без подписи читалось
      // как 12 806 штук.
      return value == null ? null : `${value} ${this.stockUnitLabel(row)}`;
    },
    // Семейство товара по базовой единице 1С (fallback — единица порции).
    unitFamily(row) {
      const unit = row.base_unit_type_id ?? row.info_weight_type_id;
      if ([2, 3].includes(unit)) return "mass";
      if ([4, 5].includes(unit)) return "volume";
      return "piece";
    },
    stockUnitLabel(row) {
      if (row.base_unit_type_id != null) {
        return { 1: "шт", 2: "г", 3: "кг", 4: "л", 5: "мл" }[row.base_unit_type_id] ?? "шт";
      }
      return { mass: "кг", volume: "л", piece: "шт" }[this.unitFamily(row)];
    },
    smallestUnitLabel(row) {
      return { mass: "г", volume: "мл", piece: "шт" }[this.unitFamily(row)];
    },
    fillPercentClass(percent) {
      const value = percent ?? 0;
      if (value < 50) return "products-page__fill--low";
      if (value < 80) return "products-page__fill--mid";
      return "products-page__fill--high";
    },
    /**
     * Фильтр по магазину для списка и сводки.
     *
     * В сетевом каталоге карточка товара принадлежит якорному магазину, а не
     * выбранному: shop_id (@eq по products.shop_id) отдал бы пустой список
     * для любого не-якорного магазина. Наличие товара в магазине определяет
     * available_in_shop_id (есть цена или остаток по этому магазину).
     */
    shopFilterVariables() {
      const shopId = this.shop?.id || undefined;

      return this.networkCatalogEnabled
        ? { available_in_shop_id: shopId }
        : { shop_id: shopId };
    },
    loadFillStats() {
      this.apollo()
        .admin.query({
          query: FILL_STATS,
          variables: {
            ...this.shopFilterVariables(),
            title: this.savedFilters.search,
            categories:
              this.savedFilters.categories?.map((c) => parseInt(c.id)) ||
              undefined,
            exclude_children: this.configurableEnabled ? true : undefined,
          },
        })
        .then(({ data }) => {
          this.fillStats = data.admin_products_fill_stats;
        })
        .catch(() => {});
    },
    getProductLink(row) {
      // shop_id — позиционный сегмент роута: без плейсхолдера ссылка без
      // магазина схлопнется в /admin/product/<id> и при F5 разберётся обратно
      // как shop_id (см. constants/productRoutes.js).
      const shopId = this.shop?.id ?? NO_SHOP_ROUTE_PARAM;

      if (row.is_configurable) {
        return {
          name: "configurable-product",
          params: { id: row.id, shop_id: shopId },
        };
      }
      return {
        name: "product",
        params: { id: row.id, shop_id: shopId },
      };
    },
    toggleProductSelect(productId) {
      const index = this.selectedProducts.indexOf(productId);
      if (index >= 0) {
        this.selectedProducts.splice(index, 1);
      } else {
        this.selectedProducts.push(productId);
      }
    },
    handleTypeFilterChange() {
      this.page = 1;
      this.get();
    },
    openBulkAssignSupplementsModal() {
      useDefaultStore().modals.push({
        component: BulkAssignSupplementsModal,
        options: {
          productIds: this.selectedProducts,
          shopId: this.shop?.id ? parseInt(this.shop.id) : undefined,
          callback: () => {
            this.selectedProducts = [];
            this.get();
          },
        },
      });
    },
    openSetStockModeModal() {
      useDefaultStore().modals.push({
        component: SetStockModeModal,
        options: {
          ids: this.selectedProducts,
          callback: () => {
            this.selectedProducts = [];
            this.get();
          },
        },
      });
    },
    openSetProductsCategoryModal() {
      useDefaultStore().modals.push({
        component: SetProductsCategoryModal,
        options: {
          ids: this.selectedProducts,
          categoriesTree: this.categoriesTree,
          callback: () => {
            this.selectedProducts = [];
            this.get();
          },
        },
      });
    },
    goToMerge() {
      this.$router.push({
        name: "configurable-product",
        params: { shop_id: this.shop?.id ?? NO_SHOP_ROUTE_PARAM },
        query: { children: this.selectedProducts.join(",") },
      });
    },
    blurShopTitle() {
      if (this.shopTitle && !this.shops.length) {
        this.shopTitle = null;
        this.getFilters();
      }
    },
    handleShopTitle($event) {
      if (this.shopTitleDebounce) {
        clearTimeout(this.shopTitleDebounce);
      }
      if (!this.optionsLoading) {
        this.shopTitle = $event;
        this.shopTitleDebounce = setTimeout(() => {
          this.getFilters();
        }, 500);
      }
    },
    updateShop() {
      useFiltersStore().set(this.$route.name, {
        partnerId: this.shop?.id || undefined,
      });
      this.loadCategories();
      this.get();
    },
    loadCategories() {
      if (!this.shop?.id) {
        this.categoriesTree = [];
        return;
      }
      this.apollo()
        .admin.query({
          query: SHOP_CATEGORIES,
          variables: { shop_id: parseInt(this.shop.id) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.categoriesTree = data.shop_categories || [];
        })
        .catch(() => {
          this.categoriesTree = [];
        });
    },
    togglePublish($event, row_status, row_id) {
      // 1 Активный
      // 3 Не активный
      let status = row_status !== 1;

      this.apollo()
        .admin.mutate({
          mutation: status ? PRODUCT_PUBLISH : PRODUCT_HIDE,
          variables: {
            id: row_id,
          },
        })
        .then(() => {
          this.get();
        })
        .catch(({ graphQLErrors }) => {
          graphQLErrors.forEach((err) => {
            if (err.extensions.category === "validation") {
              Object.entries(err.extensions.validation).forEach(
                ([key, value]) => {
                  let result = "";
                  value.forEach((key) => {
                    result = ` ${result} ${key}`;
                  });

                  alert(result);
                }
              );
            }
          });
        });
    },
    toggleShowFilters($event) {
      this.showFilters = $event;
    },
    handleSelected($event) {
      this.selected = [...$event];
    },
    handleFilters($event) {
      if (this.searchDebounce) {
        clearTimeout(this.searchDebounce);
      }
      this.searchDebounce = setTimeout(() => {
        useFiltersStore().set(this.$route.name, $event);
        this.page = 1;
        this.get();
      }, 500);
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
    handleOrdering($event) {
      this.ordering = $event;
    },
    openPhotoPreview(row) {
      useDefaultStore().modals.push({
        component: PhotoPreviewModal,
        options: {
          src: this.mediaEndpoint + row.img.path,
          title: row.title,
        },
      });
    },
    closeActions() {
      this.activeRowIndex = null;
    },
    async getFilters() {
      // Селектор магазина в сетевом каталоге скрыт (MID-234) — список
      // магазинов для его опций не нужен.
      if (this.networkCatalogEnabled) {
        return;
      }
      this.optionsLoading = true;
      await this.apollo()
        .admin.query({
          query: SHOPS,
          variables: {
            title: this.shopTitle,
            page: this.page,
          },
        })
        .then(({ data }) => {
          this.shops = data.shops_paginate.data;

          /** Заполняем селект **/
          // if (this.id) {
          //   this.shop = data.shops_paginate.data.find((item) => item.id === this.id);
          // }
          if (this.savedFilters.partnerId) {
            this.shop = data.shops_paginate.data.find(
              (item) => item.id === this.savedFilters.partnerId
            );
          }
        })
        .catch(() => {
          console.log("error");
        })
        .finally(() => {
          this.optionsLoading = false;
        });
    },
    async get() {
      this.loading = true;
      this._requestId = (this._requestId || 0) + 1;
      const requestId = this._requestId;
      // Фильтры экрана (магазин, поиск, категории, тип товара, заполненность,
      // selected_shop_id для цены/остатка строки) — общие с выгрузкой в XLSX
      const variables = {
        ...this.exportFilters,
        page: this.page,
        first: this.savedFilters.first,
      };
      this.loadFillStats();
      this.rows.data = [];
      await this.apollo()
        .admin.query({
          query: PRODUCTS,
          variables,
        })
        .then(({ data }) => {
          if (requestId !== this._requestId) return;
          if (data.admin_products_paginate) {
            this.rows.data = data.admin_products_paginate.data;
            this.rows.paginatorInfo =
              data.admin_products_paginate.paginatorInfo;
          }
        })
        .catch(() => {})
        .finally(() => {
          if (requestId === this._requestId) {
            this.loading = false;
          }
        });
    },
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Товары");
    },
    /**
     * Состав и порядок колонок — из «Настройки → Колонки таблиц»; локальный
     * флаг show остаётся за самой страницей (её собственные правила показа).
     */
    visibleColumns() {
      return useTableColumnsConfigStore()
        .applyTo("products", this.columns || [])
        .filter((c) => c.show || c.system);
    },
    /**
     * Фильтры экрана без пагинации: переменные запроса списка (get) и тело
     * запроса «Выгрузить все» — выгружается ровно тот набор, что показан
     * на экране, со всех страниц. Цена и остаток строки — по выбранному
     * магазину (selected_shop_id), без магазина бэкенд отдаёт сумму по сети.
     */
    exportFilters() {
      const categoryIds =
        this.savedFilters.categories?.map((c) => parseInt(c.id)) || undefined;
      const filters = {
        ...this.shopFilterVariables(),
        title: this.savedFilters.search || undefined,
        categories: categoryIds?.length ? categoryIds : undefined,
        ordering: this.ordering,
        has_img: this.savedFilters.has_img ?? undefined,
        fill_percent_lt: this.savedFilters.fill_percent_lt ?? undefined,
        fill_percent_gte: this.savedFilters.fill_percent_gte ?? undefined,
        is_new: this.savedFilters.is_new ?? undefined,
        is_hurry_up: this.savedFilters.is_hurry_up ?? undefined,
        selected_shop_id: this.shop?.id ? parseInt(this.shop.id) : undefined,
      };

      // Фильтры по типу товара (configurable products)
      if (this.configurableEnabled) {
        if (this.productTypeFilter?.id === "configurable") {
          filters.is_configurable = true;
          filters.exclude_children = true;
        } else if (this.productTypeFilter?.id === "simple") {
          filters.is_configurable = false;
          filters.exclude_children = true;
        } else if (this.productTypeFilter?.id === "children") {
          filters.only_children = true;
        } else {
          // По умолчанию скрываем дочерние товары
          filters.exclude_children = true;
        }
      }

      return filters;
    },
    id() {
      return this.$route.params.id
        ? parseInt(this.$route.params.id)
        : undefined;
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
      return useAuthStore().isGlobalUser;
    },
    configurableEnabled() {
      return useModuleConfigStore().isModuleEnabled("configurable_products");
    },
    supplementsEnabled() {
      return useModuleConfigStore().isModuleEnabled("supplements");
    },
    networkCatalogEnabled() {
      return useModuleConfigStore().isModuleEnabled("network_catalog");
    },
    bulkSelectEnabled() {
      // Колонка выбора (чекбоксы) нужна для базовых массовых операций над
      // товарами (перенос в категорию, режим остатков), которые не зависят
      // от модулей configurable_products/supplements — поэтому всегда включена.
      return true;
    },
    mediaEndpoint() {
      return useDefaultStore().MEDIA_ENDPOINT || "";
    },
  },
  components: {
    IconComponent,
    ProductsPageAlert,
    FlagCheckboxComponent,
    TableComponent,
    // IconComponent,
    TableHeader,
    SelectComponent,
    CheckboxComponent,
    PhotoPreviewModal,
    SetStockModeModal,
    BulkAssignSupplementsModal,
    SetProductsCategoryModal,
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
};
</script>

<style lang="stylus">
.products-page {
  display flex
  flex-direction column
  gap 16px
  padding 16px 32px
  height 100%
  width 100%
  padding-bottom 0
  background var(--gray-3)

  &--admin {
    height calc(100% - 100px)
  }

  &__table {
    max-height: calc(100vh - 220px)
    position static
  }

  &__table--filters-none {
    max-height calc(100vh - 172px)
  }

  &__table--is-admin {
    max-height: calc(100vh - 265px);
  }

  &__header {
    display flex
    justify-content space-between
    align-items center

    h2 {
      margin 0
      font-weight 500
      font-size 1.5em
    }

    button {
      height 48px
    }
  }

  &__header-actions {
    display flex
    gap 12px
    align-items center
  }

  &__type-filter {
    max-width 250px
  }

  &__checkbox-col {
    display flex
    align-items center
    justify-content center
  }

  &__photo-col {
    display flex
    align-items center
    justify-content center
  }

  &__photo-thumb {
    width 48px
    height 48px
    object-fit cover
    border-radius 6px
    cursor pointer
    transition opacity 0.15s
    display block

    &:hover {
      opacity 0.8
    }
  }

  &__photo-empty {
    color var(--gray)
    font-size 1rem
  }

  &__badge {
    display inline-block
    font-size 11px
    font-weight 600
    padding 2px 8px
    border-radius 10px
    margin-left 8px
    vertical-align middle
    line-height 16px

    &--configurable {
      background #E8F5E9
      color #2E7D32
    }

    &--variant {
      background #E3F2FD
      color #1565C0
    }
  }

  &__table-actions {
    position relative
  }

  &__table-actions-content {
    position absolute
    top 0
    left 0
    z-index 1
  }

  &__fill {
    font-weight 500

    &--low {
      color var(--red)
    }

    &--mid {
      color var(--orange)
    }

    &--high {
      color var(--green)
    }
  }

  &__fill-stats {
    padding 0 16px 12px
    font-size 0.813rem
    color var(--dark)
    background var(--white)
  }
}
</style>
