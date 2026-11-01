<template>
  <main class="page--main city-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="city-create-page__header">
      <h2>Создание города</h2>
    </div>

    <div class="city-create-page__content">
      <CityForm @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import CityForm from "./components/CityForm.vue";

export default {
  name: "CityCreatePage",
  components: {
    BreadcrumbsComponent,
    CityForm,
  },
  data() {
    return {
      breadcrumbs: [{ to: { name: "cities" }, title: "Города" }, { title: "Создание" }],
    };
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "cities" });
    },
    handleCancel() {
      this.$router.push({ name: "cities" });
    },
  },
};
</script>

<style lang="stylus">
.city-create-page {
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
