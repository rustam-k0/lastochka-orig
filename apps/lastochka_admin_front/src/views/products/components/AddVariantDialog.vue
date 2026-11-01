<template>
  <div class="default-modal add-variant-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Добавить вариант</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>

    <div class="default-modal__body add-variant-modal__body">
      <div
        v-for="ca in configurableAttributes"
        :key="ca.id"
        class="add-variant-modal__field"
      >
        <SelectComponent
          :title="ca.attribute?.title || 'Атрибут'"
          placeholder="Выберите значение"
          :options="(ca.attribute?.values || [])"
          label-name="title"
          :model-value="attrValueSelectModels[ca.attribute?.id] || null"
          @update:model-value="handleAttrValueChange(ca.attribute?.id, $event)"
          :errors="attributeValueErrors[ca.attribute?.id] || []"
          required
        />
      </div>

      <InputComponent
        v-model="form.sku.value"
        :errors="form.sku.errors"
        title="SKU варианта"
        :placeholder="previewSku || 'SKU'"
      />
      <div class="add-variant-modal__hint">
        {{ previewSku ? `Превью: ${previewSku}` : 'Выберите значения атрибутов для генерации превью SKU' }}
      </div>

      <div class="add-variant-modal__inputs">
        <InputComponent
          v-model="form.price.value"
          :errors="form.price.errors"
          title="Цена (опционально)"
          type="number"
          placeholder="0"
        />
        <InputComponent
          v-model="form.stock.value"
          :errors="form.stock.errors"
          title="Остаток (опционально)"
          type="number"
          placeholder="0"
        />
      </div>

      <InputComponent
        v-model="form.title.value"
        :errors="form.title.errors"
        title="Название (опционально)"
        placeholder="По умолчанию — название родителя"
      />
    </div>

    <div class="default-modal__footer">
      <button @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button @click.prevent="submit" class="btn btn--main btn--md" :disabled="submitting">
        {{ submitting ? 'Создание…' : 'Создать вариант' }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import ADD_VARIANT from "@/graphql/mutations/ConfigurableProductAddVariant.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";

export default {
  name: "AddVariantDialog",
  inject: ["apollo"],
  components: { IconComponent, InputComponent, SelectComponent },
  props: {
    parentId: { type: [Number, String], required: true },
    parentArticul: { type: String, default: "" },
    configurableAttributes: { type: Array, default: () => [] },
  },
  emits: ["close", "success"],
  data() {
    return {
      submitting: false,
      attrValueSelectModels: {}, // attrId -> {id, title, code}
      attributeValueErrors: {}, // attrId -> string[]
      form: {
        sku: { value: null, errors: [] },
        price: { value: null, errors: [] },
        stock: { value: null, errors: [] },
        title: { value: null, errors: [] },
      },
    };
  },
  computed: {
    selectedAttrPairs() {
      const pairs = [];
      for (const ca of this.configurableAttributes) {
        const attrId = parseInt(ca.attribute?.id);
        const selected = this.attrValueSelectModels[attrId];
        if (selected) {
          pairs.push({
            attribute_id: attrId,
            attribute_value_id: parseInt(selected.id),
            code: selected.code || selected.title?.toLowerCase().replace(/\s+/g, "_") || String(selected.id),
          });
        }
      }
      return pairs;
    },
    previewSku() {
      if (!this.selectedAttrPairs.length) return "";
      const base = this.parentArticul || `CONF-${this.parentId}`;
      return `${base}-${this.selectedAttrPairs.map((p) => p.code).join("-")}`;
    },
  },
  methods: {
    handleAttrValueChange(attrId, value) {
      this.attrValueSelectModels = { ...this.attrValueSelectModels, [attrId]: value };
      this.attributeValueErrors = { ...this.attributeValueErrors, [attrId]: [] };
    },
    validate() {
      resetErrors(this.form);
      this.attributeValueErrors = {};

      let ok = true;
      for (const ca of this.configurableAttributes) {
        const attrId = parseInt(ca.attribute?.id);
        if (!this.attrValueSelectModels[attrId]) {
          this.attributeValueErrors = {
            ...this.attributeValueErrors,
            [attrId]: ["Выберите значение"],
          };
          ok = false;
        }
      }
      return ok;
    },
    async submit() {
      if (this.submitting) return;
      if (!this.validate()) return;

      this.submitting = true;
      try {
        const { data } = await this.apollo().admin.mutate({
          mutation: ADD_VARIANT,
          variables: {
            parent_id: parseInt(this.parentId),
            attribute_values: this.selectedAttrPairs.map((p) => ({
              attribute_id: p.attribute_id,
              attribute_value_id: p.attribute_value_id,
            })),
            sku: this.form.sku.value || this.previewSku,
            price: this.form.price.value ? parseFloat(this.form.price.value) : null,
            stock: this.form.stock.value ? parseInt(this.form.stock.value) : null,
            title: this.form.title.value,
          },
        });
        const resp = data?.ConfigurableProductAddVariant;
        if (resp?.code === 200) {
          this.$emit("success", resp);
        } else {
          alert(resp?.message || "Не удалось создать вариант");
        }
      } catch (error) {
        parseGqlErrors(error, this.form);
        if (!error?.graphQLErrors?.length) {
          alert(error?.message || "Ошибка сети");
        }
      } finally {
        this.submitting = false;
      }
    },
  },
};
</script>

<style lang="stylus">
.add-variant-modal
  width 520px
  max-width 95vw
  max-height 90vh
  overflow-y auto

  &__body
    display flex
    flex-direction column
    gap 12px

  &__field
    display flex
    flex-direction column

  &__inputs
    display grid
    grid-template-columns 1fr 1fr
    gap 12px

  &__hint
    font-size 12px
    color #777
</style>
