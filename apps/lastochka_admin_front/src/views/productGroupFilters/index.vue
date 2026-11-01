<template>
  <main class="page--main product-group-filters-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="product-group-filters-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="product-group-filters-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Создать фильтр</span>
        </button>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="product-group-filters-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!filters.length" class="product-group-filters-page__empty">
      <IconComponent name="grid-fill" />
      <h3>Нет фильтров</h3>
      <p>Создайте первый фильтр товарных групп</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать фильтр</span>
      </button>
    </div>

    <!-- Таблица фильтров -->
    <div v-else class="product-group-filters-page__table-wrapper">
      <table class="product-group-filters-page__table">
        <thead>
          <tr>
            <th>Название</th>
            <th>Позиция</th>
            <th>Кол-во значений</th>
            <th>Активность</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="filter in filters" :key="filter.id">
            <td>{{ filter.name }}</td>
            <td>{{ filter.position }}</td>
            <td>{{ filter.values ? filter.values.length : 0 }}</td>
            <td>
              <label class="product-group-filters-page__toggle">
                <input
                  type="checkbox"
                  :checked="filter.is_active"
                  @change="toggleActive(filter)"
                />
                <span class="product-group-filters-page__toggle-slider"></span>
                <span class="product-group-filters-page__toggle-text">{{ filter.is_active ? 'Активен' : 'Неактивен' }}</span>
              </label>
            </td>
            <td>
              <div class="product-group-filters-page__row-actions">
                <button class="btn btn--white btn--sm" @click="openEdit(filter)">
                  <IconComponent name="edit-line" />
                  <span>Редактировать</span>
                </button>
                <button class="btn btn--white btn--sm btn--danger" @click="confirmDelete(filter)">
                  <IconComponent name="delete-bin-line" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import PRODUCT_GROUP_FILTERS from "@/graphql/productGroupFilters/product_group_filters.graphql";
import PRODUCT_GROUP_FILTER_UPDATE from "@/graphql/productGroupFilters/ProductGroupFilterUpdate.graphql";
import PRODUCT_GROUP_FILTER_DELETE from "@/graphql/productGroupFilters/ProductGroupFilterDelete.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "ProductGroupFiltersListPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
  },
  data() {
    return {
      filters: [],
      loading: true,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Фильтры товарных групп");
    },
    breadcrumbs() {
      return [{ to: { name: "product-group-filters" }, title: this.pageTitle }];
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
          query: PRODUCT_GROUP_FILTERS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.filters = data.productGroupFilters || [];
        })
        .catch((error) => {
          console.error("Error fetching product group filters:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openCreate() {
      this.$router.push({ name: "product-group-filters-create" });
    },
    openEdit(filter) {
      this.$router.push({ name: "product-group-filters-edit", params: { id: filter.id } });
    },
    confirmDelete(filter) {
      if (confirm(`Удалить фильтр "${filter.name}"?`)) {
        this.deleteFilter(filter);
      }
    },
    deleteFilter(filter) {
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_GROUP_FILTER_DELETE,
          variables: { id: parseInt(filter.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting filter:", error);
          alert("Ошибка удаления фильтра");
        });
    },
    toggleActive(filter) {
      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_GROUP_FILTER_UPDATE,
          variables: {
            id: parseInt(filter.id),
            is_active: !filter.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling filter:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.product-group-filters-page {
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
    overflow-x auto
  }

  &__table {
    width 100%
    border-collapse collapse

    th, td {
      padding 16px 20px
      text-align left
      font-size 0.875rem
      border-bottom 1px solid var(--gray-dark)
    }

    th {
      font-weight 600
      color var(--dark)
      background var(--bg)
    }

    td {
      color var(--dark)
    }

    tbody tr:last-child td {
      border-bottom none
    }
  }

  &__toggle {
    display flex
    align-items center
    gap 12px
    cursor pointer

    input {
      display none
    }
  }

  &__toggle-slider {
    width 48px
    height 24px
    background var(--gray-dark)
    border-radius 24px
    position relative
    transition background 0.2s

    &::after {
      content ''
      position absolute
      width 20px
      height 20px
      background var(--white)
      border-radius 50%
      top 2px
      left 2px
      transition left 0.2s
    }

    input:checked + & {
      background var(--green)

      &::after {
        left 26px
      }
    }
  }

  &__toggle-text {
    font-size 0.875rem
    color var(--dark)
  }

  &__row-actions {
    display flex
    gap 8px
  }
}

.btn--danger {
  &:hover {
    border-color var(--red)
    color var(--red)
  }
}
</style>
