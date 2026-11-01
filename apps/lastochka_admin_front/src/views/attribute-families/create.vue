<template>
  <main class="page--main family-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="family-create-page__header">
      <h2>Создание семейства атрибутов</h2>
    </div>
    <div class="family-create-page__content">
      <AttributeFamilyForm
        :loading="loading"
        :isEdit="false"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import AttributeFamilyForm from "./components/AttributeFamilyForm.vue";
import ATTRIBUTE_FAMILY_CREATE from "@/graphql/mutations/AttributeFamilyCreate.graphql";

export default {
  name: "AttributeFamilyCreatePage",
  inject: ["apollo"],
  components: { BreadcrumbsComponent, AttributeFamilyForm },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "attribute-families" }, title: "Семейства атрибутов" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;
      this.apollo()
        .admin.mutate({
          mutation: ATTRIBUTE_FAMILY_CREATE,
          variables: formData,
        })
        .then(() => {
          this.$router.push({ name: "attribute-families" });
        })
        .catch((error) => {
          console.error("Error creating attribute family:", error);
          alert(error.message || "Ошибка создания семейства атрибутов");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "attribute-families" });
    },
  },
};
</script>

<style lang="stylus">
.family-create-page
  &__header
    margin-bottom 24px
    h2
      font-size 1.5rem
      font-weight 600

  &__content
    background #fff
    border-radius 12px
    border 1px solid #eee
    padding 32px
</style>
