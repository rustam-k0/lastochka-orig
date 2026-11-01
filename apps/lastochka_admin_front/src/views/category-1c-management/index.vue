<template>
  <main class="page--main category-1c-management">
    <div class="category-1c-management__header">
      <div class="category-1c-management__title">
        <h2>{{ pageTitle }}</h2>
        <p class="category-1c-management__subtitle">
          Управление витринными категориями и их связью с категориями из 1С
        </p>
      </div>
      <div class="category-1c-management__controls">
        <div v-if="isNetworkMode" class="category-1c-management__shop-select">
          <label>Магазин:</label>
          <span>Вся сеть</span>
        </div>
        <div v-else-if="isAdmin" class="category-1c-management__shop-select">
          <label>Магазин:</label>
          <ShopSelectComponent v-model="selectedShopId" @change="onShopChange" />
        </div>
        <NewOneCBanner :count="newCount" @show="onShowNewOnly" />
        <SyncBatchButton
          :shop-id="selectedShopId"
          @completed="onSyncCompleted"
        />
      </div>
    </div>

    <div v-if="successMessage" class="category-1c-management__toast category-1c-management__toast--ok">
      ✓ {{ successMessage }}
    </div>

    <div v-if="!selectedShopId" class="category-1c-management__empty">
      <p>Выберите магазин, чтобы начать работу с категориями.</p>
    </div>

    <main v-else class="category-1c-management__columns">
      <OneCColumn
        ref="oneCColumn"
        :shop-id="selectedShopId"
        :selected-ids="selectedOneCIds"
        :linked-filter="linkedFilter"
        :search="searchOneC"
        :show-only-new="showOnlyNew"
        :refresh-key="refreshKey"
        @update:selectedIds="selectedOneCIds = $event"
        @update:linkedFilter="onLinkedFilterChange"
        @update:search="searchOneC = $event"
        @linked-changed="onLinkedChanged"
        @reset-show-only-new="showOnlyNew = false"
        @unlink-showcase="onUnlink"
      />
      <ShowcaseColumn
        ref="showcaseColumn"
        :shop-id="selectedShopId"
        :search="searchShowcase"
        :selected-one-c-ids="selectedOneCIds"
        :selected-showcase-ids="selectedShowcaseIds"
        :refresh-key="refreshKey"
        @update:search="searchShowcase = $event"
        @drop-link="onDropLink"
        @toggle-showcase="onToggleShowcase"
      />
    </main>

    <BulkLinkBar
      v-if="selectedOneCIds.length > 0 || selectedShowcaseIds.length > 0"
      :one-c-count="selectedOneCIds.length"
      :showcase-count="selectedShowcaseIds.length"
      @reset="resetSelection"
      @link="onLinkBulkShowcase"
    />
  </main>
</template>

<script>
import OneCColumn from "./components/OneCColumn.vue";
import ShowcaseColumn from "./components/ShowcaseColumn.vue";
import SyncBatchButton from "./components/SyncBatchButton.vue";
import BulkLinkBar from "./components/BulkLinkBar.vue";
import NewOneCBanner from "./components/NewOneCBanner.vue";
import LINK_CATEGORIES_1C from "./graphql/linkCategories1c.graphql";
import UNLINK_CATEGORY_1C from "./graphql/unlinkCategory1c.graphql";
import gql from "graphql-tag";
import SHOPS_PAGINATE from "@/graphql/queries/shops_paginate.graphql";
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import { useAuthStore } from "@/stores/auth.js";
import { useMenuStore } from "@/stores/menu.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

const NEW_COUNT_QUERY = gql`
  query NewCount1cCategories($shop_id: Int!) {
    categories_1c_new_count(shop_id: $shop_id)
  }
`;

export default {
  name: "Category1cManagementPage",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    OneCColumn,
    ShowcaseColumn,
    SyncBatchButton,
    BulkLinkBar,
    NewOneCBanner,
  },
  data() {
    return {
      selectedShopId: null,
      shops: [],
      linkedFilter: "ALL",
      searchOneC: "",
      searchShowcase: "",
      selectedOneCIds: [],
      selectedShowcaseIds: [],
      showOnlyNew: false,
      newCount: 0,
      refreshKey: 0,
      successMessage: "",
      successTimer: null,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Категории товаров");
    },
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles?.[0]?.name;
    },
    isAdmin() {
      return this.userRole === "admin";
    },
    userShopId() {
      return (
        this.user?.shop_id ||
        this.user?.shop?.id ||
        this.user?.shops?.[0]?.shop_id ||
        this.user?.shops?.[0]?.shop?.id
      );
    },
    networkAnchorShopId() {
      const mod = useModuleConfigStore().config?.category_1c_network_mapping;
      if (!mod?.enabled) return null;
      const anchor = Number(mod.anchor_shop_id);
      if (!Number.isInteger(anchor) || anchor <= 0) {
        console.warn(
          "category_1c_network_mapping включён, но anchor_shop_id отсутствует или некорректен — экран работает в пер-магазинном режиме",
        );
        return null;
      }
      return anchor;
    },
    isNetworkMode() {
      return this.networkAnchorShopId !== null;
    },
  },
  watch: {
    selectedShopId(val) {
      if (val) {
        this.selectedOneCIds = [];
        this.selectedShowcaseIds = [];
        this.fetchNewCount();
      } else {
        this.newCount = 0;
      }
    },
  },
  mounted() {
    if (this.isNetworkMode) {
      this.selectedShopId = this.networkAnchorShopId;
    } else if (this.isAdmin) {
      this.fetchShops();
    } else if (this.userShopId) {
      this.selectedShopId = parseInt(this.userShopId);
    }
  },
  methods: {
    fetchShops() {
      this.apollo()
        .admin.query({
          query: SHOPS_PAGINATE,
          variables: { first: 100 },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.shops = data?.shops_paginate?.data || [];
          if (!this.selectedShopId && this.shops.length === 1) {
            this.selectedShopId = this.shops[0].id;
          }
        })
        .catch((error) => {
          console.error("Category1cManagement: fetchShops error", error);
        });
    },
    fetchNewCount() {
      if (!this.selectedShopId) return;
      this.apollo()
        .admin.query({
          query: NEW_COUNT_QUERY,
          variables: { shop_id: parseInt(this.selectedShopId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.newCount = data?.categories_1c_new_count ?? 0;
        })
        .catch((error) => {
          console.error("Category1cManagement: fetchNewCount error", error);
        });
    },
    onShopChange() {
      this.selectedOneCIds = [];
      this.selectedShowcaseIds = [];
      this.showOnlyNew = false;
    },
    onToggleShowcase(id) {
      const idx = this.selectedShowcaseIds.indexOf(id);
      if (idx >= 0) {
        this.selectedShowcaseIds.splice(idx, 1);
      } else {
        this.selectedShowcaseIds.push(id);
      }
    },
    resetSelection() {
      this.selectedOneCIds = [];
      this.selectedShowcaseIds = [];
    },
    onLinkBulkShowcase() {
      const oneCIds = this.selectedOneCIds.map((id) => parseInt(id));
      const showcaseIds = this.selectedShowcaseIds.map((id) => parseInt(id));
      if (!oneCIds.length || !showcaseIds.length) return;
      const ok = confirm(
        `Привязать ${oneCIds.length} ${oneCIds.length === 1 ? "категорию 1С" : "категорий 1С"} ` +
          `к ${showcaseIds.length} ${showcaseIds.length === 1 ? "витринной категории" : "витринным категориям"}?`
      );
      if (!ok) return;
      this.apollo()
        .admin.mutate({
          mutation: LINK_CATEGORIES_1C,
          variables: {
            category_1c_ids: oneCIds,
            showcase_category_ids: showcaseIds,
          },
        })
        .then(() => {
          this.resetSelection();
          this.refreshKey += 1;
          this.fetchNewCount();
          this.flashSuccess(
            `Привязано: ${oneCIds.length}×${showcaseIds.length} связей (категории 1С → витрины)`
          );
        })
        .catch((error) => {
          console.error("Category1cManagement: bulk link error", error);
          alert("Не удалось привязать: " + (error?.message || ""));
        });
    },
    onShowNewOnly() {
      this.linkedFilter = "UNLINKED";
      this.showOnlyNew = true;
    },
    onLinkedFilterChange(value) {
      this.linkedFilter = value;
      // Сбрасываем «только новые» при ручной смене фильтра, чтобы не путать пользователя.
      this.showOnlyNew = false;
    },
    onSyncCompleted() {
      this.fetchNewCount();
      this.refreshKey += 1;
    },
    onLinkedChanged() {
      this.fetchNewCount();
      this.refreshKey += 1;
    },
    onDropLink({ showcase_category_id, showcase_title, override_one_c_ids }) {
      const ids = (override_one_c_ids ?? this.selectedOneCIds).map((id) => parseInt(id));
      if (!ids.length) return;
      const target = showcase_title ? `«${showcase_title}»` : `id=${showcase_category_id}`;
      const word = ids.length === 1 ? "категорию 1С" : `${ids.length} категорий 1С`;
      const ok = confirm(`Привязать ${word} к витринной категории ${target}?`);
      if (!ok) return;
      this.apollo()
        .admin.mutate({
          mutation: LINK_CATEGORIES_1C,
          variables: {
            category_1c_ids: ids,
            showcase_category_ids: [parseInt(showcase_category_id)],
          },
        })
        .then(() => {
          this.selectedOneCIds = [];
          this.refreshKey += 1;
          this.fetchNewCount();
          this.flashSuccess(`Привязано ${ids.length} ${ids.length === 1 ? "категория 1С" : "категорий 1С"} к витринной ${target}`);
        })
        .catch((error) => {
          console.error("Category1cManagement: link error", error);
          alert("Не удалось привязать: " + (error?.message || ""));
        });
    },
    flashSuccess(message) {
      this.successMessage = message;
      clearTimeout(this.successTimer);
      this.successTimer = setTimeout(() => {
        this.successMessage = "";
      }, 4000);
    },
    async onUnlink({ category_1c_id, showcase_category_id }) {
      try {
        await this.apollo().admin.mutate({
          mutation: UNLINK_CATEGORY_1C,
          variables: {
            category_1c_id: parseInt(category_1c_id),
            showcase_category_id: parseInt(showcase_category_id),
          },
        });
        this.refreshKey += 1;
        this.fetchNewCount();
      } catch (error) {
        console.error("Category1cManagement: unlink error", error);
        alert(error?.message ?? "Ошибка при отвязке");
      }
    },
  },
  beforeUnmount() {
    clearTimeout(this.successTimer);
  },
};
</script>

<style lang="stylus">
.category-1c-management
  display flex
  flex-direction column
  gap 16px
  padding-bottom 80px

  &__header
    display flex
    justify-content space-between
    align-items flex-start
    gap 24px
    flex-wrap wrap

  &__title
    h2
      font-size 1.5rem
      font-weight 600
      color var(--dark, #222)

  &__subtitle
    color var(--dark-light, #666)
    font-size 14px
    margin-top 4px

  &__controls
    display flex
    gap 16px
    align-items center
    flex-wrap wrap

  &__shop-select
    display flex
    align-items center
    gap 8px

    label
      font-size 13px
      color var(--dark-light, #555)

    select
      padding 8px 32px 8px 12px
      border 1px solid var(--gray-dark, #ddd)
      border-radius 4px
      background var(--white, #fff)
      font-size 14px
      cursor pointer
      min-width 200px

      &:focus
        outline none
        border-color var(--pink, #ff8c00)

  &__toast
    padding 12px 16px
    border-radius 6px
    font-size 14px
    font-weight 500
    animation toast-fade-in 0.2s ease-out

    &--ok
      background var(--green-light, #d4edda)
      color var(--green-dark, #155724)
      border 1px solid var(--green, #28a745)

  @keyframes toast-fade-in
    from
      opacity 0
      transform translateY(-8px)
    to
      opacity 1
      transform translateY(0)

  &__empty
    background var(--white, #fff)
    border 1px dashed var(--gray-dark, #ddd)
    border-radius 8px
    padding 48px
    text-align center
    color var(--dark-light, #888)

  &__columns
    display grid
    grid-template-columns 1fr 1fr
    gap 24px
    flex 1

    +below(960px)
      grid-template-columns 1fr
</style>
