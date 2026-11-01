<template>
  <main class="page--main merchant-sale-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-sale-create-page__header">
      <h2>Создание акции</h2>
    </div>
    
    <div class="merchant-sale-create-page__content">
      <MerchantSaleForm
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
import MerchantSaleForm from "./components/MerchantSaleForm.vue";
import MERCHANT_SALE_CREATE from "@/graphql/mutations/MerchantSaleCreate.graphql";

export default {
  name: "MerchantSaleCreatePage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    MerchantSaleForm,
  },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-sales" }, title: "Акции магазинов" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_SALE_CREATE,
          variables: formData,
        })
        .then(({ data }) => {
          if (data.createMerchantSale?.id) {
            this.$router.push({ name: "merchant-sales" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating merchant sale:", error);
          alert(gqlErrorText(error, "Ошибка создания акции"));
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-sales" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-sale-create-page {
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
