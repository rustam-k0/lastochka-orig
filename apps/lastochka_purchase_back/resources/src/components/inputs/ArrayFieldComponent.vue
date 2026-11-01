<template>
  <div class="ms">
    <span class="ms__title">{{ title }}</span>
    <div v-if="localValue.length" class="ms__list">
      <div v-for="(item, i) in localValue" :key="i" class="ms__item">
        <div class="form__inputs">
          <slot :i="i" :item="item"></slot>
          <div>
            <button class="btn btn--medium btn--red" type="button" @click.prevent="remove(i)">Удалить</button>
          </div>
        </div>
      </div>
    </div>
    <div class="ms__footer">
      <button class="btn btn--main btn--medium" type="button" @click.prevent="add">Добавить</button>
      <button
        v-if="localValue.length"
        class="btn btn--main-outline btn--medium"
        type="button"
        @click.prevent="reset"
      >
        Сбросить
      </button>
    </div>
  </div>
</template>

<script>
export default {
  name: "ArrayFieldComponent",
  props: {
    title: String,
    template: Object,
    value: {
      type: Array,
      default() {
        return [];
      },
    },
  },
  data() {
    return {
      localValue: this.value,
    };
  },
  watch: {
    value: {
      immediate: true,
      handler(val) {
        this.localValue = val;
      },
    },
  },
  methods: {
    remove(i) {
      if (confirm("Вы уверены?")) {
        this.localValue.splice(i, 1);
        this.$notify({
          title: "Внимание",
          text: "Сохраните чтобы не потерять изменения",
          type: "warning",
        });
      }
    },
    emit() {
      this.$emit("input", this.localValue);
    },
    reset() {
      this.localValue = [];
      this.emit();
    },
    add() {
      this.localValue.push(JSON.parse(JSON.stringify(this.template)));
      this.emit();
    },
  },
};
</script>
