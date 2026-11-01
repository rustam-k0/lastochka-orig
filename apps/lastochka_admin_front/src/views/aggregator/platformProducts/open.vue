<template>
  <main class="page platform-product-page" v-if="pageInfo">
    <div class="platform-product-page__header">
      <router-link :to="{ name: 'platform-products' }" class="platform-product-page__back">
        ← Платформенные товары
      </router-link>
      <h2>{{ pageInfo.canonical_title }}</h2>
      <button class="btn btn--md" @click="openMergeModal">Объединить с другой карточкой</button>
    </div>

    <div class="platform-product-page__meta">
      <span>Статус: {{ pageInfo.is_active ? "Активен" : "Скрыт" }}</span>
      <span>Курация: {{ pageInfo.is_curated ? "Да" : "—" }}</span>
      <span>Офферов: {{ pageInfo.offers ? pageInfo.offers.length : 0 }}</span>
    </div>

    <!-- Блок штрихкодов -->
    <section class="platform-product-page__section">
      <div class="platform-product-page__section-head">
        <h3>Штрихкоды</h3>
        <button class="btn btn--sm" @click="openAddBarcodeModal">Добавить штрихкод</button>
      </div>
      <table class="platform-product-page__table">
        <thead>
          <tr>
            <th>EAN</th>
            <th>Основной</th>
            <th>Источник (shop)</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in pageInfo.barcodes" :key="b.id">
            <td>{{ b.ean }}</td>
            <td>{{ b.is_primary ? "★" : "" }}</td>
            <td>{{ b.source_shop_id || "—" }}</td>
            <td class="platform-product-page__row-actions">
              <button v-if="!b.is_primary" class="btn btn--xs" @click="setPrimary(b)">Сделать основным</button>
              <button class="btn btn--xs btn--danger" @click="removeBarcode(b)">Удалить</button>
            </td>
          </tr>
          <tr v-if="!pageInfo.barcodes || !pageInfo.barcodes.length">
            <td colspan="4">Штрихкодов нет</td>
          </tr>
        </tbody>
      </table>
    </section>

    <!-- Офферы (только просмотр) -->
    <section class="platform-product-page__section">
      <h3>Офферы магазинов</h3>
      <table class="platform-product-page__table">
        <thead>
          <tr>
            <th>Магазин</th>
            <th>Исходное название</th>
            <th>id у поставщика</th>
            <th>Цена</th>
            <th>Остаток</th>
            <th>Мин. заказ</th>
            <th>Активен</th>
            <th>Синк</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="o in pageInfo.offers" :key="o.id">
            <td>{{ o.shop_title || o.shop_id }}</td>
            <td>{{ o.local_product_title || "—" }}</td>
            <td>{{ o.local_product_id }}</td>
            <td>{{ formatPrice(o.price) }}</td>
            <td>{{ o.qty }}</td>
            <td>{{ o.min_qty }}</td>
            <td>{{ o.is_active ? "Да" : "Нет" }}</td>
            <td>{{ o.last_sync_at ? formatDate(o.last_sync_at) : "—" }}</td>
          </tr>
          <tr v-if="!pageInfo.offers || !pageInfo.offers.length">
            <td colspan="8">Офферов нет</td>
          </tr>
        </tbody>
      </table>
    </section>
  </main>
</template>

<script>
import { shallowRef } from "vue";
import { useDefaultStore } from "@/stores/default.js";
import { formatPrice } from "@/utils.js";
import dayjs from "dayjs";
import PLATFORM_PRODUCT from "@/graphql/pages/Aggregator/platformProduct.graphql";
import SET_PRIMARY from "@/graphql/pages/Aggregator/setPrimaryPlatformProductBarcode.graphql";
import REMOVE_BARCODE from "@/graphql/pages/Aggregator/removePlatformProductBarcode.graphql";
import AddBarcodeModal from "@/views/aggregator/modals/AddBarcodeModal.vue";
import MergeDuplicatesModal from "@/views/aggregator/modals/MergeDuplicatesModal.vue";

export default {
  name: "PlatformProductPage",
  inject: ["apollo"],
  data() {
    return {
      loading: false,
      pageInfo: null,
    };
  },
  computed: {
    id() {
      return parseInt(this.$route.params.id);
    },
  },
  mounted() {
    this.get();
  },
  methods: {
    formatPrice,
    formatDate(value) {
      return dayjs(value).format("DD.MM.YYYY HH:mm");
    },
    get() {
      this.loading = true;
      this.apollo()
        .admin.query({
          query: PLATFORM_PRODUCT,
          variables: { id: this.id },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.pageInfo = data.platformProduct;
          this.loading = false;
        })
        .catch(() => {
          this.loading = false;
        });
    },
    setPrimary(barcode) {
      this.apollo()
        .admin.mutate({
          mutation: SET_PRIMARY,
          variables: { barcodeId: barcode.id },
        })
        .then(() => this.get());
    },
    removeBarcode(barcode) {
      if (!confirm(`Удалить штрихкод ${barcode.ean}?`)) return;
      this.apollo()
        .admin.mutate({
          mutation: REMOVE_BARCODE,
          variables: { barcodeId: barcode.id },
        })
        .then(() => this.get());
    },
    openAddBarcodeModal() {
      useDefaultStore().modals.push({
        component: shallowRef(AddBarcodeModal),
        options: {
          platformProductId: this.id,
          callback: this.get,
        },
      });
    },
    openMergeModal() {
      useDefaultStore().modals.push({
        component: shallowRef(MergeDuplicatesModal),
        options: {
          sourceId: this.id,
          callback: () => this.$router.replace({ name: "platform-products" }),
        },
      });
    },
  },
};
</script>

<style lang="stylus">
.platform-product-page {
  &__header {
    display flex
    align-items center
    gap 16px
    margin-bottom 12px
  }

  &__back {
    color var(--blue)
    font-size 0.875em
  }

  &__meta {
    display flex
    gap 24px
    margin-bottom 24px
    color var(--dark)
    font-size 0.9375em
  }

  &__section {
    margin-bottom 32px

    &-head {
      display flex
      align-items center
      justify-content space-between
      margin-bottom 12px
    }
  }

  &__table {
    width 100%
    border-collapse collapse

    th, td {
      text-align left
      padding 8px 12px
      border-bottom 1px solid var(--white-2)
      font-size 0.9375em
    }
  }

  &__row-actions {
    display flex
    gap 8px
  }
}
</style>
