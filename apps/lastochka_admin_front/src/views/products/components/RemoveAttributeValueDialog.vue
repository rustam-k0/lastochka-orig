<template>
  <div class="default-modal remove-attr-value-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Удалить значение атрибута</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>

    <div class="default-modal__body remove-attr-value-modal__body">
      <div class="remove-attr-value-modal__selectors">
        <SelectComponent
          v-model="selectedAttribute"
          :options="availableAttributes"
          label-name="title"
          title="Атрибут"
          placeholder="Выберите"
          required
          @update:model-value="onAttrChange"
        />
        <SelectComponent
          v-model="selectedValue"
          :options="availableValues"
          label-name="title"
          title="Значение"
          placeholder="Выберите"
          required
          :disabled="!selectedAttribute"
          @update:model-value="onValueChange"
        />
      </div>

      <button
        class="btn btn--md btn--outline"
        :disabled="!selectedAttribute || !selectedValue || loading"
        @click="loadVariants"
      >
        {{ loading ? 'Загрузка…' : 'Найти варианты' }}
      </button>

      <div v-if="loaded && !variantIds.length" class="remove-attr-value-modal__empty">
        Варианты с этим значением не найдены
      </div>

      <div v-if="variantIds.length" class="remove-attr-value-modal__list">
        <h4>Затронуто вариантов: {{ variantIds.length }}</h4>
        <div
          v-for="vid in variantIds"
          :key="vid"
          class="remove-attr-value-modal__row"
          :class="{ 'remove-attr-value-modal__row--blocked': blocked.has(vid) }"
        >
          <span>Variant #{{ vid }}</span>
          <span v-if="blocked.has(vid)" class="remove-attr-value-modal__blocked-label">В активных заказах — удаление невозможно</span>
          <span v-else class="remove-attr-value-modal__ok-label">Готов к удалению</span>
        </div>

        <div v-if="blocked.size === 0" class="remove-attr-value-modal__warning">
          Будут удалены {{ variantIds.length }} вариантов. Клиенты с этими товарами в корзине получат уведомление.
        </div>
        <div v-else class="remove-attr-value-modal__danger">
          Нельзя удалить — {{ blocked.size }} вариантов в активных заказах. Завершите заказы или отмените их, чтобы продолжить.
        </div>
      </div>
    </div>

    <div class="default-modal__footer">
      <button @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button
        @click.prevent="submit"
        class="btn btn--danger btn--md"
        :disabled="!variantIds.length || blocked.size > 0 || submitting"
      >
        {{ submitting ? 'Удаление…' : 'Удалить значение' }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import VARIANTS_BY_ATTR from "@/graphql/queries/configurable_variants_by_attribute.graphql";
import REMOVE from "@/graphql/mutations/ConfigurableProductRemoveAttributeValue.graphql";

export default {
  name: "RemoveAttributeValueDialog",
  inject: ["apollo"],
  components: { IconComponent, SelectComponent },
  props: {
    parentId: { type: [Number, String], required: true },
    configurableAttributes: { type: Array, default: () => [] },
    existingChildren: { type: Array, default: () => [] },
  },
  emits: ["close", "success"],
  data() {
    return {
      loading: false,
      loaded: false,
      submitting: false,
      selectedAttribute: null,
      selectedValue: null,
      variantIds: [],
      blocked: new Set(),
    };
  },
  computed: {
    availableAttributes() {
      return this.configurableAttributes.map((ca) => ca.attribute).filter(Boolean);
    },
    availableValues() {
      if (!this.selectedAttribute) return [];
      const usedValueIds = new Set();
      for (const child of this.existingChildren) {
        for (const a of (child.attributes || [])) {
          if (parseInt(a.attribute_id) === parseInt(this.selectedAttribute.id)) {
            usedValueIds.add(parseInt(a.attribute_value_id));
          }
        }
      }
      return (this.selectedAttribute?.values || []).filter((v) =>
        usedValueIds.has(parseInt(v.id))
      );
    },
  },
  methods: {
    onAttrChange() {
      this.selectedValue = null;
      this.variantIds = [];
      this.blocked = new Set();
      this.loaded = false;
    },
    onValueChange() {
      this.variantIds = [];
      this.blocked = new Set();
      this.loaded = false;
    },
    async loadVariants() {
      if (!this.selectedAttribute || !this.selectedValue) return;

      this.loading = true;
      try {
        const { data } = await this.apollo().admin.query({
          query: VARIANTS_BY_ATTR,
          variables: {
            parent_id: parseInt(this.parentId),
            attribute_id: parseInt(this.selectedAttribute.id),
            attribute_value_id: parseInt(this.selectedValue.id),
          },
          fetchPolicy: "network-only",
        });
        const resp = data?.configurableProductVariantsByAttribute;
        this.variantIds = (resp?.variant_ids || []).map((id) => parseInt(id));
        this.blocked = new Set((resp?.blocked_by_orders || []).map((id) => parseInt(id)));
        this.loaded = true;
      } catch (e) {
        alert("Ошибка загрузки вариантов");
      } finally {
        this.loading = false;
      }
    },
    async submit() {
      if (!this.variantIds.length || this.blocked.size > 0 || this.submitting) return;

      this.submitting = true;
      try {
        const { data } = await this.apollo().admin.mutate({
          mutation: REMOVE,
          variables: {
            parent_id: parseInt(this.parentId),
            attribute_id: parseInt(this.selectedAttribute.id),
            attribute_value_id: parseInt(this.selectedValue.id),
          },
        });
        const resp = data?.ConfigurableProductRemoveAttributeValue;
        if (resp?.status) {
          alert(`Удалено вариантов: ${resp.deleted_variant_ids?.length || 0}`);
          this.$emit("success", resp);
        } else {
          alert(resp?.message || "Не удалось удалить");
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
.remove-attr-value-modal
  width 560px
  max-width 95vw
  max-height 90vh
  overflow-y auto

  &__body
    display flex
    flex-direction column
    gap 16px

  &__selectors
    display grid
    grid-template-columns 1fr 1fr
    gap 12px

  &__list
    background #f8f8f8
    padding 12px
    border-radius 8px

    h4
      margin 0 0 8px
      font-size 14px
      font-weight 600

  &__row
    display flex
    justify-content space-between
    align-items center
    padding 6px 10px
    border-bottom 1px solid #eee
    font-size 13px

    &--blocked
      background #fff6f6
      color #a94442

  &__ok-label
    color #3c763d
    font-weight 500

  &__blocked-label
    color #a94442
    font-weight 500

  &__warning
    margin-top 8px
    font-size 13px
    color #8a6d3b
    background #fcf8e3
    padding 8px 12px
    border-radius 4px
    border 1px solid #faebcc

  &__danger
    margin-top 8px
    font-size 13px
    color #a94442
    background #f2dede
    padding 8px 12px
    border-radius 4px
    border 1px solid #ebccd1

  &__empty
    text-align center
    padding 16px
    color #999
    background #fafafa
    border-radius 8px
</style>
