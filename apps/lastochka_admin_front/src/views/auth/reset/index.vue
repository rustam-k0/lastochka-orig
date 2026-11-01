<template>
  <main class="auth">
    <div class="auth__left">
      <div class="auth__left-container">
        <div class="auth__header">
          <img v-if="logoUrl" :src="logoUrl" alt="Logo" class="auth__logo-img" />
          <IconComponent v-else category="default" name="logo-small" class="auth__logo" />
        </div>
        <div class="auth__body">
          <span class="auth__title">Восстановить пароль</span>
          <template v-if="done">
            <p class="auth__success">
              Инструкция по восстановлению пароля отправлена на указанный email. Проверьте почту.
            </p>
            <div class="auth__form-footer">
              <RouterLink :to="{ name: 'login' }" class="link">Войти</RouterLink>
            </div>
          </template>
          <form v-else class="auth__form" @submit.prevent="submit">
            <InputComponent
              :title="form.email.title"
              v-model="form.email.value"
              :errors="form.email.errors"
              :messages="form.email.messages"
              placeholder="example@mail.com"
              :required="form.email.required"
            />
            <div class="auth__form-footer">
              <button type="submit" class="btn btn--lg" :class="{ 'btn--loading': loading }">
                <span v-if="loading">Загрузка</span>
                <span v-else>Восстановить</span>
              </button>
              <RouterLink :to="{ name: 'login' }" class="link">Войти</RouterLink>
            </div>
          </form>
        </div>
        <div class="auth__footer"></div>
      </div>
    </div>
    <div class="auth__right">
      <img src="/public/assets/svg/default/login.svg" class="auth__image" alt="Войти" />
    </div>
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import FORGOT_PASSWORD from "@/graphql/mutations/UserForgotPassword.graphql";
import PUBLIC_SETTING from "@/graphql/queries/publicSetting.graphql";
import { parseGqlErrors } from "@/utils.js";

export default {
  name: "AuthLoginPage",
  inject: ["apollo"],
  data: () => ({
    loading: false,
    done: false,
    logoUrl: null,
    form: {
      email: {
        title: "Email",
        value: null,
        defaultValue: null,
        errors: [],
        required: true,
        messages: ["Инструкция по сбросу пароля будет отправлена на ваш Email."],
      },
    },
  }),
  created() {
    this.loadLogo();
  },
  methods: {
    parseGqlErrors,
    loadLogo() {
      this.apollo()
        .admin.query({
          query: PUBLIC_SETTING,
          variables: { key: "admin_logo" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.publicSetting && data.publicSetting.value) {
            const url = import.meta.env.VITE_MEDIA_ENDPOINT + data.publicSetting.value;
            this.logoUrl = url;
            this.updateFavicon(url);
          }
        })
        .catch(() => {});
      this.apollo()
        .admin.query({
          query: PUBLIC_SETTING,
          variables: { key: "admin_title" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.publicSetting && data.publicSetting.value) {
            document.title = data.publicSetting.value;
          }
        })
        .catch(() => {});
    },
    updateFavicon(url) {
      let link = document.querySelector("link[rel='icon']");
      if (link) {
        link.href = url;
      }
    },
    submit() {
      if (!this.loading) {
        this.loading = true;
        this.apollo()
          .defaultClient.mutate({
            mutation: FORGOT_PASSWORD,
            variables: {
              email: this.form.email.value,
            },
          })
          .then(() => {
            this.done = true;
          })
          .catch(({ graphQLErrors }) => {
            this.parseGqlErrors(graphQLErrors);
          })
          .finally(() => {
            this.loading = false;
          });
      }
    },
  },
  components: { InputComponent, IconComponent },
};
</script>

<style lang="stylus">
@import "@/styles/parts/auth.styl";
</style>
