<template>
  <div class="attribute-form">
    <InputComponent
      v-model="form.title.value"
      :errors="form.title.errors"
      title="Название"
      placeholder="Название атрибута"
      required
      @update:model-value="onTitleInput"
    />

    <div class="attribute-form__code-field">
      <InputComponent
        v-model="form.code.value"
        :errors="form.code.errors"
        :messages="['латиница, цифры, подчёркивание; используется для синхронизации']"
        title="Системный код"
        placeholder="например: color, material, power_watts"
        required
        @update:model-value="onCodeInput"
      />
      <div v-if="codeChanged" class="attribute-form__warning">
        <span class="attribute-form__warning-icon">⚠</span>
        <div>
          Вы изменили системный код (было: <code>{{ originalCode }}</code>).
          Это сломает привязку уже проставленных значений этого атрибута в товарах на витрине —
          при следующей публикации они создадутся заново под новым кодом.
        </div>
      </div>
    </div>

    <TextareaComponent
      v-model="form.description.value"
      :errors="form.description.errors"
      :rows="3"
      title="Описание"
      placeholder="Что означает параметр — показывается в INFO-модалке в карточке товара"
    />

    <div class="attribute-form__row">
      <div class="attribute-form__field">
        <label class="attribute-form__label">Тип значения</label>
        <select class="attribute-form__select" v-model="form.type_id.value">
          <option :value="1">Строка</option>
          <option :value="2">Текст</option>
          <option :value="5">Выбор из списка</option>
        </select>
      </div>

      <div class="attribute-form__field">
        <label class="attribute-form__label">Группа</label>
        <select class="attribute-form__select" v-model="form.category_type_id.value">
          <option v-for="g in availableGroups" :key="g.value" :value="g.value">
            {{ g.label }}
          </option>
        </select>
      </div>

      <div class="attribute-form__field">
        <label class="attribute-form__label">&nbsp;</label>
        <div
          class="attribute-form__system-info"
          :class="{ 'attribute-form__system-info--active': isSystemGroup }"
        >
          {{ isSystemGroup ? 'Системный — отображается во всех товарах' : 'Не системный' }}
        </div>
      </div>
    </div>

    <div v-if="isSelectType" class="attribute-form__field">
      <label class="attribute-form__label">Варианты значений</label>
      <div
        v-for="(value, index) in form.values.value"
        :key="index"
        class="attribute-form__value-row"
      >
        <div class="attribute-form__value-input-wrapper">
          <InputComponent
            v-model="value.title"
            :errors="value.errors || []"
            placeholder="Значение"
          />
        </div>
        <button
          type="button"
          class="attribute-form__remove-btn"
          @click="removeValue(index)"
          :disabled="form.values.value.length <= 1"
        >
          ✕
        </button>
      </div>
      <button type="button" class="attribute-form__add-btn" @click="addValue">
        + Добавить вариант
      </button>
    </div>

    <div class="attribute-form__actions">
      <button
        type="button"
        class="btn btn--md"
        :disabled="saving || !isValid"
        @click="submit"
      >
        {{ saving ? 'Сохранение...' : (isEdit ? 'Сохранить' : 'Создать') }}
      </button>
      <button
        type="button"
        class="btn btn--md btn--outline"
        @click="$emit('cancel')"
      >
        Отмена
      </button>
    </div>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import ATTRIBUTE_CREATE from "@/graphql/mutations/AttributeCreate.graphql";
import ATTRIBUTE_UPDATE from "@/graphql/mutations/AttributeUpdate.graphql";

const ALL_GROUPS = [
  { value: 100, label: "Состав", moduleKey: "product_composition" },
  { value: 101, label: "Параметры", moduleKey: "product_params" },
  { value: 103, label: "Динамические", moduleKey: null },
  { value: 104, label: "Параметры упаковки", moduleKey: null },
];

export default {
  name: "AttributeForm",
  inject: ["apollo"],
  components: { InputComponent, TextareaComponent },
  props: {
    attribute: { type: Object, default: null },
    isEdit: { type: Boolean, default: false },
  },
  emits: ["success", "cancel"],
  data() {
    return {
      saving: false,
      codeDirty: false,
      originalCode: null,
      form: {
        title: { value: "", errors: [] },
        code: { value: "", errors: [] },
        description: { value: "", errors: [] },
        type_id: { value: 5, errors: [] },
        category_type_id: { value: 103, errors: [] },
        values: { value: [{ title: "", errors: [] }], errors: [] },
      },
    };
  },
  computed: {
    isSelectType() {
      return this.form.type_id.value === 5;
    },
    isSystemGroup() {
      return (
        this.form.category_type_id.value === 100 ||
        this.form.category_type_id.value === 101
      );
    },
    availableGroups() {
      const moduleStore = useModuleConfigStore();
      return ALL_GROUPS.filter((g) => {
        if (!g.moduleKey) return true;
        if (this.isEdit && this.attribute?.category_type_id === g.value) return true;
        return moduleStore.isModuleEnabled(g.moduleKey);
      });
    },
    isValid() {
      if (!this.form.title.value || !this.form.title.value.trim()) return false;
      const code = (this.form.code.value || "").trim();
      if (!code) return false;
      if (!/^[a-z][a-z0-9_]*$/.test(code)) return false;
      if (this.isSelectType) {
        const vals = this.form.values.value || [];
        return vals.length > 0 && vals.every((v) => v.title && v.title.trim());
      }
      return true;
    },
    codeChanged() {
      if (!this.isEdit || !this.originalCode) return false;
      return (this.form.code.value || "").trim() !== this.originalCode;
    },
  },
  watch: {
    attribute: {
      immediate: true,
      handler(val) {
        if (!val) return;
        this.form.title.value = val.title || "";
        this.form.code.value = val.code || "";
        this.form.description.value = val.description || "";
        this.form.type_id.value = val.type_id || 5;
        this.form.category_type_id.value = val.category_type_id || 103;
        this.form.values.value =
          val.values && val.values.length
            ? val.values.map((v) => ({ id: v.id, title: v.title, errors: [] }))
            : [{ title: "", errors: [] }];
        this.codeDirty = Boolean(val.code);
        this.originalCode = val.code || null;
      },
    },
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    onTitleInput(newTitle) {
      this.form.title.errors = [];
      if (this.codeDirty) return;
      this.form.code.value = this.generateSlug(newTitle || "");
      this.form.code.errors = [];
    },
    onCodeInput() {
      this.codeDirty = true;
      this.form.code.errors = [];
    },
    addValue() {
      this.form.values.value.push({ title: "", errors: [] });
    },
    removeValue(index) {
      this.form.values.value.splice(index, 1);
    },
    generateSlug(str) {
      if (!str) return "";
      const ruToLat = {
        а: "a", б: "b", в: "v", г: "g", д: "d", е: "e", ё: "yo",
        ж: "zh", з: "z", и: "i", й: "y", к: "k", л: "l", м: "m",
        н: "n", о: "o", п: "p", р: "r", с: "s", т: "t", у: "u",
        ф: "f", х: "h", ц: "ts", ч: "ch", ш: "sh", щ: "shch",
        ъ: "", ы: "y", ь: "", э: "e", ю: "yu", я: "ya",
      };
      const slug = str
        .toLowerCase()
        .split("")
        .map((c) => (ruToLat[c] !== undefined ? ruToLat[c] : c))
        .join("")
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_+|_+$/g, "")
        .replace(/_{2,}/g, "_");
      return slug.replace(/^[0-9_]+/, "");
    },
    buildVariables() {
      const variables = {
        title: (this.form.title.value || "").trim(),
        code: (this.form.code.value || "").trim(),
        // null, не undefined — очистка описания должна долетать до бэка
        description: (this.form.description.value || "").trim() || null,
        type_id: this.form.type_id.value,
        category_type_id: this.form.category_type_id.value,
        is_default: this.isSystemGroup,
      };
      if (this.isSelectType) {
        variables.values = (this.form.values.value || [])
          .filter((v) => v.title && v.title.trim())
          .map((v) =>
            v.id
              ? { id: v.id, title: v.title.trim() }
              : { title: v.title.trim() }
          );
      }
      if (this.isEdit && this.attribute?.id) {
        variables.id = this.attribute.id;
      }
      return variables;
    },
    submit() {
      this.resetErrors();
      this.saving = true;
      const variables = this.buildVariables();
      this.apollo()
        .admin.mutate({
          mutation: this.isEdit ? ATTRIBUTE_UPDATE : ATTRIBUTE_CREATE,
          variables,
        })
        .then(() => {
          this.$emit("success");
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            this.parseGqlErrors(graphQLErrors);
          }
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.attribute-form
  display flex
  flex-direction column
  gap 16px

  &__field
    display flex
    flex-direction column

  &__row
    display flex
    gap 24px
    align-items flex-start

  &__label
    display block
    font-weight 600
    margin-bottom 8px
    color #333

  &__select
    width 100%
    padding 10px 12px
    border 1px solid #ddd
    border-radius 6px
    font-size 14px
    background #fff
    &:focus
      outline none
      border-color var(--primary)

  &__code-field
    display flex
    flex-direction column
    gap 8px

  &__warning
    display flex
    gap 8px
    align-items flex-start
    background #fff8e6
    border 1px solid #f5d98a
    color #8a6300
    padding 10px 12px
    border-radius 6px
    font-size 13px
    line-height 1.4

    code
      background rgba(138, 99, 0, 0.1)
      padding 1px 6px
      border-radius 3px
      font-family monospace
      font-size 12px

  &__warning-icon
    font-size 16px
    line-height 1
    flex-shrink 0

  &__system-info
    font-size 13px
    color #999
    padding 10px 0

    &--active
      color var(--primary)
      font-weight 500

  &__value-row
    display flex
    align-items flex-start
    gap 8px
    margin-bottom 8px

  &__value-input-wrapper
    flex 1

  &__remove-btn
    // 48px = высота .input__field; у InputComponent тут нет title-label,
    // поэтому кнопка выравнивается по верху ряда без компенсирующих отступов
    width 32px
    height 48px
    border none
    background #f5f5f5
    border-radius 6px
    cursor pointer
    color #999
    font-size 16px
    flex-shrink 0
    &:hover:not(:disabled)
      background #fee
      color #e55
    &:disabled
      opacity 0.3
      cursor not-allowed

  &__add-btn
    background none
    border none
    color var(--primary)
    cursor pointer
    font-size 14px
    padding 4px 0
    align-self flex-start
    &:hover
      text-decoration underline

  &__actions
    display flex
    gap 12px
    margin-top 16px
</style>
