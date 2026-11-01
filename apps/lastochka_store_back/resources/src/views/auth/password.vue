<template>
  <main class="main">
    <BreadcrumbsComponent :title="pageTitle" />
    <div class="page">
      <div class="container form">
        <div class="form__inputs">
          <InputComponent
            id="old_password"
            v-model.trim="form.old_password.value"
            :errors="form.old_password.errors"
            title="Старый пароль"
            :type="'password'"
          />
          <InputComponent
            id="password"
            v-model.trim="form.password.value"
            :errors="form.password.errors"
            title="Новый пароль"
            :type="'password'"
          />
          <InputComponent
            id="password_confirmation"
            v-model.trim="form.password_confirmation.value"
            :errors="form.password_confirmation.errors"
            title="Подтвердите новый пароль"
            :type="'password'"
          />
        </div>
        <AsideComponent :loading="saving" :showReset="false" @submit="submit"> </AsideComponent>
      </div>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/Breadcrumbs.vue";
import UPDATE from "@/graphql/auth/PasswordUpdate.graphql";
import AsideComponent from "@/components/AsideComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";

export default {
  name: "UserPage",
  data() {
    return {
      saving: false,
      loading: true,
      object: {},

      form: {
        old_password: { value: null, defaultValue: null, errors: [] },
        password: { value: null, defaultValue: null, errors: [] },
        password_confirmation: { value: null, defaultValue: null, errors: [] },
      },
    };
  },
  computed: {
    pageTitle() {
      return "Сменить пароль";
    },
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
    submit() {
      if (!this.saving) {
        this.saving = true;
        let variables = {};
        Object.keys(this.form).forEach((k) => {
          variables[k] = this.form[k].value !== null ? this.form[k].value : undefined;
        });
        if (this.object.id) {
          variables.id = this.object.id;
        }

        this.$apollo
          .mutate({
            mutation: UPDATE,
            variables: variables,
          })
          .then(({ data }) => {
            this.saving = false;
            let message = data.UserChangePassword.message;

            this.$notify({
              title: "Сохранено",
              text: message,
              type: "success",
            });
            this.$apollo.provider.defaultClient.resetStore();
            this.$router.push({ name: "home" });
          })
          .catch(({ graphQLErrors }) => {
            this.saving = false;
            this.parseGqlErrors(graphQLErrors);
          });
      }
    },
  },
  components: {
    AsideComponent,
    BreadcrumbsComponent,
    InputComponent,
  },
};
</script>
