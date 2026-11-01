<template>
  <main class="page--main merchant-promocode-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-promocode-edit-page__header">
      <h2>Редактирование промокода</h2>
    </div>
    
    <div v-if="pageLoading" class="merchant-promocode-edit-page__loading">
      <LoadingIndicator />
    </div>
    
    <div v-else-if="!promocodeData" class="merchant-promocode-edit-page__error">
      <IconComponent name="error-warning-line" />
      <h3>Промокод не найден</h3>
      <button @click="goBack" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>
    
    <div v-else class="merchant-promocode-edit-page__content">
      <MerchantPromocodeForm
        :initialData="promocodeData"
        :loading="loading"
        :isEdit="true"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import MerchantPromocodeForm from "./components/MerchantPromocodeForm.vue";
import MERCHANT_PROMOCODE_ITEM from "@/graphql/queries/merchant_promocode_item.graphql";
import MERCHANT_PROMOCODE_UPDATE from "@/graphql/mutations/MerchantPromocodeUpdate.graphql";

export default {
  name: "MerchantPromocodeEditPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantPromocodeForm,
  },
  data() {
    return {
      promocodeData: null,
      pageLoading: true,
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-promocodes" }, title: "Промокоды" },
        { title: "Редактирование" },
      ],
    };
  },
  computed: {
    promocodeId() {
      return this.$route.params.id;
    },
  },
  mounted() {
    this.fetchPromocode();
  },
  methods: {
    fetchPromocode() {
      this.pageLoading = true;
      
      this.apollo()
        .admin.query({
          query: MERCHANT_PROMOCODE_ITEM,
          variables: { id: parseInt(this.promocodeId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.merchantPromocode) {
            this.promocodeData = data.merchantPromocode;
          }
        })
        .catch((error) => {
          console.error("Error fetching promocode:", error);
        })
        .finally(() => {
          this.pageLoading = false;
        });
    },
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PROMOCODE_UPDATE,
          variables: {
            input: {
              id: parseInt(this.promocodeId),
              ...formData,
            },
          },
        })
        .then(({ data }) => {
          if (data.updateMerchantPromocode?.id) {
            this.$router.push({ name: "merchant-promocodes" });
          } else {
            throw new Error("Ошибка сохранения");
          }
        })
        .catch((error) => {
          console.error("Error updating merchant promocode:", error);
          const message = error.graphQLErrors?.[0]?.message || error.message || "Ошибка сохранения промокода";
          alert(message);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-promocodes" });
    },
    goBack() {
      this.$router.push({ name: "merchant-promocodes" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-promocode-edit-page {
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
