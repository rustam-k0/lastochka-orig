<template>
  <main class="page--main onec-shop-list">
    <div class="onec-shop-list__header">
      <h2>1С: Подключения магазинов</h2>
      <router-link :to="{ name: 'integrations' }" class="onec-shop-list__back">
        ← Назад к интеграциям
      </router-link>
    </div>

    <div v-if="loading" class="onec-shop-list__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="loadError" class="onec-shop-list__error">
      <p>{{ loadError }}</p>
      <button @click="loadData" class="onec-shop-list__btn onec-shop-list__btn--primary">Повторить</button>
    </div>

    <div v-else-if="shops.length === 0" class="onec-shop-list__empty">
      <p>Нет магазинов. Сначала создайте партнёра.</p>
    </div>

    <div v-else class="onec-shop-list__table-wrap">
      <table class="onec-shop-list__table">
        <thead>
          <tr>
            <th>Магазин</th>
            <th>Статус</th>
            <th>Последний обмен</th>
            <th>Действие</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in shops" :key="item.shop.id">
            <td class="onec-shop-list__shop-name">{{ item.shop.title }}</td>
            <td>
              <span :class="badgeClass(item)">{{ badgeText(item) }}</span>
            </td>
            <td class="onec-shop-list__date">{{ item.last_exchange_at ? formatDate(item.last_exchange_at) : '—' }}</td>
            <td>
              <button
                @click="openConfig(item)"
                class="onec-shop-list__btn"
                :class="item.has_config ? 'onec-shop-list__btn--outline' : 'onec-shop-list__btn--primary'"
              >
                {{ item.has_config ? 'Изменить' : 'Настроить' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <IntegrationConfigModal
      v-if="showModal"
      :descriptor="oneCDescriptor"
      :config="selectedConfig"
      :shop="selectedShop"
      @close="showModal = false"
      @save="onSaved"
    />
  </main>
</template>

<script>
import ONEC_SHOPS_SUMMARY from "@/graphql/queries/oneCShopsSummary.graphql";
import INTEGRATION_CONFIGS from "@/graphql/queries/integrationConfigs.graphql";
import INTEGRATION_REGISTRY from "@/graphql/queries/integrationRegistry.graphql";
import IntegrationConfigModal from "./components/IntegrationConfigModal.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";

export default {
  name: "OneCShopList",
  inject: ["apollo"],
  components: { IntegrationConfigModal, LoadingIndicator },

  data() {
    return {
      shops: [],
      loading: true,
      loadError: null,
      showModal: false,
      selectedShop: null,
      selectedConfig: null,
      oneCDescriptor: null,
    };
  },

  mounted() {
    this.loadData();
  },

  methods: {
    async loadData() {
      this.loading = true;
      this.loadError = null;

      try {
        const [summaryRes, registryRes] = await Promise.all([
          this.apollo().admin.query({
            query: ONEC_SHOPS_SUMMARY,
            fetchPolicy: "network-only",
          }),
          this.apollo().admin.query({
            query: INTEGRATION_REGISTRY,
            fetchPolicy: "cache-first",
          }),
        ]);

        this.shops = summaryRes.data.oneCShopsSummary || [];
        const registry = registryRes.data.integrationRegistry || [];
        this.oneCDescriptor = registry.find((d) => d.type === "1c");
      } catch (e) {
        this.loadError = e.message || "Ошибка загрузки";
      } finally {
        this.loading = false;
      }
    },

    async openConfig(item) {
      this.selectedShop = item.shop;

      if (item.has_config) {
        try {
          const { data } = await this.apollo().admin.query({
            query: INTEGRATION_CONFIGS,
            variables: { shop_id: item.shop.id },
            fetchPolicy: "network-only",
          });
          const configs = data.integrationConfigs || [];
          this.selectedConfig = configs.find((c) => c.integration_type === "1c") || null;
        } catch (e) {
          this.selectedConfig = null;
        }
      } else {
        this.selectedConfig = null;
      }

      this.showModal = true;
    },

    onSaved() {
      this.showModal = false;
      this.loadData();
    },

    badgeClass(item) {
      if (item.has_errors) return "badge badge--error";
      if (item.is_active) return "badge badge--active";
      return "badge badge--inactive";
    },

    badgeText(item) {
      if (item.has_errors) return "Ошибка";
      if (item.is_active) return "Активно";
      return "Не настроено";
    },

    formatDate(dateString) {
      if (!dateString) return "";
      return new Date(dateString).toLocaleString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    },
  },
};
</script>

<style lang="stylus">
.onec-shop-list
  &__header
    display flex
    flex-direction column
    gap 8px
    margin-bottom 24px

    h2
      font-size 1.5rem
      font-weight 600
      color var(--dark)

  &__back
    font-size 0.875rem
    color var(--blue-dark)
    text-decoration none
    transition color var(--transition)

    &:hover
      color var(--blue-hover)

  &__loading
    display flex
    justify-content center
    padding 48px

  &__error
    display flex
    flex-direction column
    align-items center
    gap 16px
    padding 48px
    background var(--white)
    border-radius 12px
    border 1px solid var(--border-color)
    text-align center

    p
      font-size 0.875rem
      color var(--red)

  &__empty
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--border-color)
    text-align center

    p
      font-size 0.875rem
      color var(--dark-light)

  &__table-wrap
    background var(--white)
    border-radius 12px
    border 1px solid var(--border-color)
    overflow hidden

  &__table
    width 100%
    border-collapse collapse

    thead
      tr
        background var(--neutral-50)
        border-bottom 1px solid var(--border-color)

      th
        padding 12px 20px
        font-size 0.75rem
        font-weight 600
        color var(--body-muted)
        text-transform uppercase
        letter-spacing 0.5px
        text-align left

    tbody
      tr
        border-bottom 1px solid var(--border-color)
        transition background-color var(--transition)

        &:last-child
          border-bottom none

        &:hover
          background-color var(--neutral-50)

      td
        padding 16px 20px
        font-size 0.875rem
        color var(--dark)
        vertical-align middle

  &__shop-name
    font-weight 500

  &__date
    color var(--gray-dark-2)

  .badge
    display inline-flex
    align-items center
    padding 4px 10px
    border-radius 6px
    font-size 0.75rem
    font-weight 500
    line-height 1
    white-space nowrap

    &--active
      background var(--green-50)
      color var(--green-700)

    &--inactive
      background var(--orange-50)
      color var(--orange-600)

    &--error
      background var(--red-50)
      color var(--red-dark)

  &__btn
    display inline-flex
    align-items center
    justify-content center
    padding 8px 20px
    border-radius 8px
    font-size 0.813rem
    font-weight 500
    cursor pointer
    border none
    transition all var(--transition)

    &--primary
      background var(--main)
      color var(--white)

      &:hover
        background var(--main-o9)

    &--outline
      background var(--white)
      color var(--dark)
      border 1px solid var(--border-color)

      &:hover
        background var(--neutral-50)
        border-color var(--gray-dark-3)
</style>
