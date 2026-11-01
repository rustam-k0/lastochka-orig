<template>
  <main class="main">
    <BreadcrumbsComponent :title="pageTitle" />
    <div class="page">
      <div class="container form">
        <div class="form__inputs">
          <InputComponent
            id="name"
            v-model.trim="form.name.value"
            :errors="form.name.errors"
            title="Имя"
            autofocus
          />
          <InputComponent
            id="email"
            v-model.trim="form.email.value"
            :errors="form.email.errors"
            title="E-mail"
          />
        </div>
        <AsideComponent :loading="saving" :showReset="false" @submit="submit"> </AsideComponent>
      </div>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/Breadcrumbs.vue";
import GET from "@/graphql/queries/me.graphql";
import UPDATE from "@/graphql/mutations/user/ProfileUpdate.graphql";
import AsideComponent from "@/components/AsideComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";

export default {
  name: "UserPage",
  created() {
    this.$apollo
      .query({
        query: GET,
      })
      .then(({ data }) => {
        this.loading = false;
        this.object = data.me;
        Object.keys(this.form).forEach((key) => {
          this.form[key].value = this.object[key];
        });
      })
      .catch(() => {
        this.loading = false;
      });
  },
  data() {
    return {
      saving: false,
      loading: true,
      object: {},

      form: {
        name: { value: null, defaultValue: null, errors: [] },
        email: { value: null, defaultValue: null, errors: [] },
      },
    };
  },
  computed: {
    pageTitle() {
      return "Личный кабинет";
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
            let message = data.UserProfileEdit.message;

            this.$notify({
              title: "Сохранено",
              text: message,
              type: "success",
            });
            this.$apollo.provider.defaultClient.resetStore();
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
