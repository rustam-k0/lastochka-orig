<template>
  <BaseWrapper>
    <template #title>Промокоды</template>
    <div class="client-promo">
      <div class="client-promo__list">
        <div v-for="(item, i) in data" :key="i" class="client-promo__item">
          <div class="dot" :class="{ 'dot--active': item.active, 'dot--unactive': !item.active }" />
          <span>{{ item.name }}</span>
          <div @click="$emit('promo-remove')" class="client-promo__remove">
            <IconComponent name="close-fill" />
          </div>
        </div>
      </div>
      <form @submit.prevent="$emit('add-promo', form.promoCode.value)" class="client-promo__form">
        <InputComponent v-model="form.promoCode.value" placeholder="Промокод" />
        <button class="btn btn--green btn--lg">Добавить</button>
      </form>
    </div>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";

export default {
  name: "ClientPromoCodeComponent",
  components: { InputComponent, IconComponent, BaseWrapper },
  props: {
    data: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {
      form: {
        promoCode: {
          value: null,
          errors: [],
        },
      },
    };
  },
};
</script>

<style lang="stylus">
.client-promo {
  display flex
  flex-direction column
  gap 16px

  &__list {
    display flex
    flex-direction column
    gap 4px
  }

  &__item {
    background var(--neutral-50)
    border-radius: 16px;
    padding: 18px 16px 18px 20px
    display flex
    gap 8px
    align-items center

    & span {
      font-weight: 600;
      font-size: 0.875rem
      line-height: 20px
      color var(--dark)
    }
  }

  &__remove {
    margin-left auto
    cursor pointer
    display flex
    justify-content center
    align-items center

    & .icon {
      width 16px
      height 16px
    }
  }

  &__form {
    display flex
    gap 8px
  }
}
</style>
