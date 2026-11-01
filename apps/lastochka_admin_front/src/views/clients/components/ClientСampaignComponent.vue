<template>
  <BaseWrapper>
    <template #title>Кaмпании</template>
    <div class="client-campaign">
      <div class="client-campaign__list">
        <div v-for="(item, i) in data" :key="i" class="client-campaign__item">
          <span>{{ item.title }}</span>
          <div @click="$emit('campaign-remove')" class="client-campaign__remove">
            <IconComponent name="close-fill" />
          </div>
        </div>
      </div>
    </div>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "ClientCampaignComponent",
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
.client-campaign {
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
    padding: 20px 16px
    display flex
    gap 8px
    align-items center

    & span {
      font-weight: 600;
      font-size: 0.875rem
      line-height: 20px
      color var(--dark)
    }
  }

  &__remove {
    margin-left auto
    cursor pointer
    display flex
    justify-content center
    align-items center

    & .icon {
      width 16px
      height 16px
    }
  }
}
</style>
