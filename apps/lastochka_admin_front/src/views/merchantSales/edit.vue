<template>
  <main class="page--main merchant-sale-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-sale-edit-page__header">
      <h2>Редактирование акции</h2>
    </div>
    
    <div v-if="pageLoading" class="merchant-sale-edit-page__loading">
      <LoadingIndicator />
    </div>
    
    <div v-else-if="!saleData" class="merchant-sale-edit-page__error">
      <IconComponent name="error-warning-line" />
      <h3>Акция не найдена</h3>
      <button @click="goBack" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>
    
    <div v-else class="merchant-sale-edit-page__content">
      <MerchantSaleForm
        :initialData="saleData"
        :loading="loading"
        :isEdit="true"
        :excludeSaleId="saleId"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import { gqlErrorText } from "@/utils.js";
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import MerchantSaleForm from "./components/MerchantSaleForm.vue";
import MERCHANT_SALE_ITEM from "@/graphql/queries/merchant_sale_item.graphql";
import MERCHANT_SALE_UPDATE from "@/graphql/mutations/MerchantSaleUpdate.graphql";

export default {
  name: "MerchantSaleEditPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantSaleForm,
  },
  data() {
    return {
      saleData: null,
      pageLoading: true,
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-sales" }, title: "Акции магазинов" },
        { title: "Редактирование" },
      ],
    };
  },
  computed: {
    saleId() {
      return this.$route.params.id;
    },
  },
  mounted() {
    this.fetchSale();
  },
  methods: {
    fetchSale() {
      this.pageLoading = true;
      
      this.apollo()
        .admin.query({
          query: MERCHANT_SALE_ITEM,
          variables: { id: parseInt(this.saleId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.merchantSale) {
            this.saleData = data.merchantSale;
          }
        })
        .catch((error) => {
          console.error("Error fetching sale:", error);
        })
        .finally(() => {
          this.pageLoading = false;
        });
    },
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_SALE_UPDATE,
          variables: {
            id: parseInt(this.saleId),
            ...formData,
          },
        })
        .then(({ data }) => {
          if (data.updateMerchantSale?.id) {
            this.$router.push({ name: "merchant-sales" });
          } else {
            throw new Error("Ошибка сохранения");
          }
        })
        .catch((error) => {
          console.error("Error updating merchant sale:", error);
          alert(gqlErrorText(error, "Ошибка сохранения акции"));
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-sales" });
    },
    goBack() {
      this.$router.push({ name: "merchant-sales" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-sale-edit-page {
  &__header {
    margin-bottom 24px
    
    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }
  
  &__loading {
    display flex
    justify-content center
    padding 48px
  }
  
  &__error {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center
    
    .icon {
      width 64px
      height 64px
      margin-bottom 16px
      
      svg path {
        fill var(--red)
      }
    }
    
    h3 {
      font-size 1.25rem
      color var(--dark)
      margin-bottom 24px
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
