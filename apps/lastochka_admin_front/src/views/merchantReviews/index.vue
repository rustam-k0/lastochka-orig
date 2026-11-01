<template>
  <main class="page--main merchant-reviews-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="merchant-reviews-page__header">
      <h2>{{ pageTitle }}</h2>
      <div v-if="isAdmin" class="merchant-reviews-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Создать отзыв</span>
        </button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="merchant-reviews-page__filters">
      <div class="merchant-reviews-page__search">
        <IconComponent name="search-line" />
        <input
          type="text"
          v-model="search"
          placeholder="Поиск по описанию и автору..."
          @input="debouncedSearch"
        />
      </div>

      <div v-if="isAdmin" class="merchant-reviews-page__filter-group">
        <ShopSelectComponent
          v-model="shopFilter"
          placeholder="Все магазины"
          clearable
          @change="onFilterChange"
        />
      </div>

      <div class="merchant-reviews-page__filter-group">
        <div class="merchant-reviews-page__select-wrapper">
          <select v-model="statusFilter" @change="onFilterChange">
            <option :value="null">Все статусы</option>
            <option :value="true">Опубликованные</option>
            <option :value="false">Черновики</option>
          </select>
          <IconComponent
            name="arrow-drop-down-line"
            class="merchant-reviews-page__select-icon"
          />
        </div>
      </div>

      <div class="merchant-reviews-page__filter-group">
        <div class="merchant-reviews-page__select-wrapper">
          <!-- value="" + v-model.number: 6 статичных option стрингифицируются
               компилятором, :value теряет _value — см. фикс ALADDIN-11945 -->
          <select v-model.number="ratingFilter" @change="onFilterChange">
            <option value="">Все оценки</option>
            <option :value="5">5 звёзд</option>
            <option :value="4">4 звезды</option>
            <option :value="3">3 звезды</option>
            <option :value="2">2 звезды</option>
            <option :value="1">1 звезда</option>
          </select>
          <IconComponent
            name="arrow-drop-down-line"
            class="merchant-reviews-page__select-icon"
          />
        </div>
      </div>

      <button
        v-if="hasActiveFilters"
        @click="resetFilters"
        class="btn btn--white btn--sm"
      >
        Сбросить
      </button>
    </div>

    <!-- Таблица -->
    <TableComponent
      class="merchant-reviews-page__table"
      :ordering="ordering"
      :columns="columns"
      :rows="reviews"
      :loading="loading"
      @update:ordering="handleOrdering"
      @update:page="handlePaginate"
      :total="paginatorInfo.total"
      :first="savedFilters.first"
      @update:count="handleFirstCount"
      :page="page"
      pagination
    >
      <template #col="{ col, row }">
        <router-link
          v-if="col.field === 'shop'"
          :to="{ name: 'merchant-review', params: { id: row.id } }"
          class="rows__left rows__elipsis"
          :title="row.shop?.title"
        >
          {{ row.shop?.title || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'rating'"
          :to="{ name: 'merchant-review', params: { id: row.id } }"
          class="rows__center"
        >
          <StarRating :modelValue="row.rating" readonly small />
        </router-link>

        <router-link
          v-if="col.field === 'author_name'"
          :to="{ name: 'merchant-review', params: { id: row.id } }"
          class="rows__left rows__elipsis"
        >
          {{ row.author_name || "—" }}
        </router-link>

        <router-link
          v-if="col.field === 'is_published'"
          :to="{ name: 'merchant-review', params: { id: row.id } }"
          class="rows__center"
        >
          <span
            class="merchant-reviews-page__status"
            :class="{
              'merchant-reviews-page__status--published': row.is_published,
            }"
          >
            {{ row.is_published ? "Опубликован" : "Черновик" }}
          </span>
        </router-link>

        <router-link
          v-if="col.field === 'created_at'"
          :to="{ name: 'merchant-review', params: { id: row.id } }"
          class="rows__center"
        >
          {{ formatDate(row.created_at) }}
        </router-link>

        <div v-if="col.field === 'actions'" class="rows__actions">
          <div class="merchant-reviews-page__dropdown">
            <button
              type="button"
              class="merchant-reviews-page__dropdown-trigger"
              @click.stop="toggleDropdown(row.id)"
            >
              <IconComponent name="more-2-fill" />
            </button>
            <div
              v-if="openDropdownId === row.id"
              class="merchant-reviews-page__dropdown-menu"
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
                class="merchant-reviews-page__dropdown-delete"
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
      v-if="!loading && !reviews.length"
      class="merchant-reviews-page__empty"
    >
      <IconComponent name="chat-3-line" />
      <h3>Нет отзывов</h3>
      <p v-if="hasActiveFilters">Ничего не найдено по заданным фильтрам</p>
      <p v-else>Отзывов пока нет</p>
      <button
        v-if="hasActiveFilters"
        @click="resetFilters"
        class="btn btn--white btn--md"
      >
        Сбросить фильтры
      </button>
      <button v-else-if="isAdmin" @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать отзыв</span>
      </button>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import ShopSelectComponent from "@/components/inputs/ShopSelectComponent.vue";
import TableComponent from "@/components/table/index.vue";
import { useFiltersStore } from "@/stores/pageFilters.js";
import StarRating from "./components/StarRating.vue";
import MERCHANT_REVIEWS_PAGINATE from "@/graphql/queries/merchant_reviews_paginate.graphql";
import MERCHANT_REVIEW_PUBLISH from "@/graphql/mutations/MerchantReviewPublish.graphql";
import ENTITY_DELETE from "@/graphql/mutations/EntityDelete.graphql";
import { useAuthStore } from "@/stores/auth.js";
import dayjs from "dayjs";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "MerchantReviewsListPage",
  inject: ["apollo"],
  components: {
    ShopSelectComponent,
    BreadcrumbsComponent,
    IconComponent,
    TableComponent,
    StarRating,
  },
  data() {
    return {
      reviews: [],
      loading: true,
      fetchSeq: 0,
      search: "",
      statusFilter: null,
      shopFilter: null,
      ratingFilter: "",
      page: 1,
      first: 25,
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
      },
      ordering: [
        {
          orderColumn: "created_at",
          orderBy: "DESC",
        },
      ],
      searchTimeout: null,
      openDropdownId: null,
      columns: [
        {
          title: "Магазин",
          field: "shop",
          show: true,
          width: "200px",
        },
        {
          title: "Оценка",
          field: "rating",
          show: true,
          width: "140px",
          sortable: true,
        },
        {
          title: "Автор",
          field: "author_name",
          show: true,
          width: "180px",
        },
        {
          title: "Статус",
          field: "is_published",
          show: true,
          width: "140px",
          sortable: true,
        },
        {
          title: "Дата",
          field: "created_at",
          show: true,
          width: "120px",
          sortable: true,
        },
        {
          title: "",
          field: "actions",
          show: true,
          width: "60px",
          system: true,
        },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Отзывы на магазины");
    },
    breadcrumbs() {
      return [{ to: { name: "merchant-reviews" }, title: this.pageTitle }];
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
    userShopId() {
      return (
        this.user?.shop_id ||
        this.user?.shop?.id ||
        this.user?.shops?.[0]?.shop_id ||
        this.user?.shops?.[0]?.shop?.id
      );
    },
    hasActiveFilters() {
      return (
        this.search ||
        this.statusFilter !== null ||
        this.shopFilter !== null ||
        this.ratingFilter !== ""
      );
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
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.get();
    },
    get() {
      // Guard от гонки: применяем только ответ последнего запроса,
      // устаревший ответ не должен перезаписывать таблицу
      const requestId = ++this.fetchSeq;
      this.loading = true;

      const variables = {
        first: this.savedFilters.first,
        page: this.page,
        search: this.search || undefined,
        is_published:
          this.statusFilter !== null ? this.statusFilter : undefined,
        rating: this.ratingFilter !== "" ? this.ratingFilter : undefined,
        ordering: this.ordering,
      };

      if (this.isAdmin && this.shopFilter) {
        variables.shop_id = this.shopFilter;
      } else if (!this.isAdmin && this.userShopId) {
        variables.shop_id = parseInt(this.userShopId);
      }

      this.apollo()
        .admin.query({
          query: MERCHANT_REVIEWS_PAGINATE,
          variables,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (requestId !== this.fetchSeq) return;
          this.reviews = data.merchantReviews?.data || [];
          this.paginatorInfo = data.merchantReviews?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching merchant reviews:", error);
        })
        .finally(() => {
          if (requestId === this.fetchSeq) {
            this.loading = false;
          }
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
    handleOrdering(event) {
      this.ordering = event;
      this.get();
    },
    handlePaginate(event) {
      this.page = event;
    },
    resetFilters() {
      this.search = "";
      this.statusFilter = null;
      this.shopFilter = null;
      this.ratingFilter = "";
      this.page = 1;
      this.get();
    },
    formatDate(date) {
      if (!date) return "—";
      return dayjs(date).format("DD.MM.YY");
    },
    toggleDropdown(id) {
      this.openDropdownId = this.openDropdownId === id ? null : id;
    },
    closeDropdown() {
      this.openDropdownId = null;
    },
    openCreate() {
      this.$router.push({ name: "merchant-review-create" });
    },
    openView(review) {
      this.closeDropdown();
      this.$router.push({ name: "merchant-review", params: { id: review.id } });
    },
    openEdit(review) {
      this.closeDropdown();
      this.$router.push({
        name: "merchant-review",
        params: { id: review.id },
        query: { edit: "true" },
      });
    },
    togglePublish(review) {
      this.closeDropdown();
      this.apollo()
        .admin.mutate({
          mutation: MERCHANT_REVIEW_PUBLISH,
          variables: {
            id: parseInt(review.id),
            is_published: !review.is_published,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling publish:", error);
          alert("Ошибка изменения статуса публикации");
        });
    },
    confirmDelete(review) {
      this.closeDropdown();
      if (
        confirm(
          `Удалить отзыв${
            review.author_name ? ` от "${review.author_name}"` : ""
          }?`
        )
      ) {
        this.deleteReview(review);
      }
    },
    deleteReview(review) {
      this.apollo()
        .admin.mutate({
          mutation: ENTITY_DELETE,
          variables: {
            id: parseInt(review.id),
            table: "merchant_reviews",
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting review:", error);
          alert("Ошибка удаления отзыва");
        });
    },
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
};
</script>

<style lang="stylus">
.merchant-reviews-page {
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

  &__table {
    max-height calc(100vh - 280px)
  }

  &__status {
    display inline-flex
    align-items center
    gap 6px
    padding 4px 12px
    border-radius 20px
    font-size 0.75rem
    font-weight 500
    background var(--gray-dark)
    color var(--dark)

    &::before {
      content ''
      width 8px
      height 8px
      border-radius 50%
      background var(--gray-2)
    }

    &--published {
      background rgba(76, 175, 80, 0.15)
      color var(--green)

      &::before {
        background var(--green)
      }
    }
  }

  &__dropdown {
    position relative
  }

  &__dropdown-trigger {
    background none
    border none
    padding 8px
    cursor pointer
    border-radius 4px
    display flex
    align-items center
    justify-content center

    &:hover {
      background var(--gray-3)
    }

    .icon {
      width 20px
      height 20px
    }
  }

  &__dropdown-menu {
    position absolute
    right 0
    top 100%
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.1)
    min-width 200px
    z-index 100
    overflow hidden

    button {
      width 100%
      padding 12px 16px
      background none
      border none
      display flex
      align-items center
      gap 12px
      font-size 0.875rem
      color var(--dark)
      cursor pointer
      text-align left

      &:hover {
        background var(--gray-3)
      }

      .icon {
        width 18px
        height 18px
        flex-shrink 0
      }
    }
  }

  &__dropdown-delete {
    color var(--red) !important

    .icon svg path {
      fill var(--red)
    }
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
}
</style>
