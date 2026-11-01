<template>
  <main class="auth">
    <div class="auth__left">
      <div class="auth__left-container">
        <div class="auth__header">
          <img v-if="logoUrl" :src="logoUrl" alt="Logo" class="auth__logo-img" />
          <IconComponent v-else category="default" name="logo-small" class="auth__logo" />
        </div>
        <div class="auth__body">
          <span class="auth__title">Войти</span>
          <form class="auth__form" @submit.prevent="submit">
            <InputComponent
              :mask="form.pin_code.mask"
              :title="form.pin_code.title"
              v-model="form.pin_code.value"
              :errors="form.pin_code.errors"
              :messages="form.pin_code.messages"
              :required="form.pin_code.required"
            />
            <div class="auth__form-footer">
              <button
                type="submit"
                class="btn btn--lg"
                :class="{
                  'btn--loading': loading,
                }"
                :disabled="loading"
              >
                {{ loading ? "Вход.." : "Войти" }}
              </button>
              <RouterLink :to="{ name: 'login' }" class="link">Еще не входили?</RouterLink>
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
import { useAuthStore } from "@/stores/auth.js";
import USER_ACCOUNT_CHANGE from "@/graphql/mutations/UserAccountChange.graphql";
import PUBLIC_SETTING from "@/graphql/queries/publicSetting.graphql";
import { parseGqlErrors } from "@/utils.js";
import Cookies from "js-cookie";

export default {
  name: "AuthLoginPage",
  inject: ["apollo"],
  data() {
    return {
      loading: false,
      logoUrl: null,
      form: {
        pin_code: {
          title: "Пинкод",
          value: null,
          defaultValue: null,
          mask: "####",
          errors: [],
          required: true,
          show: false,
          messages: ["Укажите пин-код, соответствующий вашему логину"],
        },
      },
    };
  },
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
        let variables = {};
        Object.keys(this.form).forEach((key) => {
          this.form[key].errors = [];
        });
        variables.pin_code = parseInt(this.form.pin_code.value);
        const store = useAuthStore();
        this.apollo
          .mutate({
            mutation: USER_ACCOUNT_CHANGE,
            variables,
            context: {
              headers: {
                Authorization: "Bearer " + Cookies.get("refresh_token"),
              },
            },
          })
          .then(({ data }) => {
            if (data.UserAccountChange && data.UserAccountChange.token) {
              store.save("token", data.UserAccountChange.token);
              store.save("refresh_token", data.UserAccountChange.refresh_token);
              store.save("user", data.UserAccountChange.user);
              store.setCookie("token", data.UserAccountChange.token);
              store.setCookie("refresh_token", data.UserAccountChange.refresh_token);
              location.reload();
            }
            this.loading = false;
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
