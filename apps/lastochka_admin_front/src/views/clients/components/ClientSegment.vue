<template>
  <BaseWrapper>
    <template #title>Сегменты</template>
    <div class="client-segment">
      <div class="client-segment__list">
        <div v-for="(item, i) in data" :key="i" class="client-segment__item">
          <span>{{ item.title }}</span>
        </div>
      </div>
    </div>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";

export default {
  name: "ClientSegment",
  components: { BaseWrapper },
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
.client-segment {
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

    & span {
      font-weight: 400;
      font-size: 0.875rem
      line-height: 20px
      color var(--black-2)
    }
  }
}
</style>
