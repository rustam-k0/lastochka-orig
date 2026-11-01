<template>
  <main class="page--main brand-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="brand-create-page__header">
      <h2>Создание бренда</h2>
    </div>

    <div class="brand-create-page__content">
      <BrandForm
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
import BrandForm from "./components/BrandForm.vue";
import BRAND_CREATE from "@/graphql/mutations/BrandCreate.graphql";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "BrandCreatePage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    BrandForm,
  },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "brands" }, title: "Бренды" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;

      this.apollo()
        .admin.mutate({
          mutation: BRAND_CREATE,
          variables: formData,
        })
        .then(({ data }) => {
          if (data.BrandCreate?.id) {
            this.$router.push({ name: "brands" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating brand:", error);
          alert(error.message || "Ошибка создания бренда");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "brands" });
    },
  },
};
</script>

<style lang="stylus">
.brand-create-page {
  &__header {
    margin-bottom 24px

    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }

  &__content {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
  }
}
</style>
