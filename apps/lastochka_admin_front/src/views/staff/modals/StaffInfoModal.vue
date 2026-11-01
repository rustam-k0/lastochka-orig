<template>
  <div class="default-modal staff-info-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Общая информация</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <!--    <ModalHeader title="Общая информация" />-->
    <div class="default-modal__body staff-info-modal__body">
      <div class="staff-info-modal__inputs">
        <InputComponent
          v-for="(item, i) in inputs"
          :key="i"
          :title="item.title"
          :placeholder="item.placeholder"
          :type="item.type"
          :mask="item.mask"
        />
        <SelectComponent title="Магазин" placeholder="Демо" />
      </div>
    </div>
    <div class="default-modal__footer">
      <button class="btn btn--gray btn--md">Отмена</button>
      <button class="btn btn--main btn--md">Сохранить</button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
// import ModalHeader from "@/components/modals/components/ModalHeader.vue";
export default {
  name: "StaffInfoModal",
  data() {
    return {
      inputs: [
        {
          title: "Имя",
          placeholder: "Поляков Владислав Андреевич",
          type: "",
          mask: "",
        },
        {
          title: "Дата рождения",
          placeholder: "DD/MM/YYYY",
          type: "date",
          mask: "",
        },
        {
          title: "Номер телефона",
          placeholder: "+1 (234) 567 89 00",
          type: "",
          mask: "+# (###) ### ## ##",
        },
        {
          title: "Почта",
          placeholder: "scottie87@hotmail.com",
          type: "mail",
          mask: "",
        },
      ],
    };
  },
  components: {
    // ModalHeader,
    SelectComponent,
    InputComponent,
    IconComponent,
  },
};
</script>

<style lang="stylus">
.staff-info-modal{
  max-width: 608px;

  &__body{
    display flex
    flex-direction column
    gap 16px
  }

  &__inputs{
    display grid
    gap 16px
    grid-template-columns: repeat(2, 1fr);
    width: 100%;

    :nth-child(5){
      grid-area: 3 / 1 / 4 / 3;
    }
  }

}
</style>
