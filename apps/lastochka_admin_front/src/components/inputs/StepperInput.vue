<template>
  <div class="stepper">
    <button class="btn btn--sm" @click="decrement">-</button>
    <div class="stepper__unit">
      <InputComponent
        :model-value="modelValue"
        @update:model-value="$emit('update:model-value', parseInt($event))"
        mask="###########"
        @keydown="updateKeydown"
        small
      />
      <span class="stepper__unit-item">{{ unitTypeId === 1 ? "г" : "шт" }}</span>
    </div>
    <button class="btn btn--sm" @click="$emit('update:model-value', modelValue + step)">+</button>
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
export default {
  name: "StepperInput",
  emits: ["update:model-value", "remove", "keydown"],
  props: {
    id: {
      type: Number,
    },
    modelValue: {
      type: Number,
      default: 0,
    },
    step: {
      type: Number,
    },
    unitTypeId: {
      type: Number,
    },
  },
  mounted() {},
  data() {
    return {
      localValue: null,
    };
  },

  watch: {},

  methods: {
    updateKeydown($event) {
      this.$emit("keydown", $event);
    },
    decrement() {
      if (parseInt(this.modelValue) < 2) {
        this.$emit("remove", this.id);
      } else {
        this.$emit("update:model-value", parseInt(this.modelValue) - this.step);
      }
    },
  },
  components: {
    InputComponent,
  },
};
</script>

<style lang="stylus">
.stepper {
  display flex

  &__unit {
    position relative
  }

  &__unit-item {
    position absolute
    top 7px
    right 5px
    z-index 2
  }

  .input {
    width 80px
  }

  .input__field {
    padding-left 5px
    padding-right 25px
  }

  .input__field-label {
    border-radius 0
  }

  .btn {
    width 30px

    &:first-child {
      border-top-right-radius 0
      border-bottom-right-radius 0
    }

    &:last-child {
      border-top-left-radius 0
      border-bottom-left-radius 0
    }
  }
}
</style>
