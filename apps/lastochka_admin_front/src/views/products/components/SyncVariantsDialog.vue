<template>
  <div class="default-modal sync-variants-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Добавить значение атрибута</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>

    <div class="default-modal__body sync-variants-modal__body">
      <div class="sync-variants-modal__step-1">
        <SelectComponent
          v-model="selectedAttribute"
          :errors="errors.attribute"
          :options="availableAttributes"
          label-name="title"
          title="Атрибут"
          placeholder="Выберите атрибут"
          required
          @update:model-value="handleAttributeChange"
        />
        <SelectComponent
          v-model="selectedValue"
          :errors="errors.value"
          :options="availableValues"
          label-name="title"
          title="Новое значение"
          placeholder="Выберите значение"
          required
          @update:model-value="handleValueChange"
        />
        <button
          class="btn btn--md btn--outline"
          :disabled="!selectedAttribute || !selectedValue || loadingPreview"
          @click="loadPreview"
        >
          {{ loadingPreview ? 'Расчёт…' : 'Посчитать комбинации' }}
        </button>
      </div>

      <div v-if="preview.length" class="sync-variants-modal__preview">
        <h4>Будет создано: {{ selectedCombinations.length }} из {{ preview.length }}</h4>
        <table class="sync-variants-modal__table">
          <thead>
            <tr>
              <th style="width: 40px"></th>
              <th>Атрибуты</th>
              <th style="width: 160px">SKU</th>
              <th style="width: 100px">Цена</th>
              <th style="width: 90px">Остаток</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in preview" :key="idx">
              <td>
                <CheckboxComponent
                  :model-value="row.selected"
                  @update:model-value="toggleRow(idx)"
                />
              </td>
              <td>
                <span v-for="(lbl, i) in row.labels" :key="i">
                  {{ lbl }}<template v-if="i < row.labels.length - 1"> / </template>
                </span>
              </td>
              <td>
                <input
                  class="sync-variants-modal__input"
                  type="text"
                  :value="row.sku"
                  @input="row.sku = $event.target.value"
                  :placeholder="row.previewSku"
                />
              </td>
              <td>
                <input
                  class="sync-variants-modal__input"
                  type="number"
                  min="0"
                  step="0.01"
                  :value="row.price"
                  @input="row.price = $event.target.value"
                  placeholder="—"
                />
              </td>
              <td>
                <input
                  class="sync-variants-modal__input"
                  type="number"
                  min="0"
                  step="1"
                  :value="row.stock"
                  @input="row.stock = $event.target.value"
                  placeholder="—"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else-if="previewLoaded && !preview.length" class="sync-variants-modal__empty">
        Все комбинации с этим значением уже созданы
      </div>
    </div>

    <div class="default-modal__footer">
      <button @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button
        @click.prevent="submit"
        class="btn btn--main btn--md"
        :disabled="!selectedCombinations.length || submitting"
      >
        {{ submitting ? 'Создание…' : `Создать ${selectedCombinations.length}` }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import PREVIEW from "@/graphql/queries/configurable_preview_new_combinations.graphql";
import SYNC from "@/graphql/mutations/ConfigurableProductSyncVariants.graphql";

export default {
  name: "SyncVariantsDialog",
  inject: ["apollo"],
  components: { IconComponent, SelectComponent, CheckboxComponent },
  props: {
    parentId: { type: [Number, String], required: true },
    parentArticul: { type: String, default: "" },
    configurableAttributes: { type: Array, default: () => [] },
    existingChildren: { type: Array, default: () => [] },
  },
  emits: ["close", "success"],
  data() {
    return {
      submitting: false,
      loadingPreview: false,
      previewLoaded: false,
      selectedAttribute: null,
      selectedValue: null,
      errors: { attribute: [], value: [] },
      preview: [],
    };
  },
  computed: {
    availableAttributes() {
      return this.configurableAttributes.map((ca) => ca.attribute).filter(Boolean);
    },
    availableValues() {
      if (!this.selectedAttribute) return [];
      const attr = this.configurableAttributes.find(
        (ca) => parseInt(ca.attribute?.id) === parseInt(this.selectedAttribute.id)
      );
      if (!attr) return [];
      const usedValueIds = new Set();
      for (const child of this.existingChildren) {
        for (const a of (child.attributes || [])) {
          if (parseInt(a.attribute_id) === parseInt(this.selectedAttribute.id)) {
            usedValueIds.add(parseInt(a.attribute_value_id));
          }
        }
      }
      return (attr.attribute?.values || []).filter((v) => !usedValueIds.has(parseInt(v.id)));
    },
    selectedCombinations() {
      return this.preview.filter((row) => row.selected);
    },
  },
  methods: {
    handleAttributeChange() {
      this.selectedValue = null;
      this.preview = [];
      this.previewLoaded = false;
      this.errors = { attribute: [], value: [] };
    },
    handleValueChange() {
      this.preview = [];
      this.previewLoaded = false;
    },
    getAttributeLabel(attrId) {
      const ca = this.configurableAttributes.find(
        (x) => parseInt(x.attribute?.id) === parseInt(attrId)
      );
      return ca?.attribute?.title || "—";
    },
    getValueLabelAndCode(attrId, valueId) {
      const ca = this.configurableAttributes.find(
        (x) => parseInt(x.attribute?.id) === parseInt(attrId)
      );
      const val = (ca?.attribute?.values || []).find(
        (v) => parseInt(v.id) === parseInt(valueId)
      );
      return {
        label: val?.title || "—",
        code: val?.code || val?.title?.toLowerCase().replace(/\s+/g, "_") || String(valueId),
      };
    },
    previewSkuFor(items) {
      const base = this.parentArticul || `CONF-${this.parentId}`;
      const codes = items.map((i) => this.getValueLabelAndCode(i.attribute_id, i.attribute_value_id).code);
      return `${base}-${codes.join("-")}`;
    },
    async loadPreview() {
      if (!this.selectedAttribute || !this.selectedValue) {
        this.errors = {
          attribute: this.selectedAttribute ? [] : ["Обязательное поле"],
          value: this.selectedValue ? [] : ["Обязательное поле"],
        };
        return;
      }

      this.loadingPreview = true;
      try {
        const { data } = await this.apollo().admin.query({
          query: PREVIEW,
          variables: {
            parent_id: parseInt(this.parentId),
            attribute_id: parseInt(this.selectedAttribute.id),
            attribute_value_id: parseInt(this.selectedValue.id),
          },
          fetchPolicy: "network-only",
        });
        const rows = data?.configurableProductPreviewNewCombinations || [];
        this.preview = rows.map((r) => {
          const items = (r.attribute_values || []).map((av) => ({
            attribute_id: parseInt(av.attribute_id),
            attribute_value_id: parseInt(av.attribute_value_id),
          }));
          const labels = items.map(
            (i) => `${this.getAttributeLabel(i.attribute_id)}: ${this.getValueLabelAndCode(i.attribute_id, i.attribute_value_id).label}`
          );
          const previewSku = this.previewSkuFor(items);
          return {
            items,
            labels,
            previewSku,
            sku: previewSku,
            price: "",
            stock: "",
            selected: true,
          };
        });
        this.previewLoaded = true;
      } catch (e) {
        alert("Не удалось загрузить превью комбинаций");
      } finally {
        this.loadingPreview = false;
      }
    },
    toggleRow(idx) {
      this.preview[idx].selected = !this.preview[idx].selected;
    },
    async submit() {
      if (!this.selectedCombinations.length || this.submitting) return;

      this.submitting = true;
      try {
        const { data } = await this.apollo().admin.mutate({
          mutation: SYNC,
          variables: {
            parent_id: parseInt(this.parentId),
            attribute_id: parseInt(this.selectedAttribute.id),
            attribute_value_id: parseInt(this.selectedValue.id),
            combinations_to_create: this.selectedCombinations.map((row) => ({
              attribute_values: row.items,
              sku: row.sku || row.previewSku,
              price: row.price ? parseFloat(row.price) : null,
              stock: row.stock ? parseInt(row.stock) : null,
            })),
          },
        });
        const resp = data?.ConfigurableProductSyncVariants;
        if (resp?.code === 200) {
          alert(`Создано ${resp.variant_ids?.length || 0} вариантов`);
          this.$emit("success", resp);
        } else {
          alert(resp?.message || "Не удалось создать варианты");
        }
      } catch (e) {
        alert(e?.message || "Ошибка сети");
      } finally {
        this.submitting = false;
      }
    },
  },
};
</script>

<style lang="stylus">
.sync-variants-modal
  width 820px
  max-width 95vw
  max-height 90vh
  overflow-y auto

  &__body
    display flex
    flex-direction column
    gap 16px

  &__step-1
    display flex
    gap 12px
    align-items flex-end

  &__preview
    background #f8f8f8
    padding 16px
    border-radius 8px

    h4
      margin 0 0 8px
      font-size 14px
      font-weight 600

  &__table
    width 100%
    border-collapse collapse
    font-size 13px
    background white
    border 1px solid #eee

    th, td
      padding 6px 8px
      border-bottom 1px solid #f0f0f0
      text-align left

    th
      background #fafafa
      font-weight 600

  &__input
    width 100%
    padding 4px 6px
    border 1px solid #ddd
    border-radius 4px
    font-size 12px

  &__empty
    text-align center
    padding 24px
    color #999
    background #fafafa
    border-radius 8px
    border 1px dashed #ddd
</style>
