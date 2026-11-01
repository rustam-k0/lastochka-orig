<template>
  <div v-click-outside="close" class="drop">
    <a
      :class="{
        'drop__btn--active': showDrop,
      }"
      class="drop__btn"
      @click.prevent="toggle"
    >
      <span class="drop__title">{{ title }}</span>
      <DownIconSmall />
    </a>
    <div ref="container" :class="{ 'drop__container--active': showDrop }" class="drop__container">
      <slot />
    </div>
  </div>
</template>

<script>
import ClickOutside from "vue-click-outside";
import DownIconSmall from "components/svg/DownIconSmall.vue";

export default {
  name: "DropdownComponent",
  props: {
    title: {
      type: String,
    },
  },
  data() {
    return {
      showDrop: false,
    };
  },
  methods: {
    close() {
      this.showDrop = false;
    },
    toggle() {
      this.showDrop = !this.showDrop;
      this.$emit("showDrop");
    },
  },
  components: { DownIconSmall },
  directives: {
    ClickOutside,
  },
};
</script>

<style lang="stylus">
@import "~@/styles/global/colors.styl"
@import "~@/styles/mixins/svg.styl"
.drop {
  box-sizing: border-box;
  display grid
  grid-gap 10px
  position relative

  &:hover &__btn {
    border-color: $main_color;
  }

  &__title {
    font-weight: normal;
    font-size: 1em;
    line-height: 19px;
    color: $black;
  }

  &__btn {
    display flex
    align-items center
    gap 5px
    padding 10px
    border-radius: 5px;
    border: 1px solid $gray1;
    background: $white;

    &:hover {
      border-color $main_color
    }

    &--active .icon {
      transform rotate(180deg)
    }

    .icon {
      width 24px
      height 24px
      transition .2s

      svg {
        width 100%
        height 100%
        svg($dark)
      }
    }
  }

  &__container {
    absolute bottom
    transform translateY(100%)
    visibility hidden
    opacity 0
    z-index 3
    transition .2s

    &--active {
      transform translateY(calc(100% + 10px))
      visibility visible
      opacity 1
    }
  }
}
</style>
