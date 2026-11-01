<template>
  <div class="family-form">
    <div class="family-form__field">
      <InputComponent
        v-model="form.title"
        title="Название"
        placeholder="Название семейства"
        required
      />
    </div>

    <div class="family-form__field">
      <InputComponent
        v-model="form.code"
        title="Код"
        placeholder="Код семейства (латиница)"
        :disabled="isEdit && family && family.is_system"
      />
    </div>

    <div class="family-form__section">
      <h3 class="family-form__section-title">Атрибуты</h3>

      <div class="family-form__add-attr">
        <SelectComponent
          v-model="selectedAttribute"
          :options="availableAttributes"
          title="Добавить атрибут"
          placeholder="Выберите атрибут"
          label-name="title"
          clearable
        />
        <button
          class="btn btn--md family-form__add-btn"
          :disabled="!selectedAttribute"
          @click="addAttribute"
        >
          Добавить
        </button>
      </div>

      <div v-if="!form.selectedAttributes.length" class="family-form__empty-attrs">
        Атрибуты не добавлены
      </div>

      <div v-else class="family-form__attrs-table-wrapper">
        <table class="family-form__attrs-table">
          <thead>
            <tr>
              <th class="family-form__attrs-th--pos">#</th>
              <th>Название</th>
              <th>Тип</th>
              <th class="family-form__attrs-th--center">Обязательный</th>
              <th v-if="configurableEnabled" class="family-form__attrs-th--center">Варьируемый</th>
              <th class="family-form__attrs-th--actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(attr, index) in form.selectedAttributes" :key="attr.id">
              <td class="family-form__attrs-td--pos">
                <div class="family-form__position-controls">
                  <button
                    class="family-form__pos-btn"
                    :disabled="index === 0"
                    @click="moveUp(index)"
                    title="Вверх"
                  >
                    &uarr;
                  </button>
                  <span class="family-form__pos-number">{{ index + 1 }}</span>
                  <button
                    class="family-form__pos-btn"
                    :disabled="index === form.selectedAttributes.length - 1"
                    @click="moveDown(index)"
                    title="Вниз"
                  >
                    &darr;
                  </button>
                </div>
              </td>
              <td>{{ attr.title }}</td>
              <td>{{ typeLabel(attr.type_id) }}</td>
              <td class="family-form__attrs-td--center">
                <CheckboxComponent
                  v-model="attr.is_required"
                />
              </td>
              <td v-if="configurableEnabled" class="family-form__attrs-td--center">
                <CheckboxComponent
                  v-if="attr.type_id === 5"
                  v-model="attr.is_configurable"
                />
                <span v-else class="family-form__na-text">—</span>
              </td>
              <td class="family-form__attrs-td--actions">
                <button
                  class="family-form__remove-btn"
                  @click="removeAttribute(index)"
                  title="Удалить"
                >
                  &times;
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="family-form__actions">
      <button
        class="btn btn--md"
        :disabled="loading || !isValid"
        @click="submit"
      >
        {{ loading ? 'Сохранение...' : (isEdit ? 'Сохранить' : 'Создать') }}
      </button>
      <button class="btn btn--md btn--outline" @click="$emit('cancel')">
        Отмена
      </button>
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import ATTRIBUTES_PAGINATE from "@/graphql/queries/attributes_paginate.graphql";
import DEFAULT_ATTRIBUTES from "@/graphql/queries/default_attributes.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

const TYPE_LABELS = {
  1: "Строка",
  2: "Текст",
  3: "Целое число",
  4: "Дробное число",
  5: "Выбор из списка",
};

export default {
  name: "AttributeFamilyForm",
  inject: ["apollo"],
  components: { InputComponent, SelectComponent, CheckboxComponent },
  props: {
    family: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    isEdit: { type: Boolean, default: false },
  },
  emits: ["submit", "cancel"],
  data() {
    return {
      form: {
        title: "",
        code: "",
        selectedAttributes: [],
      },
      allAttributes: [],
      loadingAttributes: false,
      selectedAttribute: null,
    };
  },
  computed: {
    configurableEnabled() {
      return useModuleConfigStore().isModuleEnabled("configurable_products");
    },
    availableAttributes() {
      const selectedIds = this.form.selectedAttributes.map((a) => a.id);
      return this.allAttributes.filter((a) => {
        if (selectedIds.includes(a.id)) return false;
        // Для кастомных семейств — только динамические атрибуты (103)
        // Для системных — только атрибуты своей группы
        if (this.isEdit && this.family?.is_system) {
          return true; // системное семейство может управлять любыми атрибутами
        }
        return a.category_type_id === 103;
      });
    },
    isValid() {
      return this.form.title.trim().length > 0;
    },
  },
  watch: {
    family: {
      immediate: true,
      handler(val) {
        if (val) {
          this.form.title = val.title || "";
          this.form.code = val.code || "";
          if (val.attributes && val.attributes.length) {
            this.form.selectedAttributes = val.attributes
              .slice()
              .sort((a, b) => (a.position || 0) - (b.position || 0))
              .map((a) => ({
                id: a.attribute?.id || a.id,
                title: a.attribute?.title || a.title,
                type_id: a.attribute?.type_id || a.type_id,
                category_type_id: a.attribute?.category_type_id || a.category_type_id,
                is_required: a.is_required || false,
                is_configurable: a.is_configurable || false,
              }));
          }
        }
      },
    },
  },
  mounted() {
    this.loadAllAttributes();
  },
  methods: {
    typeLabel(typeId) {
      return TYPE_LABELS[typeId] || typeId;
    },
    loadAllAttributes() {
      this.loadingAttributes = true;
      const client = this.apollo().admin;
      Promise.all([
        client.query({
          query: DEFAULT_ATTRIBUTES,
          fetchPolicy: "network-only",
        }),
        client.query({
          query: ATTRIBUTES_PAGINATE,
          variables: { first: 100, page: 1 },
          fetchPolicy: "network-only",
        }),
      ])
        .then(([defaultRes, dynamicRes]) => {
          const defaults = defaultRes.data?.default_attributes || [];
          const dynamic = dynamicRes.data?.attributes_paginate?.data || [];
          // Merge and deduplicate by id
          const map = new Map();
          defaults.forEach((a) => map.set(a.id, a));
          dynamic.forEach((a) => map.set(a.id, a));
          this.allAttributes = Array.from(map.values());
        })
        .catch((err) => {
          console.error("Error loading attributes:", err);
        })
        .finally(() => {
          this.loadingAttributes = false;
        });
    },
    addAttribute() {
      if (!this.selectedAttribute) return;
      const attr = this.allAttributes.find((a) => a.id === this.selectedAttribute.id);
      if (!attr) return;
      this.form.selectedAttributes.push({
        id: attr.id,
        title: attr.title,
        type_id: attr.type_id,
        category_type_id: attr.category_type_id,
        is_required: false,
        is_configurable: false,
      });
      this.selectedAttribute = null;
    },
    removeAttribute(index) {
      this.form.selectedAttributes.splice(index, 1);
    },
    moveUp(index) {
      if (index <= 0) return;
      const list = this.form.selectedAttributes;
      const item = list.splice(index, 1)[0];
      list.splice(index - 1, 0, item);
    },
    moveDown(index) {
      const list = this.form.selectedAttributes;
      if (index >= list.length - 1) return;
      const item = list.splice(index, 1)[0];
      list.splice(index + 1, 0, item);
    },
    submit() {
      const data = {
        title: this.form.title.trim(),
        code: this.form.code.trim() || null,
        attributes: this.form.selectedAttributes.map((attr, i) => ({
          attribute_id: parseInt(attr.id),
          position: i,
          is_required: attr.is_required || false,
          is_configurable: attr.is_configurable || false,
        })),
      };
      this.$emit("submit", data);
    },
  },
};
</script>

<style lang="stylus">
.family-form
  &__field
    margin-bottom 24px

  &__section
    margin-top 32px
    margin-bottom 24px

  &__section-title
    font-size 1.1rem
    font-weight 600
    margin 0 0 16px
    color #333

  &__add-attr
    display flex
    gap 12px
    align-items flex-end
    margin-bottom 16px

    .select
      flex 1

  &__add-btn
    white-space nowrap
    align-self flex-end
    margin-bottom 0

  &__empty-attrs
    text-align center
    padding 32px
    color #999
    background #fafafa
    border-radius 8px
    border 1px dashed #ddd

  &__attrs-table-wrapper
    background #fafafa
    border-radius 8px
    overflow hidden
    border 1px solid #eee

  &__attrs-table
    width 100%
    border-collapse collapse

    th, td
      padding 10px 14px
      text-align left
      border-bottom 1px solid #eee

    th
      font-weight 600
      color #666
      font-size 13px
      background #f5f5f5

    tr:hover td
      background #f0f0f0

  &__attrs-th--pos
    width 80px
    text-align center

  &__attrs-th--center
    text-align center

  &__attrs-th--actions
    width 50px

  &__attrs-td--pos
    text-align center

  &__attrs-td--center
    text-align center

  &__attrs-td--actions
    text-align center

  &__position-controls
    display flex
    align-items center
    justify-content center
    gap 4px

  &__pos-btn
    width 24px
    height 24px
    border none
    background #eee
    border-radius 4px
    cursor pointer
    font-size 12px
    line-height 1
    display flex
    align-items center
    justify-content center
    transition all 0.2s

    &:hover:not(:disabled)
      background #ddd

    &:disabled
      opacity 0.3
      cursor not-allowed

  &__pos-number
    font-size 13px
    color #666
    min-width 20px
    text-align center

  &__remove-btn
    width 32px
    height 32px
    border none
    background #f5f5f5
    border-radius 6px
    cursor pointer
    color #999
    font-size 18px
    line-height 1
    display flex
    align-items center
    justify-content center
    transition all 0.2s

    &:hover
      background #fee
      color #e55

  &__na-text
    color #ccc
    font-size 13px

  &__actions
    display flex
    gap 12px
    margin-top 32px
</style>
