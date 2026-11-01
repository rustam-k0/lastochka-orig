<template>
  <main class="no-access">
    <div class="no-access__container">
      <IconComponent name="lock-2-line" class="no-access__icon" />
      <h1 class="no-access__title">Нет доступа</h1>
      <p class="no-access__text">
        Вашей роли не назначен ни один раздел админ-панели. Обратитесь к администратору, чтобы он
        выдал доступы в разделе «Роли и доступы».
      </p>
      <p v-if="userEmail" class="no-access__user">
        Вы вошли как <b>{{ userEmail }}</b>
      </p>
      <button type="button" class="btn btn--lg" @click="logout">Выйти</button>
    </div>
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { useAuthStore } from "@/stores/auth.js";

export default {
  name: "NoAccessPage",
  components: { IconComponent },
  computed: {
    userEmail() {
      return useAuthStore().user?.email;
    },
  },
  methods: {
    logout() {
      const store = useAuthStore();
      store.deleteCookie("admin_token");
      store.user = null;
      this.$router.push({ name: "login" });
    },
  },
};
</script>

<style lang="stylus">
.no-access {
  min-height 100vh
  display flex
  align-items center
  justify-content center
  padding 24px
  background var(--gray)

  &__container {
    display flex
    flex-direction column
    align-items center
    gap 16px
    max-width 420px
    width 100%
    background var(--white)
    border-radius 16px
    padding 40px 32px
    text-align center
    box-shadow: 0 4px 30px rgba(46, 45, 116, 0.1);
  }

  &__icon {
    width 48px
    height 48px

    svg path {
      fill var(--gray-dark-2)
    }
  }

  &__title {
    font-size 1.5em
    font-weight 600
    color var(--dark)
  }

  &__text {
    font-size 0.875em
    line-height 20px
    color var(--dark)
  }

  &__user {
    font-size 0.813em
    color var(--body-muted)
  }
}
</style>
