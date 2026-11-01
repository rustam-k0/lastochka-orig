<template>
  <main class="page--main merchant-product-group-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-product-group-create-page__header">
      <h2>Создание товарной группы</h2>
    </div>
    
    <div class="merchant-product-group-create-page__content">
      <MerchantProductGroupForm
        :loading="loading"
        :isEdit="false"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import { gqlErrorText } from "@/utils.js";
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import MerchantProductGroupForm from "./components/MerchantProductGroupForm.vue";
import MERCHANT_PRODUCT_GROUP_CREATE from "@/graphql/mutations/MerchantProductGroupCreate.graphql";

export default {
  name: "MerchantProductGroupCreatePage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    MerchantProductGroupForm,
  },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-product-groups" }, title: "Товарные группы" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PRODUCT_GROUP_CREATE,
          variables: formData,
        })
        .then(({ data }) => {
          if (data.createMerchantProductGroup?.id) {
            this.$router.push({ name: "merchant-product-groups" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating merchant product group:", error);
          alert(gqlErrorText(error, "Ошибка создания группы"));
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-product-groups" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-product-group-create-page {
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
