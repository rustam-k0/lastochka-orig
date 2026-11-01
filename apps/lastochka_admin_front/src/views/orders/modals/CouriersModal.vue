<template>
  <div class="default-modal couriers-modal">
    <div class="couriers-modal__header">
      <h6>{{ mode === 'add' ? 'Добавление курьера' : 'Смена курьера' }}</h6>
      <button class="btn btn--white" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>
    <div class="couriers-modal__content-wrapper">
      <div class="couriers-modal__content">
        <div v-if="mode !== 'add'" class="couriers-modal__alert">
          <IconComponent name="error-warning-line" />
          <span class="couriers-modal__alert-first">Вы уверенны, что хотите сменить курьера?</span>
        </div>
        <div v-if="mode !== 'add'" class="couriers-modal__courier">
          <span>Текущий курьер:</span><span>{{ localCourier?.title }}</span>
        </div>
        <SelectComponent
          title="Курьеры"
          placeholder="Все"
          :options="couriersOptions"
          v-model="localCourier"
          label-name="title"
          :errors="localErrors"
          @update:model-value="resetErrors"
          clearable
        />
      </div>
    </div>
    <div class="couriers-modal__actions">
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
import COURIER_UPDATE from "@/graphql/mutations/OrderCourierUpdate.graphql";
export default {
  name: "CouriersModal",
  mounted() {
    // Подставляем текущего курьера заказа в "Текущий курьер" и предвыбор селекта.
    // Раньше тут проверялся this.localCourier (всегда null на mounted) вместо prop
    // this.courier — поле "Текущий курьер" оставалось пустым.
    this.localCourier = this.courier
      ? {
          id: this.courier?.id,
          title: `${this.courier.surname} ${this.courier.name}`,
        }
      : null;
  },
  inject: ["apollo"],
  props: {
    couriers: {
      type: Array,
      default: () => [],
    },
    courier: {
      type: Object,
    },
    statusId: {
      type: Number,
      default: null,
    },
    mode: {
      type: String,
      default: "change",
    },
    callback: {
      type: Function,
      default: null,
    },
  },
  data() {
    return {
      loading: false,
      localCourier: null,
      localErrors: [],
    };
  },

  methods: {
    resetErrors() {
      this.localErrors = [];
    },
    async submit() {
      this.localErrors = [];
      if (this.localCourier) {
        // Режим "add" только отдаёт выбранного курьера в callback (OrderCourierAdd),
        // смену курьера (COURIER_UPDATE) не выполняет.
        if (this.statusId === 4 && this.mode !== "add") {
          await this.apollo().delivery.mutate({
            mutation: COURIER_UPDATE,
            variables: {
              id: parseInt(this.$route.params.id),
              courier_id: this.localCourier?.id,
            },
          });
        }

        if (this.callback) {
          this.callback(this.localCourier?.id);
        }

        this.$emit("close");
      } else {
        this.localErrors = ["Выберите курьера"];
      }
    },
  },
  computed: {
    couriersOptions() {
      // default [] пропа не применяется при явно переданном null
      return (this.couriers || []).map((item) => {
        return {
          id: item.id,
          title: `${item.surname} ${item.name}`,
        };
      });
    },
  },
  components: {
    SelectComponent,
    IconComponent,
  },
};
</script>

<style lang="stylus">
.couriers-modal {
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

  &__courier {
    display flex
    gap 4px
    font-weight: 400;
    font-size: 0.813em
    line-height: 14px

    span:nth-child(2) {
      font-weight 500
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
