<template>
  <BaseWrapper :subtitle="subtitle">
    <template #title>{{ title }}</template>
    <template #actions>
      <button class="btn btn--md" @click="openAdd">
        <IconComponent name="add-circle-line" />
        <span>Добавить товар</span>
      </button>
    </template>

    <template #default>
      <div v-if="loading" class="stop-list-section__state">Загрузка…</div>

      <div v-else-if="!items.length" class="stop-list-section__empty">
        <IconComponent name="forbid-2-line" />
        <span class="stop-list-section__empty-title">Стоп-лист пуст</span>
        <span class="stop-list-section__empty-hint">{{ emptyHint }}</span>
      </div>

      <template v-else>
        <div class="stop-list-section__table-wrap">
          <table class="stop-list-section__table">
            <thead>
              <tr>
                <th class="stop-list-section__col-index">№</th>
                <th>Код товара (1С)</th>
                <th>Название товара</th>
                <th>Категория</th>
                <th>Дата добавления</th>
                <th class="stop-list-section__col-actions">Действия</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(product, i) in items" :key="product.id">
                <td class="stop-list-section__col-index">{{ rowNumber(i) }}</td>
                <td>{{ product.articul || "—" }}</td>
                <td>{{ product.title }}</td>
                <td>{{ categoriesText(product) }}</td>
                <td>{{ formatDate(product[dateField]) }}</td>
                <td class="stop-list-section__col-actions">
                  <button
                    type="button"
                    class="stop-list-section__remove"
                    title="Убрать из стоп-листа"
                    :disabled="removingId === product.id"
                    @click="remove(product)"
                  >
                    <IconComponent name="delete-bin-line" />
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <PaginationComponent
          v-if="lastPage > 1"
          class="stop-list-section__pagination"
          :page="page"
          :total="lastPage"
          :first="first"
          :total-items="total"
          hide-page-size
          @update:page="changePage"
        />
      </template>
    </template>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import IconComponent from "@/components/IconComponent.vue";
import PaginationComponent from "@/components/table/components/Pagination.vue";
import { shallowRef } from "vue";
import moment from "moment";
import GET from "@/graphql/pages/Stores/shop_stop_list_products.graphql";
import REMOVE from "@/graphql/pages/Stores/ProductStopListRemove.graphql";
import AddStopListProduct from "@/views/stores/modals/AddStopListProduct.vue";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "StopListSection",
  inject: ["apollo"],
  components: { BaseWrapper, IconComponent, PaginationComponent },
  props: {
    shopId: { type: Number, required: true },
    // «hidden» | «pickup_only»
    type: { type: String, required: true },
    title: { type: String, required: true },
    subtitle: { type: String, default: "" },
    emptyHint: { type: String, default: "" },
    // Поле даты добавления в объекте товара
    dateField: { type: String, required: true },
  },
  data() {
    return {
      items: [],
      loading: false,
      removingId: null,
      page: 1,
      first: 25,
      total: 0,
      lastPage: 1,
    };
  },
  mounted() {
    this.load();
  },
  methods: {
    load() {
      if (!this.shopId) return;
      this.loading = true;
      this.apollo()
        .admin.query({
          query: GET,
          variables: {
            shop_id: this.shopId,
            type: this.type,
            page: this.page,
            first: this.first,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const res = data.shop_stop_list_products;
          this.items = res?.data || [];
          this.total = res?.paginatorInfo?.total || 0;
          this.lastPage = res?.paginatorInfo?.lastPage || 1;
          // Если страница опустела после удаления — откатываемся назад.
          if (!this.items.length && this.page > 1) {
            this.page -= 1;
            this.load();
          }
        })
        .catch(() => {
          this.items = [];
          this.total = 0;
          this.lastPage = 1;
        })
        .finally(() => {
          this.loading = false;
        });
    },
    changePage(page) {
      this.page = page;
      this.load();
    },
    rowNumber(i) {
      return (this.page - 1) * this.first + i + 1;
    },
    categoriesText(product) {
      return (product.categories || [])
        .map((c) => c.title)
        .filter(Boolean)
        .join(", ");
    },
    formatDate(value) {
      if (!value) return "—";
      const parsed = moment(value);
      return parsed.isValid() ? parsed.format("DD.MM.YYYY") : "—";
    },
    openAdd() {
      useDefaultStore().modals.push({
        component: shallowRef(AddStopListProduct),
        options: {
          shopId: this.shopId,
          type: this.type,
          existingIds: this.items.map((p) => p.id),
          callback: this.load,
        },
      });
    },
    remove(product) {
      const label = product.title || product.articul || "товар";
      if (!confirm(`Убрать «${label}» из стоп-листа?`)) return;
      this.removingId = product.id;
      this.apollo()
        .admin.mutate({
          mutation: REMOVE,
          variables: {
            product_id: parseInt(product.id),
            type: this.type,
          },
        })
        .then(() => {
          this.load();
        })
        .catch(() => {
          alert("Не удалось убрать товар из стоп-листа");
        })
        .finally(() => {
          this.removingId = null;
        });
    },
  },
};
</script>

<style lang="stylus">
.stop-list-section {
  &__state {
    padding 20px
    text-align center
    color var(--dark-light)
    font-size 0.875rem
  }

  &__empty {
    display flex
    flex-direction column
    align-items center
    justify-content center
    gap 8px
    padding 40px 20px
    text-align center

    .icon {
      width 48px
      height 48px

      svg path {
        fill var(--gray-dark)
      }
    }
  }

  &__empty-title {
    font-weight 500
    font-size 1rem
    color var(--dark)
  }

  &__empty-hint {
    font-size 0.875rem
    color var(--dark-light)
  }

  &__table-wrap {
    width 100%
    overflow-x auto
  }

  &__table {
    width 100%
    border-collapse collapse
    font-size 0.875rem

    th, td {
      padding 12px 16px
      text-align left
      border-bottom 1px solid var(--white-2)
      vertical-align middle
    }

    th {
      font-weight 500
      color var(--dark-light)
      white-space nowrap
    }

    td {
      color var(--dark)
    }

    tbody tr:last-child td {
      border-bottom none
    }
  }

  &__col-index {
    width 48px
  }

  &__col-actions {
    width 90px
    text-align center
  }

  &__remove {
    background none
    border none
    padding 4px
    cursor pointer
    display inline-flex
    align-items center
    justify-content center

    .icon {
      width 20px
      height 20px

      svg path {
        fill var(--dark-light)
      }
    }

    &:hover:not(:disabled) .icon svg path {
      fill var(--red, #e53935)
    }

    &:disabled {
      cursor default
      opacity 0.5
    }
  }

  &__pagination {
    margin-top 16px
  }
}
</style>
