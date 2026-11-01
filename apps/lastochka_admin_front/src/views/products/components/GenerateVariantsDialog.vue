<template>
  <div class="mod generate-dialog" v-if="visible">
    <div class="mod__header">
      <span class="mod__title">Генерация вариантов</span>
      <button class="mod__close" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>
    <div class="mod__body">
      <p class="generate-dialog__hint">
        Выберите значения атрибутов для генерации комбинаций.
        Будут созданы все возможные комбинации выбранных значений.
      </p>

      <div class="generate-dialog__attrs">
        <div
          v-for="ca in configurableAttributes"
          :key="ca.id"
          class="generate-dialog__attr-block"
        >
          <h4>{{ ca.attribute?.title }}</h4>
          <div class="generate-dialog__values-list">
            <label
              v-for="val in (ca.attribute?.values || [])"
              :key="val.id"
              class="generate-dialog__value-item"
            >
              <CheckboxComponent
                :model-value="isValueSelected(ca.attribute?.id, val.id)"
                @update:model-value="toggleValue(ca.attribute?.id, val.id)"
              />
              <span>{{ val.title }}</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Preview -->
      <div v-if="combinations.length" class="generate-dialog__preview">
        <h4>Будет создано: {{ newCombinations.length }} вариантов</h4>
        <div v-if="skippedCount > 0" class="generate-dialog__skipped">
          ({{ skippedCount }} комбинаций уже существуют и будут пропущены)
        </div>
        <table class="generate-dialog__combos-table" v-if="newCombinations.length">
          <thead>
            <tr>
              <th>Атрибуты</th>
              <th style="width: 180px">SKU</th>
              <th style="width: 100px">Цена</th>
              <th style="width: 90px">Остаток</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(combo, idx) in newCombinations"
              :key="idx"
              class="generate-dialog__combo-row"
            >
              <td>
                <span v-for="(part, pIdx) in combo.labels" :key="pIdx">
                  {{ part }}<template v-if="pIdx < combo.labels.length - 1"> / </template>
                </span>
              </td>
              <td>
                <input
                  class="generate-dialog__combo-input"
                  type="text"
                  :value="combo.sku"
                  @input="updateComboField(idx, 'sku', $event.target.value)"
                  :placeholder="previewSku(combo)"
                  :class="{ 'generate-dialog__combo-input--err': skuDuplicates.has(combo.sku) }"
                />
              </td>
              <td>
                <input
                  class="generate-dialog__combo-input"
                  type="number"
                  min="0"
                  step="0.01"
                  :value="combo.price"
                  @input="updateComboField(idx, 'price', $event.target.value)"
                  placeholder="—"
                />
              </td>
              <td>
                <input
                  class="generate-dialog__combo-input"
                  type="number"
                  min="0"
                  step="1"
                  :value="combo.stock"
                  @input="updateComboField(idx, 'stock', $event.target.value)"
                  placeholder="—"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="generate-dialog__empty-preview">
        Выберите значения атрибутов для просмотра комбинаций
      </div>

      <div v-if="submitting" class="generate-dialog__progress">
        <div class="generate-dialog__progress-bar"></div>
        <span>Генерация {{ newCombinations.length }} вариантов…</span>
      </div>

      <div v-if="skuDuplicates.size" class="generate-dialog__warn">
        SKU не должны повторяться внутри партии.
      </div>

      <div class="generate-dialog__nav">
        <button class="btn btn--md btn--outline" @click="$emit('close')">Отмена</button>
        <button
          class="btn btn--md"
          :disabled="!canSubmit"
          @click="submit"
        >
          {{ submitting ? 'Генерация...' : `Создать ${newCombinations.length} вариантов` }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import GENERATE_VARIANTS from "@/graphql/mutations/ConfigurableProductGenerateVariants.graphql";

export default {
  name: "GenerateVariantsDialog",
  inject: ["apollo"],
  components: { IconComponent, CheckboxComponent },
  props: {
    visible: { type: Boolean, default: false },
    parentId: { type: [Number, String], required: true },
    parentArticul: { type: String, default: "" },
    configurableAttributes: { type: Array, default: () => [] },
    existingChildren: { type: Array, default: () => [] },
  },
  emits: ["close", "generated"],
  data() {
    return {
      selectedValues: {},
      submitting: false,
      overrides: {}, // key comboKey -> {sku, price, stock}
    };
  },
  computed: {
    combinations() {
      const attrIds = Object.keys(this.selectedValues).filter(
        (id) => this.selectedValues[id]?.length > 0
      );
      if (!attrIds.length) return [];

      let result = [[]];
      for (const attrId of attrIds) {
        const values = this.selectedValues[attrId];
        const newResult = [];
        for (const existing of result) {
          for (const val of values) {
            newResult.push([
              ...existing,
              { attribute_id: parseInt(attrId), attribute_value_id: parseInt(val) },
            ]);
          }
        }
        result = newResult;
      }

      return result.map((combo) => {
        const key = this.comboKey(combo);
        const over = this.overrides[key] || {};
        return {
          items: combo,
          labels: combo.map((c) => this.getValueLabel(c.attribute_id, c.attribute_value_id)),
          sku: over.sku ?? "",
          price: over.price ?? "",
          stock: over.stock ?? "",
          key,
        };
      });
    },
    newCombinations() {
      return this.combinations.filter((combo) => !this.isExistingCombination(combo));
    },
    skippedCount() {
      return this.combinations.length - this.newCombinations.length;
    },
    skuDuplicates() {
      const counts = {};
      for (const combo of this.newCombinations) {
        const finalSku = combo.sku || this.previewSku(combo);
        if (!finalSku) continue;
        counts[finalSku] = (counts[finalSku] || 0) + 1;
      }
      const dups = new Set();
      for (const [sku, count] of Object.entries(counts)) {
        if (count > 1) dups.add(sku);
      }
      return dups;
    },
    canSubmit() {
      if (this.submitting) return false;
      if (!this.newCombinations.length) return false;
      if (this.skuDuplicates.size > 0) return false;
      return true;
    },
  },
  methods: {
    comboKey(items) {
      return items
        .map((i) => `${i.attribute_id}:${i.attribute_value_id}`)
        .sort()
        .join("|");
    },
    isValueSelected(attrId, valueId) {
      return (this.selectedValues[attrId] || []).includes(valueId);
    },
    toggleValue(attrId, valueId) {
      if (!this.selectedValues[attrId]) {
        this.selectedValues[attrId] = [];
      }
      const idx = this.selectedValues[attrId].indexOf(valueId);
      if (idx >= 0) {
        this.selectedValues[attrId].splice(idx, 1);
      } else {
        this.selectedValues[attrId].push(valueId);
      }
      this.selectedValues = { ...this.selectedValues };
    },
    getValueLabel(attrId, valueId) {
      for (const ca of this.configurableAttributes) {
        if (parseInt(ca.attribute?.id) === parseInt(attrId)) {
          const val = (ca.attribute?.values || []).find(
            (v) => parseInt(v.id) === parseInt(valueId)
          );
          return val?.title || "—";
        }
      }
      return "—";
    },
    getValueCode(attrId, valueId) {
      for (const ca of this.configurableAttributes) {
        if (parseInt(ca.attribute?.id) === parseInt(attrId)) {
          const val = (ca.attribute?.values || []).find(
            (v) => parseInt(v.id) === parseInt(valueId)
          );
          return val?.code || val?.title?.toLowerCase().replace(/\s+/g, "_") || String(valueId);
        }
      }
      return String(valueId);
    },
    previewSku(combo) {
      const base = this.parentArticul || `CONF-${this.parentId}`;
      const codes = combo.items.map((i) => this.getValueCode(i.attribute_id, i.attribute_value_id));
      return `${base}-${codes.join("-")}`;
    },
    updateComboField(idx, field, value) {
      const combo = this.newCombinations[idx];
      if (!combo) return;
      const key = combo.key;
      const current = this.overrides[key] || {};
      this.overrides = {
        ...this.overrides,
        [key]: { ...current, [field]: value },
      };
    },
    isExistingCombination(combo) {
      return this.existingChildren.some((child) => {
        if (!child.attributes) return false;
        return combo.items.every((c) =>
          child.attributes.some(
            (a) =>
              parseInt(a.attribute_id) === c.attribute_id &&
              parseInt(a.attribute_value_id) === c.attribute_value_id
          )
        );
      });
    },
    submit() {
      if (!this.canSubmit) return;

      this.submitting = true;
      const matrix = this.newCombinations.map((combo) => {
        const sku = combo.sku || this.previewSku(combo);
        const payload = {
          attribute_values: combo.items.map((c) => ({
            attribute_id: c.attribute_id,
            attribute_value_id: c.attribute_value_id,
          })),
          sku,
        };
        if (combo.price !== "" && combo.price !== null) payload.price = parseFloat(combo.price);
        if (combo.stock !== "" && combo.stock !== null) payload.stock = parseInt(combo.stock);
        return payload;
      });

      this.apollo()
        .admin.mutate({
          mutation: GENERATE_VARIANTS,
          variables: {
            parent_id: parseInt(this.parentId),
            matrix,
          },
        })
        .then(({ data }) => {
          const resp = data?.ConfigurableProductGenerateVariants;
          if (resp?.code === 200) {
            alert(`Создано ${this.newCombinations.length} вариантов`);
            this.$emit("generated");
          } else {
            alert(resp?.message || "Не удалось создать варианты");
          }
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            alert(graphQLErrors.map((e) => e.message).join("\n"));
          }
        })
        .finally(() => {
          this.submitting = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.generate-dialog
  width 820px
  max-width 95vw
  max-height 90vh
  overflow-y auto

  &__hint
    font-size 13px
    color #666
    margin 0

  &__attrs
    display flex
    flex-direction column
    gap 16px

  &__attr-block
    h4
      margin 0 0 8px
      font-size 14px
      font-weight 600
      color #333

  &__values-list
    display flex
    flex-wrap wrap
    gap 8px

  &__value-item
    display flex
    align-items center
    gap 4px
    padding 4px 10px
    background #fafafa
    border-radius 6px
    border 1px solid #eee
    cursor pointer
    font-size 13px

    &:hover
      background #f0f0f0

  &__preview
    background #f8f8f8
    padding 16px
    border-radius 8px

    h4
      margin 0 0 8px
      font-size 14px
      font-weight 600

  &__skipped
    font-size 12px
    color #999
    margin-bottom 8px

  &__combos-table
    width 100%
    border-collapse collapse
    font-size 13px
    background white
    border 1px solid #eee
    border-radius 4px
    max-height 400px
    overflow auto

    th, td
      padding 6px 8px
      border-bottom 1px solid #f0f0f0
      text-align left

    th
      background #fafafa
      font-weight 600

  &__combo-input
    width 100%
    padding 4px 6px
    border 1px solid #ddd
    border-radius 4px
    font-size 12px

    &--err
      border-color #d9534f
      background #fff6f6

  &__warn
    color #d9534f
    font-size 12px
    margin-top 8px

  &__progress
    display flex
    align-items center
    gap 12px
    padding 12px
    background #eef6ff
    border-radius 6px
    font-size 13px
    color #0558a7

  &__progress-bar
    flex 1
    height 4px
    background linear-gradient(90deg, #0a84ff, #80bdff)
    border-radius 2px
    animation gen-progress 1.2s linear infinite

  &__empty-preview
    text-align center
    padding 24px
    color #999
    background #fafafa
    border-radius 8px
    border 1px dashed #ddd

  &__nav
    display flex
    justify-content space-between
    gap 12px
    padding-top 8px
    border-top 1px solid #eee

@keyframes gen-progress
  0%
    transform translateX(-100%)
  100%
    transform translateX(100%)
</style>
