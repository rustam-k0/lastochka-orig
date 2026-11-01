<template>
  <span
    class="status"
    :class="{
      'status--green': status.code === 1 || status.code === 3,
      'status--red-light': status.code === 4 || status.code === 2,
      'status--blue': status.code === 5 || status.code === 6 || status.code === 7,
    }"
  >
    <IconComponent v-if="status.code === 1" name="add-circle-fill" />
    <IconComponent v-if="status.code === 2" name="subtract-fill" />
    <IconComponent v-if="status.code === 3" name="checkbox-circle-fill" />
    <IconComponent v-if="status.code === 4" name="close-circle-fill" />
    <IconComponent v-if="status.code === 5" name="smartphone-fill" />
    <IconComponent v-if="status.code === 6" name="message-2-fill" />
    <IconComponent v-if="status.code === 7" name="mail-check-fill" />
    {{ status.name }}
  </span>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
export default {
  name: "HistoryStatusComponent",
  components: { IconComponent },
  props: {
    status: {
      type: Object,
      default: () => {},
    },
  },
};
</script>

<style lang="stylus"></style>
