<template>
  <main class="page--main city-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="city-edit-page__header">
      <h2>Редактирование города</h2>
    </div>

    <div class="city-edit-page__content">
      <CityForm :id="cityId" @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import CityForm from "./components/CityForm.vue";

export default {
  name: "CityEditPage",
  components: {
    BreadcrumbsComponent,
    CityForm,
  },
  computed: {
    cityId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [{ to: { name: "cities" }, title: "Города" }, { title: "Редактирование" }];
    },
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
.city-edit-page {
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
