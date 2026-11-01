<template>
  <div class="delivery-pickup-tab">
    <!-- Левая колонка: Доставка -->
    <BaseWrapper class="delivery-pickup-tab__col">
      <template #title> Доставка </template>
      <template #actions>
        <button
          v-if="isAdmin && isEdit"
          class="btn btn--md"
          @click="openZone()"
        >
          <IconComponent name="add-circle-line" />
          <span>Добавить зону</span>
        </button>
      </template>
      <template #default>
        <div class="delivery-pickup-tab__delivery">
          <!-- Таблица зон -->
          <template v-if="isAdmin && isEdit">
            <div v-if="zones.length" class="delivery-pickup-tab__table-wrap">
              <table class="delivery-pickup-tab__table">
                <thead>
                  <tr>
                    <th class="delivery-pickup-tab__num">№</th>
                    <th>Наименование зоны</th>
                    <th>Тип доставки</th>
                    <th>Стоимость доставки</th>
                    <th>Мин. сумма заказа</th>
                    <th>Стоимость от суммы</th>
                    <th class="delivery-pickup-tab__active-col">Активна?</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="(item, i) in zones"
                    :key="item.id || i"
                    class="delivery-pickup-tab__zone-row"
                    @click="openZone(item, i)"
                  >
                    <td class="delivery-pickup-tab__num">{{ i + 1 }}</td>
                    <td>{{ item.name || 'Зона ' + (i + 1) }}</td>
                    <td>{{ deliveryTypeLabel(item.delivery_type) }}</td>
                    <td>{{ formatPrice(item.delivery_price) }}</td>
                    <td>{{ formatPrice(item.min_order_price) }}</td>
                    <td>{{ formatRanges(item) }}</td>
                    <td class="delivery-pickup-tab__active-col" @click.stop>
                      <FlagCheckboxComponent
                        green
                        :model-value="item.is_active !== false"
                        @update:model-value="val => toggleActive(item, val)"
                      />
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="delivery-pickup-tab__empty">
              Зоны доставки ещё не добавлены. Нажмите «Добавить зону».
            </div>
          </template>
          <div v-else-if="isAdmin" class="delivery-pickup-tab__empty">
            Сохраните магазин, чтобы добавить зоны доставки.
          </div>

          <!-- Стоимость сборки магазина -->
          <div
            v-if="isAdmin && assemblyCostEnabled"
            class="delivery-pickup-tab__row"
          >
            <span>Стоимость сборки магазина (₽)</span>
            <InputComponent
              v-model.number="form.assembly_price.value"
              :errors="form.assembly_price.errors"
              placeholder="Сумма за сборку, напр. 150"
              type="number"
              :min="0"
              :messages="['Единая стоимость сборки для всех заказов магазина']"
            />
          </div>

          <!-- Инфо-плашка -->
          <div class="delivery-pickup-tab__info">
            Зоны доставки определяют где доступна доставка из этого магазина и её условия.
            Адрес клиента проверяется по контуру зоны при оформлении заказа.
          </div>
        </div>
      </template>
    </BaseWrapper>

    <!-- Правая колонка: Самовывоз -->
    <BaseWrapper v-if="isAdmin" class="delivery-pickup-tab__col">
      <template #title> Самовывоз </template>
      <template #default>
        <div class="delivery-pickup-tab__pickup">
          <div class="delivery-pickup-tab__row">
            <FlagCheckboxComponent v-model="form.is_pickup_enabled.value" green>
              Самовывоз включён
            </FlagCheckboxComponent>
            <span class="delivery-pickup-tab__hint">
              Клиент сможет забрать заказ из магазина самостоятельно.
            </span>
          </div>

          <template v-if="form.is_pickup_enabled.value">
            <div class="delivery-pickup-tab__row">
              <span>Мин. сумма для самовывоза</span>
              <InputComponent
                v-model.number="form.min_pickup_order_amount.value"
                :errors="form.min_pickup_order_amount.errors"
                placeholder="Как для доставки"
                type="number"
              />
            </div>

            <div class="delivery-pickup-tab__row">
              <span>Оплата при самовывозе</span>
              <div class="delivery-pickup-tab__checkbox-group">
                <CheckboxComponent
                  v-if="cardPaymentEnabled"
                  :model-value="form.pickup_payment_methods.value.includes('card')"
                  @update:model-value="val => togglePickupPayment('card', val)"
                  green
                >Картой онлайн</CheckboxComponent>
                <CheckboxComponent
                  :model-value="form.pickup_payment_methods.value.includes('cash')"
                  @update:model-value="val => togglePickupPayment('cash', val)"
                  green
                >Оплата при получении</CheckboxComponent>
              </div>
            </div>

            <div class="delivery-pickup-tab__pickup-divider"></div>

            <div class="delivery-pickup-tab__row">
              <FlagCheckboxComponent v-model="form.pickup_auto_cancel_enabled.value" green>
                Автоматическая отмена самовывоза
              </FlagCheckboxComponent>
              <span class="delivery-pickup-tab__hint">
                Невыкупленный заказ отменяется автоматически по истечении времени хранения.
              </span>
            </div>

            <template v-if="form.pickup_auto_cancel_enabled.value">
              <div :class="isPerishableEnabled ? 'delivery-pickup-tab__col-2' : ''">
                <div v-if="isPerishableEnabled" class="delivery-pickup-tab__row">
                  <span>Хранение скоропортящихся, ч</span>
                  <InputComponent
                    v-model.number="form.pickup_wait_perishable_hours.value"
                    :errors="form.pickup_wait_perishable_hours.errors"
                    placeholder="1"
                    type="number"
                  />
                </div>
                <div class="delivery-pickup-tab__row">
                  <span>Хранение обычных товаров, ч</span>
                  <InputComponent
                    v-model.number="form.pickup_wait_regular_hours.value"
                    :errors="form.pickup_wait_regular_hours.errors"
                    placeholder="3"
                    type="number"
                  />
                </div>
              </div>
              <div class="delivery-pickup-tab__row">
                <span>Доп. время до отмены, ч</span>
                <InputComponent
                  v-model.number="form.pickup_grace_period_hours.value"
                  :errors="form.pickup_grace_period_hours.errors"
                  placeholder="1"
                  type="number"
                />
              </div>
            </template>
          </template>
        </div>
      </template>
    </BaseWrapper>
  </div>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import SHOP_ZONE_SET_ACTIVE from "@/graphql/pages/Stores/ShopZoneSetActive.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import { formatDeliveryPriceRanges, zoneDeliveryPriceRanges } from "@/utils/deliveryPriceRanges.js";

export default {
  name: "DeliveryPickupTab",
  inject: ["apollo"],
  components: {
    BaseWrapper,
    IconComponent,
    InputComponent,
    CheckboxComponent,
    FlagCheckboxComponent,
  },
  props: {
    form: {
      type: Object,
      required: true,
    },
    isAdmin: {
      type: Boolean,
      default: false,
    },
    isEdit: {
      type: Boolean,
      default: false,
    },
  },
  emits: ["open-zone"],
  data() {
    return {
      activeZone: 0,
    };
  },
  computed: {
    zones() {
      return this.form.zones.value || [];
    },
    assemblyCostEnabled() {
      return useModuleConfigStore().isModuleEnabled("assembly_cost");
    },
    cardPaymentEnabled() {
      return useModuleConfigStore().isModuleEnabled("pickup_card_payment");
    },
    // Модуль delivery_price_ranges (MID-280): выключен — колонка «Стоимость от суммы» всегда «—».
    deliveryPriceRangesEnabled() {
      return useModuleConfigStore().isModuleEnabled("delivery_price_ranges");
    },
    isPerishableEnabled() {
      return useCheckboxConfigStore().isEnabled("Category", "is_perishable");
    },
  },
  methods: {
    deliveryTypeLabel(type) {
      if (type === "yandex") return "Яндекс Доставка";
      return "Свои курьеры";
    },
    formatPrice(value) {
      if (value === null || value === undefined || value === "") return "—";
      const num = Number(value);
      if (Number.isNaN(num)) return "—";
      return num.toLocaleString("ru-RU") + " ₽";
    },
    // Диапазоны стоимости доставки зоны (MID-280): «от 1 000 ₽: 100 ₽; от 3 000 ₽: бесплатно» или «—».
    // Модуль выключен — колонка всегда показывает прочерк.
    formatRanges(zone) {
      if (!this.deliveryPriceRangesEnabled) return "—";
      return formatDeliveryPriceRanges(zoneDeliveryPriceRanges(zone), this.formatPrice);
    },
    togglePickupPayment(method, enabled) {
      const current = [...this.form.pickup_payment_methods.value];
      if (enabled && !current.includes(method)) {
        current.push(method);
      } else if (!enabled) {
        const idx = current.indexOf(method);
        if (idx !== -1) current.splice(idx, 1);
      }
      this.form.pickup_payment_methods.value = current;
    },
    openZone(item, i) {
      if (typeof i === "number") this.activeZone = i;
      this.$emit("open-zone", item, i);
    },
    setZoneActive(id, value) {
      // Обновляем ТОЛЬКО одну зону иммутабельно, без get() родителя — иначе безусловный
      // form[key].value = data.shop[key] перезатёр бы несохранённые правки формы
      // (например, введённую «Стоимость сборки магазина» на этом же экране).
      this.form.zones.value = (this.form.zones.value || []).map((z) =>
        z.id === id ? { ...z, is_active: value } : z
      );
    },
    toggleActive(item, val) {
      if (!item.id) return;
      const prev = item.is_active;
      // Оптимистично меняем визуальное состояние строки.
      this.setZoneActive(item.id, val);
      this.apollo()
        .admin.mutate({
          mutation: SHOP_ZONE_SET_ACTIVE,
          variables: {
            id: item.id,
            is_active: val,
          },
        })
        .then(({ data }) => {
          if (data.ShopZoneSetActive && data.ShopZoneSetActive.code === 500) {
            // Откат визуального состояния при ошибке бэка.
            this.setZoneActive(item.id, prev);
            alert("Не удалось изменить активность зоны");
          }
        })
        .catch(() => {
          this.setZoneActive(item.id, prev);
          console.log("error");
        });
    },
  },
};
</script>

<style lang="stylus">
.delivery-pickup-tab {
  display grid
  grid-template-columns 1fr 1fr
  gap 16px
  align-items start

  &__col {
    min-width 0
  }

  &__delivery,
  &__pickup {
    display flex
    flex-direction column
    gap 16px
  }

  &__row {
    display flex
    flex-direction column
    gap 8px

    > span {
      font-weight 500
      font-size 0.813em
      line-height 18px
    }
  }

  &__hint {
    color var(--gray-dark-2, #8C8C8C)
    font-size 0.8125em
    line-height 140%
  }

  &__col-2 {
    display grid
    grid-template-columns 1fr 1fr
    gap 16px
  }

  &__checkbox-group {
    display flex
    flex-direction column
    gap 8px
  }

  &__pickup-divider {
    border-top 1px solid var(--white-2)
  }

  &__table-wrap {
    overflow-x auto
    border 1px solid var(--gray-light)
    border-radius 12px
  }

  &__table {
    width 100%
    border-collapse collapse
    font-size 0.813rem

    th, td {
      text-align left
      padding 12px 14px
      white-space nowrap
    }

    thead th {
      font-weight 500
      color var(--gray-dark-2, #757575)
      background var(--gray-3, #F9FBFC)
      border-bottom 1px solid var(--gray-light)
    }

    tbody td {
      color var(--dark)
      border-bottom 1px solid var(--gray-light)
    }

    tbody tr:last-child td {
      border-bottom none
    }
  }

  &__zone-row {
    cursor pointer
    transition background .15s

    &:hover td {
      background var(--gray-3, #F9FBFC)
    }
  }

  &__num {
    width 48px
    color var(--gray-dark-2, #757575)
  }

  &__active-col {
    width 96px
  }

  &__empty {
    padding 20px
    color var(--dark-light)
    font-size 0.875rem
    background var(--gray-3, #F9FBFC)
    border-radius 12px
    text-align center
  }

  &__info {
    background var(--blue-light, #F0FAFF)
    border 1px solid var(--blue-50, #E6F6FF)
    border-radius 12px
    padding 14px 16px
    color var(--blue-600, #0082CC)
    font-size 0.813rem
    line-height 150%
  }
}

@media (max-width 1024px) {
  .delivery-pickup-tab {
    grid-template-columns 1fr
  }
}
</style>
