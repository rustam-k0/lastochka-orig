<template>
  <span
    class="indicator"
    :class="{
      'indicator--collect': code === 1,
      'indicator--assembly': code === 2,
      'indicator--complete': code === 3,
      'indicator--cancel': code === 4,
      'indicator--paid': code === 5,
      'indicator--wait': code === 6,
      'indicator--cancel-pay': code === 7,
    }"
  >
    <template v-if="code === 1">
      <IconComponent name="archive-line" />
      Собранные
    </template>
    <template v-if="code === 2">
      <IconComponent name="loader-2-line" />
      В сборке
    </template>
    <template v-if="code === 3">
      <IconComponent name="checkbox-circle-line" />
      Выполнен
    </template>
    <template v-if="code === 4">
      <IconComponent name="indeterminate-circle-line" />
      Отмена
    </template>
    <template v-if="code === 5">
      <IconComponent name="dot" />
      Оплачен
    </template>
    <template v-if="code === 6">
      <IconComponent name="dot" />
      Ожидает
    </template>
    <template v-if="code === 7">
      <IconComponent name="dot" />
      Отмена
    </template>
  </span>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
export default {
  name: "IndicatorComponent",
  components: { IconComponent },
  props: {
    code: {
      type: Number,
      default: 1,
    },
  },
};
</script>

<style lang="stylus">
.indicator{
  display flex
  justify-content center
  align-items center
  padding 4px
  border-radius: 6px;
  gap 5px
  font-weight: 500;
  font-size: 0.857em;
  line-height: 16px;
  svg {
    max-width 16px
    max-height: 16px;
  }

  &--collect {
    background-color var(--neutral-50)
    color var(--neutral-600)

    svg path {
      fill var(--neutral-600)
    }
  }

  &--assembly {
    background-color transparent
    color var(--neutral-600)

    svg path {
      fill var(--neutral-600)
    }
  }
  &--complete {
    background-color var(--green-50)
    color var(--main)
    svg path {
      fill var(--main)
    }
  }
  &--cancel {
    background-color var(--red-50)
    color var(--red-dark)

    svg path{
      fill var(--red-dark)
    }
  }
  &--paid {
    background-color var(--green-50)
    color var(--main)
    svg path {
      fill var(--main)
    }
  }

  &--wait {
    background-color var(--orange-50)
    color var(--orange-600)

    svg rect{
      fill var(--orange-600)
    }
  }
  &--cancel-pay {
    background-color var(--red-50)
    color var(--red-dark)

    svg rect{
      fill var(--red-dark)
    }
  }

}
</style>
