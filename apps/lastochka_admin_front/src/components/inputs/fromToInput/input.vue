<template>
  <div class="input-to-select">
    <div class="input-to-select__title">
      {{ title }}
    </div>
    <div class="input-to-select__body">
      <span>от</span>
      <InputComponent :mask="mask" @update:model-value="$emit('update:from', $event)" />
      <span>до</span>
      <InputComponent :mask="mask" @update:model-value="$emit('update:before', $event)" />
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
export default {
  name: "fromToInput",
  components: { InputComponent },
  emits: ["update:from", "update:before"],
  props: {
    title: [String],
    from: [String, Number],
    before: [String, Number],
    mask: String,
  },
  methods: {},
};
</script>

<style lang="stylus">
.input-to-select {
  display flex
  flex-direction column
  gap 5px

  &__title {
    font-weight: 400;
    font-size: 0.813rem
    line-height: 16px;
    color: var(--dark)
  }

  &__body {
    display flex
    align-items center
    gap 16px

    & span {
      font-weight: 400;
      font-size: 0.875
      color: var(--dark)
      line-height: 16px;
    }

    .select {
      width 100%
    }
  }
}
</style>
