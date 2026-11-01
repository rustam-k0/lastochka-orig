<template>
  <div class="page-header">
    <div @click="$router.go(-1)" class="btn btn--white btn--sm">
      <IconComponent name="arrow-left-s-line" />
    </div>
    <h2>{{ title }}</h2>
    <div class="page-header__button">
      <slot></slot>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
export default {
  name: "PageHeaderComponent",
  components: { IconComponent },
  props: {
    title: {
      type: String,
    },
  },
};
</script>

<style lang="stylus">
.page-header {
  display flex
  gap 16px
  align-items center

  &__button {
    margin-left auto
    display flex
    gap 16px
  }
}
</style>
