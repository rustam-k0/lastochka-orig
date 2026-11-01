<template>
  <main class="page--main printers-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="printers-create-page__header">
      <h2>Добавление принтера</h2>
    </div>

    <div class="printers-create-page__content">
      <PrinterForm @success="handleSuccess" @cancel="handleCancel" />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import PrinterForm from "./components/PrinterForm.vue";

export default {
  name: "PrinterCreatePage",
  components: {
    BreadcrumbsComponent,
    PrinterForm,
  },
  data() {
    return {
      breadcrumbs: [{ to: { name: "printers" }, title: "Принтеры печати" }, { title: "Добавление" }],
    };
  },
  methods: {
    handleSuccess() {
      this.$router.push({ name: "printers" });
    },
    handleCancel() {
      this.$router.push({ name: "printers" });
    },
  },
};
</script>

<style lang="stylus">
.printers-create-page {
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
