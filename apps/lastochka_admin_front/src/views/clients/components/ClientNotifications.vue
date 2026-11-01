<template>
  <BaseWrapper>
    <template #title>Уведомления</template>
    <div class="client-notifications">
      <div class="client-notifications__list">
        <div class="client-notifications__item">
          <IconComponent name="smartphone-line" />
          <router-link to="">+7 (495) 777-77-77</router-link>
        </div>
        <div class="client-notifications__item">
          <IconComponent name="mail-line" />
          <router-link to="">afton_jenkins@gmail.com</router-link>
        </div>
      </div>
    </div>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "ClientNotifications",
  components: { IconComponent, BaseWrapper },
  props: {
    data: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {};
  },
};
</script>

<style lang="stylus">
.client-notifications {
  display flex
  flex-direction column
  gap 16px

  &__list {
    display flex
    flex-direction column
    gap 4px
  }

  &__item {
    background var(--neutral-50)
    border-radius: 16px;
    padding: 16px
    display flex
    gap 8px
    align-items center

    & a {
      font-weight: 400;
      font-size: 0.875rem
      line-height: 20px;
      text-decoration-line: underline;
      color: var(--black-2)
    }

    & .icon {
      width 16px
      height 16px
    }
  }
}
</style>
