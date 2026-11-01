<template>
  <div class="mod merge-dialog" v-if="visible">
    <div class="mod__header">
      <span class="mod__title">Объединить в вариативный товар</span>
      <button class="mod__close" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>
    <div class="mod__body">
      <!-- Step indicator -->
      <div class="merge-dialog__steps">
        <div
          v-for="s in 3"
          :key="s"
          class="merge-dialog__step"
          :class="{ 'merge-dialog__step--active': step === s, 'merge-dialog__step--done': step > s }"
        >
          {{ s }}
        </div>
      </div>

      <!-- Step 1: Select configurable attributes -->
      <div v-if="step === 1" class="merge-dialog__step-content">
        <h3>Шаг 1: Выберите варьируемые атрибуты</h3>
        <p class="merge-dialog__hint">Выберите атрибуты, по которым товары различаются (например, Цвет, Размер)</p>

        <div v-if="loadingAttributes" class="merge-dialog__loading">Загрузка атрибутов...</div>
        <div v-else-if="!configurableAttrs.length" class="merge-dialog__empty">
          Сначала отметьте варьируемые атрибуты в семействе
        </div>
        <div v-else class="merge-dialog__attrs-list">
          <div
            v-for="attr in configurableAttrs"
            :key="attr.id"
            class="merge-dialog__attr-item"
          >
            <CheckboxComponent
              :model-value="selectedAttrIds.includes(attr.id)"
              @update:model-value="toggleAttr(attr.id)"
            >
              {{ attr.title }}
              <span class="merge-dialog__attr-type">({{ attr.values?.length || 0 }} значений)</span>
            </CheckboxComponent>
          </div>
        </div>

        <div class="merge-dialog__nav">
          <button class="btn btn--md btn--outline" @click="$emit('close')">Отмена</button>
          <button
            class="btn btn--md"
            :disabled="!selectedAttrIds.length"
            @click="step = 2"
          >
            Далее
          </button>
        </div>
      </div>

      <!-- Step 2: Attribute values matrix -->
      <div v-if="step === 2" class="merge-dialog__step-content">
        <h3>Шаг 2: Значения атрибутов для каждого товара</h3>
        <p class="merge-dialog__hint">Укажите значения варьируемых атрибутов для каждого товара</p>

        <div class="merge-dialog__matrix-wrapper">
          <table class="merge-dialog__matrix">
            <thead>
              <tr>
                <th>Товар</th>
                <th v-for="attr in selectedAttrs" :key="attr.id">{{ attr.title }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(product, pIdx) in products" :key="product.id">
                <td class="merge-dialog__product-title">{{ product.title }}</td>
                <td v-for="attr in selectedAttrs" :key="attr.id">
                  <SelectComponent
                    placeholder="Выберите"
                    label-name="title"
                    :options="attr.values || []"
                    :model-value="getMatrixValue(pIdx, attr.id)"
                    @update:model-value="setMatrixValue(pIdx, attr.id, $event)"
                    clearable
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="merge-dialog__nav">
          <button class="btn btn--md btn--outline" @click="step = 1">Назад</button>
          <button class="btn btn--md" @click="step = 3">Далее</button>
        </div>
      </div>

      <!-- Step 3: Parent data -->
      <div v-if="step === 3" class="merge-dialog__step-content">
        <h3>Шаг 3: Данные вариативного товара</h3>

        <InputComponent
          title="Название"
          placeholder="Название вариативного товара"
          required
          v-model="parentTitle"
        />
        <TextareaComponent
          title="Описание"
          placeholder="Описание (необязательно)"
          v-model="parentDescription"
        />

        <div class="merge-dialog__summary">
          <strong>Будет создано:</strong>
          <ul>
            <li>Вариативный товар: {{ parentTitle || '(без названия)' }}</li>
            <li>Варианты: {{ products.length }} шт.</li>
            <li>Атрибуты: {{ selectedAttrs.map(a => a.title).join(', ') }}</li>
          </ul>
        </div>

        <div class="merge-dialog__nav">
          <button class="btn btn--md btn--outline" @click="step = 2">Назад</button>
          <button
            class="btn btn--md"
            :disabled="!parentTitle || submitting"
            @click="submit"
          >
            {{ submitting ? 'Создание...' : 'Создать' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import CATEGORY_FAMILY_ATTRIBUTES from "@/graphql/queries/category_family_attributes.graphql";
import CONFIGURABLE_PRODUCT_CREATE from "@/graphql/mutations/ConfigurableProductCreate.graphql";

export default {
  name: "MergeDialog",
  inject: ["apollo"],
  components: {
    IconComponent,
    InputComponent,
    TextareaComponent,
    SelectComponent,
    CheckboxComponent,
  },
  props: {
    products: { type: Array, required: true },
    shopId: { type: [Number, String], default: null },
    visible: { type: Boolean, default: false },
  },
  emits: ["close", "created"],
  data() {
    return {
      step: 1,
      loadingAttributes: false,
      familyAttributes: [],
      selectedAttrIds: [],
      matrix: {}, // { productIndex: { attrId: valueObj } }
      parentTitle: "",
      parentDescription: "",
      submitting: false,
    };
  },
  computed: {
    configurableAttrs() {
      return this.familyAttributes.filter(
        (a) => a.attribute && a.attribute.type_id === 5
      ).map((a) => a.attribute);
    },
    selectedAttrs() {
      return this.configurableAttrs.filter((a) => this.selectedAttrIds.includes(a.id));
    },
  },
  watch: {
    visible: {
      immediate: true,
      handler(val) {
        if (val && this.products.length) {
          this.loadAttributes();
          this.guessParentTitle();
        }
      },
    },
  },
  methods: {
    guessParentTitle() {
      if (!this.products.length) return;
      // Попытка найти общую часть названий
      const titles = this.products.map((p) => p.title || "");
      if (titles.length === 1) {
        this.parentTitle = titles[0];
        return;
      }
      const words0 = titles[0].split(" ");
      const common = [];
      for (const word of words0) {
        if (titles.every((t) => t.includes(word))) {
          common.push(word);
        }
      }
      this.parentTitle = common.join(" ") || titles[0];
    },
    loadAttributes() {
      // Берем категорию из первого выбранного товара
      const firstProduct = this.products[0];
      const categoryId = firstProduct?.categories?.[0]?.id;
      if (!categoryId) {
        this.familyAttributes = [];
        return;
      }

      this.loadingAttributes = true;
      this.apollo()
        .admin.query({
          query: CATEGORY_FAMILY_ATTRIBUTES,
          variables: { category_id: parseInt(categoryId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.familyAttributes = data.category_family_attributes || [];
        })
        .catch((err) => {
          console.error("Error loading family attributes:", err);
          this.familyAttributes = [];
        })
        .finally(() => {
          this.loadingAttributes = false;
        });
    },
    toggleAttr(attrId) {
      const idx = this.selectedAttrIds.indexOf(attrId);
      if (idx >= 0) {
        this.selectedAttrIds.splice(idx, 1);
      } else {
        this.selectedAttrIds.push(attrId);
      }
    },
    getMatrixValue(productIndex, attrId) {
      return this.matrix[productIndex]?.[attrId] || null;
    },
    setMatrixValue(productIndex, attrId, value) {
      if (!this.matrix[productIndex]) {
        this.matrix[productIndex] = {};
      }
      this.matrix[productIndex][attrId] = value;
    },
    submit() {
      if (!this.parentTitle || this.submitting) return;

      this.submitting = true;
      const variables = {
        title: this.parentTitle,
        description: this.parentDescription || undefined,
        shop_id: parseInt(this.shopId),
        category_id: this.products[0]?.categories?.[0]?.id
          ? parseInt(this.products[0].categories[0].id)
          : undefined,
        configurable_attribute_ids: this.selectedAttrIds.map((id) => parseInt(id)),
        children_ids: this.products.map((p) => parseInt(p.id)),
      };

      this.apollo()
        .admin.mutate({
          mutation: CONFIGURABLE_PRODUCT_CREATE,
          variables,
        })
        .then(({ data }) => {
          const parentId = data?.ConfigurableProductCreate?.id;
          alert("Вариативный товар создан");
          this.$emit("created", parentId);
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            graphQLErrors.forEach((err) => {
              if (err.extensions?.category === "validation") {
                const messages = Object.values(err.extensions.validation).flat().join("\n");
                alert(messages);
              } else {
                alert(err.message);
              }
            });
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
.merge-dialog
  width 800px
  max-width 90vw
  max-height 90vh
  overflow-y auto

  &__steps
    display flex
    gap 8px
    justify-content center
    margin-bottom 16px

  &__step
    width 32px
    height 32px
    border-radius 50%
    background #eee
    display flex
    align-items center
    justify-content center
    font-weight 600
    font-size 14px
    color #999

    &--active
      background #222128
      color white

    &--done
      background #5AB033
      color white

  &__step-content
    display flex
    flex-direction column
    gap 16px

    h3
      margin 0
      font-size 16px
      font-weight 600

  &__hint
    font-size 13px
    color #666
    margin 0

  &__loading
    text-align center
    padding 24px
    color #999

  &__empty
    text-align center
    padding 24px
    color #e55
    background #fff5f5
    border-radius 8px
    border 1px dashed #fcc

  &__attrs-list
    display flex
    flex-direction column
    gap 8px

  &__attr-item
    padding 8px 12px
    background #fafafa
    border-radius 8px
    border 1px solid #eee

  &__attr-type
    color #999
    font-size 12px
    margin-left 4px

  &__matrix-wrapper
    overflow-x auto

  &__matrix
    width 100%
    border-collapse collapse

    th, td
      padding 8px 12px
      text-align left
      border-bottom 1px solid #eee

    th
      font-weight 600
      color #666
      font-size 13px
      background #f5f5f5
      white-space nowrap

  &__product-title
    white-space nowrap
    max-width 200px
    overflow hidden
    text-overflow ellipsis

  &__summary
    background #f8f8f8
    padding 16px
    border-radius 8px

    ul
      margin 8px 0 0
      padding-left 20px

    li
      margin-bottom 4px

  &__nav
    display flex
    justify-content space-between
    gap 12px
    padding-top 8px
    border-top 1px solid #eee
</style>
