<template>
  <div class="default-modal add-client-modal">
    <ModalHeader @close="$emit('close')" title="Новый клиент" />
    <div class="default-modal__body add-client-modal__form">
      <form class="add-client-modal__info">
        <InputComponent
          class="add-client-modal__name"
          title="Фамилия"
          placeholder="Фамилия"
          required
          v-model="form.surname.value"
          :errors="form.surname.errors"
        />
        <InputComponent
          class="add-client-modal__name"
          title="Имя"
          placeholder="Имя"
          required
          v-model="form.name.value"
          :errors="form.name.errors"
        />
        <InputComponent
          class="add-client-modal__name"
          title="Отчество"
          placeholder="Отчество"
          v-model="form.patronymic.value"
          :errors="form.patronymic.errors"
        />
        <div class="add-client-modal__wrapper">
          <InputComponent
            title="Телефон"
            placeholder="Телефон"
            mask="+7 (###) ### ##-##"
            required
            v-model="form.phone.value"
            :errors="form.phone.errors"
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
      <div class="add-client-modal__notification">
        <FlagCheckboxComponent v-if="moduleStore.isModuleEnabled('b2b')" v-model="form.is_b2b.value" green>
          B2B-клиент
        </FlagCheckboxComponent>
        <FlagCheckboxComponent v-model="form.newsletter_subscribed.value" green>
          Подписка на новости
        </FlagCheckboxComponent>
        <!--        <FlagCheckboxComponent v-model="form.push.value" green-->
        <!--          >Получать уведомления о заказе</FlagCheckboxComponent-->
        <!--        >-->
      </div>
      <div class="add-client-modal__info">
        <!--        <div class="add-client-modal__wrapper">-->
        <!--          <InputComponent-->
        <!--            title="Населенный пункт"-->
        <!--            placeholder="Населенный пункт"-->
        <!--            required-->
        <!--            v-model="form.city.value"-->
        <!--            class="add-client-modal__city"-->
        <!--          />-->
        <!--        </div>-->
        <div class="add-client-modal__wrapper">
          <div class="add-client-modal__gender">
            <span>Пол</span>
            <ToggleRadio v-model="gender" title="пол" name="add-client-modal-gender" :options="genders" />
          </div>
          <DateInput v-model="form.birthday.value" title="Дата рождения" placeholder="дд.мм.гггг" />
        </div>
        <!--        <InputComponent-->
        <!--          class="add-client-modal__street"-->
        <!--          title="Улица"-->
        <!--          placeholder="Улица"-->
        <!--          v-model="form.street.value"-->
        <!--        />-->
        <!--        <div class="add-client-modal__wrapper">-->
        <!--          <InputComponent title="Дом" placeholder="Дом" v-model="form.house.value" />-->
        <!--          <InputComponent title="Квартира" placeholder="Квартира" v-model="form.flat.value" />-->
        <!--        </div>-->
      </div>
      <FlagCheckboxComponent v-model="form.personal.value" disabled green
        >Согласен на обработку данных <span class="add-client-modal__required">*</span></FlagCheckboxComponent
      >
    </div>
    <div class="add-client-modal__footer">
      <button @click="$emit('close')" class="btn btn--white btn--md">Отмена</button>
      <button @click="submit" class="btn btn--md">Сохранить</button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import DateInput from "@/components/inputs/DateInput.vue";
import ToggleRadio from "@/components/inputs/toggle/index.vue";
import CREATE from "@/graphql/pages/Clients/ClientCreate.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { parseGqlErrors } from "@/utils.js";
import { resetErrors } from "@/utils.js";
import dayjs from "dayjs";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

export default {
  name: "AddClientModal",
  inject: ["apollo"],
  components: { ToggleRadio, DateInput, FlagCheckboxComponent, InputComponent, ModalHeader },
  computed: {
    moduleStore() {
      return useModuleConfigStore();
    },
  },
  props: {
    callback: Function,
    id: Number,
  },

  data() {
    return {
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
        birthday: {
          value: null,
          errors: [],
        },
        phone: {
          value: null,
          errors: [],
        },
        email: {
          value: null,
          errors: [],
        },
        city: {
          value: null,
          errors: [],
        },
        street: {
          value: null,
          errors: [],
        },
        house: {
          value: null,
          errors: [],
        },
        flat: {
          value: null,
          errors: [],
        },
        is_b2b: {
          value: false,
          errors: [],
        },
        newsletter_subscribed: {
          value: null,
          errors: [],
        },
        personal: {
          value: true,
          errors: [],
        },
        is_b2b: {
          value: false,
          errors: [],
        },
      },
    };
  },
  methods: {
    parseGqlErrors,
    resetErrors,
    submit() {
      this.resetErrors();
      let variables = {};
      Object.keys(this.form).forEach((key) => {
        variables[key] = this.form[key].value ? this.form[key].value : undefined;
        if (key === "birthday") {
          variables[key] = this.form.birthday.value
            ? dayjs(this.form[key].value).format("YYYY-MM-DD HH:mm:ss")
            : null;
        }
      });
      this.apollo()
        .admin.mutate({
          mutation: CREATE,
          variables: variables,
        })
        .then(() => {
          if (this.callback) {
            this.callback();
          }
          useDefaultStore().modals = [];
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors);
          console.log("error");
        });
    },
  },
};
</script>

<style lang="stylus">
.add-client-modal {
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

  &__required {
    color: var(--red);
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

  &__notification {
    display flex
    flex-direction column
    gap 16px
    padding: 8px 0
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
