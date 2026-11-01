<template>
  <div v-if="list && list.length" class="drop-menu">
    <router-link
      v-for="(link, i) in list.filter((l) => canShowLink(l.allowedRoles))"
      :key="i"
      :to="link.to"
      class="drop-menu__link"
    >
      <span>{{ link.title }}</span>
      <Icon v-if="link.list && link.list.length" category="system" name="arrow-right-s-line" />
      <DropMenu :list="link.list" />
    </router-link>
  </div>
</template>

<script>
import Icon from "@/components/IconComponent.vue";

export default {
  name: "DropMenu",
  props: {
    list: Array,
  },
  methods: {
    canShowLink(roles) {
      if (window.user && window.user.roles) {
        return roles.some((r) => r === "*" || window.user.roles.map((r) => r.name).includes(r));
      }
      return false;
    },
  },
  components: { Icon },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.drop-menu {
  background: $white;
  box-shadow: 0 30px 60px rgba($main_color, 20%);
  absolute left bottom
  width 250px
  z-index 4
  opacity 0
  visibility hidden
  transition .2s
  transform translateY(calc(100% - 20px))
  box-sizing border-box
  display: flex;
  flex-direction: column;

  &__link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: auto;
    opacity: 1;
    color: $dark;
    padding: 10px 15px;
    background: $white;
    transition .2s
    flex-shrink 0
    box-sizing border-box

    &:not(:first-child) {
      border-top: 1px solid $gray1;
    }

    .icon {
      width 18px
      height 18px;
    }

    & > .drop-menu {
      absolute right top
      transform translateX(calc(100% - 20px))
    }

    &:hover {
      background $gray

      & > .drop-menu {
        opacity 1
        visibility visible
        transform translateX(100%)
      }
    }
  }
}
</style>
