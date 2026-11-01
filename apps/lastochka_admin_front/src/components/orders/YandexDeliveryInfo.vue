<template>
  <div class="yandex-delivery-info" v-if="order.yandex_claim_id">
    <div class="yandex-delivery-info__header">
      <h3>Яндекс Доставка</h3>
    </div>

    <div class="yandex-delivery-info__body">
      <div class="yandex-delivery-info__row">
        <span class="label">Статус:</span>
        <span class="badge" :class="statusClass">{{ order.yandex_delivery_status }}</span>
      </div>

      <div class="yandex-delivery-info__row">
        <span class="label">Claim ID:</span>
        <span class="value monospace">{{ order.yandex_claim_id }}</span>
      </div>

      <div class="yandex-delivery-info__row" v-if="order.yandex_delivery_price">
        <span class="label">Цена доставки:</span>
        <span class="value">{{ order.yandex_delivery_price }} ₽</span>
      </div>

      <template v-if="order.yandex_courier_name">
        <div class="yandex-delivery-info__row">
          <span class="label">Курьер:</span>
          <span class="value">{{ order.yandex_courier_name }}</span>
        </div>
        <div class="yandex-delivery-info__row" v-if="order.yandex_courier_phone">
          <span class="label">Телефон:</span>
          <span class="value">{{ order.yandex_courier_phone }}</span>
        </div>
      </template>

      <div class="yandex-delivery-info__actions" v-if="!isTerminal && canCancelOrder">
        <button
          class="btn btn-danger btn-sm"
          :disabled="cancelling"
          @click="confirmCancel"
        >
          {{ cancelling ? 'Отмена...' : 'Отменить Яндекс-доставку' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { useAuthStore } from '@/stores/auth.js'

export default {
  name: 'YandexDeliveryInfo',
  inject: ['apollo'],
  props: {
    order: {
      type: Object,
      required: true,
    },
  },
  data() {
    return {
      cancelling: false,
    }
  },
  computed: {
    canCancelOrder() {
      return useAuthStore().canOrderAction('orders.cancel')
    },
    statusClass() {
      const status = this.order.yandex_delivery_status
      if (['new', 'estimating', 'ready_for_approval', 'accepted'].includes(status)) {
        return 'badge--blue'
      }
      if (['performer_lookup'].includes(status)) {
        return 'badge--yellow'
      }
      if (['performer_found', 'pickup_arrived', 'pickuped', 'delivery_arrived'].includes(status)) {
        return 'badge--green'
      }
      if (['delivered', 'delivered_finish'].includes(status)) {
        return 'badge--green-bright'
      }
      if (['cancelled', 'cancelled_with_payment', 'failed', 'performer_not_found'].includes(status)) {
        return 'badge--red'
      }
      return 'badge--gray'
    },
    isTerminal() {
      const terminalStatuses = [
        'delivered', 'delivered_finish', 'returned_finish',
        'cancelled', 'cancelled_with_payment', 'cancelled_with_items_on_hands',
        'cancelled_by_taxi', 'performer_not_found', 'failed',
      ]
      return terminalStatuses.includes(this.order.yandex_delivery_status)
    },
    isPickupArrivedOrLater() {
      const laterStatuses = [
        'pickup_arrived', 'ready_for_pickup_confirmation', 'pickuped',
        'delivery_arrived', 'ready_for_delivery_confirmation', 'pay_waiting',
        'delivered', 'delivered_finish',
      ]
      return laterStatuses.includes(this.order.yandex_delivery_status)
    },
  },
  methods: {
    confirmCancel() {
      const warning = this.isPickupArrivedOrLater
        ? 'Курьер уже прибыл! Яндекс спишет штраф. Отменить?'
        : 'Отменить Яндекс-доставку? Бесплатно.'

      if (!confirm(warning)) return

      this.cancelClaim()
    },
    async cancelClaim() {
      this.cancelling = true
      try {
        const storefrontUrl = import.meta.env.VITE_STOREFRONT_URL || ''
        const response = await fetch(
          `${storefrontUrl}/api/v1/internal/yandex-claims/${this.order.yandex_claim_id}/cancel`,
          { method: 'POST', headers: { 'Content-Type': 'application/json' } },
        )
        const data = await response.json().catch(() => ({}))
        if (!response.ok) {
          throw new Error(data.message || `Ошибка ${response.status}`)
        }
        const newStatus = data.claim_status || 'cancelled'
        this.$emit('claim-cancelled', newStatus)
      } catch (e) {
        alert('Ошибка отмены: ' + e.message)
      } finally {
        this.cancelling = false
      }
    },
  },
}
</script>

<style lang="stylus" scoped>
.yandex-delivery-info
  border 1px solid #e0e0e0
  border-radius 8px
  padding 16px
  margin-top 16px

  &__header
    h3
      margin 0 0 12px
      font-size 16px

  &__row
    display flex
    align-items center
    margin-bottom 8px

    .label
      color #666
      min-width 140px

    .value
      font-weight 500

    .monospace
      font-family monospace
      font-size 12px

  &__actions
    margin-top 16px

.badge
  padding 2px 8px
  border-radius 4px
  font-size 12px
  font-weight 500

  &--blue
    background #e3f2fd
    color #1565c0

  &--yellow
    background #fff8e1
    color #f57f17

  &--green
    background #e8f5e9
    color #2e7d32

  &--green-bright
    background #c8e6c9
    color #1b5e20

  &--red
    background #ffebee
    color #c62828

  &--gray
    background #f5f5f5
    color #616161
</style>
