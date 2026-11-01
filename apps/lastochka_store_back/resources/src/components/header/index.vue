<template>
  <header class="header">
    <div class="container header__container">
      <router-link :to="{ name: 'home' }" class="header__logo">
        <Icon collection="default" name="logo" />
      </router-link>
      <nav class="header__nav">
        <a class="header__nav-link" @click="showAddModal">
          <Icon category="system" name="add-line" />
          <span>Добавить контент</span>
        </a>
        <router-link :to="{ name: 'course-list' }" v-if="canShowLink(['*'])" class="header__nav-link">
          <Icon category="system" name="alarm-line" />
          <span>Список курсов</span>
          <DropMenu :list="list0" />
        </router-link>
        <router-link :to="{ name: 'test' }" v-if="canShowLink(['*'])" class="header__nav-link">
          <Icon category="system" name="alarm-line" />
          <span>Тест</span>
        </router-link>
      </nav>
      <button class="header__mobile-btn" type="button">
        <Icon category="system" name="menu-line" />
      </button>
      <button class="header__user" type="button" v-if="user">
        <img
          class="header__user-img"
          :alt="`${user.name} ${user.surname}`"
          v-if="user.head_img"
          :src="user.head_img | image"
        />

        <span class="header__user-img" :alt="`${user.name} ${user.surname}`" v-else>
          {{ (user.name || "").substr(0, 1) }}
          {{ (user.surname || "").substr(0, 1) }}
        </span>
        <span>{{ user.name }} {{ user.surname }}</span>

        <Icon category="system" name="arrow-down-s-line" />
        <UserMenuComponent />
      </button>
    </div>
  </header>
</template>

<script>
/**
 * НЕ РЕДАКТИРУЙТЕ СОДЕРЖИМОЕ ЭТОГО ФАЙЛА
 * Если задача вышла за рамки генератора,
 * то отредактируйте шаблон в папке generator/templates/frontend/header.blade.php
 */
import Icon from "@/components/IconComponent.vue";
import UserMenuComponent from "./components/UserMenu.vue";
import AddModal from "../modals/components/AddModal.vue";
import DropMenu from "./components/DropMenu.vue";
export default {
  name: "HeaderComponent",
  data() {
    return {
      user: window.user,
      list0: [
        { title: "Вложенный 1", to: { name: "course-list" }, allowedRoles: ["*"] },
        {
          title: "Вложенный 2",
          to: { name: "course-list" },
          allowedRoles: ["*"],
          list: [{ title: "Под под под под раздел", to: { name: "course-list" }, allowedRoles: ["*"] }],
        },
      ],
    };
  },
  methods: {
    canShowLink(roles) {
      if (window.user && window.user.roles) {
        return roles.some((r) => r === "*" || window.user.roles.map((r) => r.name).includes(r));
      }
      return false;
    },
    showAddModal() {
      this.$store.state._modals.push({
        component: AddModal,
      });
    },
  },
  components: {
    UserMenuComponent,
    Icon,
    DropMenu,
  },
};
</script>

<style lang="stylus">
@import  '~@/styles/laravel-mix-fix.styl';
@import  '~@/styles/mixins/svg.styl';

.header {
  width: 100%;
  background: $main_color;
  height: 60px;

  &__container {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    height: 100%;
    gap: 8px;
  }

  &__logo {
    display: inline-flex;
    align-items: center;
    flex-direction: row;

    .icon {
      width: 60px;
      height: 60px;
      padding: 5px;
      margin-left: -5px;

      svg {
        width: 100%;
        height: 100%;
      }
    }
  }

  &__mobile-btn {
    background: none;
    border: none;
    padding: 20px 5px;
    display: none;
    height: 100%;
    color: $white;
    margin-left: auto;
    cursor: pointer;

    +below(980px) {
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .icon {
      width: 20px;
      height: 20px;

      svg {
        width: 100%;
        height: 100%;
        svg($white);
      }
    }
  }

  &__user {
    background: none;
    border: none;
    padding: 0 5px;
    height: 100%;
    color: $white;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    position: relative;

    +above(981px) {
      margin-left: auto;
    }

    &:hover > .user-menu {
      opacity: 1;
      visibility: visible;
      transform: translateY(100%);
    }

    &-img {
      width: 32px;
      height: 32px;
      object-fit: cover;
      object-position: center;
      border-radius: 100%;
      background: $white;
      color $white
      font-weight bold
      text-transform: uppercase;
      line-height 30px !important
    }

    > span {
      font-weight: normal;
      font-size: 1em;
      line-height: 19px;

      +below(980px) {
        display: none;
      }
    }

    > .icon {
      width: 20px;
      height: 20px;
      transition: 0.2s;

      svg {
        width: 100%;
        height: 100%;
        svg($white);
      }
    }
  }

  &__nav {
    display: flex;
    align-items: center;
    flex: 1;
    padding: 0 25px;
    height: 100%;

    &-link {
      padding: 0 15px;
      height: 100%;
      display: flex;
      gap: 8px;
      color: $white;
      align-items: center;
      cursor: pointer;

      > .icon {
        width: 20px;
        height: 20px;

        svg {
          width: 100%;
          height: 100%;
          svg($white);
        }
      }

      &:hover > .drop-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(100%);
      }

      &:hover, &:focus, &--active {
        font-weight: 500;
        background: $white;
        color: $black;

        > .icon svg {
          svg($dark);
        }

        &:hover {
          color: $black;
        }
      }

      > span {
        font-weight: normal;
        font-size: 1em;
        line-height: 19px;
      }
    }
  }
}
</style>
