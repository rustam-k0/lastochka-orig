<template>
  <div class="aside">
    <slot></slot>
    <div class="aside__footer">
      <button :disabled="loading" class="btn btn--main" type="button" @click="$emit('submit')">
        <LoadingIndicator v-if="loading" title="Подождите" />
        <span v-else>Сохранить</span>
      </button>
      <button v-if="showReset" class="btn btn--main-outline" type="button" @click="reset">Сбросить</button>
    </div>
  </div>
</template>
<script>
import LoadingIndicator from "@/components/LoadingIndicator.vue";

export default {
  name: "AsideComponent",
  props: {
    loading: Boolean,
    table: String,
    showReset: () => true,
  },
  methods: {
    reset() {
      if (confirm("Вы уверены?")) {
        this.$emit("reset");
      }
    },
  },
  components: { LoadingIndicator },
};
</script>
