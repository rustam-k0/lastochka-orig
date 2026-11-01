<template>
  <div class="delivery-price-ranges-editor" :class="{ 'delivery-price-ranges-editor--error': errors.length }">
    <div v-if="modelValue.length" class="delivery-price-ranges-editor__head">
      <span>От суммы заказа, ₽</span>
      <span></span>
      <span>Стоимость доставки, ₽</span>
      <span></span>
    </div>
    <div v-else class="delivery-price-ranges-editor__empty">Диапазонов нет: действует цена доставки зоны</div>
    <div v-for="(row, i) in modelValue" :key="i" class="delivery-price-ranges-editor__row">
      <InputComponent
        placeholder="от, ₽"
        :model-value="row.from_amount.value"
        :errors="row.from_amount.errors"
        :disabled="disabled"
        @update:model-value="updateCell(i, 'from_amount', $event)"
      />
      <span class="delivery-price-ranges-editor__arrow">→</span>
      <InputComponent
        placeholder="доставка, ₽"
        :model-value="row.price.value"
        :errors="row.price.errors"
        :disabled="disabled"
        @update:model-value="updateCell(i, 'price', $event)"
      />
      <button
        type="button"
        class="delivery-price-ranges-editor__remove"
        title="Удалить диапазон"
        :disabled="disabled"
        @click="remove(i)"
      >
        <IconComponent name="delete-bin-line" />
      </button>
    </div>
    <div v-if="errors.length" class="delivery-price-ranges-editor__errors">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="delivery-price-ranges-editor__footer">
      <button type="button" class="btn btn--sm" :disabled="disabled" @click="add">
        <IconComponent name="add-line" />
        <span>Добавить диапазон</span>
      </button>
      <span class="delivery-price-ranges-editor__hint">
        0 ₽: бесплатная доставка. Ниже первого диапазона действует цена доставки зоны. Сумма считается по
        товарам до промокода и бонусов.
      </span>
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import { deliveryPriceRangeRow } from "@/utils/deliveryPriceRanges.js";

/**
 * Редактор диапазонов стоимости доставки зоны (MID-280): строки «от суммы заказа → стоимость доставки».
 *
 * Управляемый компонент без собственного состояния (как PriceTiersEditor): проп modelValue не мутируется,
 * каждая правка уходит наружу новым массивом. Строка: { from_amount: { value, errors }, price: { value, errors } },
 * ошибки сервера delivery_price_ranges.N.from_amount и delivery_price_ranges.N.price раскладывает parseGqlErrors.
 */
export default {
  name: "DeliveryPriceRangesEditor",
  components: { InputComponent, IconComponent },
  emits: ["update:modelValue"],
  props: {
    modelValue: {
      type: Array,
      default: () => [],
    },
    // Общие ошибки поля delivery_price_ranges (например, «бесплатная доставка должна быть последним диапазоном»)
    errors: {
      type: Array,
      default: () => [],
    },
    disabled: Boolean,
  },
  methods: {
    add() {
      this.$emit("update:modelValue", [...this.modelValue, deliveryPriceRangeRow()]);
    },
    remove(i) {
      this.$emit(
        "update:modelValue",
        this.modelValue.filter((row, index) => index !== i)
      );
    },
    // Правка ячейки сбрасывает её ошибку: сообщение сервера относится к прежнему значению.
    updateCell(i, field, value) {
      this.$emit(
        "update:modelValue",
        this.modelValue.map((row, index) => (index === i ? { ...row, [field]: { value, errors: [] } } : row))
      );
    },
  },
};
</script>

<style lang="stylus">
.delivery-price-ranges-editor {
  display grid
  grid-gap 8px

  &__head,
  &__row {
    display grid
    grid-template-columns minmax(120px, 1fr) 24px minmax(120px, 1fr) 32px
    grid-gap 8px
    align-items start
  }

  &__head {
    font-size 0.8125rem
    color var(--dark-light)
  }

  &__empty,
  &__hint {
    font-size 0.8125rem
    line-height 140%
    color var(--dark-light)
  }

  &__arrow {
    align-self center
    text-align center
    color var(--dark-light)
  }

  &__remove {
    align-self center
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
      fill var(--red-400)
    }

    &:disabled {
      cursor default
      opacity 0.5
    }
  }

  &__errors {
    font-weight 400
    font-size 0.75rem
    line-height 15px
    color var(--red)
    display grid
    grid-gap 3px
  }

  &__footer {
    display flex
    align-items center
    gap 12px
    flex-wrap wrap

    .btn {
      flex-shrink 0
    }
  }
}
</style>
