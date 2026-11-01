<template>
  <div class="default-modal edit-variant-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Редактировать вариант #{{ variant.id }}</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>

    <div class="default-modal__body edit-variant-modal__body">
      <div
        v-for="ca in configurableAttributes"
        :key="ca.id"
        class="edit-variant-modal__field"
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

      <div class="edit-variant-modal__note">
        SKU не обновляется автоматически при смене значений — измените вручную если нужно.
      </div>

      <InputComponent
        v-model="form.sku.value"
        :errors="form.sku.errors"
        title="SKU"
        placeholder="SKU"
      />

      <div class="edit-variant-modal__inputs">
        <InputComponent
          v-model="form.price.value"
          :errors="form.price.errors"
          title="Цена"
          type="number"
        />
        <InputComponent
          v-model="form.stock.value"
          :errors="form.stock.errors"
          title="Остаток"
          type="number"
        />
      </div>

      <InputComponent
        v-model="form.title.value"
        :errors="form.title.errors"
        title="Название"
      />
    </div>

    <div class="default-modal__footer">
      <button @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button @click.prevent="submit" class="btn btn--main btn--md" :disabled="submitting">
        {{ submitting ? 'Сохранение…' : 'Сохранить' }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import UPDATE_VARIANT from "@/graphql/mutations/ConfigurableProductUpdateVariant.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";

export default {
  name: "EditVariantDialog",
  inject: ["apollo"],
  components: { IconComponent, InputComponent, SelectComponent },
  props: {
    variant: { type: Object, required: true },
    configurableAttributes: { type: Array, default: () => [] },
  },
  emits: ["close", "success"],
  data() {
    return {
      submitting: false,
      attrValueSelectModels: {},
      attributeValueErrors: {},
      form: {
        sku: { value: null, errors: [] },
        price: { value: null, errors: [] },
        stock: { value: null, errors: [] },
        title: { value: null, errors: [] },
      },
    };
  },
  mounted() {
    this.form.sku.value = this.variant.articul;
    this.form.price.value = this.variant.default_price?.value ?? null;
    this.form.stock.value = this.variant.remains?.[0]?.quantity ?? null;
    this.form.title.value = this.variant.title;

    for (const ca of this.configurableAttributes) {
      const attrId = parseInt(ca.attribute?.id);
      const pa = (this.variant.attributes || []).find(
        (a) => parseInt(a.attribute_id) === attrId
      );
      if (pa) {
        const val = (ca.attribute?.values || []).find(
          (v) => parseInt(v.id) === parseInt(pa.attribute_value_id)
        );
        if (val) {
          this.attrValueSelectModels[attrId] = val;
        }
      }
    }
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
    selectedAttrPairs() {
      const pairs = [];
      for (const ca of this.configurableAttributes) {
        const attrId = parseInt(ca.attribute?.id);
        const selected = this.attrValueSelectModels[attrId];
        if (selected) {
          pairs.push({
            attribute_id: attrId,
            attribute_value_id: parseInt(selected.id),
          });
        }
      }
      return pairs;
    },
    async submit() {
      if (this.submitting) return;
      if (!this.validate()) return;

      this.submitting = true;
      try {
        const { data } = await this.apollo().admin.mutate({
          mutation: UPDATE_VARIANT,
          variables: {
            variant_id: parseInt(this.variant.id),
            attribute_values: this.selectedAttrPairs(),
            sku: this.form.sku.value,
            price: this.form.price.value !== null && this.form.price.value !== ""
              ? parseFloat(this.form.price.value)
              : null,
            stock: this.form.stock.value !== null && this.form.stock.value !== ""
              ? parseInt(this.form.stock.value)
              : null,
            title: this.form.title.value,
          },
        });
        const resp = data?.ConfigurableProductUpdateVariant;
        if (resp?.code === 200) {
          this.$emit("success", resp);
        } else {
          alert(resp?.message || "Не удалось сохранить");
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
.edit-variant-modal
  width 520px
  max-width 95vw
  max-height 90vh
  overflow-y auto

  &__body
    display flex
    flex-direction column
    gap 12px

  &__note
    font-size 12px
    color #8a6d3b
    background #fcf8e3
    border 1px solid #faebcc
    padding 6px 10px
    border-radius 4px

  &__inputs
    display grid
    grid-template-columns 1fr 1fr
    gap 12px
</style>
