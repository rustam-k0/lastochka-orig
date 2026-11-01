<template>
  <main class="page--main page-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="page-edit-page__header">
      <h2>Новая страница</h2>
    </div>

    <div class="page-edit-page__content">
      <PageForm @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import PageForm from "./components/PageForm.vue";

export default {
  name: "PageCreatePage",
  components: {
    BreadcrumbsComponent,
    PageForm,
  },
  computed: {
    breadcrumbs() {
      return [{ to: { name: "pages" }, title: "Страницы" }, { title: "Создание" }];
    },
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "pages" });
    },
    handleCancel() {
      this.$router.push({ name: "pages" });
    },
  },
};
</script>

<style lang="stylus">
.page-edit-page {
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
