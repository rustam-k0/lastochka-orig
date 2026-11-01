<template>
  <div class="statistic">
    <span class="statistic__title">{{ data.title }}</span>
    <span class="statistic__value">{{ data.value }}</span>
    <div class="statistic-range">
      <hr class="statistic-range__trait" />
      <div class="statistic-range__value">
        <span>{{ data.min }}</span>
        <span>{{ data.max }}</span>
      </div>
    </div>
    <div class="statistic__percent">
      <div class="dot dot--orange"></div>
      <span>{{ data.percent }}</span>
    </div>
  </div>
</template>

<script>
export default {
  name: "StatisticComponent",
  props: {
    data: {
      type: Object,
      default: () => {},
    },
  },
};
</script>

<style lang="stylus">
.statistic {
  display flex
  flex-direction column
  gap 16px
  padding: 16px
  justify-content center
  align-items center
  font-weight: 400;
  font-size: 0.875rem
  line-height: 140%;
  width 100%

  &__value {
    font-family var(--font-bold)
    font-weight: 500;
    font-size: 2rem
    line-height: 140%;
  }

  &__percent {
    display flex
    gap 4px
    align-items center
  }
}
.statistic-range {
  display flex
  flex-direction column
  gap 8px
  width 100%

  &__value {
    display flex
    justify-content space-between
    width 100%
  }

  &__trait {
    width 100%
    height: 1px;

    background: var(--gray-5);
  }
}
</style>
