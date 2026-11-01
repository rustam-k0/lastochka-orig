<template>
  <div class="from-to-select">
    <div class="from-to-select__title">
      {{ title }}
    </div>
    <div class="from-to-select__body">
      <span>от</span>
      <SelectComponent @update:model-value="$emit('update:from', $event)" placeholder="от °C" />
      <span>до</span>
      <SelectComponent @update:model-value="$emit('update:before', $event)" placeholder="до °C" />
    </div>
  </div>
</template>

<script>
import SelectComponent from "@/components/inputs/select/input.vue";
export default {
  name: "fromToSelect",
  components: { SelectComponent },
  emits: ["update:from", "update:before"],
  props: {
    title: [String],
    from: [String, Number],
    before: [String, Number],
  },
};
</script>

<style lang="stylus">
.from-to-select {
  display flex
  flex-direction column
  gap 5px

  &__title {
    font-weight: 400;
    font-size: 0.813rem
    line-height: 18px;
    color: var(--black-2)
  }

  &__body {
    display flex
    align-items center
    gap 16px

    & span {
      font-weight: 400;
      font-size: 0.813rem
      line-height: 20px;
      color: var(--dark)
    }

    .select {
      width 100%
    }
  }
}
</style>
