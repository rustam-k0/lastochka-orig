<template>
  <div
    class="input"
    :class="{
      'input--focused': focused,
      'input--small': small,
      'input--error': errors.length,
      'input--disabled': disabled,
    }"
  >
    <label class="input__title" :for="id" v-if="title">
      {{ title }}
      <span v-if="required">*</span>
    </label>
    <div class="input__field" :class="{ 'input--column': subtitle }">
      <label class="input__field-label" :for="id" aria-hidden="true"></label>
      <span v-if="subtitle" class="input__subtitle"> {{ subtitle }}</span>
      <slot name="before"></slot>
      <input
        :id="id"
        @input="onInput"
        :type="type"
        v-maska="mask"
        :value="modelValue"
        :min="min"
        @keydown="onKeydown"
        @focus="focused = true"
        @blur="$emit('blur', $event.target.value)"
        :placeholder="placeholder"
        :disabled="disabled"
        :autocomplete="autocomplete"
      />
      <slot name="after"></slot>
    </div>
    <div class="input__errors" v-if="errors.length">
      <span v-for="(error, i) in errors" :key="i">{{ error }}</span>
    </div>
    <div class="input__messages" v-if="messages.length">
      <span v-for="(message, i) in messages" :key="i">{{ message }}</span>
    </div>
  </div>
</template>

<script>
import { maska } from "maska";

const uid = () => Date.now().toString(36) + Math.random().toString(36).substring(2);

export default {
  name: "InputComponent",
  emits: ["update:model-value", "blur", "keydown"],
  props: {
    title: {
      type: String,
      default: "",
    },
    subtitle: {
      type: String,
      default: "",
    },
    placeholder: {
      type: String,
      default: "",
    },
    type: {
      type: String,
      default: "text",
    },
    // Прокидываем autocomplete на сам <input>: при дефолтном inheritAttrs
    // атрибут оседал на корневом <div class="input"> и игнорировался браузером,
    // из-за чего autocomplete="new-password" в полях пароля не работал и Edge/Chrome
    // подставлял сохранённые креды (логин админа — в поисковую строку). ALADDIN-11575
    autocomplete: {
      type: String,
      default: undefined,
    },
    mask: {
      type: String,
    },
    min: {
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
  methods: {
    // При min >= 0 запрещаем сам ввод минуса, а не только ругаемся на submit:
    // стрелка вниз ограничена атрибутом min, клавиша «-» блокируется здесь,
    // вставленное из буфера отрицательное значение приводится к min в onInput.
    onKeydown(e) {
      if (this.type === "number" && this.min !== undefined && Number(this.min) >= 0 && e.key === "-") {
        e.preventDefault();
        return;
      }
      this.$emit("keydown", e);
    },
    onInput(e) {
      let value = e.target.value;
      if (this.type === "number" && this.min !== undefined && value !== "" && Number(value) < Number(this.min)) {
        value = String(this.min);
        e.target.value = value;
      }
      this.$emit("update:model-value", value);
    },
  },
  directives: {
    maska,
  },
};
</script>

<style lang="stylus">
@import "@/styles/parts/input.styl"
</style>
