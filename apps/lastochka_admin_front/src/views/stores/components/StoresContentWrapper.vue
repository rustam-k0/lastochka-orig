<template>
  <div class="stores-content-wrapper">
    <slot name="main"></slot>
    <slot name="aside"></slot>
  </div>
</template>

<script>
export default {
  name: "StoresContentWrapper",
};
</script>

<style lang="stylus">
.stores-content-wrapper {
  display grid
  grid-template-columns 1fr 515px
  grid-gap 16px
}
</style>
