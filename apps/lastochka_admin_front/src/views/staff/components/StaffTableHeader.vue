<template>
  <div class="staff-header">
    <InputComponent
      :modelValue="modelValue"
      @update:modelValue="$emit('update:modelValue', $event)"
      placeholder="Поиск по имени и email"
      autocomplete="off"
      small
    >
      <template #before>
        <IconComponent name="search-line" />
      </template>
    </InputComponent>
    <!--    <button class="btn btn&#45;&#45;gray btn&#45;&#45;sm">-->
    <!--      <IconComponent name="upload-line" />-->
    <!--      Экспорт-->
    <!--    </button>-->
  </div>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
export default {
  name: "StaffTableHeader",
  emits: ["update:modelValue"],
  props: {
    modelValue: {
      type: String,
      default: "",
    },
  },
  components: { IconComponent, InputComponent },
};
</script>

<style lang="stylus">
.staff-header{
  display flex
  padding 16px
  background-color var(--white)
  gap 8px
  border-radius: 16px 16px 0 0
  align-items: center;
}
</style>
