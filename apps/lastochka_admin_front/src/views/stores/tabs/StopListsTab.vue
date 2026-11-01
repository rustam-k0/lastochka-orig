<template>
  <div class="stop-lists-tab">
    <StopListSection
      :shop-id="shopId"
      type="hidden"
      title="Стоп-лист «Не отображать»"
      subtitle="Товары из списка не будут отображаться на витрине магазина и будут недоступны для заказа"
      empty-hint="Добавьте товары, которые не должны отображаться на витрине"
      date-field="hidden_at"
    />

    <StopListSection
      :shop-id="shopId"
      type="pickup_only"
      title="Стоп-лист «Не доставлять»"
      subtitle="Товары из списка отображаются на витрине, но доступны только для самовывоза"
      empty-hint="Добавьте товары, которые должны быть доступны только для самовывоза"
      date-field="pickup_only_at"
    />

    <div class="stop-lists-tab__info">
      <IconComponent name="information-line" />
      <span>
        Примечание: Управление стоп-листами применяется мгновенно после добавления
        товара. Стоп-листы применяются к конкретному магазину и не изменяют данные в 1С.
      </span>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import StopListSection from "@/views/stores/components/StopListSection.vue";

export default {
  name: "StopListsTab",
  components: { IconComponent, StopListSection },
  computed: {
    shopId() {
      return parseInt(this.$route.params.id);
    },
  },
};
</script>

<style lang="stylus">
.stop-lists-tab {
  display flex
  flex-direction column
  gap 16px

  &__info {
    display flex
    align-items flex-start
    gap 8px
    background var(--blue-light, #F0FAFF)
    border 1px solid var(--blue-50, #E6F6FF)
    border-radius 12px
    padding 14px 16px
    color var(--blue-600, #0082CC)
    font-size 0.813rem
    line-height 150%

    .icon {
      flex-shrink 0
      width 16px
      height 16px
      margin-top 2px

      svg path {
        fill var(--blue-600, #0082CC)
      }
    }
  }
}
</style>
