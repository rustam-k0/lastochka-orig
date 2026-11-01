<template>
  <div class="shop-multi-target">
    <label class="shop-multi-target__label">Магазины <span class="required">*</span></label>
    <label class="shop-multi-target__all">
      <CheckboxComponent
        :model-value="modelValue.all_shops"
        @update:model-value="setAllShops"
      />
      <span>Во всех магазинах сети</span>
    </label>
    <SelectComponent
      v-if="!modelValue.all_shops"
      placeholder="Выберите магазины"
      label-name="title"
      multiple
      clearable
      :model-value="modelValue.shops"
      :options="options"
      :loading="loading"
      @update:search="$emit('search', $event)"
      @update:model-value="setShops"
    />
    <span v-if="error" class="shop-multi-target__error">{{ error }}</span>
  </div>
</template>

<script>
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";

/**
 * Выбор целевых магазинов при создании merchant-сущности в сетевом каталоге
 * (MID-237/238/229): чекбокс «Во всех магазинах сети» либо мультиселект
 * конкретных магазинов. modelValue: { all_shops: Boolean, shops: Shop[] }.
 */
export default {
  name: "ShopMultiTargetField",
  components: { CheckboxComponent, SelectComponent },
  props: {
    modelValue: {
      type: Object,
      default: () => ({ all_shops: false, shops: [] }),
    },
    options: {
      type: Array,
      default: () => [],
    },
    loading: {
      type: Boolean,
      default: false,
    },
    error: {
      type: String,
      default: "",
    },
  },
  emits: ["update:modelValue", "search"],
  methods: {
    setAllShops(value) {
      this.$emit("update:modelValue", {
        ...this.modelValue,
        all_shops: Boolean(value),
      });
    },
    setShops(shops) {
      this.$emit("update:modelValue", {
        ...this.modelValue,
        shops: Array.isArray(shops) ? shops : [],
      });
    },
  },
};
</script>

<style lang="stylus">
.shop-multi-target {
  display flex
  flex-direction column
  gap 8px

  &__label {
    font-weight 500
    font-size 0.875rem
    color var(--dark)

    .required {
      color var(--red)
    }
  }

  &__all {
    display flex
    align-items center
    gap 8px
    cursor pointer
    font-size 0.875rem
  }

  &__error {
    color var(--red)
    font-size 0.75rem
  }
}
</style>
