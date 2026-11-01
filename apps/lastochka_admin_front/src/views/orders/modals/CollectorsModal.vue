<template>
  <div class="default-modal collectors-modal">
    <div class="collectors-modal__header">
      <h6>Смена сборщика</h6>
      <button class="btn btn--white" @click="$emit('close')">
        <IconComponent name="close-line" />
      </button>
    </div>
    <div class="collectors-modal__content-wrapper">
      <div class="collectors-modal__content">
        <div class="collectors-modal__alert">
          <IconComponent name="error-warning-line" />
          <span class="collectors-modal__alert-first">Вы уверенны, что хотите сменить сборщика?</span>
        </div>
        <div class="collectors-modal__courier">
          <span>Текущий сборщик:</span><span>{{ localCollector?.title }}</span>
        </div>
        <SelectComponent
          title="Сборщики"
          placeholder="Все"
          :options="collectorsOptions"
          v-model="localCollector"
          label-name="title"
          :errors="localErrors"
          @update:model-value="resetErrors"
          clearable
        />
      </div>
    </div>
    <div class="collectors-modal__actions">
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
import COLLECTOR_UPDATE from "@/graphql/mutations/OrderCollectorUpdate.graphql";
export default {
  name: "CollectorModal",
  mounted() {
    this.localCollector = this.collector
      ? {
          id: this.collector?.id,
          title: `${this.collector.surname} ${this.collector.name}`,
        }
      : null;
  },
  inject: ["apollo"],
  props: {
    collectors: {
      type: Array,
      default: () => [],
    },
    collector: {
      type: Object,
    },
    statusId: {
      type: Number,
      default: null,
    },
    callback: {
      type: Function,
      default: null,
    },
  },
  data() {
    return {
      loading: false,
      localCollector: null,
      localErrors: [],
    };
  },
  methods: {
    resetErrors() {
      this.localErrors = [];
    },
    async submit() {
      this.localErrors = [];
      if (this.localCollector) {
        if (this.statusId === 2) {
          await this.apollo().delivery.mutate({
            mutation: COLLECTOR_UPDATE,
            variables: {
              id: parseInt(this.$route.params.id),
              collector_id: this.localCollector?.id,
            },
          });
        }

        if (this.callback) {
          this.callback(this.localCollector?.id);
        }

        this.$emit("close");
      } else {
        this.localErrors = ["Выберите сборщика"];
      }
    },
  },
  computed: {
    collectorsOptions() {
      // default [] пропа не применяется при явно переданном null
      return (this.collectors || []).map((item) => {
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
.collectors-modal {
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
