<template>
  <main class="page--main merchant-product-group-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-product-group-edit-page__header">
      <h2>Редактирование товарной группы</h2>
    </div>
    
    <div v-if="pageLoading" class="merchant-product-group-edit-page__loading">
      <LoadingIndicator />
    </div>
    
    <div v-else-if="!groupData" class="merchant-product-group-edit-page__error">
      <IconComponent name="error-warning-line" />
      <h3>Группа не найдена</h3>
      <button @click="goBack" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>
    
    <div v-else class="merchant-product-group-edit-page__content">
      <MerchantProductGroupForm
        :initialData="groupData"
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
import MerchantProductGroupForm from "./components/MerchantProductGroupForm.vue";
import MERCHANT_PRODUCT_GROUP_ITEM from "@/graphql/queries/merchant_product_group_item.graphql";
import MERCHANT_PRODUCT_GROUP_UPDATE from "@/graphql/mutations/MerchantProductGroupUpdate.graphql";

export default {
  name: "MerchantProductGroupEditPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantProductGroupForm,
  },
  data() {
    return {
      groupData: null,
      pageLoading: true,
      loading: false,
      breadcrumbs: [
        { to: { name: "merchant-product-groups" }, title: "Товарные группы" },
        { title: "Редактирование" },
      ],
    };
  },
  computed: {
    groupId() {
      return this.$route.params.id;
    },
  },
  mounted() {
    this.fetchGroup();
  },
  methods: {
    fetchGroup() {
      this.pageLoading = true;
      
      this.apollo()
        .admin.query({
          query: MERCHANT_PRODUCT_GROUP_ITEM,
          variables: { id: parseInt(this.groupId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.merchantProductGroup) {
            this.groupData = data.merchantProductGroup;
          }
        })
        .catch((error) => {
          console.error("Error fetching group:", error);
        })
        .finally(() => {
          this.pageLoading = false;
        });
    },
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PRODUCT_GROUP_UPDATE,
          variables: {
            id: parseInt(this.groupId),
            ...formData,
          },
        })
        .then(({ data }) => {
          if (data.updateMerchantProductGroup?.id) {
            this.$router.push({ name: "merchant-product-groups" });
          } else {
            throw new Error("Ошибка сохранения");
          }
        })
        .catch((error) => {
          console.error("Error updating merchant product group:", error);
          alert(gqlErrorText(error, "Ошибка сохранения группы"));
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "merchant-product-groups" });
    },
    goBack() {
      this.$router.push({ name: "merchant-product-groups" });
    },
  },
};
</script>

<style lang="stylus">
.merchant-product-group-edit-page {
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
