<template>
  <main class="page--main merchant-banners-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-banners-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="merchant-banners-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Создать баннер</span>
        </button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="merchant-banners-page__filters">
      <div class="merchant-banners-page__search">
        <IconComponent name="search-line" />
        <input 
          type="text"
          v-model="search"
          placeholder="Поиск по названию..."
          @input="debouncedSearch"
        />
      </div>
      
      <div v-if="isAdmin" class="merchant-banners-page__filter-group">
        <ShopSelectComponent
          v-model="shopFilter"
          placeholder="Все магазины"
          clearable
          @change="onFilterChange"
        />
      </div>
      
      <div class="merchant-banners-page__filter-group">
        <div class="merchant-banners-page__select-wrapper">
          <select v-model="statusFilter" @change="onFilterChange">
            <option :value="null">Все статусы</option>
            <option :value="true">Активные</option>
            <option :value="false">Неактивные</option>
          </select>
          <IconComponent name="arrow-drop-down-line" class="merchant-banners-page__select-icon" />
        </div>
      </div>
      
      <div class="merchant-banners-page__filter-group">
        <div class="merchant-banners-page__select-wrapper">
          <select v-model="linkTypeFilter" @change="onFilterChange">
            <option :value="null">Все типы перехода</option>
            <option value="NONE">Без перехода</option>
            <option value="PRODUCT">На товар</option>
            <option value="CATEGORY">На категорию</option>
          </select>
          <IconComponent name="arrow-drop-down-line" class="merchant-banners-page__select-icon" />
        </div>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="merchant-banners-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!banners.length" class="merchant-banners-page__empty">
      <IconComponent name="image-line" />
      <h3>Нет баннеров</h3>
      <p v-if="hasActiveFilters">Ничего не найдено по заданным фильтрам</p>
      <p v-else>Создайте первый баннер</p>
      <button v-if="hasActiveFilters" @click="resetFilters" class="btn btn--white btn--md">
        Сбросить фильтры
      </button>
      <button v-else @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать баннер</span>
      </button>
    </div>

    <!-- Список баннеров -->
    <section v-else class="merchant-banners-page__list">
      <MerchantBannerCard
        v-for="banner in banners"
        :key="banner.id"
        :data="banner"
        :showShop="isAdmin"
        :isAdmin="isAdmin"
        @edit="openEdit(banner)"
        @delete="confirmDelete(banner)"
        @toggle-active="toggleActive(banner)"
      />
    </section>

    <!-- Пагинация -->
    <div v-if="paginatorInfo.lastPage > 1" class="merchant-banners-page__pagination">
      <button 
        :disabled="page <= 1"
        @click="page--"
        class="btn btn--white btn--sm"
      >
        Назад
      </button>
      <span>{{ page }} из {{ paginatorInfo.lastPage }}</span>
      <button 
        :disabled="page >= paginatorInfo.lastPage"
        @click="page++"
        class="btn btn--white btn--sm"
      >
        Вперёд
      </button>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import MerchantBannerCard from "./components/MerchantBannerCard.vue";
import MERCHANT_BANNERS_PAGINATE from "@/graphql/queries/merchant_banners_paginate.graphql";
import MERCHANT_BANNER_UPDATE from "@/graphql/mutations/MerchantBannerUpdate.graphql";
import MERCHANT_BANNER_DELETE from "@/graphql/mutations/MerchantBannerDelete.graphql";
import { useAuthStore } from "@/stores/auth.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "MerchantBannersListPage",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantBannerCard,
  },
  data() {
    return {
      banners: [],
      loading: true,
      search: "",
      statusFilter: null,
      shopFilter: null,
      linkTypeFilter: null,
      page: 1,
      first: 20,
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
      },
      searchTimeout: null,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Баннеры");
    },
    breadcrumbs() {
      return [{ to: { name: "merchant-banners" }, title: this.pageTitle }];
    },
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles?.[0]?.name;
    },
    isAdmin() {
      return this.userRole === "admin";
    },
    userShopId() {
      return this.user?.shop_id || this.user?.shop?.id || this.user?.shops?.[0]?.shop_id || this.user?.shops?.[0]?.shop?.id;
    },
    hasActiveFilters() {
      return this.search || this.statusFilter !== null || this.shopFilter !== null || this.linkTypeFilter !== null;
    },
  },
  watch: {
    page() {
      this.get();
    },
  },
  mounted() {
    if (this.isAdmin) {
    }
    this.get();
  },
  methods: {
    get() {
      this.loading = true;
      
      const variables = {
        first: this.first,
        page: this.page,
        search: this.search || undefined,
        is_active: this.statusFilter,
        link_type: this.linkTypeFilter || undefined,
      };
      
      if (this.isAdmin && this.shopFilter) {
        variables.shop_id = this.shopFilter;
      } else if (!this.isAdmin && this.userShopId) {
        variables.shop_id = parseInt(this.userShopId);
      }
      
      this.apollo()
        .admin.query({
          query: MERCHANT_BANNERS_PAGINATE,
          variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.banners = data.merchantBanners?.data || [];
          this.paginatorInfo = data.merchantBanners?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching merchant banners:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    debouncedSearch() {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        this.page = 1;
        this.get();
      }, 300);
    },
    onFilterChange() {
      this.page = 1;
      this.get();
    },
    resetFilters() {
      this.search = "";
      this.statusFilter = null;
      this.shopFilter = null;
      this.linkTypeFilter = null;
      this.page = 1;
      this.get();
    },
    openCreate() {
      this.$router.push({ name: "merchant-banner-create" });
    },
    openEdit(banner) {
      this.$router.push({ name: "merchant-banner-edit", params: { id: banner.id } });
    },
    confirmDelete(banner) {
      if (confirm(`Вы уверены, что хотите удалить баннер "${banner.title}"?`)) {
        this.deleteBanner(banner);
      }
    },
    deleteBanner(banner) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_BANNER_DELETE,
          variables: { id: parseInt(banner.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting banner:", error);
          alert("Ошибка удаления баннера");
        });
    },
    toggleActive(banner) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_BANNER_UPDATE,
          variables: {
            id: parseInt(banner.id),
            is_active: !banner.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling banner:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.merchant-banners-page {
  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px
    
    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }
  
  &__actions {
    display flex
    gap 12px
  }
  
  &__filters {
    display flex
    align-items center
    margin-bottom 24px
    gap 16px
    flex-wrap wrap
  }
  
  &__search {
    display flex
    align-items center
    gap 8px
    padding 12px 16px
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    width 280px
    
    .icon {
      width 18px
      height 18px
      flex-shrink 0
      
      svg path {
        fill var(--dark-light)
      }
    }
    
    input {
      border none
      outline none
      width 100%
      font-size 0.875rem
      
      &::placeholder {
        color var(--dark-light)
      }
    }
  }
  
  &__filter-group {
    display flex
    gap 12px
  }
  
  &__select-wrapper {
    position relative
    
    select {
      appearance none
      padding 12px 40px 12px 16px
      border 1px solid var(--gray-dark)
      border-radius 8px
      background var(--white)
      font-size 0.875rem
      color var(--dark)
      cursor pointer
      min-width 160px
      
      &:focus {
        outline none
        border-color var(--pink)
      }
    }
  }
  
  &__select-icon {
    position absolute
    right 12px
    top 50%
    transform translateY(-50%)
    pointer-events none
    
    svg {
      width 20px
      height 20px
    }
  }
  
  &__loading {
    display flex
    justify-content center
    padding 48px
  }
  
  &__empty {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center
    
    > .icon {
      width 64px
      height 64px
      margin-bottom 16px
      
      svg path {
        fill var(--gray-light)
      }
    }
    
    h3 {
      font-size 1.25rem
      color var(--dark)
      margin-bottom 8px
    }
    
    p {
      font-size 0.875rem
      color var(--dark-light)
      margin-bottom 24px
    }
  }
  
  &__list {
    display grid
    grid-template-columns repeat(4, 1fr)
    gap 20px
    
    +below(1600px) {
      grid-template-columns repeat(3, 1fr)
    }
    
    +below(1200px) {
      grid-template-columns repeat(2, 1fr)
    }
    
    +below(768px) {
      grid-template-columns 1fr
    }
  }
  
  &__pagination {
    display flex
    justify-content center
    align-items center
    gap 16px
    margin-top 32px
    
    span {
      font-size 0.875rem
      color var(--dark)
    }
  }
}
</style>
