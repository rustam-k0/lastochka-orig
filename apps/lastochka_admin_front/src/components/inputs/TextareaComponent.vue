<template>
  <div
    class="textarea"
    :class="{
      'textarea--focused': focused,
      'textarea--small': small,
      'textarea--error': errors.length,
    }"
  >
    <label class="textarea__title" :for="id" v-if="title">
      {{ title }}
      <span v-if="required">*</span>
    </label>
    <div class="textarea__field">
      <label class="textarea__field-label" :for="id" aria-hidden="true"></label>
      <slot name="before"></slot>
      <textarea
        :id="id"
        @input="$emit('update:model-value', $event.target.value)"
        :value="modelValue"
        @focus="focused = true"
        @blur="focused = false"
        :placeholder="placeholder"
        :rows="rows"
        :disabled="disabled"
        :maxlength="maxlength"
      ></textarea>
      <slot name="after"></slot>
    </div>
    <div class="textarea__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="textarea__messages" v-if="messages.length">
      <span v-for="(message, i) in messages" :key="i">{{ message }}</span>
    </div>
  </div>
</template>

<script>
const uid = () => Date.now().toString(36) + Math.random().toString(36).substring(2);

export default {
  name: "TextareaComponent",
  emits: ["update:model-value"],
  props: {
    title: {
      type: String,
      default: "",
    },
    placeholder: {
      type: String,
      default: "",
    },
    rows: {
      type: String,
      default: "5",
    },
    maxlength: {
      type: [String, Number],
      default: undefined,
    },
    modelValue: [String, Number],
    messages: {
      type: Array,
      default: () => [],
    },
    errors: {
      type: Array,
      default: () => [],
    },
    required: Boolean,
    small: Boolean,
    disabled: Boolean,
  },
  data: () => ({
    id: uid(),
    focused: false,
  }),
};
</script>

<style lang="stylus">
@import "@/styles/parts/input.styl"
.textarea {
  @extend .input;

  textarea {
    &::placeholder {
      font-weight: 400;
      font-size: 0.875rem
      line-height: 16px;
      color: var(--gray-2)
    }
  }

  &__field {
    height auto
    padding-top: 16px
  }
}
</style>
