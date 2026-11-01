<template>
  <section class="base-wrapper">
    <div class="base-wrapper__header">
      <span class="base-wrapper__title">
        <slot name="title"></slot>
      </span>
      <div class="base-wrapper__actions">
        <slot name="actions"></slot>
      </div>
    </div>
    <div v-if="subtitle" class="base-wrapper__subtitle">
      <span>{{ subtitle }}</span>
    </div>
    <slot></slot>
    <div v-if="services" class="base-wrapper__footer">
      <CheckboxComponent v-model="form.push.value">
        <span>Получать уведомления о заказе</span>
      </CheckboxComponent>
      <CheckboxComponent v-model="form.news.value">
        <span>Подписка на новости</span>
      </CheckboxComponent>
    </div>
  </section>
</template>

<script>
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";

export default {
  name: "BaseWrapper",
  components: { CheckboxComponent },
  props: {
    title: {
      type: String,
    },
    subtitle: {
      type: String,
    },
    services: {
      type: Boolean,
      default: false,
    },
  },
  data() {
    return {
      form: {
        push: {
          value: null,
          errors: [],
        },
        news: {
          value: null,
          errors: [],
        },
      },
    };
  },
};
</script>

<style lang="stylus">
.base-wrapper {
  padding: 16px
  background var(--white)
  border: 1px solid var(--gray-light);
  border-radius: 8px;
  height min-content

  &__header {
    display flex
    justify-content space-between
    //align-items center
    width 100%
    height 50px
    padding-left: 16px
    padding-top: 5px
    border-bottom 1px solid var(--white-2);
    border-bottom 1px solid var(--white-2)
    margin-bottom 16px
  }

  &__footer {
    width 100%
    height 50px
    border-top 1px solid var(--white-2);
    padding-top 16px
    margin-top 16px
    display flex
    gap 43px
  }

  &__title {
    font-family var(--font-bold)
    font-weight: 500;
    font-size: 1.125rem
    line-height: 25px
    color: var(--dark)
  }

  &__actions {
    display flex
    //align-items center
    gap 8px
  }

  &__subtitle {
    font-weight: 400;
    font-size: 14px;
    line-height: 140%;
    color: var(--black-2);
    padding-bottom: 16px
  }
}
</style>
