<template>
  <form class="vacancy-form" @submit.prevent="submit">
    <div v-if="loadingData" class="vacancy-form__loading">
      <LoadingIndicator />
    </div>

    <template v-else>
      <div class="vacancy-form__block">
        <div class="vacancy-form__block-header">Основная информация</div>
        <div class="vacancy-form__block-content">
          <InputComponent
            v-model="form.title.value"
            :errors="form.title.errors"
            title="Наименование вакансии"
            placeholder="Например: Повар горячего цеха"
            required
          />
          <InputComponent
            v-model="form.city.value"
            :errors="form.city.errors"
            title="Город"
            placeholder="Город, в котором открыта вакансия"
          />
          <SelectComponent
            :model-value="selectedWorkFormat"
            @update:model-value="form.work_format.value = $event ? $event.value : null"
            :errors="form.work_format.errors"
            title="Формат работы"
            placeholder="Не указан"
            :options="workFormats"
            label-name="label"
            clearable
          />
          <SelectComponent
            :model-value="selectedEmploymentType"
            @update:model-value="form.employment_type.value = $event ? $event.value : null"
            :errors="form.employment_type.errors"
            title="Тип занятости"
            placeholder="Не указан"
            :options="employmentTypes"
            label-name="label"
            clearable
          />
          <InputComponent
            v-model="form.work_schedule.value"
            :errors="form.work_schedule.errors"
            title="График работы"
            placeholder="Например: 3/3, с 09:00 до 21:00"
          />
          <InputComponent
            v-model="form.salary.value"
            :errors="form.salary.errors"
            title="Уровень дохода"
            placeholder="Например: от 80 000 ₽"
          />
        </div>
      </div>

      <div class="vacancy-form__block">
        <div class="vacancy-form__block-header">Описание вакансии</div>
        <div class="vacancy-form__block-content">
          <TextareaComponent
            v-model="form.short_description.value"
            :errors="form.short_description.errors"
            title="Краткое описание"
            placeholder="Текст, который увидит пользователь в списке вакансий"
            rows="3"
            required
          />
          <TextareaComponent
            v-model="form.description.value"
            :errors="form.description.errors"
            title="Полное описание"
            placeholder="Подробная информация о вакансии"
            rows="6"
            required
          />
          <TextareaComponent
            v-model="form.responsibilities.value"
            :errors="form.responsibilities.errors"
            title="Обязанности"
            placeholder="Основные задачи сотрудника"
            rows="4"
          />
          <TextareaComponent
            v-model="form.requirements.value"
            :errors="form.requirements.errors"
            title="Требования"
            placeholder="Требования к кандидату"
            rows="4"
          />
          <TextareaComponent
            v-model="form.conditions.value"
            :errors="form.conditions.errors"
            title="Условия"
            placeholder="Условия работы и преимущества"
            rows="4"
          />
        </div>
      </div>

      <div class="vacancy-form__block">
        <div class="vacancy-form__block-header">Ссылка на вакансию</div>
        <div class="vacancy-form__block-content">
          <InputComponent
            v-model="form.link_title.value"
            :errors="form.link_title.errors"
            title="Название ссылки"
            placeholder="Например: Смотреть на hh.ru"
          />
          <InputComponent
            v-model="form.link_url.value"
            :errors="form.link_url.errors"
            title="Ссылка на вакансию"
            placeholder="https://hh.ru/vacancy/123456"
          />
          <p class="vacancy-form__hint">
            Ссылка на эту же вакансию на внешнем ресурсе, например на hh.ru. Отображается в карточке вакансии
            на сайте и в мобильном приложении. Если название не заполнено, будет показана подпись по
            умолчанию.
          </p>
        </div>
      </div>

      <div class="vacancy-form__block">
        <div class="vacancy-form__block-header">Публикация</div>
        <div class="vacancy-form__block-content">
          <InputComponent
            v-model="form.position.value"
            :errors="form.position.errors"
            title="Порядок отображения"
            placeholder="Чем меньше — тем выше в списке"
            type="number"
          />
          <FlagCheckboxComponent v-model="form.is_published.value" green>
            {{ form.is_published.value ? "Опубликована" : "Не опубликована" }}
          </FlagCheckboxComponent>
          <p class="vacancy-form__hint">
            Опубликованная вакансия отображается в мобильном приложении и на сайте, по ней можно отправить
            отклик. Отклики приходят на почту и в админ-панели не сохраняются.
          </p>
          <p v-if="publishedAt" class="vacancy-form__hint">Дата последней публикации: {{ publishedAt }}</p>
        </div>
      </div>

      <div class="vacancy-form__actions">
        <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">Отмена</button>
        <button type="submit" class="btn btn--md" :disabled="loading">
          {{ isEdit ? "Сохранить" : "Создать" }}
        </button>
      </div>
    </template>
  </form>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import VACANCY_ITEM from "@/graphql/queries/admin_vacancies_item.graphql";
import VACANCY_CREATE from "@/graphql/mutations/VacancyCreate.graphql";
import VACANCY_UPDATE from "@/graphql/mutations/VacancyUpdate.graphql";
import { VACANCY_EMPLOYMENT_TYPES, VACANCY_WORK_FORMATS } from "@/constants/vacancies.js";

export default {
  name: "VacancyForm",
  inject: ["apollo"],
  components: {
    InputComponent,
    TextareaComponent,
    SelectComponent,
    FlagCheckboxComponent,
    LoadingIndicator,
  },
  props: {
    id: {
      type: [Number, String],
      default: null,
    },
  },
  emits: ["success", "cancel"],
  data() {
    return {
      loading: false,
      loadingData: false,
      publishedAt: null,
      workFormats: VACANCY_WORK_FORMATS,
      employmentTypes: VACANCY_EMPLOYMENT_TYPES,
      form: {
        title: { value: "", errors: [] },
        city: { value: "", errors: [] },
        work_format: { value: null, errors: [] },
        employment_type: { value: null, errors: [] },
        work_schedule: { value: "", errors: [] },
        salary: { value: "", errors: [] },
        short_description: { value: "", errors: [] },
        description: { value: "", errors: [] },
        responsibilities: { value: "", errors: [] },
        requirements: { value: "", errors: [] },
        conditions: { value: "", errors: [] },
        link_title: { value: "", errors: [] },
        link_url: { value: "", errors: [] },
        position: { value: null, errors: [] },
        is_published: { value: false, errors: [] },
      },
    };
  },
  computed: {
    isEdit() {
      return !!this.id;
    },
    // SelectComponent работает с объектом опции целиком, а в форме
    // хранится код справочника — резолвим опцию по коду.
    selectedWorkFormat() {
      return this.workFormats.find((item) => item.value === this.form.work_format.value) || null;
    },
    selectedEmploymentType() {
      return this.employmentTypes.find((item) => item.value === this.form.employment_type.value) || null;
    },
  },
  mounted() {
    if (this.id) {
      this.get();
    }
  },
  methods: {
    resetErrors,
    parseGqlErrors,

    get() {
      this.loadingData = true;
      this.apollo()
        .admin.query({
          query: VACANCY_ITEM,
          variables: { id: parseInt(this.id) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const vacancy = data.admin_vacancies_item;
          if (!vacancy) {
            return;
          }
          this.form.title.value = vacancy.title;
          this.form.city.value = vacancy.city || "";
          this.form.work_format.value = vacancy.work_format;
          this.form.employment_type.value = vacancy.employment_type;
          this.form.work_schedule.value = vacancy.work_schedule || "";
          this.form.salary.value = vacancy.salary || "";
          this.form.short_description.value = vacancy.short_description;
          this.form.description.value = vacancy.description;
          this.form.responsibilities.value = vacancy.responsibilities || "";
          this.form.requirements.value = vacancy.requirements || "";
          this.form.conditions.value = vacancy.conditions || "";
          this.form.link_title.value = vacancy.link_title || "";
          this.form.link_url.value = vacancy.link_url || "";
          this.form.position.value = vacancy.position;
          this.form.is_published.value = vacancy.is_published;
          this.publishedAt = vacancy.published_at
            ? new Date(vacancy.published_at).toLocaleDateString("ru-RU", {
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
              })
            : null;
        })
        .catch((error) => {
          console.error("Ошибка при загрузке вакансии:", error);
        })
        .finally(() => {
          this.loadingData = false;
        });
    },

    nullIfEmpty(value) {
      const trimmed = typeof value === "string" ? value.trim() : value;
      return trimmed === "" || trimmed === undefined ? null : trimmed;
    },

    submit() {
      this.resetErrors();
      this.loading = true;

      const variables = {
        title: this.form.title.value,
        city: this.nullIfEmpty(this.form.city.value),
        work_format: this.form.work_format.value || null,
        employment_type: this.form.employment_type.value || null,
        work_schedule: this.nullIfEmpty(this.form.work_schedule.value),
        salary: this.nullIfEmpty(this.form.salary.value),
        short_description: this.form.short_description.value,
        description: this.form.description.value,
        responsibilities: this.nullIfEmpty(this.form.responsibilities.value),
        requirements: this.nullIfEmpty(this.form.requirements.value),
        conditions: this.nullIfEmpty(this.form.conditions.value),
        link_title: this.nullIfEmpty(this.form.link_title.value),
        link_url: this.nullIfEmpty(this.form.link_url.value),
        position:
          this.form.position.value === null || this.form.position.value === ""
            ? null
            : parseInt(this.form.position.value),
        is_published: this.form.is_published.value,
      };

      if (this.isEdit) {
        variables.id = parseInt(this.id);
      }

      this.apollo()
        .admin.mutate({
          mutation: this.isEdit ? VACANCY_UPDATE : VACANCY_CREATE,
          variables,
        })
        .then(() => {
          this.$emit("success");
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            this.parseGqlErrors(graphQLErrors);
          }
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.vacancy-form {
  display flex
  flex-direction column
  gap 24px
  max-width 780px

  &__loading {
    display flex
    justify-content center
    padding 32px
  }

  &__block {
    display flex
    flex-direction column
    gap 16px
  }

  &__block-header {
    font-size 1rem
    font-weight 600
    color var(--dark)
  }

  &__block-content {
    display flex
    flex-direction column
    gap 16px
  }

  &__hint {
    margin 0
    font-size 0.813rem
    color var(--dark-light)
  }

  &__actions {
    display flex
    gap 12px
    margin-top 8px
  }
}
</style>
