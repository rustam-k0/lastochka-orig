<template>
  <main class="page--main brand-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="brand-edit-page__header">
      <h2>Редактирование бренда</h2>
    </div>

    <div v-if="loadingData" class="brand-edit-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="brand" class="brand-edit-page__content">
      <BrandForm
        :initialData="brand"
        :loading="loading"
        :isEdit="true"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>

    <div v-else class="brand-edit-page__error">
      <IconComponent name="error-warning-line" />
      <h3>Бренд не найден</h3>
      <button @click="$router.push({ name: 'brands' })" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import BrandForm from "./components/BrandForm.vue";
import BRAND_ITEM from "@/graphql/queries/admin_brands_item.graphql";
import BRAND_UPDATE from "@/graphql/mutations/BrandUpdate.graphql";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "BrandEditPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    BrandForm,
  },
  data() {
    return {
      brand: null,
      loading: false,
      loadingData: true,
    };
  },
  computed: {
    brandId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [
        { to: { name: "brands" }, title: "Бренды" },
        { title: this.brand?.title || "Редактирование" },
      ];
    },
  },
  mounted() {
    this.fetchBrand();
  },
  methods: {
    fetchBrand() {
      this.loadingData = true;

      this.apollo()
        .admin.query({
          query: BRAND_ITEM,
          variables: { id: this.brandId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.brand = data.admin_brands_item;
        })
        .catch((error) => {
          console.error("Error fetching brand:", error);
          useDefaultStore().showNotification({
            type: "error",
            message: "Ошибка загрузки бренда",
          });
        })
        .finally(() => {
          this.loadingData = false;
        });
    },
    handleSubmit(formData) {
      this.loading = true;

      this.apollo()
        .admin.mutate({
          mutation: BRAND_UPDATE,
          variables: {
            id: this.brandId,
            ...formData,
          },
        })
        .then(({ data }) => {
          if (data.BrandUpdate?.id) {
            this.$router.push({ name: "brands" });
          } else {
            throw new Error("Ошибка обновления");
          }
        })
        .catch((error) => {
          console.error("Error updating brand:", error);
          alert(error.message || "Ошибка обновления бренда");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "brands" });
    },
  },
};
</script>

<style lang="stylus">
.brand-edit-page {
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
    padding 64px
  }

  &__content {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
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
}
</style>
