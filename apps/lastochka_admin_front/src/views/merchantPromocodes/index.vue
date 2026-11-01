<template>
  <main class="page--main merchant-promocodes-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-promocodes-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="merchant-promocodes-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <span>Создать промокод</span>
        </button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="merchant-promocodes-page__filters">
      <div class="merchant-promocodes-page__search">
        <IconComponent name="search-line" />
        <input 
          type="text"
          v-model="search"
          placeholder="Поиск по названию или коду..."
          @input="debouncedSearch"
        />
      </div>
      
      <div v-if="isAdmin" class="merchant-promocodes-page__filter-group">
        <ShopSelectComponent
          v-model="shopFilter"
          placeholder="Все магазины"
          clearable
          @change="onFilterChange"
        />
      </div>
      
      <div class="merchant-promocodes-page__filter-buttons">
        <button 
          v-for="filter in statusFilters" 
          :key="filter.value"
          class="merchant-promocodes-page__filter-btn"
          :class="{ 'merchant-promocodes-page__filter-btn--active': statusFilter === filter.value }"
          @click="statusFilter = filter.value"
        >
          {{ filter.label }}
        </button>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="merchant-promocodes-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!promocodes.length" class="merchant-promocodes-page__empty">
      <IconComponent name="coupon-3-line" />
      <h3>Нет промокодов</h3>
      <p>Создайте первый промокод для магазинов</p>
      <button @click="openCreate" class="btn btn--md">
        <span>Создать промокод</span>
      </button>
    </div>

    <!-- Таблица промокодов -->
    <div v-else class="merchant-promocodes-page__table-wrapper">
      <table class="merchant-promocodes-page__table">
        <thead>
          <tr>
            <th class="merchant-promocodes-page__th--num">#</th>
            <th v-if="isAdmin" @click="toggleSort('shop_id')" class="merchant-promocodes-page__th--sortable">
              Магазин
              <IconComponent v-if="ordering.orderColumn === 'shop_id'" :name="ordering.orderBy === 'ASC' ? 'arrow-up-s-line' : 'arrow-down-s-line'" />
            </th>
            <th @click="toggleSort('title')" class="merchant-promocodes-page__th--sortable">
              Название
              <IconComponent v-if="ordering.orderColumn === 'title'" :name="ordering.orderBy === 'ASC' ? 'arrow-up-s-line' : 'arrow-down-s-line'" />
            </th>
            <th @click="toggleSort('code')" class="merchant-promocodes-page__th--sortable">
              Код
              <IconComponent v-if="ordering.orderColumn === 'code'" :name="ordering.orderBy === 'ASC' ? 'arrow-up-s-line' : 'arrow-down-s-line'" />
            </th>
            <th @click="toggleSort('discount_percent')" class="merchant-promocodes-page__th--sortable">
              Скидка
              <IconComponent v-if="ordering.orderColumn === 'discount_percent'" :name="ordering.orderBy === 'ASC' ? 'arrow-up-s-line' : 'arrow-down-s-line'" />
            </th>
            <th @click="toggleSort('valid_from')" class="merchant-promocodes-page__th--sortable">
              Период
              <IconComponent v-if="ordering.orderColumn === 'valid_from'" :name="ordering.orderBy === 'ASC' ? 'arrow-up-s-line' : 'arrow-down-s-line'" />
            </th>
            <th>Статус</th>
            <th class="merchant-promocodes-page__th--actions">Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(promocode, index) in promocodes" :key="promocode.id">
            <td class="merchant-promocodes-page__td--num">{{ (page - 1) * first + index + 1 }}</td>
            <td v-if="isAdmin">
              <span
                v-if="promocode.scope === 'platform'"
                class="merchant-promocodes-page__badge merchant-promocodes-page__badge--platform"
              >
                Вся платформа
              </span>
              <template v-else>{{ promocode.shop?.title || '—' }}</template>
            </td>
            <td>{{ promocode.title }}</td>
            <td class="merchant-promocodes-page__td--code">{{ promocode.code }}</td>
            <td>
              <template v-if="promocode.discount_type === 'fixed'">
                {{ promocode.discount_value }} ₽
              </template>
              <template v-else>
                {{ promocode.discount_value || promocode.discount_percent }}%
              </template>
            </td>
            <td>{{ formatPeriod(promocode.valid_from, promocode.valid_to) }}</td>
            <td>
              <PromocodeStatusBadge :status="promocode.status" />
            </td>
            <td class="merchant-promocodes-page__td--actions">
              <div class="merchant-promocodes-page__actions-dropdown">
                <button 
                  class="merchant-promocodes-page__actions-btn"
                  @click.stop="toggleDropdown(promocode.id)"
                >
                  <IconComponent name="more-2-line" />
                </button>
                <div 
                  v-if="openDropdownId === promocode.id" 
                  class="merchant-promocodes-page__dropdown-menu"
                >
                  <button @click="openEdit(promocode)">
                    <IconComponent name="edit-line" />
                    <span>Редактировать</span>
                  </button>
                  <button @click="toggleActive(promocode)">
                    <IconComponent :name="promocode.is_active ? 'toggle-line' : 'toggle-fill'" />
                    <span>{{ promocode.is_active ? 'Деактивировать' : 'Активировать' }}</span>
                  </button>
                  <button @click="confirmDelete(promocode)" class="merchant-promocodes-page__dropdown-danger">
                    <IconComponent name="delete-bin-line" />
                    <span>Удалить</span>
                  </button>
                </div>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Пагинация -->
    <div v-if="paginatorInfo.lastPage > 1" class="merchant-promocodes-page__pagination">
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
import PromocodeStatusBadge from "./components/PromocodeStatusBadge.vue";
import MERCHANT_PROMOCODES_PAGINATE from "@/graphql/queries/merchant_promocodes_paginate.graphql";
import MERCHANT_PROMOCODE_TOGGLE from "@/graphql/mutations/MerchantPromocodeToggle.graphql";
import MERCHANT_PROMOCODE_DELETE from "@/graphql/mutations/MerchantPromocodeDelete.graphql";
import { useAuthStore } from "@/stores/auth.js";
import dayjs from "dayjs";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "MerchantPromocodesListPage",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    PromocodeStatusBadge,
  },
  data() {
    return {
      promocodes: [],
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
      ordering: {
        orderColumn: "created_at",
        orderBy: "DESC",
      },
      searchTimeout: null,
      openDropdownId: null,
      statusFilters: [
        { value: null, label: "Все" },
        { value: "active", label: "Активные" },
        { value: "scheduled", label: "Запланированные" },
        { value: "expired", label: "Истекшие" },
        { value: "inactive", label: "Неактивные" },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Промокоды");
    },
    breadcrumbs() {
      return [{ to: { name: "merchant-promocodes" }, title: this.pageTitle }];
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
    if (this.isAdmin) {
    }
    this.get();
    document.addEventListener("click", this.handleOutsideClick);
  },
  beforeUnmount() {
    document.removeEventListener("click", this.handleOutsideClick);
  },
  methods: {
    get() {
      this.loading = true;
      this.apollo()
        .admin.query({
          query: MERCHANT_PROMOCODES_PAGINATE,
          variables: {
            first: this.first,
            page: this.page,
            status: this.statusFilter || undefined,
            shop_id: this.shopFilter ? parseInt(this.shopFilter) : undefined,
            search: this.search || undefined,
            ordering: [this.ordering],
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.promocodes = data.merchantPromocodes?.data || [];
          this.paginatorInfo = data.merchantPromocodes?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching merchant promocodes:", error);
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
    toggleSort(column) {
      if (this.ordering.orderColumn === column) {
        this.ordering.orderBy = this.ordering.orderBy === "ASC" ? "DESC" : "ASC";
      } else {
        this.ordering.orderColumn = column;
        this.ordering.orderBy = "ASC";
      }
      this.page = 1;
      this.get();
    },
    formatPeriod(from, to) {
      if (!from || !to) return "—";
      return `${dayjs(from).format("DD.MM.YY")} — ${dayjs(to).format("DD.MM.YY")}`;
    },
    toggleDropdown(id) {
      if (this.openDropdownId === id) {
        this.openDropdownId = null;
      } else {
        this.openDropdownId = id;
      }
    },
    closeDropdown() {
      this.openDropdownId = null;
    },
    handleOutsideClick(event) {
      const dropdown = event.target.closest('.merchant-promocodes-page__actions-dropdown');
      if (!dropdown) {
        this.openDropdownId = null;
      }
    },
    openCreate() {
      this.$router.push({ name: "merchant-promocode-create" });
    },
    openEdit(promocode) {
      this.$router.push({ name: "merchant-promocode-edit", params: { id: promocode.id } });
    },
    confirmDelete(promocode) {
      if (confirm(`Удалить промокод "${promocode.code}"?`)) {
        this.deletePromocode(promocode);
      }
    },
    deletePromocode(promocode) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PROMOCODE_DELETE,
          variables: { id: parseInt(promocode.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting promocode:", error);
          alert("Ошибка удаления промокода");
        });
    },
    toggleActive(promocode) {
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_PROMOCODE_TOGGLE,
          variables: {
            id: parseInt(promocode.id),
            is_active: !promocode.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling promocode:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.merchant-promocodes-page {
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

  .btn.btn--md {
    padding 0 16px
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
    
    .icon {
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
  
  &__table-wrapper {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    overflow visible
  }
  
  &__table {
    width 100%
    border-collapse collapse
    
    th, td {
      padding 16px
      text-align left
      border-bottom 1px solid var(--gray-dark)
    }
    
    th {
      font-weight 600
      font-size 0.75rem
      color var(--dark-light)
      text-transform uppercase
      letter-spacing 0.5px
      background var(--gray-3)
    }
    
    td {
      font-size 0.875rem
      color var(--dark)
    }
    
    tbody tr:last-child td {
      border-bottom none
    }
    
    tbody tr:hover {
      background var(--gray-3)
    }
  }
  
  &__th--num {
    width 60px
  }
  
  &__th--sortable {
    cursor pointer
    user-select none
    
    .icon {
      width 14px
      height 14px
      margin-left 4px
      vertical-align middle
    }
    
    &:hover {
      color var(--pink)
    }
  }
  
  &__th--actions {
    width 80px
    text-align center
  }
  
  &__td--num {
    color var(--dark-light)
  }
  
  &__td--code {
    font-family monospace
    font-weight 600
    color var(--pink)
  }

  &__badge {
    display inline-block
    padding 4px 10px
    border-radius 12px
    font-size 0.75rem
    font-weight 600
    letter-spacing 0.2px

    &--platform {
      background rgba(236, 72, 153, 0.12)
      color var(--pink)
      border 1px solid rgba(236, 72, 153, 0.3)
    }
  }
  
  &__td--actions {
    text-align center
    position relative
  }
  
  &__actions-dropdown {
    position relative
    display inline-block
  }
  
  &__actions-btn {
    display flex
    align-items center
    justify-content center
    width 36px
    height 36px
    border none
    background transparent
    border-radius 8px
    cursor pointer
    transition background 0.2s
    
    &:hover {
      background var(--gray-3)
    }
    
    .icon {
      width 20px
      height 20px
      
      svg path {
        fill var(--dark)
      }
    }
  }
  
  &__dropdown-menu {
    position absolute
    top 100%
    right 0
    z-index 100
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.1)
    min-width 180px
    overflow hidden
    
    button {
      display flex
      align-items center
      gap 8px
      width 100%
      padding 12px 16px
      border none
      background none
      font-size 0.875rem
      color var(--dark)
      cursor pointer
      transition background 0.2s
      text-align left
      
      &:hover {
        background var(--gray-3)
      }
      
      .icon {
        width 16px
        height 16px
        
        svg path {
          fill var(--dark)
        }
      }
    }
  }
  
  &__dropdown-danger {
    color var(--red) !important
    
    .icon svg path {
      fill var(--red) !important
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
