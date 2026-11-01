<template>
  <BaseWrapper>
    <template #title>Общая информация</template>
    <div class="client-info">
      <div class="staff-input">
        <span class="staff-input__title">Имя</span>
        <span class="staff-input__subtitle">{{ fullName }}</span>
      </div>
      <div class="staff-input">
        <span class="staff-input__title">Номер телефона</span>
        <span class="staff-input__subtitle">{{
          info.phone ? mask(info.phone, "+#-###-###-##-##") : ""
        }}</span>
      </div>
      <div class="staff-input">
        <span class="staff-input__title">Почта</span>
        <span class="staff-input__subtitle rows__email">{{ info.email }}</span>
      </div>
      <div class="staff-input">
        <span class="staff-input__title">Регистрация</span>
        <span class="staff-input__subtitle">{{
          info.created_at ? dayjs(info.created_at).format("DD.MM.YYYY hh:mm") : ""
        }}</span>
      </div>
      <div class="staff-input">
        <span class="staff-input__title">Дата рождения</span>
        <span class="staff-input__subtitle">{{
          info.birthday ? dayjs(info.birthday).format("DD.MM.YYYY") : ""
        }}</span>
      </div>
    </div>
    <div class="client-info__note">
      <span class="client-info__note-title">Примечание</span>
      <span v-if="info.admin_note" class="client-info__note-text">{{ info.admin_note }}</span>
      <span v-else class="client-info__note-empty">Нет примечаний</span>
    </div>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import StaffInputComponent from "@/views/staff/components/StaffInputComponent.vue";
import { mask } from "maska";
import dayjs from "dayjs";
export default {
  name: "ClientInfoComponent",
  components: { StaffInputComponent, BaseWrapper },

  props: {
    info: {
      type: Object,
    },
  },
  data() {
    return {
      userInfo: [
        { title: "Населенный пункт", subtitle: "Москва" },
        { title: "Адрес", subtitle: "Тверская ул., 13, кв 263" },
        { title: "Дата рождения", subtitle: "14.03.1994" },
        { title: "", subtitle: "" },
      ],
    };
  },
  methods: {
    mask,
    dayjs,
  },
  computed: {
    fullName() {
      return `${this.info.surname ?? ""} ${this.info.name ?? ""} ${this.info.patronymic ?? ""}`;
    },
  },
};
</script>

<style lang="stylus">
.client-info {
  display grid
  grid-template-columns repeat(4, 1fr)
  grid-gap 16px

  &__note {
    grid-column 1 / -1
    display flex
    flex-direction column
    gap 8px
    padding 16px
    border: 1px solid var(--white-2)
    border-radius: 16px;
    background var(--white)
    margin-top 16px
  }

  &__note-title {
    font-weight: 500;
    font-size: 0.8125em;
    line-height: 18px;
    color: var(--dark);
  }

  &__note-text {
    font-weight: 400;
    font-size: 0.875em;
    line-height: 20px
    color: var(--black-2);
    white-space pre-wrap
    word-break break-word
  }

  &__note-empty {
    font-weight: 400;
    font-size: 0.875em;
    line-height: 20px
    color: var(--gray-dark, #888)
    font-style italic
  }
}

.staff-input {
  display flex
  flex-direction column
  gap 8px
  padding 16px
  border: 1px solid var(--white-2)
  border-radius: 16px;
  background var(--white)

  &__title {
    font-weight: 500;
    font-size: 0.8125em;
    line-height: 18px;
    color: var(--dark);
  }

  &__subtitle {
    font-weight: 400;
    font-size: 0.875em;
    line-height: 20px
    color: var(--black-2);
  }

  &__gray {
    background: var(--neutral-50);
    border-radius: 6px;
  }
}
</style>
