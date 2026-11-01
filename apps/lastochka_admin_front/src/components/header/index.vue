<template>
  <header class="header" id="header">
    <div class="header__top">
      <!--      <div class="header__search">-->
      <!--        <InputComponent small placeholder="Введите запрос для поиска">-->
      <!--          <template #before>-->
      <!--            <IconComponent name="search-line" />-->
      <!--          </template>-->
      <!--        </InputComponent>-->
      <!--      </div>-->
      <HeaderUserComponent />
      <!--      <button type="button" class="header__lock" title="Заблокировать экран">-->
      <!--        <IconComponent name="search-line" />-->
      <!--      </button>-->
    </div>
  </header>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import HeaderUserComponent from "@/components/header/components/HeaderUserComponent.vue";
// import { useAuthStore } from "@/stores/auth.js";
import { parseGqlErrors } from "@/utils.js";
import InputComponent from "@/components/inputs/InputComponent.vue";

export default {
  name: "HeaderComponent",
  inject: ["apollo"],
  methods: {
    parseGqlErrors,
    // lock() {
    //   if (!this.loading) {
    //     this.loading = true;
    //     const store = useAuthStore();
    //     this.apollo
    //       .mutate({
    //         mutation: USER_LOGOUT,
    //       })
    //       .then(({ data }) => {
    //         if (data.UserLogout) {
    //           store.deleteCookie("token");
    //           store.user = null;
    //           this.$router.push({ name: "lock" });
    //         }
    //       })
    //       .catch((e) => {
    //         console.log(e);
    //         // this.parseGqlErrors(graphQLErrors);
    //       })
    //       .finally(() => {
    //         this.loading = false;
    //       });
    //   }
    // },
  },
  components: { InputComponent, HeaderUserComponent, IconComponent },
};
</script>

<style lang="stylus">
.header {
  display grid
  align-items start
  position sticky
  top 0
  z-index 5

  &__top {
    background var(--white)
    display grid
    height 70px
    align-items center
    grid-template-columns 1fr auto auto
    border-bottom 1px solid var(--gray-dark)
    width 100%
  }

  &__search {
    width 100%
    padding 0 32px
  }

  &__lock {
    background none
    border none
    padding 0 28px
    height 100%

    .icon {
      width 20px
      height 20px
    }
  }
}
</style>
