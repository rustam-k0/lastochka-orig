<template>
  <main class="page platform-products-page">
    <div class="platform-products-page__header">
      <h2>{{ pageTitle }}</h2>
      <input
        v-model="search"
        @input="handleSearch"
        class="platform-products-page__search"
        type="text"
        placeholder="Поиск по названию или штрихкоду"
      />
    </div>

    <TableComponent
      v-if="rows"
      :columns="columns"
      :rows="rows.data || []"
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
      :total="rows.paginatorInfo.total"
      :first="first"
      :page="page"
      :loading="loading"
      pagination
    >
      <template #col="{ col, row }">
        <router-link
          v-if="col.field === 'canonical_title'"
          :to="{ name: 'platform-product', params: { id: row.id } }"
          class="tabs__col-content"
        >
          <span>{{ row.canonical_title }}</span>
        </router-link>
        <router-link
          v-if="col.field === 'offers_count'"
          :to="{ name: 'platform-product', params: { id: row.id } }"
          class="rows__left"
        >
          {{ row.offers_count }}
        </router-link>
        <span v-if="col.field === 'is_curated'">
          {{ row.is_curated ? "Да" : "—" }}
        </span>
        <span v-if="col.field === 'is_active'">
          {{ row.is_active ? "Активен" : "Скрыт" }}
        </span>
      </template>
    </TableComponent>
  </main>
</template>

<script>
import TableComponent from "@/components/table/index.vue";
import PLATFORM_PRODUCTS from "@/graphql/pages/Aggregator/platformProductsPaginate.graphql";
import { useFiltersStore } from "@/stores/pageFilters.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "PlatformProductsPage",
  inject: ["apollo"],
  components: { TableComponent },
  data() {
    return {
      loading: false,
      searchDebounce: null,
      first: 25,
      page: 1,
      search: null,
      rows: {
        data: [],
        paginatorInfo: { total: 0 },
      },
      columns: [
        { title: "Наименование", field: "canonical_title", show: true },
        { title: "Офферов", field: "offers_count", show: true },
        { title: "Курация", field: "is_curated", show: true },
        { title: "Статус", field: "is_active", show: true },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Платформенные товары");
    },
  },
  mounted() {
    const saved = useFiltersStore().get(this.$route.name);
    if (saved) {
      this.search = saved.search ?? null;
      this.first = saved.first ?? 25;
    }
    this.get();
  },
  methods: {
    handleSearch() {
      if (this.searchDebounce) clearTimeout(this.searchDebounce);
      this.searchDebounce = setTimeout(() => {
        this.page = 1;
        useFiltersStore().set(this.$route.name, { search: this.search, first: this.first });
        this.get();
      }, 500);
    },
    handleFirstCount(count) {
      this.first = count;
      this.page = 1;
      this.get();
    },
    handlePaginate(page) {
      this.page = page;
      this.get();
    },
    get() {
      if (this.loading) return;
      this.loading = true;
      this.apollo()
        .admin.query({
          query: PLATFORM_PRODUCTS,
          variables: {
            first: this.first,
            page: this.page,
            search: this.search || null,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.platformProducts) {
            this.rows.data = data.platformProducts.data;
            this.rows.paginatorInfo = data.platformProducts.paginatorInfo;
          }
          this.loading = false;
        })
        .catch(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.platform-products-page {
  &__header {
    display flex
    align-items center
    justify-content space-between
    gap 16px
    margin-bottom 16px
  }

  &__search {
    min-width 320px
    padding 8px 12px
    border 1px solid var(--gray-light)
    border-radius 8px
    font-size 0.9375em
  }
}
</style>
