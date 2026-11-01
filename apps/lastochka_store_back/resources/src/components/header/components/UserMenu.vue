<template>
  <div :class="{ 'user-menu--active': active, 'user-menu--static': statical }" class="user-menu">
    <router-link :to="{ name: 'password' }">
      <router-link :to="{ name: 'profile' }">
        <a class="user-menu__link">
          <span>Личный кабинет</span>
          <Icon category="user" name="account-box-line" />
        </a>
      </router-link>
      <a class="user-menu__link">
        <span>Сменить пароль</span>
        <Icon category="others" name="key-line" />
      </a>
    </router-link>
    <a class="user-menu__link" @click.prevent="logout">
      <span>Выйти</span>
      <Icon category="system" name="logout-box-r-line" />
    </a>
  </div>
</template>
<script>
import Icon from "@/components/IconComponent.vue";

export default {
  name: "UserMenuComponent",
  components: { Icon },
  data() {
    return {
      user: window.user,
    };
  },
  props: {
    statical: {
      type: Boolean,
    },
    active: {
      type: Boolean,
    },
  },
  methods: {
    logout() {
      this.$cookies.remove("admin-token");
      location.href = this.$router.resolve({
        name: "login",
      }).href;
    },
  },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
@import "~@/styles/mixins/svg.styl"
.user-menu {
  background: $white;
  box-shadow: 0 30px 60px rgba($main_color, 20%);
  absolute right bottom
  display: flex;
  flex-direction: column;
  min-width 200px
  z-index 3
  opacity 0
  visibility hidden
  transition .2s
  transform translateY(calc(100% - 20px))

  &--active {
    opacity 1
    visibility visible
    transform translateY(100%)
  }

  &--static {
    transform none
    relative left top right bottom
    width 100%
    border-bottom-left-radius 0
    border-bottom-right-radius 0
  }

  &__link {
    height: auto;
    opacity: 1;
    padding: 10px 15px;
    background: $white;
    transition .2s
    flex-shrink 0
    box-sizing border-box
    display flex
    align-items center
    justify-content space-between
    grid-gap 5px
    cursor pointer
    font-weight: normal;
    font-size: 16px;
    line-height: 19px;
    color: $dark;
    text-align: left;

    .icon {
      width 24px
      height 24px

      svg {
        width 100%
        height 100%
        svg($dark)
      }
    }

    &:not(:first-child) {
      border-top: 1px solid $gray1;
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
