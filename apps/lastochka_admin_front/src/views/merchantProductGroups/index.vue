<template>
  <main class="page--main merchant-product-groups-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-product-groups-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="merchant-product-groups-page__actions">
        <button
          @click="openPositionModal"
          class="btn btn--white btn--md"
          :disabled="isAdmin && !shopFilter"
          :title="isAdmin && !shopFilter ? 'Выберите магазин в фильтре' : null"
        >
          <IconComponent name="drag-move-line" />
          <span>Изменить позицию</span>
        </button>
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Создать группу</span>
        </button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="merchant-product-groups-page__filters">
      <div class="merchant-product-groups-page__search">
        <IconComponent name="search-line" />
        <input 
          type="text"
          v-model="search"
          placeholder="Поиск по названию..."
          @input="debouncedSearch"
        />
      </div>
      
      <div v-if="isAdmin" class="merchant-product-groups-page__filter-group">
        <ShopSelectComponent
          v-model="shopFilter"
          placeholder="Все магазины"
          clearable
          @change="onFilterChange"
        />
      </div>
      
      <div class="merchant-product-groups-page__filter-group">
        <div class="merchant-product-groups-page__select-wrapper">
          <select v-model="statusFilter" @change="onFilterChange">
            <option :value="null">Все статусы</option>
            <option :value="true">Активные</option>
            <option :value="false">Неактивные</option>
          </select>
          <IconComponent name="arrow-drop-down-line" class="merchant-product-groups-page__select-icon" />
        </div>
      </div>
      
      <div class="merchant-product-groups-page__filter-buttons">
        <button 
          v-for="filter in placementFilters" 
          :key="filter.key"
          class="merchant-product-groups-page__filter-btn"
          :class="{ 'merchant-product-groups-page__filter-btn--active': placementFilter === filter.key }"
          @click="setPlacementFilter(filter.key)"
        >
          {{ filter.icon }} {{ filter.label }}
        </button>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="merchant-product-groups-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!groups.length" class="merchant-product-groups-page__empty">
      <IconComponent name="grid-fill" />
      <h3>Нет товарных групп</h3>
      <p>Создайте первую товарную группу</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать группу</span>
      </button>
    </div>

    <!-- Список групп -->
    <section v-else class="merchant-product-groups-page__list">
      <MerchantProductGroupCard
        v-for="group in groups"
        :key="group.id"
        :data="group"
        :showShop="isAdmin"
        :isAdmin="isAdmin"
        @edit="openEdit(group)"
        @delete="confirmDelete(group)"
        @toggle-active="toggleActive(group)"
      />
    </section>

    <!-- Пагинация -->
    <div v-if="paginatorInfo.lastPage > 1" class="merchant-product-groups-page__pagination">
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
import MerchantProductGroupCard from "./components/MerchantProductGroupCard.vue";
import MERCHANT_PRODUCT_GROUPS_PAGINATE from "@/graphql/queries/merchant_product_groups_paginate.graphql";
import MERCHANT_PRODUCT_GROUP_UPDATE from "@/graphql/mutations/MerchantProductGroupUpdate.graphql";
import MERCHANT_PRODUCT_GROUP_DELETE from "@/graphql/mutations/MerchantProductGroupDelete.graphql";
import { useAuthStore } from "@/stores/auth.js";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import { shallowRef } from "vue";
import ChangePositionModal from "./modals/ChangePositionModal.vue";
import { useDefaultStore } from "@/stores/default.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "MerchantProductGroupsListPage",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    MerchantProductGroupCard,
  },
  data() {
    return {
      groups: [],
      loading: true,
      search: "",
      statusFilter: null,
      shopFilter: null,
      placementFilter: null,
      page: 1,
      first: 20,
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
      },
      searchTimeout: null,
      allPlacementFilters: [
        { key: null, label: "Все", icon: "", adminOnly: false },
        { key: "show_on_main", label: "Главная", icon: "🏠", adminOnly: true },
        { key: "show_on_merchant_page", label: "Магазин", icon: "🏪", adminOnly: false },
        { key: "show_in_merchant_catalog", label: "Каталог", icon: "📂", adminOnly: false },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Товарные группы");
    },
    breadcrumbs() {
      return [{ to: { name: "merchant-product-groups" }, title: this.pageTitle }];
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
      return this.user?.shop_id || this.user?.shop?.id;
    },
    checkboxStore() {
      return useCheckboxConfigStore();
    },
    placementFilters() {
      return this.allPlacementFilters.filter(f => {
        if (f.adminOnly && !this.isAdmin) return false;
        if (f.key && !this.checkboxStore.isEnabled('MerchantProductGroup', f.key)) return false;
        return true;
      });
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
        ordering: [{ orderColumn: "sort_order", orderBy: "ASC" }],
      };
      
      if (this.isAdmin && this.shopFilter) {
        variables.shop_id = this.shopFilter;
      } else if (!this.isAdmin && this.userShopId) {
        variables.shop_id = parseInt(this.userShopId);
      }
      
      if (this.placementFilter === "show_on_main") {
        variables.show_on_main = true;
      } else if (this.placementFilter === "show_on_merchant_page") {
        variables.show_on_merchant_page = true;
      } else if (this.placementFilter === "show_in_merchant_catalog") {
        variables.show_in_merchant_catalog = true;
      }
      
      this.apollo()
        .admin.query({
          query: MERCHANT_PRODUCT_GROUPS_PAGINATE,
          variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.groups = data.merchantProductGroups?.data || [];
          this.paginatorInfo = data.merchantProductGroups?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching merchant product groups:", error);
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
    setPlacementFilter(key) {
      this.placementFilter = key;
      this.page = 1;
      this.get();
    },
    openCreate() {
      this.$router.push({ name: "merchant-product-groups-create" });
    },
    openPositionModal() {
      const rawShopId = this.isAdmin ? this.shopFilter : this.userShopId;
      const targetShopId = rawShopId ? parseInt(rawShopId) : null;

      useDefaultStore().modals.push({
        component: shallowRef(ChangePositionModal),
        options: {
          shopId: targetShopId,
          callback: this.get,
        },
      });
    },
    openEdit(group) {
      this.$router.push({ name: "merchant-product-groups-edit", params: { id: group.id } });
    },
    confirmDelete(group) {
      if (confirm(`Удалить группу "${group.title}"?`)) {
        this.deleteGroup(group);
      }
    },
    deleteGroup(group) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PRODUCT_GROUP_DELETE,
          variables: { id: parseInt(group.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting group:", error);
          alert("Ошибка удаления группы");
        });
    },
    toggleActive(group) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PRODUCT_GROUP_UPDATE,
          variables: {
            id: parseInt(group.id),
            is_active: !group.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling group:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.merchant-product-groups-page {
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
  
  &__filter-buttons {
    display flex
    gap 8px
    margin-left auto
  }
  
  &__filter-btn {
    padding 10px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    background var(--white)
    font-size 0.813rem
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
