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

          <!-- Шаг 1: логин/пароль -->
          <form v-if="step === 'login'" class="auth__form" @submit.prevent="submit">
            <InputComponent
              :title="form.email.title"
              v-model="form.email.value"
              :errors="form.email.errors"
              :messages="form.email.messages"
              :required="form.email.required"
            />
            <InputComponent
              :title="form.password.title"
              v-model="form.password.value"
              :errors="form.password.errors"
              :messages="form.password.messages"
              :required="form.password.required"
              :type="form.password.show ? 'text' : 'password'"
            >
              <template v-slot:after>
                <button type="button" @click="form.password.show = !form.password.show">
                  <IconComponent name="eye-line" v-if="form.password.show" />
                  <IconComponent name="eye-close-line" v-else />
                </button>
              </template>
            </InputComponent>
            <div class="auth__form-footer">
              <button
                type="submit"
                class="btn btn--lg"
                :class="{ 'btn--loading': loading }"
                :disabled="loading"
              >
                {{ loading ? "Загрузка" : "Войти" }}
              </button>
              <RouterLink :to="{ name: 'reset-password' }" class="link">Забыли свой пароль?</RouterLink>
            </div>
          </form>

          <!-- Шаг 2a: настройка 2FA (первый вход) -->
          <form v-else-if="step === 'setup'" class="auth__form" @submit.prevent="verify">
            <p class="auth__2fa-hint">
              Отсканируйте QR-код в приложении-аутентификаторе (Google Authenticator, Яндекс Ключ),
              затем введите 6-значный код.
            </p>
            <img v-if="twoFactor.qr" :src="twoFactor.qr" alt="QR" class="auth__2fa-qr" />
            <p class="auth__2fa-secret">
              Или введите ключ вручную: <code>{{ twoFactor.secret }}</code>
            </p>
            <InputComponent
              title="Код из приложения"
              v-model="form.code.value"
              :errors="form.code.errors"
              mask="######"
              required
            />
            <div class="auth__form-footer">
              <button type="submit" class="btn btn--lg" :class="{ 'btn--loading': loading }" :disabled="loading">
                {{ loading ? "Проверка" : "Подтвердить" }}
              </button>
            </div>
          </form>

          <!-- Шаг 2b: ввод кода (2FA уже настроена) -->
          <form v-else-if="step === 'code'" class="auth__form" @submit.prevent="verify">
            <p class="auth__2fa-hint">Введите 6-значный код из приложения-аутентификатора.</p>
            <InputComponent
              title="Код из приложения"
              v-model="form.code.value"
              :errors="form.code.errors"
              mask="######"
              required
            />
            <div class="auth__form-footer">
              <button type="submit" class="btn btn--lg" :class="{ 'btn--loading': loading }" :disabled="loading">
                {{ loading ? "Проверка" : "Войти" }}
              </button>
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
import ADMIN_LOGIN from "@/graphql/mutations/AdminLogin.graphql";
import VERIFY_TWO_FACTOR from "@/graphql/mutations/VerifyTwoFactor.graphql";
import PUBLIC_SETTING from "@/graphql/queries/publicSetting.graphql";
import { parseGqlErrors } from "@/utils.js";

export default {
  name: "AuthLoginPage",
  inject: ["apollo"],
  data() {
    return {
      loading: false,
      logoUrl: null,
      step: "login",
      twoFactor: { challenge: null, secret: null, qr: null },
      form: {
        code: {
          title: "Код из приложения",
          value: null,
          errors: [],
          required: true,
          messages: [],
        },
        email: {
          title: "Логин или Email",
          value: null,
          errors: [],
          required: true,
          messages: ["Укажите ваш логин"],
        },
        password: {
          title: "Пароль",
          value: null,
          errors: [],
          required: true,
          show: false,
          messages: ["Укажите пароль, соответствующий вашему логину"],
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
      if (this.loading) return;
      this.loading = true;
      Object.keys(this.form).forEach((key) => {
        this.form[key].errors = [];
      });
      this.apollo()
        .defaultClient.mutate({
          mutation: ADMIN_LOGIN,
          variables: {
            email: this.form.email.value,
            password: this.form.password.value,
          },
        })
        .then(({ data }) => {
          const res = data.AdminLogin;
          if (res.token) {
            this.finishLogin(res.token);
            return;
          }
          this.twoFactor.challenge = res.challenge;
          if (res.two_factor === "setup") {
            this.twoFactor.secret = res.two_factor_secret;
            this.twoFactor.qr = res.qr;
            this.step = "setup";
          } else if (res.two_factor === "required") {
            this.step = "code";
          }
          this.loading = false;
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors);
          this.loading = false;
        });
    },
    verify() {
      if (this.loading) return;
      this.loading = true;
      this.form.code.errors = [];
      this.apollo()
        .defaultClient.mutate({
          mutation: VERIFY_TWO_FACTOR,
          variables: {
            challenge: this.twoFactor.challenge,
            code: this.form.code.value,
          },
        })
        .then(({ data }) => {
          this.finishLogin(data.VerifyTwoFactor.token);
        })
        .catch(({ graphQLErrors }) => {
          const msg = graphQLErrors && graphQLErrors[0] ? graphQLErrors[0].message : "Неверный код";
          this.form.code.errors = [msg];
          this.loading = false;
        });
    },
    finishLogin(token) {
      const store = useAuthStore();
      store.save("token", token);
      store.setCookie("admin_token", token);
      location.reload();
    },
  },
  components: { InputComponent, IconComponent },
};
</script>

<style lang="stylus">
@import "@/styles/parts/auth.styl";

.auth__2fa-hint
  font-size 0.875rem
  color var(--dark-light)
  margin-bottom 12px
.auth__2fa-qr
  display block
  width 220px
  height 220px
  margin 0 auto 12px
.auth__2fa-secret
  font-size 0.8125rem
  color var(--dark-light)
  margin-bottom 12px
  word-break break-all
  code
    font-family monospace
    color var(--dark)
</style>
