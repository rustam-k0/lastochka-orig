<template>
  <main class="page--main merchant-sales-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-sales-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="merchant-sales-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Создать акцию</span>
        </button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="merchant-sales-page__filters">
      <div class="merchant-sales-page__search">
        <IconComponent name="search-line" />
        <input 
          type="text"
          v-model="search"
          placeholder="Поиск по названию..."
          @input="debouncedSearch"
        />
      </div>
      
      <div class="merchant-sales-page__filter-group">
        <ShopSelectComponent
          v-model="shopFilter"
          placeholder="Все магазины"
          clearable
          @change="onFilterChange"
        />
      </div>
      
      <div class="merchant-sales-page__filter-buttons">
        <button 
          v-for="filter in statusFilters" 
          :key="filter.value"
          class="merchant-sales-page__filter-btn"
          :class="{ 'merchant-sales-page__filter-btn--active': statusFilter === filter.value }"
          @click="statusFilter = filter.value"
        >
          {{ filter.label }}
        </button>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="merchant-sales-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!sales.length" class="merchant-sales-page__empty">
      <IconComponent name="price-tag-3-line" />
      <h3>Нет акций</h3>
      <p>Создайте первую акцию для магазинов</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать акцию</span>
      </button>
    </div>

    <!-- Список акций -->
    <section v-else class="merchant-sales-page__list">
      <MerchantSaleCard
        v-for="sale in sales"
        :key="sale.id"
        :data="sale"
        @edit="openEdit(sale)"
        @delete="confirmDelete(sale)"
        @toggle-active="toggleActive(sale)"
      />
    </section>

    <!-- Пагинация -->
    <div v-if="paginatorInfo.lastPage > 1" class="merchant-sales-page__pagination">
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
import MerchantSaleCard from "./components/MerchantSaleCard.vue";
import MERCHANT_SALES_PAGINATE from "@/graphql/queries/merchant_sales_paginate.graphql";
import MERCHANT_SALE_UPDATE from "@/graphql/mutations/MerchantSaleUpdate.graphql";
import MERCHANT_SALE_DELETE from "@/graphql/mutations/MerchantSaleDelete.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "MerchantSalesListPage",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantSaleCard,
  },
  data() {
    return {
      sales: [],
      loading: true,
      search: "",
      statusFilter: null,
      shopFilter: null,
      page: 1,
      first: 20,
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
      },
      searchTimeout: null,
      statusFilters: [
        { value: null, label: "Все" },
        { value: "UPCOMING", label: "Предстоящие" },
        { value: "ACTIVE", label: "Активные" },
        { value: "EXPIRED", label: "Завершённые" },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Акции магазинов");
    },
    breadcrumbs() {
      return [{ to: { name: "merchant-sales" }, title: this.pageTitle }];
    },
  },
  watch: {
    page() {
      this.get();
    },
    statusFilter() {
      this.page = 1;
      this.get();
    },
  },
  mounted() {
    this.get();
  },
  methods: {
    get() {
      this.loading = true;
      this.apollo()
        .admin.query({
          query: MERCHANT_SALES_PAGINATE,
          variables: {
            first: this.first,
            page: this.page,
            status: this.statusFilter || undefined,
            shop_id: this.shopFilter || undefined,
            search: this.search || undefined,
            ordering: [{ orderColumn: "starts_at", orderBy: "DESC" }],
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.sales = data.merchantSales?.data || [];
          this.paginatorInfo = data.merchantSales?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching merchant sales:", error);
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
    openCreate() {
      this.$router.push({ name: "merchant-sale-create" });
    },
    openEdit(sale) {
      this.$router.push({ name: "merchant-sale-edit", params: { id: sale.id } });
    },
    confirmDelete(sale) {
      if (confirm(`Удалить акцию "${sale.title}"?`)) {
        this.deleteSale(sale);
      }
    },
    deleteSale(sale) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_SALE_DELETE,
          variables: { id: parseInt(sale.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting sale:", error);
          alert("Ошибка удаления акции");
        });
    },
    toggleActive(sale) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_SALE_UPDATE,
          variables: {
            id: parseInt(sale.id),
            is_active: !sale.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling sale:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.merchant-sales-page {
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
    width 320px
    
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
      min-width 180px
      
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
  
  &__filter-buttons {
    display flex
    gap 8px
    margin-left auto
  }
  
  &__filter-btn {
    padding 10px 20px
    border 1px solid var(--gray-dark)
    border-radius 8px
    background var(--white)
    font-size 0.875rem
    color var(--dark)
    cursor pointer
    transition all 0.2s
    
    &:hover {
      border-color var(--pink)
    }
    
    &--active {
      background var(--pink)
      border-color var(--pink)
      color var(--white)
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
