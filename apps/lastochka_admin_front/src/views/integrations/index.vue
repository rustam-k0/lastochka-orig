<template>
  <main class="page--main integrations-page">
    <div class="integrations-page__header">
      <div class="integrations-page__header-left">
        <router-link :to="{ name: 'settings' }" class="integrations-page__back">
          <IconComponent name="arrow-left-s-line" />
        </router-link>
        <h2>Настройки интеграций</h2>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="integrations-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!registry.length" class="integrations-page__empty">
      <IconComponent name="settings-3-line" />
      <h3>Нет доступных интеграций</h3>
      <p>Интеграции появятся после настройки бэкенда</p>
    </div>

    <!-- Карточки интеграций -->
    <div v-else class="integrations-page__grid">
      <IntegrationCard
        v-for="item in registry"
        :key="item.type"
        :descriptor="item"
        :config="getConfig(item.type)"
        @configure="openModal(item)"
      />
    </div>

    <!-- Модалка настройки -->
    <IntegrationConfigModal
      v-if="editingType"
      :descriptor="editingDescriptor"
      :config="editingConfig"
      @save="onSave"
      @close="closeModal"
    />
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import IntegrationCard from "./components/IntegrationCard.vue";
import IntegrationConfigModal from "./components/IntegrationConfigModal.vue";
import INTEGRATION_REGISTRY from "@/graphql/queries/integrationRegistry.graphql";
import INTEGRATION_CONFIGS from "@/graphql/queries/integrationConfigs.graphql";

export default {
  name: "IntegrationsPage",
  inject: ["apollo"],
  components: {
    IconComponent,
    LoadingIndicator,
    IntegrationCard,
    IntegrationConfigModal,
  },
  data() {
    return {
      registry: [],
      configs: [],
      loading: true,
      editingType: null,
    };
  },
  computed: {
    editingDescriptor() {
      if (!this.editingType) return null;
      return this.registry.find((r) => r.type === this.editingType) || null;
    },
    editingConfig() {
      if (!this.editingType) return null;
      return this.getConfig(this.editingType);
    },
  },
  mounted() {
    this.loadData();
  },
  methods: {
    loadData() {
      this.loading = true;

      const registryPromise = this.apollo()
        .admin.query({
          query: INTEGRATION_REGISTRY,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.registry = data.integrationRegistry || [];
        });

      const configsPromise = this.apollo()
        .admin.query({
          query: INTEGRATION_CONFIGS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.configs = data.integrationConfigs || [];
        });

      Promise.all([registryPromise, configsPromise])
        .catch((error) => {
          console.error("Error loading integrations:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    getConfig(type) {
      return this.configs.find((c) => c.integration_type === type) || null;
    },
    openModal(descriptor) {
      this.editingType = descriptor.type;
    },
    closeModal() {
      this.editingType = null;
    },
    onSave(savedConfig) {
      const index = this.configs.findIndex(
        (c) => c.integration_type === savedConfig.integration_type
      );
      if (index >= 0) {
        this.configs[index] = savedConfig;
      } else {
        this.configs.push(savedConfig);
      }
      this.editingType = null;
    },
  },
};
</script>

<style lang="stylus">
.integrations-page
  &__header
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px

    h2
      font-size 1.5rem
      font-weight 600
      color var(--dark)

  &__header-left
    display flex
    align-items center
    gap 12px

  &__back
    display flex
    align-items center
    justify-content center
    width 36px
    height 36px
    border-radius 8px
    border 1px solid var(--gray-dark)
    background var(--white)
    transition background 0.2s

    &:hover
      background var(--gray-3)

    .icon
      width 20px
      height 20px

      svg path
        fill var(--dark)

  &__loading
    display flex
    justify-content center
    padding 48px

  &__empty
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center

    .icon
      width 64px
      height 64px
      margin-bottom 16px

      svg path
        fill var(--gray-light)

    h3
      font-size 1.25rem
      color var(--dark)
      margin-bottom 8px

    p
      font-size 0.875rem
      color var(--dark-light)

  &__grid
    display grid
    grid-template-columns repeat(3, 1fr)
    gap 20px

    +below(1400px)
      grid-template-columns repeat(2, 1fr)

    +below(800px)
      grid-template-columns 1fr
</style>
