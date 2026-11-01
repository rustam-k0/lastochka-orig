<template>
  <div class="default-modal edit-client-modal">
    <ModalHeader @close="$emit('close')" title="Редактирование клиента" />
    <div class="default-modal__body edit-client-modal__form">
      <form class="edit-client-modal__info">
        <InputComponent
          class="edit-client-modal__name"
          title="Фамилия"
          placeholder="Фамилия"
          v-model="form.surname.value"
          :errors="form.surname.errors"
        />
        <InputComponent
          class="edit-client-modal__name"
          title="Имя"
          placeholder="Имя"
          v-model="form.name.value"
          :errors="form.name.errors"
        />
        <InputComponent
          class="edit-client-modal__name"
          title="Отчество"
          placeholder="Отчество"
          v-model="form.patronymic.value"
          :errors="form.patronymic.errors"
        />
        <div class="edit-client-modal__wrapper">
          <InputComponent
            title="Телефон"
            placeholder="Телефон"
            mask="+7 (###) ### ##-##"
            :modelValue="phone"
            disabled
          />
          <InputComponent
            title="Почта"
            placeholder="Почта"
            type="email"
            v-model="form.email.value"
            :errors="form.email.errors"
          />
        </div>
      </form>
      <div class="edit-client-modal__info">
        <div class="edit-client-modal__wrapper">
          <div class="edit-client-modal__gender">
            <span>Пол</span>
            <ToggleRadio v-model="gender" title="пол" name="edit-client-modal-gender" :options="genders" />
          </div>
          <DateInput v-model="form.birthday.value" title="Дата рождения" placeholder="дд.мм.гггг" />
        </div>
      </div>
      <div class="edit-client-modal__info">
        <div class="edit-client-modal__note">
          <div class="edit-client-modal__note-header">
            <span class="edit-client-modal__note-title">Примечание</span>
            <span class="edit-client-modal__note-counter">
              {{ adminNoteLength }} / {{ ADMIN_NOTE_MAX_LENGTH }}
            </span>
          </div>
          <textarea
            class="edit-client-modal__note-textarea"
            :maxlength="ADMIN_NOTE_MAX_LENGTH"
            rows="4"
            placeholder="Заметки для администраторов"
            v-model="form.admin_note.value"
          ></textarea>
          <span class="edit-client-modal__note-hint">Видят только администраторы</span>
          <div
            v-if="form.admin_note.errors && form.admin_note.errors.length"
            class="edit-client-modal__note-error"
          >
            {{ form.admin_note.errors.join(", ") }}
          </div>
        </div>
      </div>
    </div>
    <div class="edit-client-modal__footer">
      <button @click="$emit('close')" class="btn btn--white btn--md">Отмена</button>
      <button @click="submit" class="btn btn--md" :disabled="submitting">Сохранить</button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import DateInput from "@/components/inputs/DateInput.vue";
import ToggleRadio from "@/components/inputs/toggle/index.vue";
import UPDATE from "@/graphql/pages/Client/ClientUpdate.graphql";
import { parseGqlErrors, resetErrors } from "@/utils.js";
import dayjs from "dayjs";
import customParseFormat from "dayjs/plugin/customParseFormat";

dayjs.extend(customParseFormat);

const ADMIN_NOTE_MAX_LENGTH = 2000;

export default {
  name: "EditClientModal",
  inject: ["apollo"],
  components: { ToggleRadio, DateInput, InputComponent, ModalHeader },
  props: {
    clientData: {
      type: Object,
      required: true,
    },
    callback: Function,
  },
  created() {
    this.form.name.value = this.clientData.name || null;
    this.form.surname.value = this.clientData.surname || null;
    this.form.patronymic.value = this.clientData.patronymic || null;
    this.form.email.value = this.clientData.email || null;
    // Сырое значение бэка ("YYYY-MM-DD HH:mm:ss") — DateInput сам форматирует
    // для отображения, а submit парсит dayjs() без формата. Display-строка
    // "DD.MM.YYYY" здесь даёт "Invalid Date" при сохранении (ALADDIN-12068).
    this.form.birthday.value = this.clientData.birthday || null;
    this.form.admin_note.value = this.clientData.admin_note || "";
    this.gender = this.clientData.gender_type_id || null;
  },
  data() {
    return {
      submitting: false,
      gender: null,
      genders: [
        {
          value: 1,
          title: "Мужской",
        },
        {
          value: 2,
          title: "Женский",
        },
      ],
      form: {
        name: {
          value: null,
          errors: [],
        },
        surname: {
          value: null,
          errors: [],
        },
        patronymic: {
          value: null,
          errors: [],
        },
        email: {
          value: null,
          errors: [],
        },
        birthday: {
          value: null,
          errors: [],
        },
        admin_note: {
          value: "",
          errors: [],
        },
      },
      ADMIN_NOTE_MAX_LENGTH,
    };
  },
  computed: {
    phone() {
      return this.clientData.phone || "";
    },
    adminNoteLength() {
      return (this.form.admin_note.value || "").length;
    },
  },
  methods: {
    parseGqlErrors,
    resetErrors,
    submit() {
      this.resetErrors();
      this.submitting = true;

      let variables = {
        id: this.clientData.id,
        name: this.form.name.value || null,
        surname: this.form.surname.value || null,
        patronymic: this.form.patronymic.value || null,
        email: this.form.email.value || null,
        birthday: this.form.birthday.value
          ? dayjs(this.form.birthday.value).format("YYYY-MM-DD HH:mm:ss")
          : null,
        gender_type_id: this.gender || null,
        admin_note: this.form.admin_note.value ?? "",
      };

      this.apollo()
        .admin.mutate({
          mutation: UPDATE,
          variables: variables,
        })
        .then(() => {
          this.$emit("close");
          if (this.callback) {
            this.callback();
          }
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors);
        })
        .finally(() => {
          this.submitting = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.edit-client-modal {
  width 608px

  &__form {
    display flex
    flex-direction column
    gap 16px
  }

  &__info {
    width 100%
    display grid
    grid-template-columns repeat(1, 1fr)
    gap 16px
  }

  &__wrapper {
    display grid
    grid-template-columns repeat(2, 1fr)
    align-items: baseline;
    gap 16px
  }

  &__gender {
    display flex
    flex-direction column
    gap 12px

    & .toggle {
      display flex
      gap 18px
    }

    & > span {
      font-weight: 400;
      font-size: 0.8125em;
      line-height: 20px;
      color: var(--dark);
    }
  }

  &__note {
    display flex
    flex-direction column
    gap 8px
    width 100%
  }

  &__note-header {
    display flex
    justify-content space-between
    align-items center
  }

  &__note-title {
    font-weight: 500;
    font-size: 0.8125em;
    line-height: 18px;
    color: var(--dark);
  }

  &__note-counter {
    font-weight: 400;
    font-size: 0.75em;
    line-height: 18px;
    color: var(--gray-dark, #888);
  }

  &__note-textarea {
    width 100%
    min-height 96px
    padding 12px 16px
    border: 1px solid var(--white-2)
    border-radius 8px
    font-family inherit
    font-size 0.875em
    line-height 20px
    resize vertical
    outline none

    &:focus {
      border-color var(--primary)
    }
  }

  &__note-hint {
    font-weight: 400;
    font-size: 0.75em;
    line-height: 16px;
    color: var(--gray-dark, #888);
  }

  &__note-error {
    font-weight: 400;
    font-size: 0.75em;
    line-height: 16px;
    color: var(--red, #e53935);
  }

  &__footer {
    display flex
    padding 16px
    gap 16px
    width 100%
    justify-content flex-end
    border-top: 1px solid var(--white-2);

    & .btn {
      width 100%
      max-width 132px
    }
  }
}
</style>
