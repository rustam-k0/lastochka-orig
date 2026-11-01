<template>
  <main class="page--main order-page" v-if="page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <OrderPageHeader
      :id="id"
      :number="page.number"
      :status-id="page.status_id"
      :canceling="canceling"
      @cancel-order="cancelOrder"
    />
    <OrderSyncStatus
      v-if="page"
      :order-number="page.number"
    />
    <YandexDeliveryInfo
      v-if="page.yandex_claim_id"
      :order="page"
      @claim-cancelled="onYandexClaimCancelled"
    />
    <div class="order-page__container">
      <AsideComponent
        :page="page"
        :courier="courier"
        :collector="collector"
        :sub-collectors="subCollectors"
        :status-id="page.status_id"
        @update="get"
      />
      <div class="order-page__content">
        <!-- Подзаказы по зонам сборки -->
        <template
          v-if="
            assemblyZonesEnabled && page.childOrders && page.childOrders.length
          "
        >
          <!-- Итого — один раз сверху -->
          <div class="base-wrapper order-page__total">
            <span v-if="page.items_subtotal">Стоимость товаров: {{ page.items_subtotal }} ₽ · </span>
            <span v-if="page.promocode_code">Промокод: {{ page.promocode_code }} · </span>
            <span v-if="page.discount">Скидка: -{{ page.discount }} ₽ · </span>
            <span v-if="bonusesModuleEnabled && page.bonus_spent"
              >Списано бонусов: {{ page.bonus_spent }} ₽ ·
            </span>
            <span v-if="bonusesModuleEnabled && page.bonus_earned"
              >Начислено бонусов: {{ page.bonus_earned }} ₽ ·
            </span>
            <span v-if="showAssemblyCost"
              >Стоимость сборки: {{ page.assembly_cost }} ₽ ·
            </span>
            <span v-if="showDeliveryPrice"
              >Стоимость доставки: {{ page.delivery_price }} ₽ ·
            </span>
            <span
              v-if="page.is_heavy && heavyOrdersModuleEnabled"
              class="order-page__heavy-badge"
              >Тяжелый заказ ·
            </span>
            <span v-if="formatOrderWeight(page)"
              >Вес: {{ formatOrderWeight(page) }} ·
            </span>
            <strong>Итого: {{ page.amount }} ₽</strong>
          </div>
          <BaseWrapper
            v-for="child in page.childOrders"
            :key="child.id"
            class="order-page__table-wrapper"
          >
            <template #title>
              <div class="order-page__suborder-header">
                <span>
                  Подзаказ {{ child.label }}
                  <span v-if="child.assemblyZone" class="order-page__suborder-zone">
                    <span
                      class="order-page__suborder-zone-color"
                      :style="{ background: child.assemblyZone.color || 'transparent' }"
                    ></span>
                    {{ child.assemblyZone.title }}
                  </span>
                  <span v-else class="order-page__suborder-zone order-page__suborder-zone--none">
                    без зоны
                  </span>
                </span>
                <ul class="o-p__list">
                  <li class="o-p__select order-page__suborder-status-badge">
                    <div class="o-p__select--selected">
                      <span>{{ orderStatusLabel(child.status_id) }}</span>
                    </div>
                  </li>
                </ul>
              </div>
            </template>
            <template #actions></template>
            <template #default>
              <TableComponent
                class="order-page__table"
                :ordering="ordering"
                :columns="orderItemColumns"
                :rows="(child.items || []).filter((i) => i.status_id !== 6)"
                :loading="false"
                :total="
                  (child.items || []).filter((i) => i.status_id !== 6).length
                "
                hidePageSize
                :first="1000"
                :page="1"
              >
                <template #col="{ col, row }">
                  <div v-if="col.field === 'id'" class="rows__article">
                    {{ row.product?.id ?? row.product_id ?? "—" }}
                  </div>
                  <div v-if="col.field === 'title'" class="rows__left">
                    <div
                      :class="{
                        'order-page__missing-product': !row.product?.title,
                      }"
                    >
                      {{ row.product?.title || "Товар недоступен" }}
                    </div>
                    <span
                      v-if="['replaced', 'supplemented'].includes(itemState(row))"
                      class="order-page__marker"
                      :class="`order-page__marker--${itemState(row)}`"
                    >
                      {{ itemStateLabel(row) }}
                    </span>
                    <span
                      v-if="row.product?.pickup_only"
                      class="order-page__marker order-page__marker--pickup-only"
                    >
                      Только самовывоз
                    </span>
                    <div
                      v-if="itemState(row) === 'replaced' && row.origin_title"
                      class="order-page__origin-title"
                    >
                      Был: {{ row.origin_title }}
                    </div>
                    <div
                      v-if="honestSignEnabled && row.marking_codes?.length"
                      class="order-page__marking"
                      title="Коды маркировки (Честный знак)"
                    >
                      <div
                        v-for="code in row.marking_codes"
                        :key="code"
                        class="order-page__marking-code"
                      >
                        {{ code }}
                      </div>
                    </div>
                    <div
                      v-if="itemState(row) === 'replaced' && row.replace_reason?.title"
                      class="order-page__replace-reason"
                    >
                      Причина замены: {{ row.replace_reason.title }}
                    </div>
                    <div
                      v-if="row.supplements && row.supplements.length"
                      class="order-page__supplements"
                    >
                      <template
                        v-for="supp in row.supplements"
                        :key="supp.supplement?.id"
                      >
                        <div
                          v-for="val in supp.values"
                          :key="val.id"
                          class="order-page__supplement-item"
                        >
                          &ensp;&hairsp;└ {{ supp.supplement?.title }}:
                          {{ val.title }}
                          <span v-if="val.price">(+{{ val.price }} ₽)</span>
                        </div>
                      </template>
                    </div>
                  </div>
                  <div v-if="col.field === 'status'" class="rows__left">
                    <div class="order-page__status">
                      <span
                        class="order-page__marker"
                        :class="`order-page__marker--${itemStatusKey(row)}`"
                      >
                        {{ itemStatusLabel(row) }}
                      </span>
                      <div
                        v-if="
                          itemStatusKey(row) === 'canceled' &&
                          row.cancel_reason?.title
                        "
                        class="order-page__cancel-reason"
                      >
                        {{ row.cancel_reason.title }}
                      </div>
                    </div>
                  </div>
                  <div v-if="col.field === 'category'" class="rows__left">
                    {{ categoryLabel(row) }}
                  </div>
                  <div v-if="col.field === 'quantity'" class="rows__center">
                    <div class="order-page__qty">
                      <span :class="{ 'order-page__crossed': isNotDelivered(row) }">
                        {{ formatQuantity(row) }}
                      </span>
                      <span
                        v-if="row.returned_quantity > 0"
                        class="order-page__returned"
                      >
                        возврат {{ row.returned_quantity }}
                      </span>
                    </div>
                  </div>
                  <div
                    v-if="col.field === 'unit_price'"
                    class="rows__left"
                    :class="{ 'order-page__unit-price--tiers': hasPriceTiers(row) }"
                  >
                    <span
                      v-if="getUnitPriceOld(row)"
                      class="order-page__price-old"
                    >
                      {{ getUnitPriceOld(row) }} ₽
                    </span>
                    {{ getUnitPrice(row) }} ₽
                    <span v-if="hasPriceTiers(row)" class="order-page__price-tiers">
                      <span class="order-page__marker order-page__marker--tiers">цена от количества</span>
                      <InfoHintComponent position="right" teleport aria-label="Лесенка цен позиции">
                        <span class="order-page__price-tiers-line">
                          Цена за единицу зависит от количества в позиции.
                        </span>
                        <span
                          class="order-page__price-tiers-line"
                          :class="{ 'order-page__price-tiers-line--active': !appliedPriceTier(row) }"
                        >
                          Обычная цена: {{ row.price_tiers.base_price }} ₽
                        </span>
                        <span
                          v-for="tier in row.price_tiers.tiers"
                          :key="tier.min_quantity"
                          class="order-page__price-tiers-line"
                          :class="{ 'order-page__price-tiers-line--active': appliedPriceTier(row) === tier }"
                        >
                          от {{ formatTierQuantity(tier.min_quantity) }}: {{ tier.price }} ₽
                        </span>
                      </InfoHintComponent>
                    </span>
                  </div>
                  <div v-if="col.field === 'price'" class="rows__left">
                    <span
                      v-if="getLineTotalOld(row)"
                      class="order-page__price-old"
                    >
                      {{ getLineTotalOld(row) }} ₽
                    </span>
                    {{ getLineTotal(row) }} ₽
                  </div>
                </template>
              </TableComponent>
            </template>
          </BaseWrapper>
        </template>

        <!-- Обычный заказ без подзаказов -->
        <BaseWrapper v-else class="order-page__table-wrapper">
          <template #title> Состав </template>
          <template #actions>
            <span v-if="page.items_subtotal"
              >Стоимость товаров: {{ page.items_subtotal }} ₽ ·
            </span>
            <span v-if="page.promocode_code">Промокод: {{ page.promocode_code }} · </span>
            <span v-if="page.discount">Скидка: -{{ page.discount }} ₽ · </span>
            <span v-if="bonusesModuleEnabled && page.bonus_spent"
              >Списано бонусов: {{ page.bonus_spent }} ₽ ·
            </span>
            <span v-if="bonusesModuleEnabled && page.bonus_earned"
              >Начислено бонусов: {{ page.bonus_earned }} ₽ ·
            </span>
            <span v-if="showAssemblyCost"
              >Стоимость сборки: {{ page.assembly_cost }} ₽ ·
            </span>
            <span v-if="showDeliveryPrice"
              >Стоимость доставки: {{ page.delivery_price }} ₽ ·
            </span>
            <span
              v-if="page.is_heavy && heavyOrdersModuleEnabled"
              class="order-page__heavy-badge"
              >Тяжелый заказ ·
            </span>
            <span v-if="formatOrderWeight(page)"
              >Вес: {{ formatOrderWeight(page) }} ·
            </span>
            <span>Общая сумма: {{ page.amount }} ₽</span>
          </template>
          <template #default>
            <TableComponent
              class="order-page__table"
              :ordering="ordering"
              :columns="orderItemColumns"
              :rows="rows.data || []"
              :loading="tableLoading"
              @update:page="handlePaginate"
              @update:count="handleFirstCount"
              :total="rows.paginatorInfo.total"
              :first="savedFilters.first"
              :page="page_paginate"
              pagination
            >
              <template #col="{ col, row }">
                <div v-if="col.field === 'id'" class="rows__article">
                  {{ row.product?.id ?? row.product_id ?? "—" }}
                </div>
                <div v-if="col.field === 'title'" class="rows__left">
                  <div
                    class="order-page__title-row"
                    :class="{
                      'order-page__title-row--canceled': isNotDelivered(row),
                      'order-page__missing-product': !row.product?.title,
                    }"
                  >
                    <span>{{ row.product?.title || "Товар недоступен" }}</span>
                    <span
                      v-if="['replaced', 'supplemented'].includes(itemState(row))"
                      class="order-page__marker"
                      :class="`order-page__marker--${itemState(row)}`"
                    >
                      {{ itemStateLabel(row) }}
                    </span>
                    <span
                      v-if="row.product?.pickup_only"
                      class="order-page__marker order-page__marker--pickup-only"
                    >
                      Только самовывоз
                    </span>
                  </div>
                  <div
                    v-if="itemState(row) === 'replaced' && row.origin_title"
                    class="order-page__origin-title"
                  >
                    Был: {{ row.origin_title }}
                  </div>
                  <div
                    v-if="honestSignEnabled && row.marking_codes?.length"
                    class="order-page__marking"
                    title="Коды маркировки (Честный знак)"
                  >
                    <div
                      v-for="code in row.marking_codes"
                      :key="code"
                      class="order-page__marking-code"
                    >
                      {{ code }}
                    </div>
                  </div>
                  <div
                    v-if="itemState(row) === 'replaced' && row.replace_reason?.title"
                    class="order-page__replace-reason"
                  >
                    Причина замены: {{ row.replace_reason.title }}
                  </div>
                  <div
                    v-if="row.supplements && row.supplements.length"
                    class="order-page__supplements"
                  >
                    <template
                      v-for="supp in row.supplements"
                      :key="supp.supplement?.id"
                    >
                      <div
                        v-for="val in supp.values"
                        :key="val.id"
                        class="order-page__supplement-item"
                      >
                        &ensp;&hairsp;└ {{ supp.supplement?.title }}:
                        {{ val.title }}
                        <span v-if="val.price">(+{{ val.price }} ₽)</span>
                      </div>
                    </template>
                  </div>
                </div>
                <div v-if="col.field === 'status'" class="rows__left">
                  <div class="order-page__status">
                    <span
                      class="order-page__marker"
                      :class="`order-page__marker--${itemStatusKey(row)}`"
                    >
                      {{ itemStatusLabel(row) }}
                    </span>
                    <div
                      v-if="
                        itemStatusKey(row) === 'canceled' &&
                        row.cancel_reason?.title
                      "
                      class="order-page__cancel-reason"
                    >
                      {{ row.cancel_reason.title }}
                    </div>
                  </div>
                </div>
                <div v-if="col.field === 'category'" class="rows__left">
                  {{ categoryLabel(row) }}
                </div>
                <div v-if="col.field === 'quantity'" class="rows__center">
                  <div class="order-page__qty">
                    <span :class="{ 'order-page__crossed': isNotDelivered(row) }">
                      {{ formatQuantity(row) }}
                    </span>
                    <span
                      v-if="row.returned_quantity > 0"
                      class="order-page__returned"
                    >
                      возврат {{ row.returned_quantity }}
                    </span>
                  </div>
                </div>
                <div
                  v-if="col.field === 'unit_price'"
                  class="rows__left"
                  :class="{ 'order-page__unit-price--tiers': hasPriceTiers(row) }"
                >
                  <span
                    v-if="getUnitPriceOld(row)"
                    class="order-page__price-old"
                  >
                    {{ getUnitPriceOld(row) }} ₽
                  </span>
                  <span :class="{ 'order-page__crossed': isNotDelivered(row) }">
                    {{ getUnitPrice(row) }} ₽
                  </span>
                  <span v-if="hasPriceTiers(row)" class="order-page__price-tiers">
                    <span class="order-page__marker order-page__marker--tiers">цена от количества</span>
                    <InfoHintComponent position="right" teleport aria-label="Лесенка цен позиции">
                      <span class="order-page__price-tiers-line">
                        Цена за единицу зависит от количества в позиции.
                      </span>
                      <span
                        class="order-page__price-tiers-line"
                        :class="{ 'order-page__price-tiers-line--active': !appliedPriceTier(row) }"
                      >
                        Обычная цена: {{ row.price_tiers.base_price }} ₽
                      </span>
                      <span
                        v-for="tier in row.price_tiers.tiers"
                        :key="tier.min_quantity"
                        class="order-page__price-tiers-line"
                        :class="{ 'order-page__price-tiers-line--active': appliedPriceTier(row) === tier }"
                      >
                        от {{ formatTierQuantity(tier.min_quantity) }}: {{ tier.price }} ₽
                      </span>
                    </InfoHintComponent>
                  </span>
                </div>
                <div v-if="col.field === 'price'" class="rows__left">
                  <span
                    v-if="getLineTotalOld(row)"
                    class="order-page__price-old"
                  >
                    {{ getLineTotalOld(row) }} ₽
                  </span>
                  <span :class="{ 'order-page__crossed': isNotDelivered(row) }">
                    {{ getLineTotal(row) }} ₽
                  </span>
                </div>
              </template>
            </TableComponent>
          </template>
        </BaseWrapper>
        <BaseWrapper v-if="page.review">
          <template #title> Отзыв клиента </template>
          <template #default>
            <OrdersPageReviews :review="page.review" />
          </template>
        </BaseWrapper>
        <OrderPageHistory :order-id="id" :sub-orders="page.childOrders" />
      </div>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import AsideComponent from "./components/OrderPageAside.vue";
import TableComponent from "@/components/table/index.vue";
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import OrderPageHeader from "@/views/orders/components/OrderPageHeader.vue";
import OrderSyncStatus from "@/views/orders/components/OrderSyncStatus.vue";
import { useDefaultStore } from "@/stores/default.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { shallowRef } from "vue";
import { useFiltersStore } from "@/stores/pageFilters.js";
import ChangeStoreModal from "@/views/orders/modals/ChangeStoreModal.vue";
import CancelOrderModal from "@/views/orders/modals/CancelOrderModal.vue";
import ORDER from "@/graphql/pages/Order/admin_orders_item.graphql";
import ORDER_ITEMS from "@/graphql/pages/Order/admin_order_items_paginate.graphql";
import SHOPS_BRIEF from "@/graphql/pages/Order/shops_brief.graphql";
import COURIER_AND_COLLECTOR from "@/graphql/pages/Order/courier_and_collector.graphql";
import OrdersPageReviews from "@/views/orders/components/OrdersPageReviews.vue";
import YandexDeliveryInfo from "@/components/orders/YandexDeliveryInfo.vue";
import OrderPageHistory from "@/views/orders/components/OrderPageHistory.vue";
import InfoHintComponent from "@/components/UI/InfoHintComponent.vue";
export default {
  name: "OrderPage",
  inject: ["apollo"],
  mounted() {
    this.get();
    this.getTable();
  },

  data() {
    return {
      page: null,
      page_paginate: 1,
      first: 25,
      courier: null,
      collector: null,
      subCollectors: [],
      tableLoading: false,
      canceling: false,
      rows: {
        data: [],
        paginatorInfo: {
          total: 0,
        },
      },
      shops: [],
      ordering: [
        {
          orderColumn: "created_at",
          orderBy: "DESC",
        },
      ],
      columns: [
        {
          title: "",
          field: "id",
          // sortable: true,
          selectable: true,
          show: true,
          width: "120px",
        },
        {
          title: "Название",
          field: "title",
          // sortable: true,
          selectable: true,
          show: true,
          width: "minmax(180px, 2fr)",
        },
        {
          title: "Статус",
          field: "status",
          selectable: true,
          show: true,
          width: "190px",
        },
        {
          title: "Категория товара",
          field: "category",
          // sortable: true,
          selectable: true,
          show: true,
          width: "minmax(140px, 1fr)",
        },
        {
          title: "Количество",
          field: "quantity",
          // sortable: true,
          selectable: true,
          show: true,
          width: "110px",
        },
        {
          title: "Цена за ед.",
          field: "unit_price",
          selectable: true,
          show: true,
          width: "120px",
        },
        {
          title: "Стоимость",
          field: "price",
          show: true,
          system: true,
          width: "140px",
        },
      ],
    };
  },
  methods: {
    /**
     * Вес заказа для шапки деталей: "850 г" / "2.4 кг",
     * при is_weight_partial — приписка. null → пустая строка (строка скрыта).
     * total_weight — граммы, фиксируется при завершении сборки (LASTOCHKI-JAMI-11656).
     */
    formatOrderWeight(order) {
      const grams = order?.total_weight;
      if (grams === null || grams === undefined) return "";
      const label =
        grams < 1000
          ? `${grams} г`
          : `${(grams / 1000).toFixed(2).replace(/\.?0+$/, "")} кг`;
      return order.is_weight_partial
        ? `${label} (учтены не все позиции)`
        : label;
    },
    /**
     * Классификация позиции: canceled / replaced / supplemented / assembled / waiting.
     * Основывается на status_id, origin_product_id и is_supplemented.
     */
    itemState(row) {
      if (!row) return "waiting";
      if (row.status_id === 3) return "canceled";
      if (row.status_id === 2 && row.is_supplemented) return "supplemented";
      if (row.status_id === 2 && row.origin_product_id) return "replaced";
      if (row.status_id === 2) return "assembled";
      return "waiting";
    },
    itemStateLabel(row) {
      const state = this.itemState(row);
      const labels = {
        canceled: "Отменён",
        replaced: "Заменён",
        supplemented: "Добавлен",
      };
      return labels[state] || "";
    },
    /**
     * Полностью возвращена курьером при доставке: вернули всё собранное
     * количество (returned_quantity >= quantity). Партиальный возврат
     * (вернули часть) остаётся «Собран» с пометкой возврата.
     */
    isFullyReturned(row) {
      return (
        row?.returned_quantity > 0 &&
        row.returned_quantity >= (row.quantity || 0)
      );
    },
    /**
     * Позиция фактически не доставлена клиенту: отменена на сборке
     * (status_id=3) или полностью возвращена курьером.
     */
    isNotDelivered(row) {
      return row?.status_id === 3 || this.isFullyReturned(row);
    },
    /**
     * Статус позиции для колонки «Статус»: отражает факт сборки/отмены/
     * возврата (status_id + returned_quantity), без нюанса замены/добавления —
     * те остаются маркером у названия. 3 — отменён, полный возврат —
     * возвращён, 1 — ожидает сборки, остальное (2) — собран.
     */
    itemStatusKey(row) {
      if (!row) return "waiting";
      if (row.status_id === 3) return "canceled";
      if (this.isFullyReturned(row)) return "returned";
      if (row.status_id === 1) return "waiting";
      return "assembled";
    },
    itemStatusLabel(row) {
      const labels = {
        canceled: "Отменён",
        returned: "Возвращён",
        waiting: "Ожидает",
        assembled: "Собран",
      };
      return labels[this.itemStatusKey(row)] || "—";
    },
    /**
     * Категория(и) товара для колонки «Категория товара».
     * Product ↔ Category — many-to-many; показываем все названия через запятую.
     */
    categoryLabel(row) {
      const cats = row?.product?.categories;
      if (!cats || !cats.length) return "—";
      return cats.map((c) => c.title).filter(Boolean).join(", ") || "—";
    },
    orderStatusLabel(statusId) {
      const map = {
        1: "Создан",
        2: "На сборке",
        3: "Собран",
        4: "В доставке",
        5: this.page?.type_id === 1 ? "Выдан" : "Доставлен",
        6: "Отменён",
      };
      return map[statusId] ?? `Статус ${statusId}`;
    },
    formatQuantity(row) {
      const product = row.product;
      const actualWeight = row.actual_weight;
      const unitLabels = { 1: "шт", 2: "г", 3: "кг", 4: "л", 5: "мл" };

      // Весовой товар с фактическим весом после сборки
      if (actualWeight) {
        const originalWeight = this.getOriginalWeight(row);
        if (originalWeight && originalWeight !== actualWeight) {
          return `${actualWeight} гр (было ${originalWeight} гр)`;
        }
        return `${actualWeight} гр`;
      }

      if (product?.info_weight && product?.info_weight_type_id) {
        const unit = unitLabels[product.info_weight_type_id] || "шт";
        const weight = product.info_weight;
        if (row.quantity > 1) {
          return `${weight} ${unit} × ${row.quantity}`;
        }
        return `${weight} ${unit}`;
      }
      // Нет info_weight (детального веса) — единицу берём по info_weight_type_id
      // (null/штука → «шт»), уходя от устаревшего unit_type_id.
      const unit = unitLabels[product?.info_weight_type_id] || "шт";
      return `${row.quantity} ${unit}`;
    },
    /**
     * Заказанный вес в граммах (для товаров info_weight_type_id in [2,3]).
     * quantity после сборки уже пересчитан по факту (528 г при порции 300 г → 2),
     * заказанное — ordered_quantity (3 → 900 г). У замены «было» — другой товар,
     * его показывает блок замены, здесь не считаем.
     */
    getOriginalWeight(row) {
      const product = row.product;
      if (row.origin_product_id) return null;
      if (!product?.info_weight || !product?.info_weight_type_id) return null;
      const qty = row.ordered_quantity ?? row.quantity ?? 1;
      // 2 = граммы, 3 = кг
      if (product.info_weight_type_id === 2) {
        return product.info_weight * qty;
      }
      if (product.info_weight_type_id === 3) {
        return product.info_weight * 1000 * qty;
      }
      return null;
    },
    getLineTotal(row) {
      const supplementsTotal = (row.supplements || []).reduce((acc, supp) => {
        return (
          acc +
          (supp.values || []).reduce((vAcc, val) => vAcc + (val.price || 0), 0)
        );
      }, 0);
      // Если есть фактический вес — row.price уже итого за позицию (рассчитано в AssemblyFinish).
      if (row.actual_weight) {
        return (row.price || 0) + supplementsTotal;
      }
      const unitPrice = row.price_during_order || row.price || 0;
      return (unitPrice + supplementsTotal) * (row.quantity || 1);
    },
    // Зачёркнутая оригинальная цена позиции ДО скидки. null если скидки не
    // было или товар весовой (после сборки итог считается иначе).
    getLineTotalOld(row) {
      if (!row.price_old || row.actual_weight) return null;
      const unitEffective = row.price_during_order || row.price || 0;
      if (row.price_old <= unitEffective) return null;
      const supplementsTotal = (row.supplements || []).reduce((acc, supp) => {
        return (
          acc +
          (supp.values || []).reduce((vAcc, val) => vAcc + (val.price || 0), 0)
        );
      }, 0);
      return (row.price_old + supplementsTotal) * (row.quantity || 1);
    },
    // Цена за единицу товара (без учёта количества и добавок). Для весовых
    // товаров это цена за шаг веса (кг/100г), а не пересчитанный итог позиции.
    getUnitPrice(row) {
      return row.price_during_order ?? row.price ?? 0;
    },
    // Зачёркнутая исходная цена за единицу ДО скидки. null если скидки не было.
    getUnitPriceOld(row) {
      const unitEffective = row.price_during_order ?? row.price ?? 0;
      if (!row.price_old || row.price_old <= unitEffective) return null;
      return row.price_old;
    },
    // Цены от количества (MID-308): пометка у позиции со снапшотом лесенки, только под флагом.
    hasPriceTiers(row) {
      return this.priceTiersEnabled && (row.price_tiers?.tiers?.length ?? 0) > 0;
    },
    // Ступень, по которой посчитана цена за единицу: снапшот хранит цены за порцию, поэтому
    // сработавшую ступень находим по совпадению её цены с ценой позиции. null = обычная цена.
    appliedPriceTier(row) {
      const unitPrice = Number(this.getUnitPrice(row));
      const tiers = row.price_tiers?.tiers ?? [];
      return tiers.find((tier) => Math.abs(Number(tier.price) - unitPrice) < 0.005) ?? null;
    },
    formatTierQuantity(value) {
      return String(Number(value)).replace(".", ",");
    },
    handleFirstCount($event) {
      this.first = $event;
      this.page = 1;
      useFiltersStore().set(this.$route.name, { first: $event, page: 1 });
      this.getTable();
    },
    handlePaginate($event) {
      this.page_paginate = $event;
      this.getTable();
    },
    handleCheckedItems($event) {
      console.log($event);
    },
    onYandexClaimCancelled(newStatus) {
      this.page.yandex_delivery_status = newStatus;
    },
    cancelOrder() {
      useDefaultStore().modals.push({
        component: shallowRef(CancelOrderModal),
        options: {
          orderId: this.id,
          callback: () => {
            this.get();
            this.getTable();
          },
        },
      });
    },
    openShopModal() {
      // Список магазинов (1.5k строк, сотни KB) нужен только этой модалке —
      // грузим лениво при первом открытии, а не с каждой карточкой заказа.
      const push = () => {
        useDefaultStore().modals.push({
          component: shallowRef(ChangeStoreModal),
          options: {
            shops: this.shops,
            shop: this.page.shop,
            callback: () => {
              this.get();
              this.getTable();
            },
          },
        });
      };
      if (this.shops.length) {
        push();
        return;
      }
      this.apollo()
        .purchase.query({
          query: SHOPS_BRIEF,
        })
        .then(({ data }) => {
          this.shops = (data && data.shops) || [];
        })
        .catch(() => {})
        .finally(push);
    },
    getTable() {
      if (!this.tableLoading) {
        this.tableLoading = true;
        this.apollo()
          .purchase.query({
            query: ORDER_ITEMS,
            variables: {
              order_id: this.id,
              first: this.savedFilters.first,
              page: this.page_paginate,
            },
          })
          .then(({ data }) => {
            if (data && data.admin_order_items_paginate) {
              this.rows.data = data.admin_order_items_paginate.data;
              this.rows.paginatorInfo =
                data.admin_order_items_paginate.paginatorInfo;
            }
          })
          .catch(() => {
            console.warn("error");
          })
          .finally(() => {
            this.tableLoading = false;
          });
      }
    },
    get() {
      if (!this.loading) {
        this.loading = true;
        let variables = {
          id: this.id,
        };
        this.apollo()
          .purchase.query({
            query: ORDER,
            variables: variables,
          })
          .then(({ data }) => {
            if (data && data.admin_orders_item) {
              this.page = data.admin_orders_item;
              this.loading = false;
              if (this.page.childOrders && this.page.childOrders.length) {
                this.getSubCollectors();
              }
            }
          })
          .catch(() => {
            this.loading = false;
            console.warn("error");
          });

        this.apollo()
          .delivery.query({
            query: COURIER_AND_COLLECTOR,
            variables: {
              id: this.id,
            },
          })
          .then(({ data }) => {
            if (data) {
              this.courier = data.courier;
              this.collector = data.collector;
            }
          })
          .catch(() => {
            console.warn("error");
          });
      }
    },
    getSubCollectors() {
      // У заказа с зонами сборки Assembly создаётся на подзаказ (см. OrderPrepare
      // в delivery_back), а не на родителя — поэтому сборщика запрашиваем по id
      // каждого подзаказа отдельно, а не одним courier_and_collector по id родителя.
      Promise.all(
        this.page.childOrders.map((child) =>
          this.apollo()
            .delivery.query({
              query: COURIER_AND_COLLECTOR,
              variables: { id: child.id },
            })
            .then(({ data }) => ({ child, collector: data?.collector || null }))
            .catch(() => ({ child, collector: null }))
        )
      ).then((results) => {
        this.subCollectors = results;
      });
    },
  },
  computed: {
    savedFilters() {
      return {
        first: this.first,
        ...{
          ...useFiltersStore().get(this.$route.name),
        },
      };
    },
    assemblyZonesEnabled() {
      return useModuleConfigStore().isModuleEnabled("assembly_zones");
    },
    bonusesModuleEnabled() {
      return useModuleConfigStore().isModuleEnabled("bonuses");
    },
    // Честный знак (MID-266): коды маркировки под количеством позиции
    honestSignEnabled() {
      return useModuleConfigStore().isModuleEnabled("honest_sign");
    },
    // Цены от количества (MID-308)
    priceTiersEnabled() {
      return useModuleConfigStore().isModuleEnabled("price_tiers");
    },
    // Колонки таблицы состава. Под флагом price_tiers колонка «Цена за ед.» шире: в неё
    // встаёт пометка «цена от количества». При выключенном флаге набор колонок прежний.
    orderItemColumns() {
      return (this.columns || [])
        .filter((c) => c.show || c.system)
        .map((c) => (this.priceTiersEnabled && c.field === "unit_price" ? { ...c, width: "200px" } : c));
    },
    heavyOrdersModuleEnabled() {
      // Флоу тяжёлых заказов активен при heavy_orders ИЛИ courier_assignment
      // (совместная доставка входит в «Назначение курьера сборщиком»)
      const modules = useModuleConfigStore();
      return modules.isModuleEnabled("heavy_orders") || modules.isModuleEnabled("courier_assignment");
    },
    /**
     * Стоимость сборки — слагаемое итога (модуль assembly_cost).
     * Показываем и нулевую: расхождение «сумма товаров ≠ итог» должно
     * объясняться всеми слагаемыми, а не только ненулевыми.
     */
    showAssemblyCost() {
      return (
        useModuleConfigStore().isModuleEnabled("assembly_cost") &&
        this.page?.assembly_cost !== null &&
        this.page?.assembly_cost !== undefined
      );
    },
    /**
     * Стоимость доставки — показываем для доставки (в т.ч. 0 ₽, чтобы было
     * видно, что доставка бесплатная). Для самовывоза (type_id === 1) строки нет.
     */
    showDeliveryPrice() {
      return (
        this.page?.type_id !== 1 &&
        this.page?.delivery_price !== null &&
        this.page?.delivery_price !== undefined
      );
    },
    id() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [
        {
          to: {
            name: "desktop",
          },
          title: "Главная",
        },
        {
          title: `Заказ ${this.page?.number ?? this.page?.id}`,
        },
      ];
    },
  },
  components: {
    OrderPageHeader,
    OrderSyncStatus,
    IconComponent,
    BreadcrumbsComponent,
    AsideComponent,
    TableComponent,
    BaseWrapper,
    OrdersPageReviews,
    YandexDeliveryInfo,
    OrderPageHistory,
    InfoHintComponent,
  },
};
</script>

<style lang="stylus">
.order-page {

  &__order-number {
    display flex
    gap 16px
    align-items center
  }

  &__title {
    font-famly var(--font-bold)
    font-weight: 500;
    font-size: 24px;
  }

  &__actions {
    display flex
    gap 16px
    align-items center
  }

  &__table-wrapper {
    .base-wrapper__header {
      // Слагаемых итога много (товары, скидка, бонусы, сборка, доставка, вес):
      // фиксированная высота обрезала бы вторую строку на узких экранах
      height auto
      min-height 75px
      gap 16px
    }

    .base-wrapper__title {
      padding-top: 13px;
      white-space nowrap
    }

    .base-wrapper__actions {
      flex-wrap wrap
      justify-content flex-end
      column-gap 8px

      >span {
        // Пункт переносится целиком — разделитель «·» не отрывается от суммы
        white-space nowrap
        font-weight 500
        padding-top 16px
        font-size 0.9375em
        color var(--dark)
      }
    }
  }

  // Итоги заказа с подзаказами — тот же набор слагаемых отдельным блоком
  &__total {
    display flex
    flex-wrap wrap
    align-items center
    column-gap 8px

    >span,
    >strong {
      white-space nowrap
    }
  }

  &__store-change-btn {
    display flex
    width 199px
    height 48px
    justify-content space-between
    background: var(--white);
    border: 1px solid var(--white-2);
    border-radius: 6px;

    span {
      font-size 0.875rem
      color var(--gray-2)
    }

    .icon {
      width 16px
      height 16px

      svg path {
        fill var(--gray-2)
      }
    }
  }

  &__table {
    border-top 1px solid var(--gray-light)
    max-height: calc(100vh - 320px);

    // В составе заказа ячейка может содержать вертикальный стек
    // (название + «Был: …» + добавки/причина отмены). Общий util .rows__left
    // раскладывает контент в строку (flex-direction: row) и при узкой колонке
    // сжимает блоки до 1 символа в строку — поэтому форсим column.
    .rows__left {
      flex-direction column
      align-items flex-start
      gap 2px
    }

    & .table__pagination {
      background transparent
    }
    .pagination {
      height: 70px;
    }

    .table__container {
      border none

      .table__row--header .table__col {
        padding: 12px 15px;
      }

      .table__pagination {
        height: calc(100% - 75px) !important;
      }
    }
  }

  &__container {
    position relative
    display grid
    // minmax(0, 1fr): иначе минимум колонки = min-content широкой таблицы состава,
    // она забирает всю ширину, сводка сжимается до 100px и страница уезжает вправо.
    // Таблица прокручивается внутри своего контейнера (.table__container overflow auto)
    grid-template-columns minmax(280px, 386px) minmax(0, 1fr)
    grid-gap 16px
  }

  &__content {
    display flex
    flex-direction column
    gap 50px
  }

  &__content--btn {
    max-width 165px
    width 100%
  }

  &__supplements {
    display flex
    flex-direction column
    margin-top 4px
  }

  &__supplement-item {
    font-size 0.75rem
    color var(--gray-2)
    line-height 1.4
    padding-left 4px
  }

  &__title-row {
    display inline-flex
    align-items center
    gap 8px
    flex-wrap wrap

    &--canceled {
      color var(--gray-2)
      text-decoration line-through
    }
  }

  &__origin-title {
    font-size 0.75rem
    font-style italic
    color var(--gray-2)
    margin-top 2px
  }

  &__cancel-reason {
    font-size 0.75rem
    color var(--red)
    margin-top 2px
  }

  &__replace-reason {
    font-size 0.75rem
    color var(--orange-600, #ea580c)
    margin-top 2px
  }

  &__marker {
    display inline-flex
    align-items center
    padding 2px 8px
    border-radius 10px
    font-size 0.75rem
    font-weight 500
    line-height 1.4
    white-space nowrap

    &--assembled {
      background var(--green-o15, #dcfce7)
      color var(--green, #16a34a)
    }

    &--waiting {
      background var(--gray-o15, #f1f5f9)
      color var(--gray-2, #64748b)
    }

    &--canceled {
      background var(--red-o15, #fee2e2)
      color var(--red, #dc2626)
      text-decoration none
    }

    &--returned {
      background var(--red-o15, #fee2e2)
      color var(--red, #dc2626)
      text-decoration none
    }

    &--replaced {
      background var(--orange-o15, #fed7aa)
      color var(--orange-600, #ea580c)
    }

    &--supplemented {
      background var(--green-o15, #dcfce7)
      color var(--green, #16a34a)
    }

    &--pickup-only {
      background var(--purple-o15, #ede9fe)
      color var(--purple-600, #7c3aed)
    }

    // Цены от количества (MID-308)
    &--tiers {
      background var(--blue-o15, #dbeafe)
      color var(--blue-2, #2563eb)
    }
  }

  // Ячейка «Цена за ед.» позиции со снапшотом лесенки: пометка уходит на вторую строку.
  &__unit-price--tiers {
    flex-wrap wrap
    row-gap 3px
  }

  &__price-tiers {
    flex 0 0 100%
    display inline-flex
    align-items center
    gap 4px
  }

  &__price-tiers-line {
    display block

    &--active {
      font-weight 600
    }
  }

  &__status {
    display inline-flex
    flex-direction column
    align-items flex-start
    gap 4px
  }

  &__crossed {
    text-decoration line-through
    color var(--gray-2)
  }

  &__qty {
    display inline-flex
    flex-direction column
    align-items center
    gap 3px
  }

  // Коды маркировки (Честный знак, MID-266) под количеством позиции
  &__marking {
    display flex
    flex-direction column
    gap 2px
    margin-top 4px
  }

  &__marking-code {
    font-family ui-monospace, SFMono-Regular, Menlo, monospace
    font-size 0.7rem
    line-height 1.3
    color var(--gray-600, #4b5563)
    word-break break-all
  }

  &__returned {
    display inline-flex
    align-items center
    margin-top 4px
    padding 1px 7px
    border-radius 8px
    font-size 0.7rem
    font-weight 500
    line-height 1.4
    white-space nowrap
    background var(--red-o15, #fee2e2)
    color var(--red, #dc2626)
  }

  &__price-old {
    margin-right 0.375rem
    font-size 0.75rem
    color var(--gray-2)
    text-decoration line-through
  }

  &__missing-product {
    color var(--gray-2)
    font-style italic
  }

  &__suborder-header {
    display flex
    align-items center
    justify-content space-between
    width 100%
    gap 16px
  }

  &__suborder-zone {
    display inline-flex
    align-items center
    gap 6px
    margin-left 8px
    font-size 0.8125rem
    color var(--gray-2)

    &--none {
      font-style italic
    }
  }

  &__suborder-zone-color {
    width 12px
    height 12px
    border-radius 50%
    border 1px solid var(--white-2)
    flex-shrink 0
  }

  &__suborder-status-badge {
    cursor default !important
    padding 6px 12px !important
    border-radius 8px !important
    font-size 0.75rem !important
    pointer-events none

    .icon {
      display none
    }
  }

  &__suborder-meta {
    font-size 0.813rem
    color var(--dark)
    font-weight 400

    strong {
      font-weight 600
    }
  }

  &__suborder-title-right {
    font-size 0.875rem
    font-weight 500
    color var(--dark)
  }

  &__total {
    padding 12px 16px
    text-align right
    font-size 0.875rem
    color var(--dark)

    strong {
      font-weight 600
    }
  }

  &__heavy-badge {
    color #ff9500
    font-weight 600
  }
}
</style>
