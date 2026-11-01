<template>
  <section class="auth">
    <div class="auth__left">
      <!--logo-->
    </div>
    <div class="auth__right">
      <form class="auth__form" @submit.prevent="submit">
        <span class="auth__form-title">Вход</span>
        <div class="auth__form-inputs">
          <InputComponent
            v-model.trim="form.email.value"
            :errors="form.email.errors"
            autofocus
            title="E-mail"
            type="email"
          />
          <InputComponent
            v-model.trim="form.password.value"
            :errors="form.password.errors"
            :type="form.password.show ? 'text' : 'password'"
            title="Пароль"
            @submit="form.password.show = !form.password.show"
          >
            <template v-slot:action>
              <Icon v-if="form.password.show" category="system" name="eye-line" />
              <Icon v-else category="system" name="eye-close-line" />
            </template>
          </InputComponent>
          <div class="auth__form-buttons">
            <button class="btn btn--main" type="submit">
              <LoadingIndicator v-if="loading" title="Обработка" />
              <span v-else>Войти</span>
            </button>
            <a href="#" @click="showRestoreModal">Забыли пароль?</a>
          </div>
        </div>
      </form>
    </div>
  </section>
</template>

<script>
import Icon from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import LOGIN from "@/graphql/auth/AdminLogin.graphql";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import RestorePasswordModal from "@/components/modals/components/RestorePasswordModal.vue";

export default {
  name: "LoginPage",
  data() {
    return {
      loading: false,
      form: {
        email: {
          errors: [],
          value: null,
        },
        password: {
          errors: [],
          value: null,
          show: false,
        },
      },
    };
  },
  computed: {
    formFilled() {
      return this.form.email.value && this.form.password.value;
    },
  },
  methods: {
    showRestoreModal() {
      this.$store.state._modals.push({
        component: RestorePasswordModal,
      });
    },
    parseGqlErrors(graphQLErrors) {
      graphQLErrors.forEach((err) => {
        if (err.extensions.category === "validation") {
          Object.keys(err.extensions.validation).forEach((key) => {
            if (this.form[key]) {
              this.form[key].errors = err.extensions.validation[key];
            }
          });
        }
      });
    },
    resetErrors(form = "form") {
      Object.keys(this[form]).forEach((key) => {
        this[form][key].errors = [];
      });
    },
    submit() {
      if (!this.loading && this.formFilled) {
        this.resetErrors();
        this.loading = true;
        this.$apollo
          .mutate({
            mutation: LOGIN,
            variables: {
              email: this.form.email.value,
              password: this.form.password.value,
            },
          })
          .then(({ data }) => {
            if (data && data.AdminLogin) {
              this.$cookies.set(
                "admin-token",
                data.AdminLogin.token,
                new Date(2147483647 * 1000).toUTCString(),
                "/",
                undefined,
                "strict"
              );
            }
            location.href = this.$router.resolve({
              name: "home",
            }).href;
          })
          .catch(({ graphQLErrors }) => {
            this.loading = false;
            this.parseGqlErrors(graphQLErrors);
          });
      }
    },
  },
  components: { LoadingIndicator, InputComponent, Icon },
};
</script>

<style lang="stylus">
@import "~@/styles/laravel-mix-fix.styl"
.auth {
  width 100%
  min-height 100vh
  display grid
  grid-template-columns 1fr 1fr

  &__left {
    background $main_color
    display flex
    align-items center
    justify-content center

    .icon {
      width 200px
      height 200px
      display flex
      align-items center
      justify-content center

      svg {
        width 100%
        height 100%
      }
    }
  }

  &__right {
    background $white
    padding 32px
    display flex
    justify-content center
    align-items center
    +below(940px) {
      padding 16px
    }
  }

  &__form {
    max-width 280px
    width 100%
    display grid
    grid-gap 32px

    &-title {
      font-size: 2em;
    }

    &-inputs {
      display grid
      grid-gap 15px
    }

    &-buttons {
      display flex
      align-items center
      gap 15px

      a {
        text-decoration underline
      }
    }
  }
}
</style>
