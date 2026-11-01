<template>
  <BaseWrapper class="order-object-address">
    <template #title>Объект</template>
    <template #default>
      <div class="order-object-address__head">
        <div class="order-object-address__name">{{ data.name }}</div>
        <div v-if="data.company" class="order-object-address__company">{{ data.company }}</div>
      </div>

      <div v-if="data.contactName || data.contactPhone" class="order-object-address__section">
        <div class="order-object-address__label">Контакт</div>
        <div v-if="data.contactName">{{ data.contactName }}</div>
        <a v-if="data.contactPhone" :href="`tel:${data.contactPhone}`" class="order-object-address__phone">
          {{ data.contactPhone }}
        </a>
      </div>

      <div v-if="hasLiftInfo" class="order-object-address__section">
        <div class="order-object-address__label">Лифт</div>
        <div>{{ liftLine }}</div>
      </div>

      <div v-if="hasAccessInfo" class="order-object-address__section">
        <div class="order-object-address__label">Доступ</div>
        <ul class="order-object-address__list">
          <li v-if="data.needsPass">⚠ Нужен пропуск</li>
          <li v-if="data.canOversized">✓ Возможен проезд крупногабарита</li>
        </ul>
      </div>

      <div v-if="hasTimeInfo" class="order-object-address__section">
        <div class="order-object-address__label">Время приёма</div>
        <div>{{ timeLine }}</div>
      </div>

      <div v-if="hasDaysInfo" class="order-object-address__section">
        <div class="order-object-address__label">Дни работы</div>
        <div class="order-object-address__chips">
          <span
            v-if="data.isDaily"
            class="order-object-address__chip order-object-address__chip--all"
          >Ежедневно</span>
          <template v-else>
            <span
              v-for="code in data.workingDays"
              :key="code"
              class="order-object-address__chip order-object-address__chip--active"
            >{{ dayLabel(code) }}</span>
          </template>
        </div>
      </div>

      <div v-if="data.photos && data.photos.length" class="order-object-address__section">
        <div class="order-object-address__label">Фотографии</div>
        <div class="order-object-address__photos">
          <a
            v-for="(photo, i) in data.photos"
            :key="i"
            :href="photo.url"
            target="_blank"
            rel="noopener"
            class="order-object-address__photo"
          >
            <img :src="photo.url" :alt="`Фото ${i + 1}`" loading="lazy" />
          </a>
        </div>
      </div>
    </template>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from '@/components/UI/BaseWrapper.vue'

const DAY_LABELS = {
  mon: 'Пн', tue: 'Вт', wed: 'Ср', thu: 'Чт', fri: 'Пт', sat: 'Сб', sun: 'Вс',
}

export default {
  name: 'OrderObjectAddress',
  components: { BaseWrapper },
  props: {
    data: {
      type: Object,
      required: true,
    },
  },
  computed: {
    hasLiftInfo() {
      return this.data.hasLift !== null || !!this.data.liftSize
    },
    liftLine() {
      const parts = []
      if (this.data.hasLift === true) parts.push('Есть')
      else if (this.data.hasLift === false) parts.push('Нет')
      if (this.data.liftSize) parts.push(`размер ${this.data.liftSize}`)
      return parts.join(', ')
    },
    hasAccessInfo() {
      return this.data.needsPass !== null || this.data.canOversized !== null
    },
    hasTimeInfo() {
      return this.data.is247 || (this.data.workingHoursFrom && this.data.workingHoursTo)
    },
    timeLine() {
      if (this.data.is247) return 'Круглосуточно'
      return `${this.data.workingHoursFrom} – ${this.data.workingHoursTo}`
    },
    hasDaysInfo() {
      return this.data.isDaily || (this.data.workingDays && this.data.workingDays.length > 0)
    },
  },
  methods: {
    dayLabel(code) {
      return DAY_LABELS[code] || code
    },
  },
}
</script>

<style lang="stylus" scoped>
.order-object-address
  &__head
    margin-bottom 12px
  &__name
    font-weight 600
    font-size 16px
  &__company
    color var(--gray-2)
    font-size 13px
  &__section
    margin-top 12px
    padding-top 8px
    border-top 1px solid var(--gray-3, #eee)
  &__label
    font-size 12px
    text-transform uppercase
    color var(--gray-2)
    margin-bottom 4px
  &__list
    margin 0
    padding 0
    list-style none
    li
      padding 2px 0
  &__phone
    color var(--orange-2, #FF6B00)
    text-decoration none
    &:hover
      text-decoration underline
  &__chips
    display flex
    flex-wrap wrap
    gap 6px
  &__chip
    display inline-block
    padding 4px 10px
    border-radius 12px
    font-size 12px
    background var(--gray-3, #eee)
    color var(--gray-1, #555)
    &--active
      background var(--orange-2, #FF6B00)
      color #fff
    &--all
      background #2ecc71
      color #fff
  &__photos
    display grid
    grid-template-columns repeat(auto-fill, minmax(80px, 1fr))
    gap 6px
  &__photo
    display block
    aspect-ratio 1
    overflow hidden
    border-radius 6px
    img
      width 100%
      height 100%
      object-fit cover
</style>
