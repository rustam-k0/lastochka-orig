<template>
  <div class="user">
    <!--    <router-link :to="{ name: 'settings' }" class="user__settings">-->
    <!--      <IconComponent name="settings-3-line" />-->
    <!--    </router-link>-->
    <div class="user__actions">
      <div class="user__img">
        <img src="/public/public/assets/images/avatar_profile.png" alt="аватар" />
      </div>
      <div class="user__drop">
        <!--        <router-link :to="{ name: 'profile' }">-->
        <!--          <span> Профиль </span>-->
        <!--        </router-link>-->
        <div @click="logout">
          <span>Выход</span>
          <IconComponent name="logout-box-r-line" />
        </div>
      </div>
      <div class="user__info">
        <span class="user__name"> {{ userName }} </span>
        <!--        <span class="user__email"> З. </span>-->
        <IconComponent name="arrow-drop-down-fill" />
      </div>
    </div>
  </div>
</template>

<script>
import { useAuthStore } from "@/stores/auth.js";
import IconComponent from "@/components/IconComponent.vue";

export default {
  name: "HeaderUserComponent",
  components: { IconComponent },
  methods: {
    logout() {
      let store = useAuthStore();
      if (confirm("Вы уверены?")) {
        store.deleteCookie("admin_token");
        store.user = null;
        this.$router.push({ name: "login" });
      }
    },
  },
  computed: {
    user() {
      return useAuthStore().user;
    },
    userName() {
      if (this.user?.surname) {
        let name = (this.user.name || "").slice(0, 1) + (this.user.name ? "." : "");
        let patronymic = (this.user.patronymic || "").slice(0, 1) + (this.user.patronymic ? "." : "");
        return `${this.user.surname} ${name} ${patronymic}`;
      }
      return this.user?.name;
    },
  },
};
</script>

<style lang="stylus">
.user {
  display flex
  gap 10px
  align-items center
  padding-right 32px
  height 100%
  margin-left: auto

  &__settings {
    border-radius 999px
    width 36px
    height 36px
    background var(--gray-3)
    display flex
    justify-content center
    align-items center
    cursor: pointer;
    transition .2s


    &:active {
      transform scale(0.95)
    }

    .icon {
      width 20px
      height 20px


      svg path {
        fill #484B4E
      }
    }
  }

  &__actions {
    position relative
    display flex
    gap 8px
    align-items center
    height 100%

    &:hover {

      .user__drop {
        visibility visible
        opacity 1
      }
    }
  }

  &__drop {
    visibility hidden
    opacity 0
    position absolute
    width 240px
    bottom calc(-33px - 50%)
    right 0
    background var(--white)
    padding 12px
    box-shadow: 0 4px 30px rgba(46, 45, 116, 0.1);
    border-radius: 8px;
    transition var(--transition)


    > div {
      color var(--red)
    }

    > div, a {
      display flex
      align-items center
      justify-content space-between
      padding 10px 12px
      cursor pointer

      span {
        font-weight 500
        font-size 0.875em
        line-height 20px
      }

      .icon {
        width 24px
        height 24px

        svg path {
          fill var(--red)
        }
      }

    }
  }

  &__img {
    width 36px
    height 36px
    object-fit cover
    object-position center
    border-radius 100%
    background var(--gray-dark)
    overflow hidden
    color transparent
    display inline-flex
    align-items center
    justify-content center
  }

  &__img-icon {
    width 36px
    height 36px
  }

  &__info {
    font-weight: 500;
    color: var(--dark);
    display flex
    align-items center
    cursor pointer

    .icon {
      width 20px
      height 20px
    }
  }

  &__name {
    font-size 0.813em
    line-height 18px
  }

  &__email {
    font-size: 0.813em
    line-height: 18px;
  }
}
</style>
