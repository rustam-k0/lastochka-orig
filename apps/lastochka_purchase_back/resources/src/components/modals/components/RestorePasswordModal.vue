<template>
  <div class="modal-body add-modal">
    <div class="modal-body__header">
      <span class="modal-body__title">Забыли пароль?</span>
      <button class="modal-body__close" type="button" @click="$emit('close')">
        <Icon name="close-line" category="system" class="icon" />
      </button>
    </div>
    <div class="modal-body__content">
      <div class="modal-body__subtitle">
        <span>Введите E-mail, который вы используете для входа</span>
        <span>Мы вышлем на него ссылку на восстановления пароля</span>
      </div>
    </div>
    <form class="modal-body__content" @submit.prevent="submit">
      <InputComponent
        v-model.trim="form.email.value"
        :errors="form.email.errors"
        autofocus
        title="E-mail"
        type="email"
      />
      <button class="btn btn--main btn--medium" type="submit">
        <LoadingIndicator v-show="loading" />
        <span v-show="!loading">Восстановить пароль</span>
      </button>
    </form>
  </div>
</template>

<script>
import Icon from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import FORGOT_PASSWORD from "@/graphql/auth/ForgotPassword.graphql";
import LoadingIndicator from "@/components/LoadingIndicator.vue";

export default {
  name: "RestorePasswordModalComponent",
  data() {
    return {
      loading: false,
      form: {
        email: {
          errors: [],
          value: null,
        },
      },
    };
  },
  methods: {
    submit() {
      if (!this.loading) {
        this.loading = true;
        this.$apollo
          .mutate({
            mutation: FORGOT_PASSWORD,
            variables: {
              email: this.form.email.value,
            },
          })
          .then(({ data }) => {
            if (data && data.UserForgotPassword) {
              if (data.UserForgotPassword.code === 200) {
                this.$notify({
                  title: "OK",
                  text: data.UserForgotPassword.message,
                  type: "success",
                });
              } else {
                this.$notify({
                  title: "Ошибка!",
                  text: data.UserForgotPassword.message,
                  type: "warning",
                });
              }
              this.$emit("close");
            }
            this.loading = false;
          })
          .catch(({ graphQLErrors }) => {
            this.loading = false;
            this.parseGqlErrors(graphQLErrors);
          });
      }
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
  },
  components: { LoadingIndicator, InputComponent, Icon },
};
</script>
