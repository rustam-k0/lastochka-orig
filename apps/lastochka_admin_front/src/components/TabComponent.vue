<template>
  <div class="select-tabs">
    <div
      v-for="(item, i) in tabs"
      :key="i"
      class="select-tabs__item"
      :class="{ 'select-tabs__item--active': activeTab === item.type_id }"
      @click="changeActiveTab(item.type_id)"
    >
      {{ item.title }}
    </div>
  </div>
</template>

<script>
export default {
  name: "TabComponent",
  props: {
    activeTab: {
      type: Number,
      default: 0,
    },
    tabs: {
      type: Array,
      default: () => [],
    },
  },
  // data() {
  //   return {
  //     activeTab: 0,
  //   };
  // },
  methods: {
    changeActiveTab(type_id) {
      this.$emit("change", type_id);
    },
  },
};
</script>

<style lang="stylus">
.select-tabs {
  display flex
  gap 1px
  background: var(--white);
  border-radius: 6px;
  border 1px solid var(--gray-dark)
  width: fit-content;
  overflow hidden


  &__item {
    padding 10px 46px
    font-weight: 500;
    font-size: 0.75em;
    line-height: 16px;
    color: var(--dark);
    transition var(--transition)
    cursor pointer

    &:hover {
      background: var(--neutral-800);
      color var(--white)
    }

    &--active {
      background: var(--neutral-800);
      color var(--white)
    }

  }
}
</style>
