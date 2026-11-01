<template>
  <div class="staff-input" :class="{ 'staff-input__gray': fill }">
    <span class="staff-input__title">{{ data.title }}</span>
    <span class="staff-input__subtitle">{{ data.subtitle }}</span>
  </div>
</template>

<script>
export default {
  name: "StaffInputComponent",
  props: {
    data: {
      type: Object,
      default: () => {},
    },
    fill: {
      type: Boolean,
      default: false,
    },
  },
};
</script>

<style lang="stylus">

</style>
