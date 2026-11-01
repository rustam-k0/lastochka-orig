<template>
  <div class="default-modal change-shops-modal">
    <div class="change-shops-modal__header">
      <h6>Смена магазина</h6>
      <button class="btn btn--white" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>
    <div class="change-shops-modal__content-wrapper">
      <div class="change-shops-modal__content">
        <div class="change-shops-modal__alert">
          <IconComponent name="error-warning-line" />
          <span class="change-shops-modal__alert-first">Вы уверенны, что хотите сменить магазин?</span>
        </div>
        <div class="change-shops-modal__shops">
          <InputComponent title="Магазины" :model-value="shop?.title" disabled />
          <IconComponent name="right-long-arrow" />
          <SelectComponent placeholder="Все" :options="shops" v-model="localShop" label-name="title" />
        </div>
      </div>
    </div>
    <div class="change-shops-modal__actions">
      <button class="btn btn--lg btn--white" @click="$emit('close')">
        <span>Отмена</span>
      </button>
      <button class="btn btn--lg" @click="submit">
        <span>Подтвердить</span>
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SHOP_UPDATE from "@/graphql/mutations/OrderShopUpdate.graphql";
export default {
  name: "CouriersModal",
  inject: ["apollo"],
  props: {
    shops: {
      type: Array,
      default: () => [],
    },
    shop: {
      type: Object,
      default: () => {},
    },
    callback: {
      type: Function,
    },
  },
  mounted() {
    this.localShop = this.shop;
  },
  methods: {
    submit() {
      this.apollo()
        .purchase.mutate({
          mutation: SHOP_UPDATE,
          variables: {
            order_id: parseInt(this.$route.params.id),
            shop_id: this.localShop?.id,
          },
        })
        .then(() => {
          this.callback();
          this.$emit("close");
        })
        .catch(() => {
          alert("Вы не можете сменить магазин");
          this.$emit("close");
        });
    },
  },
  data() {
    return {
      loading: false,
      localShop: null,
    };
  },
  components: {
    InputComponent,
    SelectComponent,
    IconComponent,
  },
};
</script>

<style lang="stylus">
.change-shops-modal {
  width 100%
  max-width 529px

  &__header {
    padding 16px
    display flex
    justify-content space-between
    align-items center

    h6 {
      font-weight: 500;
      font-size: 1.125em;
      font-family var(--font-bold)
    }

    .btn {
      width 34px
      height 34px
    }

    .icon {
      width 18px
      height 18px
    }
  }

  &__content-wrapper {
    border-bottom 1px solid var(--border-color)
    border-top 1px solid var(--border-color)
  }

  &__content {
    padding 16px
    display flex
    flex-direction column
    gap 16px
  }

  &__alert {
    display grid
    grid-template-columns 24px 1fr
    gap 8px
    font-weight: 400;
    font-size: 13px;
    line-height: 20px;
    background var(--blue-light)
    padding 16px
    border-radius 16px
    align-items center

    .icon {
      height 24px
    }
  }

  &__shops {
    display grid
    grid-template-columns 1fr 24px 1fr
    align-items flex-end
    gap 16px
    font-weight: 400;
    font-size: 0.813rem
    line-height: 14px

    .input__title {
      font-size 0.875rem
    }

    .icon {
      width 24px
      height 48px
      display flex
      align-items center
    }
  }

  &__actions {
    display flex
    padding 16px
    width 100%
    justify-content space-between

    .btn {
      width 100%
      max-width 132px
    }
  }
}
</style>
