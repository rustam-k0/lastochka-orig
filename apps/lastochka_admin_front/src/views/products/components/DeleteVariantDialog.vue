<template>
  <div class="default-modal delete-variant-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Удалить вариант #{{ variant.id }}</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>

    <div class="default-modal__body delete-variant-modal__body">
      <div class="delete-variant-modal__info">
        <div><b>SKU:</b> {{ variant.articul || '—' }}</div>
        <div><b>Название:</b> {{ variant.title }}</div>
      </div>

      <div v-if="loading" class="delete-variant-modal__loading">Проверка заказов…</div>
      <div v-else>
        <div v-if="activeOrders > 0" class="delete-variant-modal__blocked">
          Вариант находится в {{ activeOrders }} активных заказах. Удаление невозможно.
        </div>
        <div v-else class="delete-variant-modal__ok">
          Активных заказов нет. Вариант можно удалить (soft delete).
          Клиентские корзины и исторические заказы не пострадают.
        </div>
      </div>
    </div>

    <div class="default-modal__footer">
      <button @click="$emit('close')" class="btn btn--gray btn--md">Отмена</button>
      <button
        @click.prevent="submit"
        class="btn btn--danger btn--md"
        :disabled="activeOrders > 0 || submitting || loading"
      >
        {{ submitting ? 'Удаление…' : 'Удалить вариант' }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import VARIANT_ORDERS_COUNT from "@/graphql/queries/variant_orders_count.graphql";
import DELETE_VARIANT from "@/graphql/mutations/ConfigurableProductDeleteVariant.graphql";

export default {
  name: "DeleteVariantDialog",
  inject: ["apollo"],
  components: { IconComponent },
  props: {
    variant: { type: Object, required: true },
  },
  emits: ["close", "success"],
  data() {
    return {
      loading: true,
      submitting: false,
      activeOrders: 0,
      totalOrders: 0,
    };
  },
  async mounted() {
    try {
      const { data } = await this.apollo().admin.query({
        query: VARIANT_ORDERS_COUNT,
        variables: { variant_id: parseInt(this.variant.id) },
        fetchPolicy: "network-only",
      });
      const resp = data?.variantOrdersCount;
      this.activeOrders = resp?.active_orders || 0;
      this.totalOrders = resp?.total_orders || 0;
    } catch (e) {
      alert("Не удалось проверить заказы");
    } finally {
      this.loading = false;
    }
  },
  methods: {
    async submit() {
      if (this.activeOrders > 0 || this.submitting) return;
      this.submitting = true;
      try {
        const { data } = await this.apollo().admin.mutate({
          mutation: DELETE_VARIANT,
          variables: { variant_id: parseInt(this.variant.id) },
        });
        const resp = data?.ConfigurableProductDeleteVariant;
        if (resp?.code === 200) {
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
.delete-variant-modal
  width 480px
  max-width 95vw

  &__body
    display flex
    flex-direction column
    gap 12px

  &__info
    background #fafafa
    padding 10px
    border-radius 6px
    font-size 13px
    line-height 1.6

  &__blocked
    color #a94442
    background #f2dede
    padding 8px 12px
    border-radius 4px
    border 1px solid #ebccd1

  &__ok
    color #3c763d
    background #dff0d8
    padding 8px 12px
    border-radius 4px
    border 1px solid #d6e9c6

  &__loading
    text-align center
    padding 10px
    color #777
</style>
