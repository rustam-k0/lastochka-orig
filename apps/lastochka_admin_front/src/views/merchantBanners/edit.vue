<template>
  <main class="page--main merchant-banner-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-banner-edit-page__header">
      <h2>Редактирование баннера</h2>
    </div>
    
    <div v-if="pageLoading" class="merchant-banner-edit-page__loading">
      <LoadingIndicator />
    </div>
    
    <div v-else-if="!bannerData" class="merchant-banner-edit-page__error">
      <IconComponent name="error-warning-line" />
      <h3>Баннер не найден</h3>
      <button @click="goBack" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>
    
    <div v-else class="merchant-banner-edit-page__content">
      <MerchantBannerForm
        :initialData="bannerData"
        :loading="loading"
        :isEdit="true"
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
import MerchantBannerForm from "./components/MerchantBannerForm.vue";
import MERCHANT_BANNER_ITEM from "@/graphql/queries/merchant_banner_item.graphql";
import MERCHANT_BANNER_UPDATE from "@/graphql/mutations/MerchantBannerUpdate.graphql";

export default {
  name: "MerchantBannerEditPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantBannerForm,
  },
  data() {
    return {
      bannerData: null,
      pageLoading: true,
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-banners" }, title: "Баннеры" },
        { title: "Редактирование" },
      ],
    };
  },
  computed: {
    bannerId() {
      return this.$route.params.id;
    },
  },
  mounted() {
    this.fetchBanner();
  },
  methods: {
    fetchBanner() {
      this.pageLoading = true;
      
      this.apollo()
        .admin.query({
          query: MERCHANT_BANNER_ITEM,
          variables: { id: parseInt(this.bannerId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.merchantBanner) {
            this.bannerData = data.merchantBanner;
          }
        })
        .catch((error) => {
          console.error("Error fetching banner:", error);
        })
        .finally(() => {
          this.pageLoading = false;
        });
    },
    handleSubmit(formData) {
      this.loading = true;
      
      const { shop_id, ...updateData } = formData;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_BANNER_UPDATE,
          variables: {
            id: parseInt(this.bannerId),
            ...updateData,
          },
        })
        .then(({ data }) => {
          if (data.merchantBannerUpdate?.id) {
            this.$router.push({ name: "merchant-banners" });
          } else {
            throw new Error("Ошибка сохранения");
          }
        })
        .catch((error) => {
          console.error("Error updating merchant banner:", error);
          alert(gqlErrorText(error, "Ошибка сохранения баннера"));
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-banners" });
    },
    goBack() {
      this.$router.push({ name: "merchant-banners" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-banner-edit-page {
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
