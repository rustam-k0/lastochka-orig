<template>
  <div class="integration-card" :class="statusClass">
    <div class="integration-card__header">
      <h3 class="integration-card__title">{{ descriptor.label }}</h3>
      <span class="integration-card__badge" :class="badgeClass">
        {{ badgeText }}
      </span>
    </div>

    <div class="integration-card__body">
      <div class="integration-card__info" v-if="config && config.driver">
        <span class="integration-card__driver">{{ driverLabel }}</span>
      </div>
      <div class="integration-card__info" v-if="config && config.tested_at">
        <span class="integration-card__tested">
          Последний тест: {{ formatDate(config.tested_at) }}
        </span>
      </div>
      <div class="integration-card__error" v-if="config && config.test_error">
        {{ config.test_error }}
      </div>
    </div>

    <div class="integration-card__body-summary" v-if="onecSummary">
      <span class="integration-card__summary">
        Настроено {{ onecSummary.configured }} из {{ onecSummary.total }}
      </span>
    </div>

    <div class="integration-card__footer">
      <router-link
        v-if="descriptor.type === '1c'"
        :to="{ name: 'integrations-1c' }"
        class="btn btn--md"
      >
        {{ config ? 'Изменить' : 'Настроить' }}
      </router-link>
      <button v-else class="btn btn--md" @click="$emit('configure')">
        {{ config ? 'Изменить' : 'Настроить' }}
      </button>
    </div>
  </div>
</template>

<script>
export default {
  name: "IntegrationCard",
  emits: ["configure"],
  props: {
    descriptor: {
      type: Object,
      required: true,
    },
    config: {
      type: Object,
      default: null,
    },
    onecSummary: {
      type: Object,
      default: null,
    },
  },
  computed: {
    isActive() {
      return this.config && this.config.is_active;
    },
    hasError() {
      return this.config && this.config.test_error;
    },
    statusClass() {
      if (this.hasError) return "integration-card--error";
      if (this.isActive) return "integration-card--active";
      return "";
    },
    badgeClass() {
      if (this.hasError) return "integration-card__badge--error";
      if (this.isActive) return "integration-card__badge--active";
      return "integration-card__badge--inactive";
    },
    badgeText() {
      if (this.hasError) return "Ошибка";
      if (this.isActive) return "Активно";
      return "Не настроено";
    },
    driverLabel() {
      if (!this.config || !this.config.driver) return "";
      const driver = this.descriptor.drivers.find(
        (d) => d.name === this.config.driver
      );
      return driver ? driver.label : this.config.driver;
    },
  },
  methods: {
    formatDate(dateString) {
      if (!dateString) return "";
      const date = new Date(dateString);
      return date.toLocaleString("ru-RU", {
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
.integration-card
  background var(--white)
  border 1px solid var(--gray-dark)
  border-radius 12px
  padding 24px
  display flex
  flex-direction column
  gap 16px
  transition border-color 0.2s

  &--active
    border-color var(--green)

  &--error
    border-color var(--red)

  &__header
    display flex
    justify-content space-between
    align-items flex-start
    gap 12px

  &__title
    font-size 1rem
    font-weight 600
    color var(--dark)
    margin 0

  &__badge
    flex-shrink 0
    padding 4px 10px
    border-radius 100px
    font-size 0.75rem
    font-weight 500
    line-height 16px
    white-space nowrap

    &--active
      background var(--green)
      color var(--white)

    &--inactive
      background var(--gray-3)
      color var(--dark)

    &--error
      background var(--red)
      color var(--white)

  &__body
    display flex
    flex-direction column
    gap 8px
    flex 1

  &__driver
    font-size 0.875rem
    color var(--dark)
    font-weight 500

  &__tested
    font-size 0.75rem
    color var(--dark-light, #888)

  &__error
    font-size 0.75rem
    color var(--red)
    line-height 1.4

  &__footer
    display flex
    justify-content flex-start
    padding-top 8px
</style>
