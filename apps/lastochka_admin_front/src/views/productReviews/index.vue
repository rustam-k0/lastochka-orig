<template>
  <div class="product-reviews-page">
    <!-- Заголовок -->
    <div class="product-reviews-page__header">
      <h1 class="product-reviews-page__title">{{ pageTitle }}</h1>
      <router-link
        v-if="isAdmin"
        :to="{ name: 'product-review-create' }"
        class="btn btn--md"
      >
        <IconComponent name="add-line" />
        <span>Создать отзыв</span>
      </router-link>
    </div>

    <!-- Фильтры -->
    <div class="product-reviews-page__filters">
      <div class="product-reviews-page__filter">
        <ShopSelectComponent
          v-model="shopFilter"
          placeholder="Все магазины"
          clearable
          @change="onShopFilterChange"
        />
      </div>

      <div class="product-reviews-page__filter">
        <SelectComponent
          :model-value="selectedProductFilter"
          @update:model-value="onProductFilterSelect"
          @update:search="onProductFilterSearch"
          :options="filterProducts"
          label-name="title"
          :loading="productsLoading"
          :disabled="!shopFilter"
          :placeholder="!shopFilter ? 'Сначала выберите магазин' : 'Все товары'"
          clearable
          small
        />
      </div>

      <div class="product-reviews-page__filter">
        <div class="product-reviews-page__select-wrapper">
          <select v-model="statusFilter" class="product-reviews-page__select">
            <option :value="null">Все статусы</option>
            <option :value="true">Опубликован</option>
            <option :value="false">Черновик</option>
          </select>
          <IconComponent
            name="arrow-drop-down-line"
            class="product-reviews-page__select-icon"
          />
        </div>
      </div>

      <div class="product-reviews-page__filter">
        <div class="product-reviews-page__select-wrapper">
          <!-- value="" + v-model.number: 6 статичных option стрингифицируются
               компилятором (порог 5+), :value="5"/:value="null" теряют _value —
               v-model получал строку "5" (GraphQL Int error), а null-опция
               не матчилась (пустой селект без плейсхолдера) -->
          <select v-model.number="ratingFilter" class="product-reviews-page__select">
            <option value="">Все оценки</option>
            <option :value="5">5 звёзд</option>
            <option :value="4">4 звезды</option>
            <option :value="3">3 звезды</option>
            <option :value="2">2 звезды</option>
            <option :value="1">1 звезда</option>
          </select>
          <IconComponent
            name="arrow-drop-down-line"
            class="product-reviews-page__select-icon"
          />
        </div>
      </div>

      <div
        class="product-reviews-page__filter product-reviews-page__filter--search"
      >
        <div class="product-reviews-page__search-wrapper">
          <IconComponent
            name="search-line"
            class="product-reviews-page__search-icon"
          />
          <input
            v-model="search"
            type="text"
            placeholder="Поиск..."
            class="product-reviews-page__search"
            @input="debouncedFetch"
          />
        </div>
      </div>

      <button
        v-if="hasActiveFilters"
        type="button"
        class="btn btn--white btn--sm"
        @click="resetFilters"
      >
        Сбросить
      </button>
    </div>

    <!-- Таблица -->
    <TableComponent
      class="product-reviews-page__table"
      :columns="columns"
      :rows="reviews"
      :loading="loading"
      :ordering="ordering"
      :total="paginatorInfo.total"
      :first="savedFilters.first"
      :page="page"
      pagination
      @update:ordering="updateOrdering"
      @update:page="updatePage"
      @update:count="handleFirstCount"
    >
      <template #col="{ col, row }">
        <router-link
          v-if="col.field === 'shop'"
          :to="{ name: 'product-review', params: { id: row.id } }"
          class="rows__left rows__elipsis"
          :title="row.shop?.title"
        >
          {{ row.shop?.title || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'product'"
          :to="{ name: 'product-review', params: { id: row.id } }"
          class="rows__left rows__elipsis"
          :title="row.product?.title"
        >
          {{ row.product?.title || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'rating'"
          :to="{ name: 'product-review', params: { id: row.id } }"
          class="rows__center"
        >
          <StarRating :modelValue="row.rating" readonly small />
        </router-link>

        <router-link
          v-if="col.field === 'author_name'"
          :to="{ name: 'product-review', params: { id: row.id } }"
          class="rows__left rows__elipsis"
        >
          {{ row.author_name || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'is_published'"
          :to="{ name: 'product-review', params: { id: row.id } }"
          class="rows__center"
        >
          <span
            class="product-reviews-page__status"
            :class="{
              'product-reviews-page__status--published': row.is_published,
            }"
          >
            {{ row.is_published ? "Опубликован" : "Черновик" }}
          </span>
        </router-link>

        <router-link
          v-if="col.field === 'created_at'"
          :to="{ name: 'product-review', params: { id: row.id } }"
          class="rows__center"
        >
          {{ formatDate(row.created_at) }}
        </router-link>

        <div v-if="col.field === 'actions'" class="rows__actions">
          <div class="product-reviews-page__dropdown">
            <button
              type="button"
              class="product-reviews-page__dropdown-trigger"
              @click.stop="toggleDropdown(row.id)"
            >
              <IconComponent name="more-2-fill" />
            </button>
            <div
              v-if="openDropdownId === row.id"
              class="product-reviews-page__dropdown-menu"
              v-click-outside="closeDropdown"
            >
              <button @click.stop="openView(row)">
                <IconComponent name="eye-line" />
                <span>Просмотр</span>
              </button>
              <button v-if="isAdmin" @click.stop="openEdit(row)">
                <IconComponent name="pencil-line" />
                <span>Редактировать</span>
              </button>
              <button v-if="isAdmin" @click.stop="togglePublish(row)">
                <IconComponent
                  :name="row.is_published ? 'eye-off-line' : 'check-line'"
                />
                <span>{{
                  row.is_published ? "Снять с публикации" : "Опубликовать"
                }}</span>
              </button>
              <button
                v-if="isAdmin"
                @click.stop="confirmDelete(row)"
                class="product-reviews-page__dropdown-delete"
              >
                <IconComponent name="delete-bin-line" />
                <span>Удалить</span>
              </button>
            </div>
          </div>
        </div>
      </template>
    </TableComponent>

    <!-- Пустое состояние -->
    <div
      v-if="!loading && reviews.length === 0"
      class="product-reviews-page__empty"
    >
      <IconComponent name="file-list-3-line" />
      <p>Отзывов пока нет</p>
    </div>

  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import TableComponent from "@/components/table/index.vue";
import StarRating from "@/views/merchantReviews/components/StarRating.vue";
import PRODUCT_REVIEWS_PAGINATE from "@/graphql/queries/product_reviews_paginate.graphql";
import PRODUCT_REVIEW_PUBLISH from "@/graphql/mutations/ProductReviewPublish.graphql";
import ENTITY_DELETE from "@/graphql/mutations/EntityDelete.graphql";
import { useFiltersStore } from "@/stores/pageFilters.js";
import PRODUCTS_PAGINATE from "@/graphql/queries/admin_product_paginate.graphql";
import SelectComponent from "@/components/inputs/select/input.vue";
import { useAuthStore } from "@/stores/auth.js";
import { debounce } from "lodash";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "ProductReviewsIndex",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    IconComponent,
    TableComponent,
    StarRating,
    SelectComponent,
  },
  directives: {
    "click-outside": {
      mounted(el, binding) {
        el._clickOutsideHandler = (event) => {
          if (!el.contains(event.target)) {
            binding.value();
          }
        };
        document.addEventListener("click", el._clickOutsideHandler);
      },
      unmounted(el) {
        document.removeEventListener("click", el._clickOutsideHandler);
      },
    },
  },
  data() {
    return {
      reviews: [],
      loading: false,
      page: 1,
      first: 25,
      search: "",
      shopFilter: null,
      productFilter: null,
      statusFilter: null,
      ratingFilter: "",
      ordering: [{ orderColumn: "created_at", orderBy: "DESC" }],
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
        perPage: 25,
      },
      filterProducts: [],
      selectedProductFilter: null,
      productsLoading: false,
      productSearchTimeout: null,
      openDropdownId: null,
      fetchSeq: 0,
      columns: [
        { field: "shop", title: "Магазин", sortable: true, show: true },
        { field: "product", title: "Товар", sortable: true, show: true },
        {
          field: "rating",
          title: "Оценка",
          sortable: true,
          show: true,
          width: "120px",
        },
        {
          field: "author_name",
          title: "Автор",
          sortable: false,
          width: "150px",
        },
        {
          field: "is_published",
          title: "Статус",
          sortable: true,
          show: true,
          width: "120px",
        },
        {
          field: "created_at",
          title: "Дата",
          sortable: true,
          show: true,
          width: "100px",
        },
        { field: "actions", title: "", sortable: false, width: "60px" },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Отзывы на товары");
    },
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...useFiltersStore().get(this.$route.name),
        },
      };
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
    hasActiveFilters() {
      return (
        this.shopFilter !== null ||
        this.productFilter !== null ||
        this.statusFilter !== null ||
        this.ratingFilter !== "" ||
        this.search !== ""
      );
    },
  },
  watch: {
    shopFilter() {
      this.page = 1;
      this.fetchReviews();
    },
    productFilter() {
      this.page = 1;
      this.fetchReviews();
    },
    statusFilter() {
      this.page = 1;
      this.fetchReviews();
    },
    ratingFilter() {
      this.page = 1;
      this.fetchReviews();
    },
  },
  created() {
    this.debouncedFetch = debounce(() => {
      this.page = 1;
      this.fetchReviews();
    }, 300);
  },
  mounted() {
    this.fetchReviews();
  },
  methods: {
    fetchReviews() {
      // Guard от гонки: при серии кликов по сортировке/фильтрам ответ
      // устаревшего запроса может прийти позже актуального и перезаписать
      // таблицу (строки и оценки «скачут»). Применяем только последний.
      const requestId = ++this.fetchSeq;
      this.loading = true;
      this.closeDropdown();

      const variables = {
        first: this.savedFilters.first,
        page: this.page,
        search: this.search || undefined,
        shop_id: this.shopFilter !== null ? this.shopFilter : undefined,
        product_id:
          this.productFilter !== null ? this.productFilter : undefined,
        is_published:
          this.statusFilter !== null ? this.statusFilter : undefined,
        rating: this.ratingFilter !== "" ? this.ratingFilter : undefined,
        ordering: this.ordering,
      };

      this.apollo()
        .admin.query({
          query: PRODUCT_REVIEWS_PAGINATE,
          variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (requestId !== this.fetchSeq) return;
          this.reviews = data.productReviews?.data || [];
          this.paginatorInfo =
            data.productReviews?.paginatorInfo || this.paginatorInfo;
        })
        .catch((error) => {
          if (requestId !== this.fetchSeq) return;
          console.error("Error fetching product reviews:", error);
          alert("Ошибка загрузки отзывов");
        })
        .finally(() => {
          if (requestId === this.fetchSeq) {
            this.loading = false;
          }
        });
    },
    fetchFilterProducts(shopId, search = "") {
      if (!shopId) {
        this.filterProducts = [];
        return;
      }

      this.productsLoading = true;
      this.apollo()
        .admin.query({
          query: PRODUCTS_PAGINATE,
          variables: {
            shop_id: parseInt(shopId),
            title: search || undefined,
            // Фильтр списка отзывов предлагает только товары, на которые
            // есть отзывы — выбор прочих дал бы заведомо пустую таблицу
            has_reviews: true,
            first: 50,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.filterProducts = (data.admin_products_paginate?.data || []).map((p) => ({
            id: parseInt(p.id),
            title: p.title,
          }));
        })
        .catch((error) => {
          console.error("Error fetching products:", error);
          this.filterProducts = [];
        })
        .finally(() => {
          this.productsLoading = false;
        });
    },
    onProductFilterSearch(search) {
      clearTimeout(this.productSearchTimeout);
      this.productSearchTimeout = setTimeout(() => {
        if (this.shopFilter) {
          this.fetchFilterProducts(this.shopFilter, search);
        }
      }, 300);
    },
    onProductFilterSelect(product) {
      this.selectedProductFilter = product;
      this.productFilter = product ? parseInt(product.id) : null;
    },
    onShopFilterChange() {
      this.productFilter = null;
      this.selectedProductFilter = null;
      this.filterProducts = [];
      if (this.shopFilter) {
        this.fetchFilterProducts(this.shopFilter);
      }
    },
    updatePage(newPage) {
      this.page = newPage;
      this.fetchReviews();
    },
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.fetchReviews();
    },
    updateOrdering(newOrdering) {
      this.ordering = newOrdering;
      this.fetchReviews();
    },
    resetFilters() {
      this.shopFilter = null;
      this.productFilter = null;
      this.selectedProductFilter = null;
      this.statusFilter = null;
      this.ratingFilter = "";
      this.search = "";
      this.filterProducts = [];
      this.page = 1;
      this.fetchReviews();
    },
    formatDate(dateString) {
      if (!dateString) return "—";
      const date = new Date(dateString);
      return date.toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "2-digit",
      });
    },
    toggleDropdown(id) {
      this.openDropdownId = this.openDropdownId === id ? null : id;
    },
    closeDropdown() {
      this.openDropdownId = null;
    },
    openView(row) {
      this.$router.push({ name: "product-review", params: { id: row.id } });
    },
    openEdit(row) {
      this.$router.push({
        name: "product-review",
        params: { id: row.id },
        query: { edit: "true" },
      });
    },
    togglePublish(row) {
      const newStatus = !row.is_published;

      this.apollo()
        .admin.mutate({
          mutation: PRODUCT_REVIEW_PUBLISH,
          variables: {
            id: row.id,
            is_published: newStatus,
          },
        })
        .then(() => {
          row.is_published = newStatus;
          this.closeDropdown();
        })
        .catch((error) => {
          console.error("Error publishing product review:", error);
          alert("Ошибка изменения статуса");
        });
    },
    confirmDelete(row) {
      this.closeDropdown();
      if (
        confirm(
          "Вы уверены, что хотите удалить этот отзыв? Это действие нельзя отменить."
        )
      ) {
        this.deleteReview(row);
      }
    },
    deleteReview(review) {
      this.apollo()
        .admin.mutate({
          mutation: ENTITY_DELETE,
          variables: {
            id: parseInt(review.id),
            table: "product_reviews",
          },
        })
        .then(() => {
          this.reviews = this.reviews.filter((r) => r.id !== review.id);
        })
        .catch((error) => {
          console.error("Error deleting product review:", error);
          alert("Ошибка удаления отзыва");
        });
    },
  },
};
</script>

<style lang="stylus">
.product-reviews-page {
  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px
  }

  &__title {
    font-size 1.5rem
    font-weight 600
    color var(--dark)
    margin 0
  }

  &__filters {
    display flex
    flex-wrap wrap
    gap 12px
    margin-bottom 24px
  }

  &__filter {
    &--search {
      flex 1
      min-width 200px
    }
  }

  &__select-wrapper {
    position relative
  }

  &__select {
    appearance none
    padding 10px 36px 10px 12px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem
    background var(--white)
    cursor pointer
    min-width 150px

    &:focus {
      outline none
      border-color var(--pink)
    }

    &:disabled {
      background var(--gray-3)
      cursor not-allowed
    }
  }

  &__select-icon {
    position absolute
    right 10px
    top 50%
    transform translateY(-50%)
    pointer-events none

    svg {
      width 20px
      height 20px
    }
  }

  &__search-wrapper {
    position relative
  }

  &__search {
    width 100%
    padding 10px 12px 10px 36px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.875rem

    &:focus {
      outline none
      border-color var(--pink)
    }
  }

  &__search-icon {
    position absolute
    left 10px
    top 50%
    transform translateY(-50%)

    svg {
      width 18px
      height 18px
      color var(--dark-light)
    }
  }

  &__status {
    display inline-block
    padding 4px 8px
    border-radius 4px
    font-size 0.75rem
    font-weight 500
    background var(--gray-3)
    color var(--dark-light)

    &--published {
      background var(--green-light, #e6f7e6)
      color var(--green, #28a745)
    }
  }

  &__dropdown {
    position relative
  }

  &__dropdown-trigger {
    display flex
    align-items center
    justify-content center
    width 32px
    height 32px
    border none
    background transparent
    border-radius 4px
    cursor pointer
    transition background 0.2s

    &:hover {
      background var(--gray-3)
    }

    svg {
      width 20px
      height 20px
    }
  }

  &__dropdown-menu {
    position absolute
    right 0
    top 100%
    z-index 10
    min-width 180px
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.1)
    overflow hidden

    button {
      display flex
      align-items center
      gap 8px
      width 100%
      padding 10px 12px
      border none
      background transparent
      font-size 0.875rem
      text-align left
      cursor pointer
      transition background 0.2s

      &:hover {
        background var(--gray-3)
      }

      svg {
        width 18px
        height 18px
      }
    }
  }

  &__dropdown-delete {
    color var(--red)

    &:hover {
      background var(--red-light, #fde8e8) !important
    }
  }

  &__empty {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px 24px
    color var(--dark-light)

    svg {
      width 48px
      height 48px
      margin-bottom 16px
      opacity 0.5
    }

    p {
      margin 0
      font-size 1rem
    }
  }
}
</style>
