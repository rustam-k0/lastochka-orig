<template>
  <div class="order-reviews-page">
    <!-- Заголовок -->
    <div class="order-reviews-page__header">
      <h1 class="order-reviews-page__title">{{ pageTitle }}</h1>
      <div style="width: 140px"></div>
      <!-- Заглушка для баланса -->
    </div>

    <!-- Фильтры -->
    <div class="order-reviews-page__filters">
      <div class="order-reviews-page__filter">
        <div class="order-reviews-page__select-wrapper">
          <select
            v-model.number="ratingFilter"
            class="order-reviews-page__select"
          >
            <option value="">Все оценки</option>
            <option :value="5">5 звёзд</option>
            <option :value="4">4 звезды</option>
            <option :value="3">3 звезды</option>
            <option :value="2">2 звезды</option>
            <option :value="1">1 звезда</option>
          </select>
          <IconComponent
            name="arrow-drop-down-line"
            class="order-reviews-page__select-icon"
          />
        </div>
      </div>

      <div class="order-reviews-page__filter">
        <div class="order-reviews-page__select-wrapper">
          <select v-model="errorTypeFilter" class="order-reviews-page__select">
            <option value="">Все отзывы</option>
            <option
              v-for="type in errorTypes"
              :key="type.value"
              :value="type.value"
            >
              {{ type.label }}
            </option>
          </select>
          <IconComponent
            name="arrow-drop-down-line"
            class="order-reviews-page__select-icon"
          />
        </div>
      </div>

      <div
        class="order-reviews-page__filter order-reviews-page__filter--search"
      >
        <div class="order-reviews-page__search-wrapper">
          <IconComponent
            name="search-line"
            class="order-reviews-page__search-icon"
          />
          <input
            v-model="search"
            type="text"
            placeholder="Поиск..."
            class="order-reviews-page__search"
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
      class="order-reviews-page__table"
      :columns="columns"
      :rows="reviews"
      :loading="loading"
      :ordering="ordering"
      :total="paginatorInfo.total"
      :first="savedFilters.first"
      :page="page"
      pagination
      @update:ordering="updateOrdering"
      @update:count="handleFirstCount"
      @update:page="updatePage"
    >
      <template #col="{ col, row }">
        <router-link
          v-if="col.field === 'order_id'"
          :to="{ name: 'order-review', params: { id: row.id } }"
          class="rows__left"
        >
          № {{ row.order_number || row.order_id }}
        </router-link>

        <router-link
          v-if="col.field === 'rating'"
          :to="{ name: 'order-review', params: { id: row.id } }"
          class="rows__center"
        >
          <StarRating :modelValue="row.rating" readonly small />
        </router-link>

        <router-link
          v-if="col.field === 'description'"
          :to="{ name: 'order-review', params: { id: row.id } }"
          class="rows__left rows__elipsis"
          :title="row.description"
        >
          {{ row.description || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'author_name'"
          :to="{ name: 'order-review', params: { id: row.id } }"
          class="rows__left rows__elipsis"
        >
          {{ row.author_name || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'error_type'"
          :to="{ name: 'order-review', params: { id: row.id } }"
          class="rows__left"
        >
          {{ row.error_type_label || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'created_at'"
          :to="{ name: 'order-review', params: { id: row.id } }"
          class="rows__center"
        >
          {{ formatDate(row.created_at) }}
        </router-link>

        <div v-if="col.field === 'actions'" class="rows__actions">
          <div class="order-reviews-page__dropdown">
            <button
              type="button"
              class="order-reviews-page__dropdown-trigger"
              @click.stop="toggleDropdown(row.id)"
            >
              <IconComponent name="more-2-fill" />
            </button>
            <div
              v-if="openDropdownId === row.id"
              class="order-reviews-page__dropdown-menu"
              v-click-outside="closeDropdown"
            >
              <button @click.stop="openView(row)">
                <IconComponent name="eye-line" />
                <span>Просмотр</span>
              </button>
            </div>
          </div>
        </div>
      </template>
    </TableComponent>

    <!-- Пустое состояние -->
    <div
      v-if="!loading && reviews.length === 0"
      class="order-reviews-page__empty"
    >
      <IconComponent name="file-list-3-line" />
      <p>Отзывов пока нет</p>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import TableComponent from "@/components/table/index.vue";
import StarRating from "@/views/merchantReviews/components/StarRating.vue";
import { useFiltersStore } from "@/stores/pageFilters.js";
import ORDER_REVIEWS_PAGINATE from "@/graphql/queries/order_reviews_paginate.graphql";
import ORDER_REVIEW_ERROR_TYPES from "@/graphql/queries/order_review_error_types.graphql";
import { useAuthStore } from "@/stores/auth.js";
import { debounce } from "lodash";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "OrderReviewsIndex",
  inject: ["apollo"],
  components: {
    IconComponent,
    TableComponent,
    StarRating,
  },
  directives: {
    "click-outside": {
      mounted(el, binding) {
        el.clickOutsideEvent = (event) => {
          if (!(el === event.target || el.contains(event.target))) {
            binding.value(event);
          }
        };
        document.body.addEventListener("click", el.clickOutsideEvent);
      },
      beforeUnmount(el) {
        document.body.removeEventListener("click", el.clickOutsideEvent);
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
      ratingFilter: "",
      errorTypeFilter: "",
      errorTypes: [],
      fetchSeq: 0,
      ordering: [{ orderColumn: "created_at", orderBy: "DESC" }],
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
        perPage: 25,
      },
      openDropdownId: null,
      columns: [
        {
          field: "order_id",
          title: "Заказ",
          sortable: true,
          show: true,
          width: "120px",
        },
        {
          field: "rating",
          title: "Оценка",
          sortable: true,
          show: true,
          width: "120px",
        },
        { field: "description", title: "Отзыв", sortable: false },
        {
          field: "author_name",
          title: "Автор",
          sortable: false,
          width: "200px",
        },
        {
          field: "error_type",
          title: "Ошибка сборки",
          sortable: false,
          width: "140px",
        },
        {
          field: "created_at",
          title: "Дата",
          sortable: true,
          show: true,
          width: "140px",
        },
        { field: "actions", title: "", sortable: false, width: "60px" },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Отзывы на доставку");
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
        this.ratingFilter !== "" ||
        this.errorTypeFilter !== "" ||
        this.search !== ""
      );
    },
  },
  watch: {
    ratingFilter() {
      this.page = 1;
      this.fetchReviews();
    },
    errorTypeFilter() {
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
    this.fetchErrorTypes();
  },
  methods: {
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.fetchReviews();
    },

    fetchReviews() {
      // Guard от гонки: применяем только ответ последнего запроса,
      // устаревший ответ не должен перезаписывать таблицу
      const requestId = ++this.fetchSeq;
      this.loading = true;
      this.closeDropdown();

      const variables = {
        first: this.savedFilters.first,
        page: this.page,
        search: this.search || undefined,
        rating: this.ratingFilter !== "" ? this.ratingFilter : undefined,
        error_type:
          this.errorTypeFilter !== "" ? this.errorTypeFilter : undefined,
        ordering: this.ordering,
      };

      this.apollo()
        .admin.query({
          query: ORDER_REVIEWS_PAGINATE,
          variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (requestId !== this.fetchSeq) return;
          this.reviews = data.orderReviews?.data || [];
          this.paginatorInfo =
            data.orderReviews?.paginatorInfo || this.paginatorInfo;
        })
        .catch((error) => {
          if (requestId !== this.fetchSeq) return;
          console.error("Error fetching order reviews:", error);
          alert("Ошибка загрузки отзывов");
        })
        .finally(() => {
          if (requestId === this.fetchSeq) {
            this.loading = false;
          }
        });
    },
    fetchErrorTypes() {
      this.apollo()
        .admin.query({ query: ORDER_REVIEW_ERROR_TYPES })
        .then(({ data }) => {
          this.errorTypes = data.orderReviewErrorTypes || [];
        })
        .catch((error) => {
          console.error("Error fetching order review error types:", error);
        });
    },
    updatePage(newPage) {
      this.page = newPage;
      this.fetchReviews();
    },
    updateOrdering(newOrdering) {
      this.ordering = newOrdering;
      this.fetchReviews();
    },
    resetFilters() {
      this.ratingFilter = "";
      this.errorTypeFilter = "";
      this.search = "";
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
      this.$router.push({ name: "order-review", params: { id: row.id } });
    },
  },
};
</script>

<style lang="stylus">
.order-reviews-page {
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
