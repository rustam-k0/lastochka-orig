<template>
  <div class="default-modal__header">
    <span class="default-modal__title">{{ title }}</span>
    <button type="button" class="default-modal__close" @click="$emit('close')">
      <IconComponent name="close-fill" />
    </button>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
export default {
  name: "ModalHeader",
  props: {
    title: {
      type: String,
    },
  },
  components: { IconComponent },
};
</script>

<style lang="stylus"></style>
