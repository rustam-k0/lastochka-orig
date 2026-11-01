<template>
  <div class="price-tiers-editor" :class="{ 'price-tiers-editor--error': errors.length }">
    <div v-if="modelValue.length" class="price-tiers-editor__head">
      <span>От количества, {{ unitLabel }}</span>
      <span></span>
      <span>Цена за 1 {{ unitLabel }}, ₽</span>
      <span></span>
    </div>
    <div v-else class="price-tiers-editor__empty">Ступеней нет: действует обычная цена</div>
    <div v-for="(row, i) in modelValue" :key="i" class="price-tiers-editor__row">
      <InputComponent
        :placeholder="`от, ${unitLabel}`"
        :model-value="row.min_quantity.value"
        :errors="row.min_quantity.errors"
        :disabled="disabled"
        @update:model-value="updateCell(i, 'min_quantity', $event)"
      />
      <span class="price-tiers-editor__arrow">→</span>
      <InputComponent
        placeholder="цена, ₽"
        :model-value="row.value.value"
        :errors="row.value.errors"
        :disabled="disabled"
        @update:model-value="updateCell(i, 'value', $event)"
      />
      <button
        type="button"
        class="price-tiers-editor__remove"
        title="Удалить ступень"
        :disabled="disabled"
        @click="remove(i)"
      >
        <IconComponent name="delete-bin-line" />
      </button>
    </div>
    <div v-if="errors.length" class="price-tiers-editor__errors">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="price-tiers-editor__footer">
      <button type="button" class="btn btn--sm" :disabled="disabled" @click="add">
        <IconComponent name="add-line" />
        <span>Добавить ступень</span>
      </button>
      <span class="price-tiers-editor__hint">
        Порог и цена задаются за базовую единицу товара ({{ unitLabel }}). Цена ступени действует на все
        единицы позиции, каждая следующая ступень дешевле предыдущей.
      </span>
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import IconComponent from "@/components/IconComponent.vue";

/**
 * Редактор лесенки «цены от количества» (MID-308): строки «от N единиц → цена».
 *
 * Управляемый компонент без собственного состояния: проп modelValue не мутируется,
 * каждая правка уходит наружу новым массивом. Поэтому родитель может отдавать строки,
 * собранные на лету из ответа API, и заводить буфер правок только на первом изменении.
 * Строка: { min_quantity: { value, errors }, value: { value, errors } }, ошибки
 * сервера price_tiers.N.min_quantity и price_tiers.N.value раскладывает parseGqlErrors.
 */
export default {
  name: "PriceTiersEditor",
  components: { InputComponent, IconComponent },
  emits: ["update:modelValue"],
  props: {
    modelValue: {
      type: Array,
      default: () => [],
    },
    // Общие ошибки поля price_tiers (например, «каждая следующая ступень дешевле предыдущей»)
    errors: {
      type: Array,
      default: () => [],
    },
    // Подпись базовой единицы товара: шт, килограмм, литр
    unitLabel: {
      type: String,
      default: "ед.",
    },
    disabled: Boolean,
  },
  methods: {
    add() {
      this.$emit("update:modelValue", [
        ...this.modelValue,
        { min_quantity: { value: "", errors: [] }, value: { value: "", errors: [] } },
      ]);
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
.price-tiers-editor {
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
