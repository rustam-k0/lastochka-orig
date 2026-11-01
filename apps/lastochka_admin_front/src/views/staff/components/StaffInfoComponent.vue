<template>
  <div class="staff-info">
    <div class="staff-info__header">
      <span>Основная информация</span>
      <button @click="openModal()" class="btn btn--gray btn--sm">
        <IconComponent name="pencil-fill" />
        Редактировать
      </button>
    </div>
    <div class="staff-info__main">
      <ImgComponent src="images/staff.png" />
      <StaffInputComponent v-for="(item, i) in inputs" :key="i" :data="item" />
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import ImgComponent from "@/components/ImgComponent.vue";
import StaffInputComponent from "@/views/staff/components/StaffInputComponent.vue";
import { useDefaultStore } from "@/stores/default.js";
import StaffInfoModal from "@/views/staff/modals/StaffInfoModal.vue";
export default {
  name: "StaffInfoComponent",
  data() {
    return {
      inputs: [
        {
          title: "Имя",
          subtitle: "Поляков Владислав Андреевич",
        },
        {
          title: "Почта",
          subtitle: "scottie87@hotmail.com",
        },
        {
          title: "Номер телефона",
          subtitle: "+7 (764) 474-3710",
        },
        {
          title: "Дата рождения",
          subtitle: "10.12.2022 18:25",
        },
        {
          title: "Магазин",
          subtitle: "Демо-магазин",
        },
        {
          title: "Последняя авторизация",
          subtitle: "10.12.2022 18:25",
        },
        {
          title: "Последняя активность",
          subtitle: "10.12.2022 18:25",
        },
        {
          title: "Регистрация",
          subtitle: "10.12.2022 18:25",
        },
      ],
    };
  },
  methods: {
    openModal() {
      useDefaultStore().modals.push({
        component: StaffInfoModal,
      });
    },
  },
  components: { StaffInputComponent, ImgComponent, IconComponent },
};
</script>

<style lang="stylus">
.staff-info {
  display flex
  flex-direction column
  background-color var(--white)
  border-radius: 16px;

  &__header {
    display flex
    justify-content space-between
    align-items center
    padding 20px 16px
    border-bottom: 1px solid #E3E8EC;
  }

  &__main {
    display grid
    grid-template-columns: 160fr repeat(4, 334fr);
    gap 16px
    padding 16px
  }

  img {
    grid-area: 1 / 1 / 3 / 2;
    border-radius 100px
  }
}
</style>
