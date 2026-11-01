<template>
  <div class="default-modal new-staff-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">{{
        id ? "Редактирование сотрудника" : "Новый сотрудник"
      }}</span>
      <button
        type="button"
        class="default-modal__close"
        @click="$emit('close')"
      >
        <IconComponent name="close-fill" />
      </button>
    </div>
    <div class="default-modal__body new-staff-modal__body">
      <InputComponent
        v-model="form.name.value"
        :errors="form.name.errors"
        title="Имя"
        placeholder="Имя"
        required
      />
      <InputComponent
        v-model="form.surname.value"
        :errors="form.surname.errors"
        title="Фамилия"
        placeholder="Фамилия"
        required
      />
      <InputComponent
        v-model="form.patronymic.value"
        :errors="form.patronymic.errors"
        title="Отчество"
        placeholder="Отчество"
      />
      <div class="new-staff-modal__inputs">
        <InputComponent
          v-model="form.phone.value"
          :errors="form.phone.errors"
          title="Номер телефона"
          mask="+# (###) ### ##-##"
          placeholder="+7 (123) 456 78 90"
          required
        />
        <InputComponent
          v-model="form.email.value"
          :errors="form.email.errors"
          title="Почта"
          type="mail"
          placeholder="example@mail.com"
          :required="emailRequired"
        />
      </div>
      <SelectComponent
        v-model="form.role_id.value"
        :errors="form.role_id.errors"
        :options="roles"
        :disabled="disabled"
        label-name="name_ru"
        title="Должность"
        placeholder="Выберите..."
        :clearable="!disabled"
        required
      />
      <SelectComponent
        v-if="isAdmin"
        v-model="form.shops.value"
        :errors="form.shops.errors"
        :options="shops"
        label-name="title"
        title="Магазины"
        multiple
        placeholder="Выберите..."
        clearable
      />

      <div class="new-staff-modal__divider"></div>
      <span class="new-staff-modal__section-title">{{
        id ? "Сменить пароль" : "Пароль"
      }}</span>

      <div class="new-staff-modal__inputs new-staff-modal__inputs--password">
        <InputComponent
          v-model="form.password.value"
          :errors="form.password.errors"
          :title="id ? 'Новый пароль' : 'Пароль'"
          :type="showPassword ? 'text' : 'password'"
          placeholder="Минимум 8 символов"
          autocomplete="new-password"
          :required="!id"
        >
          <template #after>
            <IconComponent
              :name="showPassword ? 'password-hide' : 'password-show'"
              class="new-staff-modal__inputs-icon"
              :key="showPassword ? 'password-hide' : 'password-show'"
              @click="showPassword = !showPassword"
            />
          </template>
        </InputComponent>
        <InputComponent
          v-if="form.password.value"
          v-model="form.password_confirmation.value"
          :errors="form.password_confirmation.errors"
          title="Подтверждение пароля"
          :type="showConfirmPassword ? 'text' : 'password'"
          placeholder="Повторите пароль"
          autocomplete="new-password"
        >
          <template #after>
            <IconComponent
              :name="showConfirmPassword ? 'password-hide' : 'password-show'"
              class="new-staff-modal__inputs-icon"
              :key="showConfirmPassword ? 'password-hide' : 'password-show'"
              @click="showConfirmPassword = !showConfirmPassword"
            />
          </template>
        </InputComponent>
      </div>

      <template v-if="id">
        <div class="new-staff-modal__divider"></div>
        <span class="new-staff-modal__section-title"
          >Двухфакторная аутентификация</span
        >
        <div class="new-staff-modal__2fa-row">
          <span
            :class="[
              'new-staff-modal__2fa-status',
              twoFactorEnabled ? 'is-on' : 'is-off',
            ]"
          >
            {{ twoFactorEnabled ? "Подключена" : "Не подключена" }}
          </span>
          <button
            v-if="twoFactorEnabled"
            type="button"
            class="btn btn--gray btn--md"
            :disabled="resetting2fa"
            @click="resetTwoFactor"
          >
            {{ resetting2fa ? "Сброс..." : "Сбросить 2FA" }}
          </button>
        </div>
      </template>
    </div>
    <div class="default-modal__footer">
      <button v-if="id" @click="remove" class="btn btn--danger btn--md">
        Удалить
      </button>
      <button v-else @click="$emit('close')" class="btn btn--gray btn--md">
        Отмена
      </button>
      <button @click.prevent="submit" class="btn btn--main btn--md">
        {{ id ? "Изменить" : "Создать" }}
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CREATE from "@/graphql/pages/Staffs/UserCreate.graphql";
import UPDATE from "@/graphql/pages/Staffs/UserUpdate.graphql";
import GET_WITHOUT_ID from "@/graphql/pages/Staffs/staff_page.graphql";
import GET from "@/graphql/pages/Staffs/user.graphql";
import REMOVE from "@/graphql/mutations/UserDelete.graphql";
import RESET_TWO_FACTOR from "@/graphql/mutations/ResetUserTwoFactor.graphql";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import { useAuthStore } from "@/stores/auth.js";
// import ModalHeader from "@/components/modals/components/ModalHeader.vue";
export default {
  name: "NewStaffModal",
  inject: ["apollo"],
  props: {
    callback: Function,
    id: Number,
  },
  components: {
    // ModalHeader,
    SelectComponent,
    InputComponent,
    IconComponent,
  },
  data() {
    return {
      disabled: false,
      // Обязательность почты форк-зависима: настройка staff_email_required
      // (Настройки → Общие → Сотрудники), приезжает вместе с формой (MID-293).
      emailRequired: false,
      twoFactorEnabled: false,
      resetting2fa: false,
      shops: [],
      roles: [],
      showPassword: false,
      showConfirmPassword: false,
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
        phone: {
          value: null,
          errors: [],
        },
        email: {
          value: null,
          errors: [],
        },
        role_id: {
          value: null,
          errors: [],
        },
        shops: {
          value: [],
          errors: [],
        },
        password: {
          value: null,
          errors: [],
        },
        password_confirmation: {
          value: null,
          errors: [],
        },
      },
    };
  },
  mounted() {
    this.get();
  },
  computed: {
    // Партнёр-селлер (shop-scope роль) не выбирает магазин — сотрудник
    // автоматически крепится к его магазину; селектор магазинов показываем
    // только глобальным ролям (admin и кастомные глобальные роли матрицы).
    isAdmin() {
      return useAuthStore().isGlobalUser;
    },
  },
  methods: {
    resetErrors,
    parseGqlErrors,
    remove() {
      this.apollo()
        .admin.mutate({
          mutation: REMOVE,
          variables: {
            id: this.id,
          },
        })
        .then(() => {
          this.$emit("close");
          this.callback();
        })
        .catch(() => {
          console.log("error");
        });
    },
    resetTwoFactor() {
      if (
        !confirm(
          "Сбросить двухфакторную аутентификацию этому пользователю? При следующем входе он настроит её заново."
        )
      ) {
        return;
      }
      this.resetting2fa = true;
      this.apollo()
        .admin.mutate({
          mutation: RESET_TWO_FACTOR,
          variables: { id: this.id },
        })
        .then(() => {
          this.twoFactorEnabled = false;
        })
        .catch(() => {})
        .finally(() => {
          this.resetting2fa = false;
        });
    },
    get() {
      this.resetErrors();
      this.apollo()
        .admin.query({
          query: this.id ? GET : GET_WITHOUT_ID,
          variables: {
            id: this.id || undefined,
          },
        })
        .then(({ data }) => {
          if (data.user) {
            let info = data.user;
            const skipKeys = ["password", "password_confirmation"];
            Object.keys(this.form).forEach((key) => {
              if (skipKeys.includes(key)) return;
              this.form[key].value = info[key];
              if (key === "shops") {
                // shop может быть null, если привязанный магазин удалён —
                // без фильтра падает весь обработчик и список магазинов не грузится
                this.form[key].value = info[key]
                  .filter((item) => item.shop)
                  .map((item) => ({
                    id: item.shop.id,
                    title: item.shop.title,
                  }));
              }
              if (key === "role_id") {
                this.form[key].value = {
                  id: info["roles"][0].id,
                  name_ru: info["roles"][0].name_ru,
                };
              }
            });
            this.twoFactorEnabled = !!info.two_factor_enabled;
          }
          this.shops = data.shops || [];
          this.roles = data.roles || [];
          this.emailRequired = data.staffEmailRequired?.value === "1";
          // Партнёр не выбирает магазин: при создании сотрудника крепим его
          // к магазину(ам) партнёра автоматически. Запрос shops скоуплен на бэке
          // и для партнёра возвращает только его магазины.
          if (!this.id && !this.isAdmin) {
            this.form.shops.value = this.shops.map((shop) => ({
              id: shop.id,
              title: shop.title,
            }));
          }
        })
        .catch(() => {});
    },
    validateEmail() {
      this.form.email.errors = [];

      const email = (this.form.email.value || "").trim();

      // MID-293: пустая почта — ошибка только когда включена настройка
      // staff_email_required (её же проверяют UserCreate/UserUpdate на бэке).
      if (!email) {
        if (!this.emailRequired) {
          return true;
        }
        this.form.email.errors.push("Поле обязательно для заполнения");
        return false;
      }

      if (email.length < 6) {
        this.form.email.errors.push(
          "Email должен содержать минимум 6 символов"
        );
        return false;
      }
      const emailRegex = /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/;

      if (!emailRegex.test(email)) {
        this.form.email.errors.push(
          "Введите корректный email, используя только латинские буквы"
        );
        return false;
      }

      return true;
    },
    validatePassword() {
      this.form.password.errors = [];
      this.form.password_confirmation.errors = [];
      const password = this.form.password.value;

      if (this.form.password.value) {
        if (password.length < 8 || password.length > 50) {
          this.form.password.errors.push(
            "Пароль должен содержать от 8 до 50 символов"
          );
          return false;
        }
        const passwordRegex = /^[^А-Яа-я\s]+$/;

        if (!passwordRegex.test(password)) {
          this.form.password.errors.push(
            "Пароль не должен содержать кириллицу и пробелы"
          );
          return false;
        }
        if (
          this.form.password.value !== this.form.password_confirmation.value
        ) {
          this.form.password_confirmation.errors.push("Пароли не совпадают");
          return false;
        }
      }
      return true;
    },
    submit() {
      this.resetErrors();
      // Обе проверки запускаем всегда, чтобы ошибки пароля и почты
      // показывались одновременно, а не по одной за submit.
      const passwordValid = this.validatePassword();
      const emailValid = this.validateEmail();
      if (!passwordValid || !emailValid) {
        return;
      }

      // Необязательные поля: при очистке отправляем явный null, чтобы бэкенд
      // обнулил их. Если оставить undefined, Apollo опускает переменную, и по
      // GraphQL-спеке аргумент считается отсутствующим — поле не меняется
      // (баг: нельзя удалить почту/отчество при редактировании сотрудника).
      // Почта остаётся в списке и при включённой настройке staff_email_required:
      // пустая до submit не доходит (validateEmail), а явный null нужен, чтобы
      // бэкенд отличал очистку от «поле не передавали».
      const nullableKeys = ["email", "patronymic"];

      let variables = {};
      if (this.id) {
        variables.id = this.id;
      }
      Object.keys(this.form).forEach((key) => {
        if (key === "role_id") {
          variables[key] = this.form.role_id.value
            ? this.form.role_id.value.id
            : undefined;
          return;
        }
        if (key === "shops") {
          variables[key] = this.form.shops.value.map((item) => item.id);
          return;
        }
        if (nullableKeys.includes(key)) {
          variables[key] = this.form[key].value ? this.form[key].value : null;
          return;
        }
        variables[key] = this.form[key].value
          ? this.form[key].value
          : undefined;
      });
      this.apollo()
        .admin.mutate({
          mutation: this.id ? UPDATE : CREATE,
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
        });
    },
  },
};
</script>

<style lang="stylus" scoped>
.new-staff-modal {
  width 100%
  max-width: 600px;

  &__body {
    display flex
    flex-direction column
    gap 16px
  }
  &__inputs {
    display flex
    width: 100%;
    align-items baseline
    gap 16px

    &-icon {
      width: 48px;
      cursor pointer
      user-select: none;

      :deep(svg) {
        width: 24px;
        height: 24px;
      }

      :deep(svg path) {
        fill: none;
      }
    }
  }

  &__divider {
    height 1px
    background #E5E7EB
    margin 8px 0
  }

  &__section-title {
    font-weight 600
    font-size 14px
    color #374151
  }

  &__2fa-row {
    display flex
    align-items center
    justify-content space-between
    gap 16px
  }
  &__2fa-status {
    font-size 14px
    font-weight 500
    &.is-on {
      color #16a34a
    }
    &.is-off {
      color #6b7280
    }
  }
}
</style>
