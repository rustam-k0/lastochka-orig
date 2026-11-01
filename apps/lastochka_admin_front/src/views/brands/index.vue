<template>
  <main class="page--main brands-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="brands-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="brands-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Добавить бренд</span>
        </button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="brands-page__filters">
      <div class="brands-page__search">
        <IconComponent name="search-line" />
        <input
          type="text"
          v-model="search"
          placeholder="Поиск по названию..."
          @input="debouncedSearch"
        />
      </div>
      <div class="brands-page__filter-buttons">
        <button
          v-for="filter in statusFilters"
          :key="filter.value"
          class="brands-page__filter-btn"
          :class="{ 'brands-page__filter-btn--active': statusFilter === filter.value }"
          @click="statusFilter = filter.value"
        >
          {{ filter.label }}
        </button>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="brands-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!brands.length" class="brands-page__empty">
      <IconComponent name="price-tag-3-line" />
      <h3>Нет брендов</h3>
      <p>Создайте первый бренд для отображения в каталоге</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать бренд</span>
      </button>
    </div>

    <!-- Таблица брендов -->
    <div v-else class="brands-page__table-wrapper">
      <table class="brands-page__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Логотип</th>
            <th>Название</th>
            <th>Активность</th>
            <th>Позиция</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="brand in brands" :key="brand.id">
            <td>{{ brand.id }}</td>
            <td>
              <div class="brands-page__logo">
                <img
                  v-if="brand.logoImage"
                  :src="mediaEndpoint + brand.logoImage.path"
                  :alt="brand.logoImage.alt || brand.title"
                />
                <div v-else class="brands-page__logo-placeholder">
                  <IconComponent name="image-line" />
                </div>
              </div>
            </td>
            <td>{{ brand.title }}</td>
            <td>
              <label class="brands-page__toggle" @click.prevent="toggleActive(brand)">
                <input type="checkbox" :checked="brand.is_active" />
                <span class="brands-page__toggle-slider"></span>
              </label>
            </td>
            <td>{{ brand.position }}</td>
            <td>
              <div class="brands-page__row-actions">
                <button class="brands-page__action-btn" @click="openEdit(brand)" title="Редактировать">
                  <IconComponent name="pencil-line" />
                </button>
                <button class="brands-page__action-btn brands-page__action-btn--delete" @click="confirmDelete(brand)" title="Удалить">
                  <IconComponent name="delete-bin-line" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Пагинация -->
    <div v-if="paginatorInfo.lastPage > 1" class="brands-page__pagination">
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
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import BRANDS_PAGINATE from "@/graphql/queries/admin_brands_paginate.graphql";
import BRAND_DELETE from "@/graphql/mutations/BrandDelete.graphql";
import BRAND_UPDATE from "@/graphql/mutations/BrandUpdate.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "BrandsListPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
  },
  data() {
    return {
      brands: [],
      loading: true,
      search: "",
      statusFilter: "all",
      page: 1,
      first: 20,
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
      },
      searchTimeout: null,
      statusFilters: [
        { value: "all", label: "Все" },
        { value: "active", label: "Активные" },
        { value: "inactive", label: "Неактивные" },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Бренды");
    },
    breadcrumbs() {
      return [{ to: { name: "brands" }, title: this.pageTitle }];
    },
    isActiveFilter() {
      if (this.statusFilter === "active") return true;
      if (this.statusFilter === "inactive") return false;
      return undefined;
    },
    mediaEndpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
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
          query: BRANDS_PAGINATE,
          variables: {
            first: this.first,
            page: this.page,
            is_active: this.isActiveFilter,
            search: this.search || undefined,
            ordering: [{ orderColumn: "position", orderBy: "ASC" }],
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.brands = data.admin_brands_paginate?.data || [];
          this.paginatorInfo = data.admin_brands_paginate?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching brands:", error);
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
    openCreate() {
      this.$router.push({ name: "brand-create" });
    },
    openEdit(brand) {
      this.$router.push({ name: "brand-edit", params: { id: brand.id } });
    },
    confirmDelete(brand) {
      if (confirm(`Удалить бренд "${brand.title}"?`)) {
        this.deleteBrand(brand);
      }
    },
    deleteBrand(brand) {
      this.apollo()
        .admin.mutate({
          mutation: BRAND_DELETE,
          variables: { id: parseInt(brand.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting brand:", error);
          alert("Ошибка удаления бренда");
        });
    },
    toggleActive(brand) {
      this.apollo()
        .admin.mutate({
          mutation: BRAND_UPDATE,
          variables: {
            id: parseInt(brand.id),
            is_active: !brand.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling brand:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.brands-page {
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
    justify-content space-between
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

  &__filter-buttons {
    display flex
    gap 8px
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

  &__table-wrapper {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    overflow hidden
  }

  &__table {
    width 100%
    border-collapse collapse

    th, td {
      padding 16px
      text-align left
      font-size 0.875rem
      border-bottom 1px solid var(--gray-dark)
    }

    th {
      font-weight 600
      color var(--dark-light)
      background var(--gray-3)
    }

    td {
      color var(--dark)
    }

    tbody tr {
      transition background 0.2s

      &:hover {
        background var(--gray-3)
      }

      &:last-child td {
        border-bottom none
      }
    }
  }

  &__logo {
    width 48px
    height 48px
    border-radius 8px
    overflow hidden
    border 1px solid var(--gray-dark)
    display flex
    align-items center
    justify-content center
    background var(--white)

    img {
      width 100%
      height 100%
      object-fit contain
    }
  }

  &__logo-placeholder {
    display flex
    align-items center
    justify-content center
    width 100%
    height 100%
    background var(--gray-3)

    .icon {
      width 24px
      height 24px

      svg path {
        fill var(--gray-light)
      }
    }
  }

  &__toggle {
    display inline-flex
    cursor pointer

    input {
      display none
    }
  }

  &__toggle-slider {
    width 40px
    height 20px
    background var(--gray-dark)
    border-radius 20px
    position relative
    transition background 0.2s

    &::after {
      content ''
      position absolute
      width 16px
      height 16px
      background var(--white)
      border-radius 50%
      top 2px
      left 2px
      transition left 0.2s
    }

    input:checked + & {
      background var(--green)

      &::after {
        left 22px
      }
    }
  }

  &__row-actions {
    display flex
    gap 8px
  }

  &__action-btn {
    display flex
    align-items center
    justify-content center
    width 36px
    height 36px
    border-radius 8px
    border 1px solid var(--gray-dark)
    background var(--white)
    cursor pointer
    transition all 0.2s

    .icon {
      width 18px
      height 18px

      svg path {
        fill var(--dark)
      }
    }

    &:hover {
      border-color var(--pink)

      .icon svg path {
        fill var(--pink)
      }
    }

    &--delete {
      &:hover {
        border-color var(--red)

        .icon svg path {
          fill var(--red)
        }
      }
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
