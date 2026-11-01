<template>
  <div class="list-filters">
    <span class="list-filters__title">Фильтр</span>
    <div class="list-filters__body">
      <slot></slot>
      <button class="btn btn--medium btn--main" type="button" @click="$emit('submit')">
        <LoadingIndicator v-if="loading" title="Загрузка" />
        <span v-else>Применить</span>
      </button>
      <button class="btn btn--medium btn--main-outline" type="button" @click="$emit('reset')">
        Сбросить
      </button>
    </div>
  </div>
</template>
<script>
import LoadingIndicator from "./LoadingIndicator.vue";

export default {
  name: "MainFilters",
  props: {
    loading: Boolean,
  },
  components: { LoadingIndicator },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.list-filters {
  display grid
  grid-gap 5px

  &__title {
    font-size 0.875em
    display block
    line-height 1.5em
    color $gray3
  }

  &__body {
    display flex
    align-items stretch
    gap 10px

    .date-select {
      min-width: 251px;
    }

    .btn {
      height: 100%;
    }
  }
}
</style>
