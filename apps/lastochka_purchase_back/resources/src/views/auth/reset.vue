<template>
  <main class="main">
    <div class="page">
      <div class="container form">
        <div class="form__inputs">
          <h3>Введите E-mail, который вы используете и придумайте новый пароль</h3>

          <InputComponent
            v-model.trim="form.email.value"
            :errors="form.email.errors"
            title="E-mail"
            autofocus
          />
          <InputComponent
            v-model.trim="form.password.value"
            :errors="form.password.errors"
            :type="'password'"
            title="Новый пароль"
          />
          <InputComponent
            v-model.trim="form.password_confirmation.value"
            :errors="form.password_confirmation.errors"
            :type="'password'"
            title="Повторите новый пароль"
          />
          <button class="btn btn--main" @click="submit" type="submit">
            <LoadingIndicator v-if="loading" title="Обработка" />
            <span v-else>Сбросить пароль</span>
          </button>
        </div>
      </div>
    </div>
  </main>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import RESET_PASSWORD from "@/graphql/auth/ResetPassword.graphql";
import LoadingIndicator from "@/components/LoadingIndicator.vue";

export default {
  name: "ResetPasswordPage",

  data() {
    return {
      loading: false,
      form: {
        email: { value: null, defaultValue: null, errors: [] },
        password: { value: null, defaultValue: null, errors: [] },
        password_confirmation: { value: null, defaultValue: null, errors: [] },
      },
    };
  },
  methods: {
    parseGqlErrors(graphQLErrors, tail) {
      graphQLErrors.forEach((err) => {
        if (err.extensions.category === "validation") {
          Object.keys(err.extensions.validation).forEach((key) => {
            let newKey = key.replace(tail + ".", "");
            if (newKey.indexOf(".") === -1) {
              if (this.form[newKey]) {
                this.form[newKey].errors = err.extensions.validation[key];
              }
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
      const urlParams = new URLSearchParams(window.location.search);
      if (!this.loading) {
        this.resetErrors();
        this.loading = true;
        this.saving = true;
        let variables = {
          token: urlParams.get("password_reset_token"),
          email: this.form.email.value,
          password: this.form.password.value,
          password_confirmation: this.form.password_confirmation.value,
        };

        this.$apollo
          .mutate({
            mutation: RESET_PASSWORD,
            variables: variables,
          })
          .then(({ data }) => {
            this.saving = false;
            let message = data.UserResetPassword.message;
            let code = data.UserResetPassword.code;

            if (code === 200) {
              this.$notify({
                title: "OK!",
                text: message,
                type: "success",
              });

              this.$router.push({
                name: "login",
              });
            } else {
              this.$notify({
                title: "Ошибка!",
                text: message,
                type: "error",
              });
            }
          })
          .catch(({ graphQLErrors }) => {
            this.loading = false;
            this.parseGqlErrors(graphQLErrors);
          });

        this.$apollo.provider.defaultClient.resetStore();
      }
    },
  },
  components: {
    InputComponent,
    LoadingIndicator,
  },
};
</script>
