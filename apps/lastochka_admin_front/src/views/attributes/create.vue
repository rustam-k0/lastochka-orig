<template>
  <main class="page--main attribute-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="attribute-create-page__header">
      <h2>Создание атрибута</h2>
    </div>
    <div class="attribute-create-page__content">
      <AttributeForm
        :isEdit="false"
        @success="onSuccess"
        @cancel="onCancel"
      />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import AttributeForm from "./components/AttributeForm.vue";

export default {
  name: "AttributeCreatePage",
  components: { BreadcrumbsComponent, AttributeForm },
  data() {
    return {
      breadcrumbs: [
        { to: { name: "attributes" }, title: "Атрибуты" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    onSuccess() {
      this.$router.push({ name: "attributes" });
    },
    onCancel() {
      this.$router.push({ name: "attributes" });
    },
  },
};
</script>

<style lang="stylus">
.attribute-create-page
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
